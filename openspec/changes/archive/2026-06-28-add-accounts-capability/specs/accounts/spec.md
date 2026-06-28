## ADDED Requirements

### Requirement: Open an account

The system SHALL open an account in a single currency with zero available and zero reserved
balance, emitting `AccountOpened`. An opened account is active.

#### Scenario: Opening a new account

- **WHEN** an account is opened with currency `USD`
- **THEN** an `AccountOpened` event is recorded
- **AND** its available balance, reserved balance, and total are zero `USD`
- **AND** the account is active

### Requirement: Deposit funds

An active account SHALL accept a deposit of a positive amount in the account's currency,
increasing the available balance and emitting `FundsDeposited`.

#### Scenario: Depositing into an active account

- **WHEN** `100 USD` is deposited into an active `USD` account with zero balance
- **THEN** a `FundsDeposited` event is recorded
- **AND** the available balance is `100 USD` and the total is `100 USD`

### Requirement: Place a hold on available funds

An active account SHALL place a hold for a positive amount not exceeding the available
balance, moving that amount from available to reserved and emitting `FundsHeld`.

#### Scenario: Holding within the available balance

- **WHEN** `40 USD` is held on an account with `100 USD` available
- **THEN** a `FundsHeld` event is recorded
- **AND** the available balance is `60 USD`, the reserved balance is `40 USD`, and the total is `100 USD`

#### Scenario: Holding more than available is rejected

- **WHEN** a hold of `150 USD` is attempted on an account with `100 USD` available
- **THEN** the operation fails with an insufficient-funds error
- **AND** no event is recorded and the balances are unchanged

### Requirement: Release a hold

An active account SHALL release a hold for a positive amount not exceeding the reserved
balance, moving that amount from reserved back to available and emitting `HoldReleased`.

#### Scenario: Releasing a previously held amount

- **WHEN** `40 USD` is released on an account with `40 USD` reserved and `60 USD` available
- **THEN** a `HoldReleased` event is recorded
- **AND** the available balance is `100 USD` and the reserved balance is `0 USD`

#### Scenario: Releasing more than reserved is rejected

- **WHEN** a release of `50 USD` is attempted on an account with `40 USD` reserved
- **THEN** the operation fails
- **AND** no event is recorded and the balances are unchanged

### Requirement: Debit settled funds

An active account SHALL debit a positive amount not exceeding the reserved balance, removing
it from reserved (the held funds leave the account) and emitting `FundsDebited`.

#### Scenario: Debiting held funds

- **WHEN** `40 USD` is debited from an account with `40 USD` reserved
- **THEN** a `FundsDebited` event is recorded
- **AND** the reserved balance is `0 USD` and the total is reduced by `40 USD`

#### Scenario: Debiting more than reserved is rejected

- **WHEN** a debit of `50 USD` is attempted on an account with `40 USD` reserved
- **THEN** the operation fails and no event is recorded

### Requirement: Credit funds

An active account SHALL credit a positive amount in the account's currency, increasing the
available balance and emitting `FundsCredited`.

#### Scenario: Crediting an account

- **WHEN** `40 USD` is credited to an account
- **THEN** a `FundsCredited` event is recorded
- **AND** the available balance increases by `40 USD`

### Requirement: Freeze an account

An open account SHALL be freezable, emitting `AccountFrozen` and moving it to the frozen
state. A frozen account is not active.

#### Scenario: Freezing an open account

- **WHEN** an open account is frozen
- **THEN** an `AccountFrozen` event is recorded and the account is not active

### Requirement: Close an account

An open account SHALL be closable, emitting `AccountClosed` and moving it to the closed
state. A closed account is not active.

#### Scenario: Closing an open account

- **WHEN** an open account is closed
- **THEN** an `AccountClosed` event is recorded and the account is not active

### Requirement: Operations require an active account

The account SHALL reject every balance operation (deposit, hold, release, debit, credit)
when it is frozen or closed, recording no event and leaving balances unchanged.

#### Scenario: Operation on a frozen account is rejected

- **WHEN** a deposit is attempted on a frozen account
- **THEN** the operation fails with a not-active error and no event is recorded

#### Scenario: Operation on a closed account is rejected

- **WHEN** a hold is attempted on a closed account
- **THEN** the operation fails with a not-active error and no event is recorded

### Requirement: Amounts must be positive

Every balance operation SHALL require a strictly positive amount; a zero or negative amount
is rejected with no event recorded.

#### Scenario: Zero-amount deposit is rejected

- **WHEN** a deposit of `0 USD` is attempted
- **THEN** the operation fails with an invalid-amount error and no event is recorded

#### Scenario: Negative-amount operation is rejected

- **WHEN** an operation with a negative amount is attempted
- **THEN** the operation fails with an invalid-amount error and no event is recorded

### Requirement: Currency consistency

Every balance operation SHALL require the amount's currency to match the account's currency;
a mismatch is rejected with no event recorded. `Money` arithmetic SHALL likewise refuse
operations across different currencies.

#### Scenario: Operation in a different currency is rejected

- **WHEN** a deposit of `100 EUR` is attempted on a `USD` account
- **THEN** the operation fails with a currency-mismatch error and no event is recorded

### Requirement: Available balance never goes negative

The account SHALL never allow the available balance to become negative; any operation that
would do so is rejected (see hold) and the available balance always satisfies `available ≥ 0`.

#### Scenario: Available balance stays non-negative under a too-large hold

- **WHEN** a hold larger than the available balance is attempted
- **THEN** it is rejected and the available balance remains unchanged and non-negative

### Requirement: Reserved balance stays within bounds

The reserved balance SHALL always satisfy `0 ≤ reserved ≤ total`. Operations that would push
reserved below zero (over-release, over-debit) are rejected.

#### Scenario: Reserved never exceeds total

- **WHEN** any sequence of valid operations is applied
- **THEN** the reserved balance is always between zero and the total inclusive

### Requirement: Money is integer minor units with a currency

Monetary amounts SHALL be represented as an integer number of minor units together with a
currency code, never as floating-point values.

#### Scenario: Adding two amounts of the same currency

- **WHEN** `100 USD` and `50 USD` are added
- **THEN** the result is `150 USD`

#### Scenario: Adding amounts of different currencies is rejected

- **WHEN** `100 USD` and `50 EUR` are added
- **THEN** the operation fails with a currency-mismatch error

### Requirement: Accounts are rebuilt from their event history

An account's full state (status, currency, available, reserved) SHALL be reconstructable by
replaying its persisted events from the event store, with no behavioral difference from the
live aggregate.

#### Scenario: Rehydrating an account from the event store

- **WHEN** an account is opened, deposited into, and a hold is placed, then saved and reloaded
  from the event store
- **THEN** the reloaded account has the same status, currency, available, and reserved balances
  as before it was saved
