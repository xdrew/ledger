> Optional AI feature (brief §5), flag-gated (default off). New dep: anthropic-ai/sdk (official PHP
> SDK). The LLM translates NL → a typed StatementFilter at the API edge; SQL execution and
> aggregates are ours. CI never calls the Anthropic API (fake translator + fixture-tested adapter).

## 1. Filter & read model

- [x] 1.1 `StatementFilter` DTO (entry types enum `deposit|hold|hold_release|debit|credit`, date range, amount range, aggregation `list|sum|count`) with validating `fromArray` (rejects unknown types, inverted ranges).
- [x] 1.2 `AccountStatementView::forAccountFiltered(id, filter)` — bound-parameter SQL WHERE + SQL-computed `sum`/`count` aggregates.

## 2. Translation port & Anthropic adapter

- [x] 2.1 `StatementQueryTranslator` port + `TranslationFailed` exception (`src/Infrastructure/NlQuery/`).
- [x] 2.2 Add `anthropic-ai/sdk`; `AnthropicStatementQueryTranslator`: Messages API with structured outputs (`outputConfig.format`, JSON schema with `additionalProperties: false`), system prompt naming only the filterable columns, `LLM_MODEL` (default `claude-opus-4-8`), small `maxTokens`; SDK/API errors and schema-invalid content → `TranslationFailed`.
- [x] 2.3 DI: port → adapter; env `LLM_STATEMENT_QUERY_ENABLED=0`, `ANTHROPIC_API_KEY=`, `LLM_MODEL=claude-opus-4-8`.

## 3. Endpoint

- [x] 3.1 Statement `Action`: `?q=` branch — flag off → `501` problem+json; empty `q` → plain statement; on → translate, filter, respond with `entries` (for `list`), `aggregate` (`sum`/`count`), and the `interpretation` echo. `TranslationFailed` → `502` problem+json (exception listener mapping).
- [x] 3.2 Response DTO extension; OpenAPI: document `q` via `#[QueryParam]`.

## 4. Tests

- [x] 4.1 Unit: `StatementFilter::fromArray` validation; adapter request construction (schema, model, prompt) and response parsing against fixture payloads incl. malformed → `TranslationFailed`.
- [x] 4.2 Integration: `forAccountFiltered` filters and aggregates correctly against PostgreSQL.
- [x] 4.3 Functional (fake translator via `services_test.yaml`): `?q=` returns filtered entries + interpretation; `sum` aggregation; flag off → `501`; translator failure → `502`; no `q` → unchanged response.
- [x] 4.4 Manual live smoke documented (README note): flag on + real key + one query.

## 5. Docs & gate

- [x] 5.1 README: feature section (flag, env, honest limits — no counterparty column).
- [x] 5.2 Green: php-cs-fixer, PHPStan max, all suites; `openspec validate add-llm-statement-query --strict` passes.
