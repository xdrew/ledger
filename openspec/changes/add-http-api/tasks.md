> Depends on accounts, ledger, transfers, idempotency, projections. RoadRunner serving and the
> production image are deferred to `add-deployment`. New deps: thesis/message-bus,
> symfony/validator, PSR-7 bridge; dev: league/openapi-psr7-validator.

## 1. HTTP stack & message bus

- [ ] 1.1 Enable the Symfony HTTP stack: `public/index.php` front controller, router/framework HTTP config; add a dev `http` serve command to docker compose (PHP built-in server).
- [ ] 1.2 Add `thesis/message-bus`; wire a command bus and a query bus with correlation-id middleware; bind handlers.
- [ ] 1.3 Add `symfony/validator`, `nyholm/psr7`, `symfony/psr-http-message-bridge`.

## 2. Commands, queries & handlers

- [ ] 2.1 Commands + handlers: `OpenAccount`, `DepositFunds` (accounts), `InitiateTransfer` (delegates to `TransferOrchestrator`).
- [ ] 2.2 Queries + handlers: `GetAccountBalance`, `GetAccountStatement` (projection views), `GetTransfer` (transfer repository).

## 3. Cross-cutting listeners

- [ ] 3.1 API-key auth request listener (`401` problem+json on missing/invalid key).
- [ ] 3.2 Idempotency listener over mutating routes: `begin` (hash the request), replay Completed, `409` InProgress, `422` Mismatch; capture the response and `complete`.
- [ ] 3.3 RFC 9457 exception listener mapping throwables to problem+json (`422`/`401`/`404`/`409`/`500`).
- [ ] 3.4 Request DTOs + `symfony/validator` validation (`422` with field details).

## 4. Controllers & endpoints

- [ ] 4.1 `POST /accounts`, `GET /accounts/{id}`, `POST /accounts/{id}/deposits`.
- [ ] 4.2 `POST /transfers`, `GET /transfers/{id}`.
- [ ] 4.3 `GET /accounts/{id}/statement`.
- [ ] 4.4 Response presenters (JSON shapes for account, transfer, statement, problem+json).

## 5. OpenAPI & contract

- [ ] 5.1 Author `openapi.yaml` (OpenAPI 3.1) covering all endpoints, schemas, and error shapes; serve it (`GET /openapi.json`).
- [ ] 5.2 Contract test (`WebTestCase`): exercise each endpoint and validate responses against the document with `league/openapi-psr7-validator`; catch up projections before asserting reads.

## 6. Tests

- [ ] 6.1 Functional tests per endpoint: open account, deposit, transfer (completed + failed), reads (incl. `404`), statement.
- [ ] 6.2 Auth tests (missing/invalid key → `401`).
- [ ] 6.3 Idempotency tests (replay; `409` in-flight; `422` payload mismatch).
- [ ] 6.4 Validation + problem+json tests (`422` shapes, not-found `404`).

## 7. Verification & gate

- [ ] 7.1 Confirm the "done" criteria: OpenAPI validates; contract test asserts responses match the schema.
- [ ] 7.2 Green: php-cs-fixer (phpyh), PHPStan max, unit + integration/functional suites; `openspec validate add-http-api --strict` passes.
