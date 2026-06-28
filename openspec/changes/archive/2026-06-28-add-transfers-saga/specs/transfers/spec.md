## ADDED Requirements

### Requirement: Initiate a transfer

The system SHALL initiate a transfer between a source and destination account for a positive
amount, recording `TransferInitiated` and placing the transfer in the `Initiated` state.

#### Scenario: Initiating a transfer

- **WHEN** a transfer of `100 USD` is initiated from account A to account B
- **THEN** a `TransferInitiated` event is recorded and the transfer is in the `Initiated` state

### Requirement: Complete a transfer on the happy path

The system SHALL, for a fully funded transfer, place a hold on the source, post a balanced
double-entry journal entry (debit source / credit destination), settle the balances, and reach
the `Completed` state.

#### Scenario: A funded transfer completes and moves the money

- **WHEN** account A has `100 USD` available and a transfer of `100 USD` from A to B is run
- **THEN** the transfer reaches `Completed`
- **AND** account A's total decreases by `100 USD` and account B's available increases by `100 USD`
- **AND** a journal entry debiting A and crediting B by `100 USD` is posted

### Requirement: Insufficient funds fail at the hold with no partial effects

The system SHALL fail a transfer at the hold step when the source has insufficient available
funds, leaving no partial effects: no hold, no journal entry, and unchanged balances.

#### Scenario: Transfer with insufficient funds

- **WHEN** a transfer of `150 USD` is run from account A which has only `100 USD` available
- **THEN** the transfer reaches `Failed`
- **AND** no hold is placed, no journal entry is posted, and A's and B's balances are unchanged

### Requirement: A failure after the hold is compensated

The system SHALL, when a transfer fails after the hold is placed but before completion, release
the hold and reach the `Failed` state, restoring the source's available balance.

#### Scenario: Destination is closed after the hold

- **WHEN** a transfer is run whose destination account is closed, so posting the journal entry fails after the source hold
- **THEN** the hold on the source is released and the transfer reaches `Failed`
- **AND** the source's available balance is restored and no money is moved

### Requirement: Transfer state transitions are enforced

The transfer aggregate SHALL permit only valid state transitions
(`Initiated → Held → Posted → Completed`, and `→ Failed` from `Initiated`/`Held`/`Posted`), and
SHALL reject an attempt to advance from the wrong state.

#### Scenario: Completing a transfer that was never posted is rejected

- **WHEN** a transfer in the `Held` state is asked to complete without having posted
- **THEN** the operation is rejected as an invalid transition

### Requirement: Concurrent transfers from one source settle exactly once

The system SHALL settle exactly once when two transfers run concurrently from the same source
that has funds for only one: it completes one transfer, fails the other, and debits the source
only once.

#### Scenario: Two concurrent transfers, funds for one

- **WHEN** account A has `100 USD` and two transfers of `100 USD` from A run concurrently
- **THEN** exactly one transfer reaches `Completed` and the other reaches `Failed`
- **AND** account A is debited exactly once

### Requirement: Reverse a completed transfer with a compensating transfer

The system SHALL reverse a `Completed` transfer by creating a new transfer with the source and
destination swapped and a reference to the original, without modifying the original transfer.

#### Scenario: Reversing a completed transfer

- **WHEN** a completed transfer from A to B for `100 USD` is reversed
- **THEN** a new transfer from B to A for `100 USD` is created referencing the original
- **AND** the original transfer's history is unchanged

#### Scenario: Reversing a non-completed transfer is rejected

- **WHEN** a reversal is requested for a transfer that is not in the `Completed` state
- **THEN** the request is rejected and no compensating transfer is created
