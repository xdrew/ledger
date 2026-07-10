---
name: update-packages
description: Update Composer dependencies (root + isolated vendor-bin tools), refresh Symfony config, bump version constraints, and run the full quality gate before opening a PR. Use when the user wants to update packages, check `composer outdated`, upgrade dependencies, or bump a specific package.
metadata:
  author: ledger
  version: "1.0"
---

# Update packages

Bring dependencies up to date safely: survey what's outdated, update in
reviewable slices, bump constraints, auto-migrate deprecations, and prove the
whole gate green **before** anything reaches `main`.

## Ground rules for this repo (read first)

- **Two dependency sets.** Runtime + framework deps live in the root
  `composer.json`. Dev tooling (phpstan, phpunit, infection, php-cs-fixer via
  `phpyh/coding-standard`, rector) is **isolated** in `vendor-bin/tools/composer.json`
  via `bamarni/composer-bin-plugin`. Update each set on its own.
- **No Symfony Flex.** There are no Flex-managed recipes — `composer recipes:update`
  does **not** apply here. Config under `config/` is hand-maintained. When a
  Symfony component takes a major bump, read its `UPGRADE-*.md` and apply config
  changes by hand (see step 4).
- **Everything runs in Docker.** PHP is not on the host. Run composer and the
  test suites inside the stack: `docker compose exec -T app <cmd>` (or the
  `task` targets, which wrap it). Integration/functional tests need Postgres —
  bring the stack up first.
- **Push to `main` auto-deploys to production.** The CI pipeline ends in a
  `deploy` job that ships to the showcase VM (`ledger.avelent.work`). Therefore
  this skill works on a **branch + PR**, never commits straight to `main`, and
  never deploys without explicit human sign-off.
- **No silent skips.** If a package is held back by another dependency, say so
  explicitly with the blocking constraint — don't quietly leave it behind.

## Procedure

### 1. Preconditions
- Working tree clean (`git status`). If not, stop and ask.
- Create a branch: `git switch -c chore/update-deps-$(date +%Y%m%d)`.
- Bring the stack up so tests can run: `task up` (Postgres + app), then `task tools`
  to ensure the isolated tools are installed.

### 2. Survey what's outdated
- Root, direct deps only: `docker compose exec -T app composer outdated --direct`
- Isolated tools: `docker compose exec -T app composer bin tools outdated --direct`
- Sort each package into **patch**, **minor**, or **major**. Patches/minors are
  low-risk batch updates; majors are handled one at a time.

### 3. Update
Do the low-risk batch first, verify (step 5), then take majors individually so a
failure is easy to bisect.

- **Patch/minor (root):**
  `docker compose exec -T app composer update --with-all-dependencies`
  (constraints in `composer.json` cap this to non-breaking versions).
- **Patch/minor (tools):**
  `docker compose exec -T app composer bin tools update`
- **Majors:** edit the constraint in the relevant `composer.json`
  (root or `vendor-bin/tools/composer.json`) for **one** package, then
  `composer update <vendor/pkg> --with-all-dependencies`. This is the "bump".
  Verify before moving to the next major.

### 4. Refresh config (no Flex — do it by hand)
- For any Symfony component that jumped a major, open its `UPGRADE-<ver>.md`
  upstream and apply the relevant changes to `config/`, `src/`, and
  `docker/` yourself. Common touch points here: `config/packages/*`,
  `config/bundles.php`, security config.
- Doctrine/ORM/DBAL bumps: re-check `config/packages/doctrine.yaml` and run
  `docker compose exec -T app php bin/console doctrine:schema:validate`.
- **Do not** run `composer recipes:update` — Flex isn't installed; it will fail
  or do nothing.

### 5. Auto-migrate deprecations, then verify — the FULL gate
Unit tests alone are **not** enough (that's how a green-looking change still
broke `main` once). Run every gate CI runs, in this order, and get each green:

1. `docker compose exec -T app vendor/bin/rector process`   — auto-apply supported upgrades
2. `task lint:fix`                                           — php-cs-fixer, then confirm `task lint` is clean
3. `task analyse`                                            — PHPStan max, must be **No errors**
4. `task test:unit`
5. `task test:integration`                                  — needs the stack up
6. `task test:functional`                                   — needs the stack up
7. `task infection`                                          — mutation floor (min MSI 95)
8. `docker compose exec -T app composer validate --strict`
9. If `deploy/helm/**` changed: `helm lint deploy/helm/ledger-core && helm template smoke deploy/helm/ledger-core >/dev/null`

**Gotcha — updating dev tools can poison PHPStan.** `phpstan.dist.neon` uses
`scanDirectories` to discover PHPUnit's `TestCase` from `vendor-bin/tools/vendor`.
Some tools (rector especially) ship **stub** classes (e.g. a stripped
`PHPUnit\Framework\TestCase` under `stubs-rector/`) and unscoped bundled vendor
trees. If PHPStan suddenly reports a flood of "undefined method assertSame()"
errors across `tests/`, a scanned tool is shadowing the real PHPUnit symbols —
keep the scan narrowed to `vendor-bin/tools/vendor/phpunit`, not the whole tree.

### 6. Commit, PR — stop before deploy
- Commit with a clear message; end with the repo's `Co-Authored-By` trailer.
  Group logically: dependency bump(s) separate from any code migration if large.
- `git push -u origin <branch>` and open a PR (`gh pr create`). Let CI run the
  full pipeline.
- **Report back and wait for human approval** before merging to `main` — merge
  triggers the auto-deploy to production. Do not merge or deploy autonomously.

### 7. Report
Summarize: what updated (old → new per package), any majors and the config
changes they required, anything held back and why, and the gate results. Link
the PR and its CI run.
