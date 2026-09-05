# Contributing

Thank you for considering contributing to this project.

## Before you start

1. Read [ARCHITECTURE.md](ARCHITECTURE.md) in full — every contribution must conform to it.
2. Read [SECURITY.md](SECURITY.md) — apply the security checklist to every PR.
3. Read [AGENTS.md](AGENTS.md) — these rules apply to all contributors, human or AI.
4. [docs/quality-pipeline.md](docs/quality-pipeline.md) maps the whole pipeline —
   tests, CI, code review, the release gates, and the GitHub settings none of it
   works without. Read it once; come back to it when a check surprises you.

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
6. If you touched `public/assets/js/`, ensure JavaScript static analysis passes: `npm ci` then `npm run typecheck` — the JavaScript equivalent of PHPStan above (see README.md § Analyse statique JavaScript).
7. If you touched `public/assets/js/` or `tests/js/`, ensure the JavaScript tests pass: `npm ci` then `npm test` (or `npm run test:coverage` — see README.md § Développement).
8. If you touched the application's boot path, routing, or the shared layout (`public/index.php`, `core/Http/`, `core/View/templates/base.html.twig`, `schema/core.sql`, …), run the end-to-end test: `npm run e2e:install` once, then `npm run e2e` — see README.md § Tests de bout en bout. It is the only check that proves the application still starts; CI runs it as a blocking check and `scripts/release.sh` as a release gate either way. If you changed the scout-year transition workflow described on `/admin/scout-year`, update `tests/e2e/specs/scout-year-transition.spec.js` in the same change — that page is the test's specification.
9. If you found a real problem and deliberately decided not to fix it in this change — a review finding you verified but judged out of scope, a limitation you hit while implementing, a decision that is the maintainer's to make — open a GitHub issue for it and link it from the PR. It must say on its first line whether it is a **bug** or an **enhancement** (and carry the matching label), and it must contain everything needed to make the fix without the PR thread: symptom, reproduction, mechanism with the code quoted inline, options, and the test that will pin the fix. See AGENTS.md § A problem you decide not to fix now becomes a GitHub issue. A PR thread is not a backlog. **One exception: a deferred security vulnerability is never a public issue** — report it privately per [SECURITY.md](SECURITY.md) § Reporting a vulnerability, since everything the issue would have to contain is what an exploit needs.
10. Open a PR against `main` and fill in the PR template checklist.
11. CI additionally runs [SonarQube Cloud](https://sonarcloud.io/project/overview?id=xdubois-57_scoutmagic) analysis on the PR, alongside PHPStan/PHPUnit/the JavaScript static analysis/Vitest/the end-to-end browser test/`composer audit`/CodeQL — see README.md § Intégration continue. Its Quality Gate must pass before merge.

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

```bash
composer install
cp config/app.php.dist config/app.php
composer serve

npm ci               # only needed for JS static analysis and the Node-based tests (Vitest, Playwright) — see README.md
npm run e2e:install  # only needed once, before your first `npm run e2e`
```

(`composer serve` runs `php -S` with raised upload limits — see README.md. If your IDE runs its own built-in PHP server instead, add `-d upload_max_filesize=100M -d post_max_size=110M` to its PHP interpreter's CLI options, or uploads over 8M will 413.)
