> Depends on `add-message-bus` (command/query dispatch) and accounts, transfers, idempotency,
> projections. New deps: RoadRunner (spiral/roadrunner-http + Symfony RoadRunner runtime,
> nyholm/psr7, symfony/psr-http-message-bridge), symfony/validator, nelmio/api-doc-bundle; dev:
> league/openapi-psr7-validator. The production image and Helm are deferred to add-deployment.

## 1. HTTP stack & RoadRunner

- [ ] 1.1 Enable the Symfony HTTP stack: `public/index.php`, router/framework HTTP config; add the PSR-7 bridge (`nyholm/psr7`, `symfony/psr-http-message-bridge`).
- [ ] 1.2 Add RoadRunner: `spiral/roadrunner-http` + a Symfony RoadRunner runtime; `.rr.yaml` (HTTP plugin); the `rr` binary in the dev image; a compose `http` service running `rr serve`.

## 2. Cross-cutting listeners

- [ ] 2.1 API-key auth request listener (`401` problem+json on missing/invalid key).
- [ ] 2.2 Idempotency listener over mutating routes: `begin` (hash request), replay Completed, `409` InProgress, `422` Mismatch; capture the response and `complete`.
- [ ] 2.3 RFC 9457 exception listener mapping throwables to problem+json (`422`/`401`/`404`/`409`/`500`).
- [ ] 2.4 Request DTOs + `symfony/validator` validation (`422` with field details).

## 3. Controllers & endpoints

- [ ] 3.1 `POST /accounts`, `GET /accounts/{id}`, `POST /accounts/{id}/deposits` (dispatch `OpenAccount`/`DepositFunds`/`GetAccountBalance`).
- [ ] 3.2 `POST /transfers`, `GET /transfers/{id}` (dispatch `InitiateTransfer`/`GetTransfer`).
- [ ] 3.3 `GET /accounts/{id}/statement` (dispatch `GetAccountStatement`).
- [ ] 3.4 Response presenters (JSON shapes for account, transfer, statement, problem+json).

## 4. OpenAPI & contract

- [ ] 4.1 Add `nelmio/api-doc-bundle`; annotate controllers with OpenAPI 3.1 attributes (request/response schemas, error shapes); serve the generated document as JSON.
- [ ] 4.2 Contract test (`WebTestCase`): exercise each endpoint and validate responses against the generated document with `league/openapi-psr7-validator`; catch up projections before asserting reads.

## 5. Tests

- [ ] 5.1 Functional tests per endpoint: open account, deposit, transfer (completed + failed), reads (incl. `404`), statement.
- [ ] 5.2 Auth tests (missing/invalid key → `401`).
- [ ] 5.3 Idempotency tests (replay; `409` in-flight; `422` payload mismatch).
- [ ] 5.4 Validation + problem+json tests (`422` shapes, `404`).
- [ ] 5.5 A RoadRunner smoke run (server boots and serves a request).

## 6. Verification & gate

- [ ] 6.1 Confirm the "done" criteria: OpenAPI validates; contract test asserts responses match the schema.
- [ ] 6.2 Green: php-cs-fixer (phpyh), PHPStan max, unit + functional suites; `openspec validate add-http-api --strict` passes.
