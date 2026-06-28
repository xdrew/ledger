## ADDED Requirements

### Requirement: Commands are dispatched synchronously to a single handler

The command bus SHALL dispatch each command to its one registered handler and run it
synchronously, in-process, returning no value.

#### Scenario: A command reaches its handler

- **WHEN** a registered command is dispatched on the command bus
- **THEN** its handler runs in-process and applies the command's effect

### Requirement: An unregistered command is rejected

The command bus SHALL fail explicitly when dispatching a command that has no registered handler,
rather than silently doing nothing.

#### Scenario: Dispatching a command with no handler

- **WHEN** a command with no registered handler is dispatched
- **THEN** dispatch raises a no-handler error

### Requirement: Account and transfer commands apply their effects

The bus SHALL handle `OpenAccount`, `DepositFunds`, and `InitiateTransfer` by invoking the
corresponding aggregate or orchestrator and persisting the result.

#### Scenario: Opening then depositing via commands

- **WHEN** `OpenAccount` then `DepositFunds` are dispatched for the same account
- **THEN** the account exists and its balance reflects the deposit

#### Scenario: Initiating a transfer via a command

- **WHEN** `InitiateTransfer` is dispatched for a funded source
- **THEN** the transfer runs to completion through the orchestrator

### Requirement: Correlation id propagates into recorded events

When a command is dispatched with a correlation id, the events its handler records SHALL carry
that correlation id in their metadata.

#### Scenario: Correlation id flows from command to events

- **WHEN** `OpenAccount` is dispatched with a correlation id
- **THEN** the account's recorded events carry that correlation id in their metadata
