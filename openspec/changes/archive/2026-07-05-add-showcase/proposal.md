## Why

The project is a showcase, but today it can only be *inspected* (tests, metrics, SQL), not
*experienced*. A visitor cannot open an account, move money, watch the event log grow, or see the
saga step through hold → post → settle without curl and psql. This change adds the missing
demonstration layer: a zero-build **playground page** that drives the real API and shows, side by
side, what the caller sees and what the event-sourced core recorded — plus a human-readable **API
docs page**. It deliberately amends the original "no UI" non-goal: a demo surface *is* the product
for a showcase.

## What Changes

- **Event-stream read endpoints** (the "under the hood" data source — the log finally becomes
  visible through the API, read-only):
  - `GET /api/accounts/{id}/events` and `GET /api/transfers/{id}/events` return the aggregate's
    recorded events: global position, version, event type, schema version, payload,
    correlation/causation ids, occurred-at. Same API-key auth as every other endpoint; strictly
    read-only over the existing `EventStore::load`.
- **The playground** — `GET /` serves a single self-contained HTML page (vanilla JS + `fetch`, no
  framework, no build step; styling via the frontend-design pass at implementation):
  - **Act:** buttons for the guided story — open two accounts, deposit, transfer (success), transfer
    that fails on insufficient funds, replay a request with the same idempotency key — plus a free
    NL statement-query box (`?q=`).
  - **Race (the brief's centerpiece, live):** a "double-spend" button funds an account for exactly
    one transfer and fires **two concurrent transfers** from it (`Promise.all` → two RoadRunner
    workers → a real race against the UNIQUE stream-version guard). The panels show both responses
    side by side — exactly one `completed`, the other `failed` (`insufficient_funds` or `conflict`,
    depending on who lost where) — and the source stream's single hold→debit trail. The caption
    explains that both loser outcomes are correct: the money moved exactly once.
  - **Edge cases chapter** — each a one-click scenario with its real API failure rendered:
    idempotency-key reuse with a different payload (`422`), zero-amount deposit (`422` with field
    errors), wrong-currency deposit (`422` CurrencyMismatch), and a transfer to a nonexistent
    destination — which demonstrates **saga compensation**: the response is `failed`, and the source
    stream visibly shows `FundsHeld` followed by `HoldReleased` with the balance intact.
  - **See:** after every action, three synchronized panels update: the **API exchange** (request →
    response, including problem+json errors and the `Idempotent-Replayed` header), the **read
    models** (balances with projection `version`, statement), and the **event log** for the touched
    streams (the saga's Initiated→Held→Posted→Completed trail, correlation ids linking everything).
  - **Explain:** each step carries a one-line caption of what just happened architecturally
    ("the hold reserved funds — see the account stream", "same key → stored response replayed").
  - The API key is entered on the page (prefilled with the local-dev default, stored in
    localStorage) — the page is a pure API client, no backdoor.
  - Links out to Grafana (dashboard), Prometheus, and the docs page for the full-stack view.
- **API docs page** — `GET /api/docs`: a Redoc shell rendering the live `/api/openapi.json`
  (public, like the spec itself; travel-project convention — `RedocAction`). CDN-loaded JS, no
  build tooling.
- **README**: a "Try it" section — `task up:stack` → open `http://localhost:8080/` → the guided
  story; screenshots optional.

## Capabilities

### New Capabilities
- `showcase`: the playground page (guided actions, synchronized API/read-model/event-log panels,
  NL query box, architectural captions) and the API docs page.

### Modified Capabilities
- `api`: adds the read-only per-stream event endpoints (`/api/accounts/{id}/events`,
  `/api/transfers/{id}/events`) exposing the recorded events with their metadata.

## Impact

- **New code:** `App\Api\Accounts\Events\Action` + `App\Api\Transfers\Events\Action` (+ shared
  response mapping over `EventStore::load`); `App\Api\Documentation\RedocAction`;
  `App\Showcase\PlaygroundAction` (`GET /`, public route outside `/api`) serving the page;
  `public/playground.html` (or inline template) — one static file, vanilla JS.
- **No new dependencies.** No schema changes. No writes outside the existing API.
- **Security posture unchanged:** the events endpoints require the API key like everything else;
  the playground is a client of the public API and holds no privileged access; demo data is
  synthetic by construction.
- **Non-goals:** hosting it anywhere (deployment target still doesn't exist); admin capabilities;
  websockets/live push (the page polls after actions); production-grade frontend tooling.
- **Depends on** the api, projections, transfers, nl-query capabilities. OpenAPI picks the new
  endpoints up automatically; the docs page renders them.
