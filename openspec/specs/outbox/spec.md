# outbox Specification

## Purpose
TBD - created by archiving change add-outbox. Update Purpose after archive.
## Requirements
### Requirement: The relay publishes every recorded event in order

The relay SHALL publish every event recorded in the event log to the transport, in ascending
global-position order, advancing its checkpoint as it goes.

#### Scenario: All recorded events are published in order

- **WHEN** several events have been appended and the relay runs to completion
- **THEN** every event has been published to the transport in ascending global-position order
- **AND** the relay checkpoint equals the latest published position

### Requirement: Delivery is at-least-once

The relay SHALL advance its checkpoint only after an event has been successfully published, so
that every event is delivered at least once and none is skipped.

#### Scenario: A publish failure does not advance the checkpoint

- **WHEN** publishing an event fails
- **THEN** the relay does not advance its checkpoint past that event
- **AND** a subsequent run re-attempts that event

### Requirement: The relay recovers from a crash without losing events

The relay SHALL resume from its checkpoint after being killed mid-batch, so that restarting it
publishes every not-yet-checkpointed event and loses none.

#### Scenario: Kill mid-batch then restart

- **WHEN** the relay is interrupted after publishing only some of the pending events
- **THEN** restarting it publishes the remaining events
- **AND** every recorded event has been published at least once

### Requirement: Idempotent consumers see each event once

A consumer guarded by the event-id idempotency record SHALL apply each event's effect exactly
once, even when the relay delivers that event more than once.

#### Scenario: Re-delivered event is applied once

- **WHEN** the relay delivers the same event twice to an idempotent consumer
- **THEN** the consumer applies its effect only the first time and ignores the duplicate

### Requirement: The transport is abstracted behind a port

Event publication SHALL go through a transport port so the transport (in-memory, PostgreSQL
`LISTEN/NOTIFY`, or a future NATS JetStream) can be swapped without changing the relay.

#### Scenario: Swapping the transport does not change relay behavior

- **WHEN** the relay is configured with a different `EventPublisher` implementation
- **THEN** it publishes the same events in the same order without code changes to the relay

