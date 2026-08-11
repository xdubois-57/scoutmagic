# Contributing

Thank you for considering contributing to this project.

## Before you start

1. Read [ARCHITECTURE.md](ARCHITECTURE.md) in full — every contribution must conform to it.
2. Read [SECURITY.md](SECURITY.md) — apply the security checklist to every PR.
3. Read [AGENTS.md](AGENTS.md) — these rules apply to all contributors, human or AI.

## Key rules

- All code, comments, variable names, and file names must be in **English**.
- All user-facing text (templates, labels, messages) must be in **French**.
- Automated tests are **mandatory** for every feature.
- Follow the layered MVC pattern: Controller → Service → Repository.
- No SQL in Controllers, no business logic in Controllers or Views.

## Submitting a pull request

1. Create a feature branch from `main`.
2. Write your code and tests.
3. Ensure all tests pass: `vendor/bin/phpunit`
4. Ensure static analysis passes: `vendor/bin/phpstan analyse` (covers `core/`, `modules/`, and `public/index.php`/`public/cron.php` — the composition roots where controllers are wired up are in scope specifically because a wiring bug there only ever surfaces at runtime, never in an IDE or a unit test)
5. Open a PR against `main` and fill in the PR template checklist.

## Security issues

Report security vulnerabilities privately — not via public issues. Contact the maintainer directly.

## Development setup

```bash
composer install
cp config/app.php.dist config/app.php
composer serve
```

(`composer serve` runs `php -S` with raised upload limits — see README.md. If your IDE runs its own built-in PHP server instead, add `-d upload_max_filesize=100M -d post_max_size=110M` to its PHP interpreter's CLI options, or uploads over 8M will 413.)
