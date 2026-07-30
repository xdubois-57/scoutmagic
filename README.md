# ScoutMagic

Open-source website for Belgian scout units in the "Les Scouts" federation.

## Features

- Member management from Desk CSV import
- Role-based access control (6 levels)
- Passwordless authentication (magic link, password, passkey)
- Mobile-first responsive design
- Configuration mode for inline content editing
- Modular architecture for extensibility
- Encrypted personal data at rest
- DKIM-signed transactional emails
- Cookie consent management (ePrivacy compliant)
- Automated schema migration
- Task scheduler (cron + poor man's cron)
- Audit journal
- Web Push notifications
- On-demand and automatic encrypted backups, one-click update from GitHub releases, reset/restore
- Optional modules: financial management (bank statement import, receipts, receivables), news/event articles with registration forms and payment, activity calendar with ICS feeds, photo/video gallery (local or S3 storage), post-activity retrospective boards, staff trombinoscope, mass email, homepage banners, member statistics, on-call phone forwarding for the unit's emergency number
- Optional AI integration (receipt data extraction, RGPD text generation, retrospective moderation, article summaries)

## Requirements

- PHP >= 8.4
- MySQL >= 8.0
- Composer (for development/build only — not needed on the server)
- FTP access to the hosting server

## Installation

1. Clone the repository.
2. Run `composer install`.
3. Point your web server document root to `public/`.
4. Access the site — the setup wizard will guide you through configuration.

## Development

```bash
composer install
composer serve                     # local dev server (localhost:8000)
vendor/bin/phpunit                 # run tests
vendor/bin/phpstan analyse core/   # static analysis
```

`composer serve` wraps `php -S` with raised `upload_max_filesize`/`post_max_size` (`public/.user.ini`, used in production, isn't honored by the built-in server) — if you run `php -S` directly instead, uploads over 8M will 413. Raise the values in `composer.json`'s `scripts.serve` if you need to test bigger uploads locally (e.g. gallery videos).

## Deployment

```bash
# Set environment variables: FTP_HOST, FTP_USER, FTP_PASS, FTP_REMOTE_DIR
./scripts/deploy.sh               # differential FTP deploy
./scripts/release.sh              # create a new release (patch by default)
./scripts/release.sh --minor      # minor version bump
./scripts/release.sh --major      # major version bump
```

## Architecture

See [ARCHITECTURE.md](ARCHITECTURE.md) for the full architectural reference.

## Security

See [SECURITY.md](SECURITY.md) for security requirements.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for contribution guidelines.

## Module development

See [docs/module-development.md](docs/module-development.md) for how to create modules.

## License

[AGPL-3.0](LICENSE)

This project is made available for scout units and the community, with the expectation
that all usage remains open source.
