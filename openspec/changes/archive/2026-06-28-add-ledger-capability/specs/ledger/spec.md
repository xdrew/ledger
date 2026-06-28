## ADDED Requirements

### Requirement: Post a balanced double-entry journal entry

The ledger SHALL post a journal entry of two or more legs, where each leg debits or credits a
strictly positive amount on an account, and SHALL emit a single `JournalEntryPosted` event.
The entry SHALL be balanced: for every currency in the entry, the sum of debit amounts equals
the sum of credit amounts.

#### Scenario: Posting a balanced two-leg entry

- **WHEN** an entry is posted debiting `100 USD` on account A and crediting `100 USD` on account B
- **THEN** a `JournalEntryPosted` event is recorded with both legs
- **AND** the entry is balanced

#### Scenario: Posting a balanced multi-leg entry

- **WHEN** an entry is posted debiting `100 USD` on A and crediting `70 USD` on B and `30 USD` on C
- **THEN** a `JournalEntryPosted` event is recorded with the three legs

### Requirement: An entry must have at least two legs

The ledger SHALL reject a journal entry with fewer than two legs, recording no event.

#### Scenario: Single-leg entry is rejected

- **WHEN** an entry with only one leg is posted
- **THEN** the posting fails with an unbalanced/too-few-legs error and no event is recorded

### Requirement: Entries must balance per currency

The ledger SHALL reject a journal entry whose debit and credit totals are not equal for some
currency, recording no event.

#### Scenario: Unbalanced entry is rejected

- **WHEN** an entry debits `100 USD` on A and credits `90 USD` on B
- **THEN** the posting fails with an unbalanced error and no event is recorded

### Requirement: Leg amounts must be positive

The ledger SHALL require every leg amount to be strictly positive; a zero or negative leg
amount is rejected with no event recorded.

#### Scenario: Zero-amount leg is rejected

- **WHEN** an entry contains a leg of `0 USD`
- **THEN** the posting fails with an invalid-amount error and no event is recorded

### Requirement: No posting against a closed account

The ledger SHALL reject a journal entry that references an account which is closed, recording
no event.

#### Scenario: Posting that references a closed account is rejected

- **WHEN** an entry is posted with a leg referencing a closed account
- **THEN** the posting fails with a closed-account error and no event is recorded

### Requirement: Journal entries are immutable

A posted journal entry SHALL never be amended; its event stream contains exactly one
`JournalEntryPosted` event. Corrections are made by posting new compensating entries.

#### Scenario: A posted entry's stream holds a single event

- **WHEN** a journal entry is posted and its stream is loaded
- **THEN** the stream contains exactly one `JournalEntryPosted` event

### Requirement: Journal entries are rebuilt from the event store

A journal entry's legs SHALL be reconstructable by replaying its persisted event, with no
difference from the posted entry.

#### Scenario: Rehydrating a posted entry

- **WHEN** an entry is posted, saved, and reloaded from the ledger repository
- **THEN** the reloaded entry has the same legs (account, direction, amount) as posted

### Requirement: The ledger reconciles to global zero-sum (trial balance)

Across all posted journal entries, the net of debits minus credits SHALL reconcile: for every
account the per-account net is well defined, and for every currency the sum across all
accounts SHALL be zero.

#### Scenario: N random balanced entries net to zero

- **WHEN** any number of individually balanced entries are posted
- **THEN** summing all legs yields, for each currency, total debits equal to total credits
  (a global net of zero)
- **AND** each account's debits-minus-credits net is consistent with those entries
