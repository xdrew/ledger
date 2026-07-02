## ADDED Requirements

### Requirement: Events are upcast on read

The event store's deserialization SHALL transform payloads stored at an older schema version to the
event type's current version by applying registered single-step upcasters in version order, without
ever rewriting stored payloads. Payloads already at the current version SHALL bypass upcasting.
When a stored version is older than the current version and no upcaster covers a required step,
deserialization SHALL fail loudly rather than construct the event from a stale payload.

#### Scenario: An old event loads in the current shape

- **WHEN** an event stored at schema version 1 is loaded after its type advanced to version 2 with a registered v1→v2 upcaster
- **THEN** the deserialized event has the current shape, with upcaster-supplied values for the added fields, and the stored row is unchanged

#### Scenario: Upcasters chain across multiple versions

- **WHEN** an event stored at version 1 is loaded after its type advanced to version 3 with v1→v2 and v2→v3 upcasters registered
- **THEN** both steps apply in order and the event materializes at version 3's shape

#### Scenario: A missing upcasting step fails loudly

- **WHEN** an event stored at an older version is loaded and no upcaster is registered for a required step
- **THEN** deserialization throws a missing-upcaster error instead of building the event from the stale payload
