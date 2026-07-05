## ADDED Requirements

### Requirement: Read-only event streams per aggregate

The API SHALL expose the recorded event stream of an account via `GET /api/accounts/{id}/events`
and of a transfer via `GET /api/transfers/{id}/events`, returning for each event its global
position, stream version, event type, schema version, payload, correlation and causation ids, and
occurred-at timestamp. The endpoints SHALL be strictly read-only, require the API key like the rest
of the API, and return `404` problem+json for an unknown stream.

#### Scenario: An account's history is readable

- **WHEN** `GET /api/accounts/{id}/events` is called for an account that was opened and received a deposit
- **THEN** the response lists the account-opened and funds-deposited events in stream order with their metadata

#### Scenario: A transfer's saga trail is readable

- **WHEN** `GET /api/transfers/{id}/events` is called for a completed transfer
- **THEN** the response lists the initiated, held, posted, and completed events in order

#### Scenario: Unknown streams are 404

- **WHEN** the events of a nonexistent id are requested
- **THEN** the response is `404` `application/problem+json`
