## ADDED Requirements

### Requirement: Maintain an account balances read model

The system SHALL project account events into an `account_balances` read model holding, per
account, the currency and the available, reserved, and total balances together with the source
stream version.

#### Scenario: Balances reflect account activity

- **WHEN** an account is opened, `100 USD` is deposited, and `40 USD` is held, and the projector runs
- **THEN** the account's balances row shows available `60 USD`, reserved `40 USD`, and total `100 USD`

### Requirement: Maintain an ordered account statement read model

The system SHALL project an account's postings and holds into an `account_statement` read model,
ordered by global position, with one entry per posting/hold event.

#### Scenario: Statement lists entries in order

- **WHEN** an account has a deposit then a hold and the projector runs
- **THEN** the statement for that account lists the deposit before the hold, in global-position order

### Requirement: Read models are rebuildable from the event store

The system SHALL provide a `projections:rebuild` operation that truncates the read models, resets
the checkpoint, and replays all events from the start, producing state identical to the live
projection.

#### Scenario: Drop and rebuild yields identical balances

- **WHEN** read models have been projected live and are then rebuilt from the event store
- **THEN** the rebuilt `account_balances` are identical to the balances before the rebuild

### Requirement: Projection is exactly-once and replay-safe

The projection runner SHALL advance its checkpoint in the same transaction as the read-model
writes, so that re-running the projection does not double-apply already-processed events.

#### Scenario: Re-running after catching up changes nothing

- **WHEN** the projection runner has caught up to the latest event and is run again with no new events
- **THEN** the read models are unchanged

### Requirement: Projection lag is observable

The system SHALL expose the projection lag — the number of global positions between the latest
stored event and the runner's checkpoint — via a status operation.

#### Scenario: Lag is zero once caught up

- **WHEN** the projection runner has processed every stored event
- **THEN** the reported projection lag is zero

#### Scenario: Lag reflects unprocessed events

- **WHEN** new events are appended that the runner has not yet processed
- **THEN** the reported projection lag equals the number of those unprocessed events
