# ledger-core — operational runbook

Plays for the situations the alerts page you about. Commands are given for compose
(`docker compose exec api|worker php bin/console …`) — under Kubernetes substitute
`kubectl exec deploy/<release>-ledger-core-api -- php bin/console …`.

Orientation in 30 seconds: `GET /readyz` (db + relay heartbeat), `projections:status`
(checkpoint/head/lag), Grafana **Ledger Core** dashboard (golden signals + business metrics).

## Play: rebuild a projection

**When:** a projector bug shipped (read models wrong but events correct); a read model was
truncated/corrupted; a new projection needs history. Read models are disposable by design
(ADR-003) — rebuilding is routine, not surgery.

```sh
docker compose exec worker php bin/console projections:status    # note head + lag
docker compose exec worker php bin/console projections:rebuild   # truncate + replay from 0
docker compose exec worker php bin/console projections:status    # lag returns to ~0
```

- The rebuild truncates `account_balances` + `account_statement`, resets the projection
  checkpoint, and replays the whole event log in one command; it is safe to run while the API
  serves traffic — reads are stale (not wrong) during the replay, and `GET /accounts/{id}`
  responses carry `version` so consumers can see the projection point.
- **Expect** `projection_lag_seconds` to spike for the duration of the replay and
  `ProjectionLagHigh` to fire if it exceeds 5m — silence it for a planned rebuild.
- **Verify:** lag back to ~0; spot-check a busy account's balance against its statement; the
  rebuild-equals-live invariant is also covered by the projections test suite.

## Play: drain a stuck transfer saga

**When:** a transfer sits in a non-terminal status (`initiated`, `held`, `posted`) — e.g. the
process died mid-saga. Funds are never lost in these states: a `held` transfer has money parked in
the source account's `reserved` bucket; nothing has moved.

1. **Diagnose.** Read the saga's own event stream — the transfer aggregate records every step:
   ```sql
   SELECT version, event_type, payload, occurred_at FROM events
   WHERE stream_type = 'transfer' AND stream_id = '<transfer-id>' ORDER BY version;
   ```
   The last event tells you exactly which step didn't run (ADR-005: hold → post → settle).
2. **Drain.** There is deliberately no blind "resume" command; resolution is explicit:
   - Stuck in `initiated` (no hold taken): nothing to compensate — mark it failed in the books by
     initiating nothing further; the client retries with a new transfer.
   - Stuck in `held` (hold taken, no journal entry): release the hold and fail the transfer —
     replay the compensation the orchestrator would have run. Verify the source account's
     `reserved` returns to 0 for that amount in `GET /accounts/{id}`.
   - Stuck in `posted` (journal entry exists, settlement incomplete): **complete forward**, never
     compensate — the double-entry record exists, so settle the balances (debit source / credit
     destination) to match it; the account streams show which settlement leg is missing.
3. **Verify.** The transfer's stream ends in `transfer_completed`/`transfer_failed`; the trial
   balance holds (`SUM(debits) = SUM(credits)` over journal legs); `holds_active` gauge drops.

> Residual holds from failed best-effort compensation surface in the `holds_active` metric and
> reconcile against transfer streams in `held` state — investigate any hold with no live saga.

## Play: outbox backlog

**When:** `OutboxBacklogGrowing` fires, or `outbox_pending` climbs on the dashboard.

1. **Is the relay alive?** `GET /readyz` reports `outbox_relay: stale (…)`/`no heartbeat` if the
   relay loop hasn't beaten within `READINESS_RELAY_MAX_AGE` (30s). Dead relay → restart the
   worker (`docker compose restart worker` / let Kubernetes do it — worker liveness will also
   recycle it). The relay is checkpointed: it resumes exactly where it stopped, re-publishing at
   most one in-flight event (at-least-once; consumers dedupe by event id — ADR-002). **Restarting
   the relay is always safe.**
2. **Alive but slow?** Backlog with a fresh heartbeat means the transport or a consumer is slow.
   Check relay position vs head:
   ```sql
   SELECT name, position, updated_at FROM projection_checkpoints;
   SELECT MAX(global_position) FROM events;
   ```
   A healthy relay drains in bursts; sustained growth at steady input means throughput ceiling —
   see the 100x analysis (partition the relay) before it becomes chronic.
3. **Never** advance or reset `projection_checkpoints` rows by hand to "skip" events — skipping
   breaks at-least-once and consumers will permanently miss facts. The safe interventions are:
   restart, scale, or fix the transport.

## Alert index

Every rule in `deploy/observability/alerts.yaml`:

| Alert | Meaning | First response |
| --- | --- | --- |
| `ProjectionLagHigh` (`projection_lag_seconds > 30` for 5m) | Read models are falling behind the log; reads increasingly stale (writes unaffected) | Is the worker up? `GET /readyz`, worker logs. Planned rebuild in progress → silence. Chronic at steady load → scale/partition projections (design doc §100x) |
| `OutboxBacklogGrowing` (`outbox_pending > 100` and rising for 10m) | Events awaiting publication accumulate; downstream consumers behind | Play: **outbox backlog** above |
| `RequestLatencyP99SloBurn` (p99 > 500ms for 5m) | API latency SLO burning (see `docs/slo.md`) | Check saturation: RR workers busy (`rr_http_workers_ready` ≈ 0 → scale api), PostgreSQL latency, then recent deploys |
| `NotReady` (`up == 0` for 2m) | Prometheus cannot scrape a target — pod down or metrics port broken | `kubectl get pods` / `docker compose ps`; check the pod's `/healthz`; a crash-looping worker also trips relay staleness in `/readyz` |

**Degradation model:** every alert above except a full API outage is *degraded, not down* — the
write side keeps recording money movements even with the relay and every projection stopped; the
log is append-only, so nothing downstream can corrupt it. Prioritize accordingly.
