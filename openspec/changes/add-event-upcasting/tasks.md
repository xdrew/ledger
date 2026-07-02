> Closes the last open §6 requirement (mechanism + example upcaster), implementing the strategy
> fixed in ADR-006. No schema change, no data migration; writers keep emitting current versions.

## 1. Mechanism

- [ ] 1.1 `Upcaster` interface (`eventType`, `fromVersion`, `upcast(array): array`) and `MissingUpcaster` exception in `src/EventStore/Serialization/`.
- [ ] 1.2 `UpcasterChain`: index by `(type, fromVersion)`, duplicate registration throws, steps stored→current one version at a time, missing step throws `MissingUpcaster`.
- [ ] 1.3 Hook into `EventSerializer::deserialize` (chain before `fromPayload`, bypass when versions are equal; optional constructor arg defaulting to an empty chain).
- [ ] 1.4 DI: `_instanceof` tag `app.upcaster`; `UpcasterChain` via `!tagged_iterator`; serializer wired with the chain.

## 2. Example: AccountOpened v1 → v2

- [ ] 2.1 `AccountOpened` gains `accountType` (default `'standard'`, in `toPayload`/`fromPayload`); `Account::open` passes it through; `AccountEventTypes` registers schema version 2.
- [ ] 2.2 `AccountOpenedV1ToV2` in `src/Accounts/Infrastructure/Upcasting/` supplying the default.

## 3. Tests

- [ ] 3.1 Unit: chain upcasts a captured v1 fixture to v2; multi-step composition (v1→v2→v3 with a synthetic test event); missing step throws; equal versions bypass.
- [ ] 3.2 Integration: insert a raw v1 `accounts.account_opened` row in PostgreSQL; loading yields the v2 shape and the account aggregate rehydrates.

## 4. Docs & gate

- [ ] 4.1 Update ADR-006's status line: mechanism implemented by this change.
- [ ] 4.2 Green: php-cs-fixer, PHPStan max, all suites (incl. mutation floor locally); `openspec validate add-event-upcasting --strict` passes.
