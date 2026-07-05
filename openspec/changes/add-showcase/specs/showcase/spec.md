## ADDED Requirements

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

### Requirement: Human-readable API documentation page

The system SHALL serve a public documentation page rendering the live generated OpenAPI document,
requiring no API key (like the document itself).

#### Scenario: Docs render without a key

- **WHEN** the docs page is requested without an API key
- **THEN** an HTML page rendering the current OpenAPI document is returned
