## ADDED Requirements

### Requirement: Natural-language statement queries translate to structured filters

When enabled, the statement endpoint SHALL accept a natural-language query (`?q=`) and answer it by
translating the question into a structured filter (entry types, date range, amount range, and an
aggregation of `list`, `sum`, or `count`) via the Anthropic API with schema-enforced output, then
executing that filter as parameterized SQL against the statement read model. The language model
SHALL only produce the filter — it SHALL NOT generate SQL, see statement data, or compute values.

#### Scenario: A deposit question returns filtered entries

- **WHEN** `GET /api/accounts/{id}/statement?q=show my deposits from June` runs with the feature enabled
- **THEN** the response contains only matching entries, produced by the read model under the translated filter

#### Scenario: A "how much" question returns a SQL-computed aggregate

- **WHEN** the translated filter's aggregation is `sum`
- **THEN** the response carries the sum computed by the database over the filtered rows, not a model-computed value

### Requirement: The interpretation is echoed

Every natural-language query response SHALL include the structured filter the question was
translated to, so callers can see exactly how their question was understood.

#### Scenario: The caller sees the applied filter

- **WHEN** a `?q=` request succeeds
- **THEN** the response includes an interpretation object naming the entry types, date range, amount range, and aggregation that were applied

### Requirement: Feature-flagged, off by default

The capability SHALL be gated by a feature flag that defaults to off. With the flag off, a `?q=`
request SHALL be rejected with `501` problem+json rather than silently returning the unfiltered
statement; without `q`, the statement endpoint SHALL behave identically to the flag being absent.

#### Scenario: Disabled flag rejects rather than degrades

- **WHEN** `?q=` is sent while the feature flag is off
- **THEN** the response is `501` `application/problem+json`

#### Scenario: No query parameter, no behavior change

- **WHEN** the statement endpoint is called without `q`
- **THEN** the response is identical regardless of the flag

### Requirement: Translation failures never guess

The endpoint SHALL respond `502` problem+json whenever translation fails (an API error, a refusal,
or output that does not validate against the filter schema). Model output SHALL be validated by the
application before any SQL executes, rejecting unknown entry types and inverted ranges.

#### Scenario: A translation error surfaces as 502

- **WHEN** the translator raises a failure for a `?q=` request
- **THEN** the response is `502` `application/problem+json` and no filter is executed

### Requirement: Testable without the Anthropic API

The translation SHALL sit behind a port with a deterministic fake used by the test suite, so CI
exercises the endpoint, filtering, flag, and failure behavior without network calls; the Anthropic
adapter's request construction and response parsing SHALL be unit-tested against fixtures.

#### Scenario: CI runs without API access

- **WHEN** the test suites run
- **THEN** all nl-query behavior is verified with no call to the Anthropic API
