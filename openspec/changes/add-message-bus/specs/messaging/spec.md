## ADDED Requirements

### Requirement: Commands are dispatched to a single handler

The command bus SHALL dispatch each command to exactly one registered handler and execute it.

#### Scenario: A command reaches its handler

- **WHEN** a registered command is dispatched on the command bus
- **THEN** its single handler runs and applies the command's effect

#### Scenario: An unregistered command is rejected

- **WHEN** a command with no registered handler is dispatched
- **THEN** dispatch fails with an explicit error rather than silently doing nothing

### Requirement: Queries are dispatched to a single handler and return a result

The query bus SHALL dispatch each query to exactly one registered handler and return that
handler's result to the caller.

#### Scenario: A query returns read data

- **WHEN** a registered query is dispatched on the query bus
- **THEN** its handler runs and the result is returned to the caller

### Requirement: Account and transfer commands apply their effects

The bus SHALL handle the `OpenAccount`, `DepositFunds`, and `InitiateTransfer` commands by
invoking the corresponding aggregate or orchestrator and persisting the result.

#### Scenario: Opening then depositing via commands

- **WHEN** `OpenAccount` then `DepositFunds` are dispatched for the same account
- **THEN** the account exists and its balance reflects the deposit

#### Scenario: Initiating a transfer via a command

- **WHEN** `InitiateTransfer` is dispatched for a funded source
- **THEN** the transfer runs to completion through the orchestrator

### Requirement: Read queries return read-model data

The bus SHALL handle `GetAccountBalance`, `GetAccountStatement`, and `GetTransfer` by returning
data from the projections or the transfer stream.

#### Scenario: Querying a balance

- **WHEN** `GetAccountBalance` is dispatched for a projected account
- **THEN** the current available, reserved, and total balances are returned

### Requirement: Correlation id propagates into recorded events

A command dispatched with a correlation id SHALL cause the events it records to carry that
correlation id in their metadata.

#### Scenario: Correlation id flows from command to events

- **WHEN** a command is dispatched with a correlation id and records events
- **THEN** those events' metadata carry the same correlation id
