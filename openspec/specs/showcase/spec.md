# showcase Specification

## Purpose
TBD - created by archiving change add-showcase. Update Purpose after archive.
## Requirements
### Requirement: A guided playground demonstrates the system end to end

The system SHALL serve a self-contained playground page (no framework, no build step) that drives
the public API through a guided story — opening accounts, depositing, a completing transfer, an
insufficient-funds transfer, and an idempotent replay — and free-form exploration including
natural-language statement queries. The playground SHALL act as a plain API client: it holds no
privileged access and authenticates with an API key entered on the page.

#### Scenario: The guided story runs against the real API

- **WHEN** a visitor steps through the guided story
- **THEN** every action is a real call to the public API and its actual response (including problem+json failures and the idempotent-replay header) is displayed

#### Scenario: No privileged access

- **WHEN** the playground makes any API call
- **THEN** it uses the visitor-provided API key exactly as an external integrator would

### Requirement: The playground shows what the core recorded

After each action, the playground SHALL display, side by side: the API exchange, the read models
(balances with their projection version, statement), and the event streams of the touched
aggregates — so cause (the call), effect (the projection), and record (the events, including the
transfer saga's state trail and correlation ids) are visible together.

#### Scenario: A transfer's saga trail is visible

- **WHEN** the guided transfer completes
- **THEN** the transfer stream panel shows the Initiated, Held, Posted, and Completed events with their correlation ids

### Requirement: The double-spend race is demonstrable live

The playground SHALL include a concurrency scenario that funds an account for exactly one transfer
and issues two transfer requests concurrently, displaying both real responses and verifying the
invariant: exactly one transfer completes, the other fails (insufficient funds or conflict), and the
source stream records a single hold-and-debit trail — the money moves exactly once.

#### Scenario: Two racing transfers, one winner

- **WHEN** the double-spend scenario fires two concurrent transfers against funds sufficient for one
- **THEN** the page shows exactly one `completed` and one `failed` response, and the source account's event stream shows one hold and one debit

### Requirement: Failure design is demonstrable per edge case

The playground SHALL offer one-click edge-case scenarios rendering the API's real failure
responses: idempotency-key reuse with a different payload, invalid amounts, currency mismatch, and
a transfer to a nonexistent destination — the last showing saga compensation (hold placed, then
released, balance unchanged) in the event stream.

#### Scenario: Compensation is visible

- **WHEN** the nonexistent-destination scenario runs
- **THEN** the transfer is reported `failed` and the source stream shows the hold followed by its release, with the balance panel unchanged

#### Scenario: Idempotency is payload-bound

- **WHEN** the key-reuse scenario sends a different payload under a used idempotency key
- **THEN** the page shows the API's `422` problem+json response

### Requirement: Human-readable API documentation page

The system SHALL serve a public documentation page rendering the live generated OpenAPI document,
requiring no API key (like the document itself).

#### Scenario: Docs render without a key

- **WHEN** the docs page is requested without an API key
- **THEN** an HTML page rendering the current OpenAPI document is returned

