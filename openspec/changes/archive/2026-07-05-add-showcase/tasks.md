> Amends the original "no UI" non-goal: a showcase needs a demo surface. No new dependencies, no
> schema changes; the playground is a pure client of the public API. Frontend implementation uses
> the frontend-design pass (no default-template look).

## 1. Event-stream endpoints (api capability)

- [x] 1.1 `App\Api\Accounts\Events\Action` (`GET /api/accounts/{id}/events`) and `App\Api\Transfers\Events\Action` (`GET /api/transfers/{id}/events`): map `EventStore::load` to JSON (globalPosition, version, type, schemaVersion, payload, correlationId, causationId, occurredAt); unknown stream → `404` problem+json; API-key auth as usual.
- [x] 1.2 Functional tests: events appear after actions (account opened+deposit; the transfer saga trail Initiated→Held→Posted→Completed with correlation ids); 404 for unknown ids; OpenAPI documents the endpoints.

## 2. Playground

- [x] 2.1 `App\Showcase\PlaygroundAction` (`GET /`, public route outside `/api`) serving the self-contained `playground.html` (vanilla JS, inline CSS, no CDN).
- [x] 2.2 Guided story: open Alice+Bob → deposit → transfer (completed) → transfer (insufficient funds → `failed`) → idempotent replay (same key, `Idempotent-Replayed` header shown) — each step updates the panels + a one-line architectural caption.
- [x] 2.2a Double-spend race: fund a fresh account for exactly one transfer, fire two concurrent transfers (`Promise.all`); display both responses and assert the invariant (exactly one `completed`; loser `failed` with `insufficient_funds` or `conflict`); source stream shows a single hold→debit trail.
- [x] 2.2b Edge cases (one click each, real API responses rendered): idempotency-key reuse with different payload → `422`; zero-amount deposit → `422` field errors; wrong-currency deposit → `422`; transfer to nonexistent destination → `failed` + compensation visible (`FundsHeld` → `HoldReleased` in the source stream, balance intact).
- [x] 2.3 Panels: API exchange (request/response incl. problem+json), read models (balances with projection `version`, statement), event log per touched stream (via the new endpoints).
- [x] 2.4 Free exploration: NL statement-query box (`?q=`), API-key field (prefilled local-dev default, localStorage); story/race/edge buttons are re-runnable. (Custom-amount inputs dropped — the scripted amounts carry the story; scope kept minimal.)
- [x] 2.5 Links out: Grafana, Prometheus, `/api/docs`.
- [x] 2.6 Visual design pass (frontend-design skill): intentional, non-template look consistent with a financial-infrastructure showcase.

## 3. Docs page

- [x] 3.1 `App\Api\Documentation\RedocAction` (`GET /api/docs`, `#[OpenApiPublic]`): Redoc CDN shell over `/api/openapi.json`.
- [x] 3.2 Functional test: `/api/docs` serves HTML without a key.

## 4. Docs & gate

- [x] 4.1 README "Try it": `task up:stack` → `http://localhost:8080/` → the guided story; note the playground is a plain API client.
- [x] 4.2 Green: php-cs-fixer, PHPStan max, all suites; live smoke of the playground through RoadRunner; `openspec validate add-showcase --strict` passes.
