## ADDED Requirements

### Requirement: Account opening records an account type

Opening an account SHALL record an account type (defaulting to `standard`) on the `AccountOpened`
event (schema version 2). Account-opened events stored at schema version 1 (without the field)
SHALL remain loadable forever, materializing with the default type via the registered upcaster.

#### Scenario: New accounts carry the type

- **WHEN** an account is opened
- **THEN** its `AccountOpened` event carries `account_type` (`standard` by default) at schema version 2

#### Scenario: Version-1 history still loads

- **WHEN** an account whose `AccountOpened` was stored at schema version 1 is rehydrated
- **THEN** loading succeeds and the event materializes with account type `standard`
