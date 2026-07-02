## Why

The brief's optional AI-native feature (§5): let clients ask the statement endpoint questions in
natural language — `GET /api/accounts/{id}/statement?q=how much did I deposit in June` — with the
Anthropic API translating the question into a **structured filter** that runs against the existing
`account_statement` read model. Small, behind a feature flag, and deliberately confined to the edge:
the LLM never touches the domain, never writes, and never fabricates data — it only produces a
filter the projection executes.

## What Changes

- **`?q=` on the statement endpoint.** When present and the feature is enabled, the query is
  translated to a `StatementFilter` (entry types among `deposit|hold|hold_release|debit|credit`,
  date range, amount range, and an aggregation: `list`, `sum`, or `count`) and applied as SQL against
  `account_statement`. The response carries the filtered `entries`, the computed `aggregate`, and an
  **`interpretation`** echo of the structured filter — so the caller always sees exactly how the
  question was understood (and what was ignored). Without `q`, the endpoint behaves exactly as today.
- **Feature flag:** `LLM_STATEMENT_QUERY_ENABLED` (default **off**). When off, `?q=` returns
  `501 application/problem+json` ("feature disabled") — honest, not silent degradation.
- **Anthropic integration** through the **official PHP SDK** (`anthropic-ai/sdk`), behind a port:
  - `StatementQueryTranslator` (port) → `AnthropicStatementQueryTranslator` (adapter). The adapter
    calls the Messages API with **structured outputs** (`outputConfig.format` + a JSON schema with
    `additionalProperties: false`), which guarantees a schema-conforming JSON response — no prompt
    parsing tricks. The system prompt describes only the filterable columns; a question the schema
    cannot express degrades to the broadest valid filter rather than inventing capability.
  - Model: **`claude-opus-4-8`** by default, configurable via `LLM_MODEL` (cost/latency tuning —
    e.g. `claude-haiku-4-5` — is the operator's explicit choice, not a silent default).
  - `ANTHROPIC_API_KEY` from the environment (empty in `.env`; real key injected like other secrets).
  - Translation failure (API error, unparseable output) → `502` problem+json; the endpoint never
    guesses.
- **Testing without the API:** a `FakeStatementQueryTranslator` replaces the adapter in the test
  container (same pattern as `InMemoryMetrics`); functional tests cover the endpoint + filter + flag
  behavior end-to-end, and the adapter's request-building and response-parsing are unit-tested
  against fixture payloads. No live API calls in CI.

## Capabilities

### New Capabilities
- `nl-query`: natural-language statement queries — the flag-gated `?q=` parameter, NL→structured-
  filter translation via the Anthropic API (structured outputs, port/adapter), filtered read-model
  execution with aggregates, the interpretation echo, and honest failure modes (501 disabled /
  502 translation failure).

### Modified Capabilities
<!-- None at the spec level: without ?q= the api statement endpoint is byte-identical; the addition
     is expressed entirely in the new capability. -->

## Impact

- **New code:** `src/Projections/Query/StatementFilter.php` + a filtered query method on
  `AccountStatementView` (plain SQL WHERE — no LLM involvement in execution);
  `src/Infrastructure/NlQuery/{StatementQueryTranslator, AnthropicStatementQueryTranslator,
  TranslationFailed}`; the statement `Action` gains the `q` branch and a richer `Response`;
  `tests/Support/FakeStatementQueryTranslator`.
- **Dependencies (new):** `anthropic-ai/sdk` (the official PHP SDK).
- **Env:** `LLM_STATEMENT_QUERY_ENABLED=0`, `ANTHROPIC_API_KEY=`, `LLM_MODEL=claude-opus-4-8`.
- **Honest limits:** the statement read model has `entry_type`, `amount`, `currency`, `occurred_at` —
  there is **no counterparty column**, so "how much went to *Alice*" filters by direction/date only;
  the interpretation echo makes that visible. Adding counterparty data would be a projection change,
  out of scope here.
- **Depends on** the api and projections capabilities. Runtime cost: one Messages API call per
  `?q=` request; no calls when the flag is off or `q` absent.
