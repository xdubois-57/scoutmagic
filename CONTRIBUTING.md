# Contributing

Thank you for considering contributing to this project.

## Before you start

1. Read [ARCHITECTURE.md](ARCHITECTURE.md) in full — every contribution must conform to it.
2. Read [SECURITY.md](SECURITY.md) — apply the security checklist to every PR.
3. Read [AGENTS.md](AGENTS.md) — these rules apply to all contributors, human or AI.
4. [docs/quality-pipeline.md](docs/quality-pipeline.md) maps the whole pipeline —
   tests, CI, code review, the release gates, and the GitHub settings none of it
   works without. Read it once; come back to it when a check surprises you.
5. [docs/exigences-non-fonctionnelles.md](docs/exigences-non-fonctionnelles.md)
   holds the numbers: sizing target, rendering budget, recovery objectives,
   operational alert thresholds, technical baseline. It is what turns "is that
   fast enough?" and "is that too full?" into questions a review can settle.

## Key rules

- All code, comments, variable names, and file names must be in **English**.
- All user-facing text (templates, labels, messages) must be in **French**.
- Automated tests are **mandatory** for every feature.
- Follow the layered MVC pattern: Controller → Service → Repository.
- No SQL in Controllers, no business logic in Controllers or Views.

## Submitting a pull request

1. Create a feature branch from `main`.
2. Write your code and tests. If your change touches `public/assets/js/` behavior that is deterministic and reasonably decoupled from the DOM it ships with, add/update a Vitest spec in `tests/js/` — see AGENTS.md § Tests for when this does (and doesn't) apply.
3. If your change adds or reworks a page an end user sees, ship its help topic in the same change — a `{id}.md` under the module's `help/` directory, or `docs/help/` for a core page. Write it to the editorial charter in `design.md` §7.11 (vouvoiement, the §7.1 lexicon, ~400 words, at most one `> ` callout) and declare the page in the topic's `paths`; `tests/Core/Help/` fails otherwise. See `docs/module-development.md` § Help topics.
4. Ensure all PHP tests pass: `vendor/bin/phpunit`
5. Ensure static analysis passes: `vendor/bin/phpstan analyse` (covers `core/`, `modules/`, and `public/index.php`/`public/cron.php` — the composition roots where controllers are wired up are in scope specifically because a wiring bug there only ever surfaces at runtime, never in an IDE or a unit test)
6. If you touched `public/assets/js/`, ensure JavaScript static analysis passes: `npm ci` then `npm run typecheck` — the JavaScript equivalent of PHPStan above (see [docs/quality-pipeline.md](docs/quality-pipeline.md) § Static analysis).
7. If you touched `public/assets/js/` or `tests/js/`, ensure the JavaScript tests pass: `npm ci` then `npm test` (or `npm run test:coverage` — see [docs/quality-pipeline.md](docs/quality-pipeline.md) § JavaScript — Vitest).
8. If you touched the application's boot path, routing, or the shared layout (`public/index.php`, `core/Http/`, `core/View/templates/base.html.twig`, `schema/core.sql`, …), run the end-to-end test: `npm run e2e:install` once, then `npm run e2e` — see [docs/quality-pipeline.md](docs/quality-pipeline.md) § End-to-end — Playwright. It is the only check that proves the application still starts; CI runs it as a blocking check and the release workflow runs the full tier on every tag. If you changed the scout-year transition workflow described on `/admin/scout-year`, update `tests/e2e/specs/scout-year-transition.spec.js` in the same change — that page is the test's specification.
9. If you found a real problem and deliberately decided not to fix it in this change — a review finding you verified but judged out of scope, a limitation you hit while implementing, a decision that is the maintainer's to make — open a GitHub issue for it and link it from the PR. It must say on its first line whether it is a **bug** or an **enhancement** (and, for a defect, carry `bug:confirmed`; an enhancement carries no `bug:*` label, and neither carries the older `bug` label, which the maintainer applies by hand, nor any `triage:*` label, which `issue-triage.yml` sets when it runs on the issue you just opened), and it must contain everything needed to make the fix without the PR thread: symptom, reproduction, mechanism with the code quoted inline, options, and the test that will pin the fix. See AGENTS.md § A problem you decide not to fix now becomes a GitHub issue. A PR thread is not a backlog. **One exception: a deferred security vulnerability — or a finding whose security status you are not sure of — is never a public issue** — report it privately per [SECURITY.md](SECURITY.md) § Reporting a vulnerability, since everything the issue would have to contain is what an exploit needs.
10. Open a PR against `main` and fill in the PR template checklist.
11. CI additionally runs [SonarQube Cloud](https://sonarcloud.io/project/overview?id=xdubois-57_scoutmagic) analysis on the PR, alongside PHPStan/PHPUnit/the JavaScript static analysis/Vitest/the end-to-end browser test/`composer audit`/CodeQL — see [docs/quality-pipeline.md](docs/quality-pipeline.md) § Continuous integration. Its Quality Gate must pass before merge.

## License and attribution

This project is licensed under AGPL-3.0-or-later (see [LICENSE](LICENSE)). By submitting a
contribution, you agree that it is licensed under the same terms.

Contributors are added to [NOTICE](NOTICE) as their contributions are merged. LICENSE also
carries an additional permission under AGPL §7: a modified version of this project may not be
distributed, or offered as a service, under the name "ScoutMagic" (or a confusingly similar
name) without the copyright holder's prior written permission. This does not restrict
contributing to or running this project — only publishing a modified fork under its name.

## Security issues

Report security vulnerabilities privately — not via public issues. Contact the maintainer directly.

## Development setup

Development requires PHP >= 8.4. The JavaScript tooling requires Node.js >= 22 and npm; neither
Node nor npm is required on the hosting server.

```bash
composer install
composer dev-config  # config/app.php for plain http://localhost (https_required => false)
composer serve

npm ci               # only needed for JS static analysis and the Node-based tests (Vitest, Playwright)
npm run e2e:install  # only needed once, before your first `npm run e2e`
```

`composer serve` runs `php -S` with raised upload limits because the built-in server ignores
`public/.user.ini`. If your IDE runs its own built-in PHP server instead, add
`-d upload_max_filesize=100M -d post_max_size=110M` to its PHP interpreter's CLI options,
or uploads over 8M will return 413.

### Database-backed PHP tests

`vendor/bin/phpunit` runs the whole configured suite. Most tests labelled `database` use the
in-memory SQLite helper, but the tests that exercise the production database engine read
`TEST_DB_HOST`, `TEST_DB_PORT`, `TEST_DB_NAME`, `TEST_DB_USER` and `TEST_DB_PASSWORD`.
When those variables promise a server, a refused connection is a failed run rather than a skip.
`TEST_DB_PASSWORD` must not be empty because the setup-controller tests replay the real
installation form, where the database password is required.

### End-to-end and dynamic tests

`npm run e2e` provisions a disposable ScoutMagic installation and database, applies the real
schema, activates every shipped module, serves the real `public/index.php`, drives headless
Chromium, and removes the temporary installation afterwards. It never reads or modifies a local
ScoutMagic installation. Mail scenarios use the application's real mail stack; only the last
transport hop is redirected to `scripts/e2e-maildrop.php`.

The harness first uses `E2E_DB_*`, then `TEST_DB_*`, then the usual local MySQL defaults. If no
server is reachable and Docker is available, it starts a disposable MySQL 8 container. An
environment that already provides a compatible Chromium but cannot use Playwright's managed
download can set `E2E_CHROMIUM_EXECUTABLE=/path/to/chromium`. On failure, Playwright diagnostics
live under `tests/e2e/test-results/` and `tests/e2e/playwright-report/`.

For the dynamic scan, pull the ZAP image once and then run the profile you need:

```bash
docker pull ghcr.io/zaproxy/zaproxy:stable
./scripts/dast.sh --profile=passive
```

The `deep` and `audit` profiles are active scans: they send attack payloads while authenticated
against the disposable installation. Read `tests/dast/zap-active.yaml` before adding a route that
resets, restores, imports, reconfigures, changes credentials or roles, or contacts an external
service. The exclusions in that file are what keep the scan from destroying its own state or
reaching real third parties.

For what each layer proves, the two E2E tiers, CI behaviour and the four DAST profiles, see
[docs/quality-pipeline.md](docs/quality-pipeline.md). For the architecture of the E2E/DAST harness,
including module activation, TLS termination, proxy coverage and the maildrop, see
[ARCHITECTURE.md](ARCHITECTURE.md) §15 and [SECURITY.md](SECURITY.md) for the security model.
