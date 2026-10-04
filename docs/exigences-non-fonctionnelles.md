# Non-functional requirements

This document is intentionally kept in English, like the rest of the technical documentation.

## 1. Purpose

This document defines the measurable non-functional requirements that complement the functional specifications.

## 2. Hosting and capacity

ScoutMagic targets a small-to-medium scouting unit and must remain deployable on conventional shared hosting.

## 3. Reliability and operations

Operational requirements are documented alongside the maintenance and quality documentation.

## 4. Data retention

Retention rules must remain explicit and auditable; no data is retained merely because nobody connected it to retention.

## 5. Technical baseline

| Item | Requirement |
|---|---|
| PHP | **>= 8.4** (`CONTRIBUTING.md` § Development setup) |
| MySQL | **>= 8.0**; the reference production installation is **MariaDB 10.11** and both engines are supported (`AGENTS.md` § Database) |
| PHP extensions | `openssl`, `pdo_mysql`, `zip` (with libzip crypto support for encrypted archives — `BackupService::supportsZipEncryption()` degrades cleanly without it), `mbstring`, `sodium` when available |
| Server-side runtime | No shell, no `mysqldump` binary, no Composer, no Node on the hosting server |
| Cron | `php public/cron.php` every minute (`docs/help/installation-serveur.md`) |

### Supported browsers

The last two major versions of Chrome, Firefox, Edge and Safari, desktop
and mobile, plus the installed PWA on Android and iOS. That is what
`tsconfig.json`'s `ES2020` target already assumes; production JavaScript
is shipped unbundled and untranspiled (`AGENTS.md` § CSS / frontend), so
the language level in the source *is* the browser requirement.

### Accessibility

**WCAG 2.2 level AA** is the target for every page an end user reaches.
Two consequences already written down elsewhere, restated here only
because they are the ones with a number: touch targets meet the 24×24 CSS
pixel minimum AA requires, with 44 px as a comfort goal for small controls
(`design.md` §7.2), and every interactive control has an accessible name —
which is what the end-to-end suite asserts by reaching elements through
`getByRole`/`getByLabel` (see the preserved detailed README reference in
`docs/readme-reference.md` § Tests de bout en bout).

Nothing measures this automatically yet. Adding `axe-core` to the existing
Playwright suite is the natural next step and is deliberately not in this
document's scope.
