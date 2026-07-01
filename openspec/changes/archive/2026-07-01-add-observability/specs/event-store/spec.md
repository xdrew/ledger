## ADDED Requirements

### Requirement: Event metadata carries trace context

Event metadata SHALL carry an optional W3C `traceparent` alongside the correlation and causation
ids, persisted and rehydrated with the event, so that asynchronous consumers (the outbox relay and
projectors) can continue the trace of the request that produced the event. It SHALL be optional and
backward compatible: absent on events written before this change and when tracing is disabled.

#### Scenario: Trace context round-trips through the store

- **WHEN** an event is appended with a `traceparent` in its metadata and later loaded
- **THEN** the loaded event's metadata carries the same `traceparent`

#### Scenario: Events without trace context still load

- **WHEN** an event was stored without a `traceparent`
- **THEN** it loads successfully with a null `traceparent`
