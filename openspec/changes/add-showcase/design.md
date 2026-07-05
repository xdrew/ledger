## Context

Everything in the system already *demonstrates* itself to machines (tests, metrics, spans); this
change gives it a face for humans. The constraint that shapes the design: the showcase must not
compromise the architecture it showcases — the page is a plain client of the public API, the new
endpoints are read-only projections of the log, and the whole thing stays true to the project's
no-magic ethos (no framework, no build pipeline, one HTML file you can read).

## Goals / Non-Goals

**Goals:** a guided, clickable story (accounts → deposit → transfers → idempotent replay → NL
query) with the API exchange, read models, and event streams visible side by side; per-stream event
endpoints; a human-readable docs page; README "Try it".

**Non-Goals:** hosting/deployment; authentication UX beyond pasting the API key; live push
(polling after actions is enough); a generic event-log browser across all streams (per-aggregate
is the demo's unit); mobile polish; frontend build tooling of any kind.

## Decisions

### D1: The playground is a pure API client
The page holds no privileged access: it calls the same endpoints any integrator would, with an API
key the visitor enters (prefilled with the local-dev default, kept in localStorage). Errors render
as the problem+json the API actually returned — failure modes are part of the demo, not hidden.
The page is served at `GET /` by a tiny public action outside the `/api` firewall (like
`/healthz`), because serving static HTML needs no key even though every API call from it does.

- *Alternatives rejected:* a privileged demo backend/proxy (would demo something that isn't the
  product); embedding the key server-side (hides the auth model the page should demonstrate).

### D2: One self-contained HTML file, vanilla JS
No framework, no bundler, no npm. A single `playground.html` with `fetch`, `<template>` elements,
and inline CSS (styled properly — the frontend-design skill guides the implementation pass; a
showcase page that looks default-Bootstrap undermines the "senior craftsmanship" message). This is
the same transparency argument as the hand-rolled event store: a visitor can read the entire demo
surface in one file. Redoc/Swagger-UI-style CDN `<script>` tags are acceptable (docs page uses
Redoc from CDN); the playground itself uses none.

### D3: Event endpoints are per-aggregate, read-only, key-protected
`GET /api/accounts/{id}/events` and `/api/transfers/{id}/events` map `EventStore::load(StreamId)`
to JSON: `globalPosition`, `version`, `type`, `schemaVersion`, `payload`, `correlationId`,
`causationId`, `occurredAt`. They answer the showcase's central question — "show me what was
*recorded*" — and double as a legitimate integration/debugging surface. Unknown stream → `404`
problem+json. The payload is exposed verbatim: demo data is synthetic, and the events *are* the
product being demonstrated.

- *Alternatives rejected:* a global `/api/events?after=` feed (a firehose is a worse demo unit than
  an aggregate's story, and it invites accidental use as an integration bus — the outbox owns
  that); a separate unauthenticated demo endpoint (two auth regimes for one API).

### D4: The guided story is scripted, the exploration is free
Five numbered story buttons run the canonical arc (open Alice + Bob → deposit → successful transfer
→ insufficient-funds transfer → idempotent replay), each updating the three panels and a one-line
architectural caption. Below that, free-form controls (amounts, the NL query box) let visitors go
off-script. The page polls `projections:status`-equivalent state implicitly by re-fetching
balances/statement after each action — eventual consistency becomes *visible* (the projection
`version` ticks) rather than explained.

### D5: Docs page = Redoc shell over the live spec
`GET /api/docs` (public, `#[OpenApiPublic]`) returns a ~15-line HTML shell loading Redoc from CDN
and pointing it at `/api/openapi.json`. Travel-project convention; zero maintenance because the
spec is generated. Swagger UI's "try it out" is redundant next to the playground, so Redoc's
cleaner reading experience wins.

## Risks / Trade-offs

- **CDN dependency on the docs page** → accepted for a demo backend; the playground itself is
  self-contained, and the OpenAPI JSON remains available raw.
- **Events endpoint exposes payloads** → intended for a showcase; if this ever fronts real data,
  the endpoint is where field-level redaction would live (noted, not built).
- **Long streams** → `load()` returns everything; fine at demo scale, and the response mapping is
  where a `?limit` would go later.
- **Page rot vs API** → functional tests cover the new endpoints; the playground's API calls are
  exercised manually (it is one file calling endpoints that are themselves fully tested).

## Open Questions

- None blocking. Visual direction decided at implementation with the frontend-design pass.
