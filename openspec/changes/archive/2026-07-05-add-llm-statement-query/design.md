## Context

An optional, deliberately small AI feature (brief §5): NL → structured filter over the statement
read model. The design constraint that matters is **containment** — the LLM is a translator at the
API edge, nothing more. It cannot read the database, cannot write, and its output is a typed filter
validated by our code before any SQL runs. The brief asks for a feature flag and for the core domain
to stay unpolluted; both are structural here, not aspirational.

## Goals / Non-Goals

**Goals:** `?q=` on the statement endpoint (flag-gated, default off); translation via the Anthropic
API with schema-guaranteed output; filtered execution + aggregates on the read model; the
interpretation echoed so callers see what was understood; failure modes that never guess; tests that
run without the API.

**Non-Goals:** free-form SQL generation (categorically excluded); counterparty search (no such
column in the read model); conversation/memory; streaming; caching translations; any LLM use
elsewhere in the system.

## Decisions

### D1: NL → typed filter, never NL → SQL
The LLM produces a `StatementFilter` — entry types (enum), date range, amount range, aggregation
(`list|sum|count`) — and our code builds the SQL with bound parameters. A model cannot inject SQL it
never writes. The filter is also the **interpretation contract**: it is echoed verbatim in the
response, so a partially-understood question is visible rather than silently wrong.

- *Alternatives rejected:* text-to-SQL (unbounded blast radius, unauditable); answering from the
  LLM's own arithmetic over returned rows (fabrication risk — aggregates are computed by SQL, the
  model never sees the data at all).

### D2: Official PHP SDK + structured outputs
`anthropic-ai/sdk` with `messages->create(..., outputConfig: ['format' => ['type' => 'json_schema',
'schema' => …]])`. The schema (`additionalProperties: false`, enums for entry types and aggregation,
`date` formats) makes conforming JSON an API guarantee, not a parsing hope. The system prompt states
the filterable columns and the "translate, don't answer" role; `maxTokens` small (the output is a
filter, not prose).

- *Alternatives rejected:* raw HTTP via symfony/http-client (the official SDK exists — hand-rolling
  auth/retries/types buys nothing); tool-use with `strict: true` (equivalent guarantee, more moving
  parts for a single-shot extraction); prompt-engineered "reply only with JSON" (no guarantee).

### D3: Model choice is explicit, not silently cheap
Default `LLM_MODEL=claude-opus-4-8`; the operator may set `claude-haiku-4-5` for cost/latency.
Downgrading by default to save money is a product decision the operator should make consciously —
the env var makes it a one-line, documented choice.

### D4: Port/adapter + fake for tests
`StatementQueryTranslator` is the port; `AnthropicStatementQueryTranslator` the adapter;
`FakeStatementQueryTranslator` (deterministic keyword mapping) replaces it in `services_test.yaml` —
the same override pattern as `InMemoryMetrics`. Functional tests exercise endpoint + filter + flag +
failure paths against the fake; the adapter's schema/request construction and response parsing are
unit-tested with fixture payloads (including a malformed one → `TranslationFailed`). CI never calls
the API; a live smoke is a documented manual step for a machine with a key.

### D5: Failure modes never guess
- Flag off + `?q=` → **501** problem+json ("not enabled"), because pretending the parameter doesn't
  exist would silently return the unfiltered statement — a wrong answer to the asked question.
- Translation failure (API error, refusal, schema-invalid content) → **502** problem+json.
- Empty/whitespace `q` → the plain statement (parameter ignored).
- The filter the model returns is validated (`StatementFilter::fromArray` rejects unknown types,
  inverted ranges) — a valid-JSON-wrong-content response still cannot reach SQL malformed.

### D6: Aggregates computed by SQL
`sum`/`count` run as SQL over the filtered rows and are returned alongside (or instead of) the
entries. The model chooses *which* aggregation the question implies; it never computes values.

## Risks / Trade-offs

- **Latency:** one model round-trip per `?q=` request (hundreds of ms to seconds). Acceptable for an
  analytical query; the flag keeps it off any hot path by default.
- **Misinterpretation:** the model may map a question to a narrower/wider filter than intended →
  mitigated by the interpretation echo (the caller can see and refine) and the enum-constrained
  schema (it cannot invent filter dimensions).
- **Key handling:** `ANTHROPIC_API_KEY` follows the existing secret convention (empty in `.env`,
  injected in deployment; the Helm chart's Secret already carries app secrets).
- **SDK beta churn:** we use only the stable Messages API + structured outputs surface.

## Open Questions

- None blocking. Counterparty filtering is explicitly out (no column); revisit only if the
  projection ever grows one.
