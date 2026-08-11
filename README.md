# ScoutMagic

Open-source website for Belgian scout units in the "Les Scouts" federation.

## Auteur

ScoutMagic est développé et maintenu par Xavier Dubois.

Voir [NOTICE](NOTICE) pour la liste des contributeurs et
[LICENSE](LICENSE) pour les conditions de licence, y compris les conditions
additionnelles relatives à l'usage du nom « ScoutMagic ».

## Avertissement

ScoutMagic est un logiciel libre fourni sans garantie d'aucune sorte,
conformément à la licence AGPL-3.0 (voir LICENSE, sections 15-16).

Toute unité qui déploie ScoutMagic agit en tant que responsable de
traitement au sens du RGPD pour les données qu'elle y héberge : c'est à
elle qu'incombent l'évaluation de la conformité RGPD, la sécurisation de
son hébergement, la tenue du registre de traitement et la notification en
cas de violation de données. L'auteur et les contributeurs du projet ne
sont ni responsables de traitement ni sous-traitants pour les instances
déployées par des tiers, et n'ont aucun accès aux données qui y sont
hébergées.

Une faille de sécurité découverte dans le code doit être signalée via
[SECURITY.md](SECURITY.md). Les corrections sont apportées sur une base
volontaire, sans garantie de délai.

## Features

- Member management from Desk CSV import
- Role-based access control (6 levels)
- Passwordless authentication (magic link, password, passkey)
- Mobile-first responsive design, installable as a home-screen app (PWA) with offline access to the calendar, notification centre, trombinoscope (with pre-downloaded staff photos), and public pages
- Configuration mode for inline content editing
- Modular architecture for extensibility
- Encrypted personal data at rest
- DKIM-signed transactional emails
- Cookie consent management (banner and preferences aligned with ePrivacy requirements)
- Automated schema migration
- Task scheduler (cron + poor man's cron)
- Audit journal
- Notification centre with Web Push, per-type preferences (in-app/push/email), quiet hours, and a discretion mode
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

### Cutting a release (maintainers)

```bash
./scripts/release.sh              # create a new release (patch by default)
./scripts/release.sh --minor      # minor version bump
./scripts/release.sh --major      # major version bump
```

Publishes a GitHub release with the install artifact and `bootstrap.php` as assets. Requires the GitHub CLI (`gh`).

The script runs a **security gate first**: it refuses to create any commit, tag, or release while any CodeQL scanning finding or Dependabot alert is open in the repository (`gh api repos/{owner}/{repo}/code-scanning/alerts` and `.../dependabot/alerts`, filtered on `state == "open"`). Fix (or justify-dismiss) them before releasing — see `AGENTS.md` § Releases.

### Installation sur hébergement mutualisé (unit administrators)

No SSH, Git, or Composer needed on the server — only FTP, and only once.

1. Download `bootstrap.php` from the [latest release](https://github.com/xdubois-57/scoutmagic/releases/latest).
2. Upload it via FTP to the empty web folder your host serves as the document root.
3. Open it in a browser (e.g. `https://votre-domaine.be/bootstrap.php`). It downloads the latest release, installs it, and runs a full security check before showing you anything else — the confirmation screen explains which of the two installation layouts it picked for your host and why.
4. Click **Installer**. It reports progress step by step, then a pass/fail table for every security check it ran (including checks your own browser performed by fetching URLs directly). Any failure rolls back cleanly and explains what to fix — nothing is left half-installed.
5. Once every check passes, it writes a `token.php` file next to itself (or tells you to create it manually over FTP if it couldn't), deletes itself, and redirects you to the setup wizard.
6. The setup wizard asks for the value in `token.php` before showing anything else — copy it from the file over FTP if you didn't note it down. It's deleted automatically once you finish the wizard.
7. Complete the wizard: database credentials, unit settings, email configuration, and your admin account.

**Enabling automatic updates**: once installed, go to *Configuration > Maintenance* and generate a GitHub webhook secret. In your GitHub repository's *Settings > Webhooks*, add a webhook with:
- **Payload URL**: `https://votre-domaine.be/api/webhook/github`
- **Content type**: `application/json`
- **Secret**: the value generated on the Maintenance page
- **Events**: select only *Releases*

Without this, the site never learns a new release exists — see ARCHITECTURE.md §8.17 for how update installation itself works once notified.

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
