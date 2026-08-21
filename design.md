# Design

This document covers UI/UX decisions, data model, and technical design choices.

## 1. UI/UX principles

### 1.1 Mobile-first
Primary device is mobile. Base CSS for mobile, `min-width` breakpoints for larger. Bootstrap 5 compiled files. HTML5 input types. Touch targets: see §7.2 — 44px is a comfort goal for small and icon-only controls, not a threshold to apply to every control.

### 1.2 Navigation

**Mobile**: hamburger left, unit name right. Offcanvas from left: user card (initials, display name, role, member count), accordion sub-menus (one open), login/logout at bottom.

**Desktop**: horizontal bar (unit name left, menus center, user right). Sub-menu bar below, wraps to multiple lines.

**Espace des animés sub-menu**: dynamic member entries (totem/prénom + section) → separator → static module pages.

### 1.3 Configuration mode
Banner when active. Text: click → rich text editor. Images: click → upload page (drag-drop, file picker, camera).

### 1.4 SectionPicker
Reusable. Sections with branch subtitle. Horizontal scroll mobile, wraps desktop. Unconfigured sections show badge. Pre-selects highest-role member's section.

### 1.5 Login page
Three-tab segmented control: "Lien magique" (default), "Mot de passe", "Clé numérique".
- Magic link: email → send → waiting spinner → success.
- Password: email + password.
- Passkey: fingerprint icon + button (no email field).

### 1.6 Account page ("Mon compte")
Name/surname. Password section (status + set/change). Passkey section (list + add). Cookie preferences link/section.

### 1.7 Cookie consent banner
Bottom of screen on first visit. Three buttons: "Accepter tout", "Refuser tout", "Personnaliser". Non-intrusive, does not block content. Disappears after choice.

### 1.8 Cookie preferences page
Each category as a card: name, description, toggle (except strictly necessary: always on, explained). List of individual cookies per category with name, purpose, duration. Accessible from banner, RGPD page, Mon compte.

### 1.9 Settings page
Grouped by module. Rows: label, description, value, chevron (editable) or lock (read-only). Click → edit dialog.

### 1.10 Module registry
Cards: icon, name, badge, version, description, toggle.

### 1.11 Staffs page
SectionPicker → section header (name/code, branch badge, count) → section staff group photo (editable in configuration mode, one per scout year with fallback to the most recent earlier year) → staff cards. Section name/email are edited from Configuration > Config Desk, not from this page.

## 2. Data model (conceptual)

### 2.1 Core entities

**members**: persistent identity. Key: `desk_id`.

**scout_years**: `label`, `start_date`, `end_date`.

**member_years**: annual snapshot linked to member + scout year. Personal data encrypted.

**member_addresses**: per member_year. Address fields encrypted.

**member_functions**: per member_year. Links to functions, sections, age_branches. `main_function` flag.

**functions**: `desk_code` → `label`, `role`. Defaults to lowest role.

**age_branches**: `desk_code` → `label`, `sort_order`.

**sections**: `desk_code` → `name` (nullable), `email` (nullable), `age_branch_id`.

**fee_categories**: `desk_code` → `label`.

### 2.2 Authentication entities

**user_accounts**: `email`, `first_name`, `last_name`, `password_hash` (nullable).

**magic_links**: `email`, `token_hash`, `expires_at`, `used`.

**webauthn_credentials**: `user_account_id`, `credential_id`, `public_key`, `sign_count`, `device_label`.

### 2.3 Content entities

**editable_contents**: `key`, `type`, `value`, `module_id`, `modified_at`, `modified_by`.

**files**: `relative_path`, `original_name`, `mime_type`, `role_min`, `custom_resolver`, `encrypted`.

### 2.4 Configuration entities

**settings**: `module_id`, `key`, `value`, `type`, `label`, `description` (NOT NULL), `validation_regex`, `editable`, `sort_order`.

**module_registry**: `module_id`, `enabled`, `installed_version`.

### 2.5 Operational entities

**event_log**: `timestamp`, `member_id`, `category`, `type`, `level`, `description`, `context` (JSON).

**scheduled_actions**: `module_id`, `task_key`, `reference`, `payload` (JSON), `run_at`, `status`, `attempts`, `last_error`.

**import_journal**: `scout_year_id`, `user_account_id`, `line_count`, `new_functions_count`, `imported_at`.

### 2.5.1 Usage-statistics entities

Present on every installation, as `settings` keys rather than tables (ARCHITECTURE.md §8.47): `statistics_enabled`, `statistics_destination`, `statistics_installation_id`, `support_email`, `installed_at`, plus the three send-state keys `statistics_last_success_at`, `statistics_last_failure_at`, `statistics_last_failure_reason`. The authentication secret is **not** among them — it lives only in `secrets.enc`.

Present only on the installation acting as receiver (`modules/support_dashboard`, §8.49):

**support_installations**: `installation_id` (unique), `instance_url`, `secret_hash`, `payload` (JSON, the exact document received), `statistics_schema_version`, `first_seen_at`, `last_received_at`, plus denormalised copies of the payload fields the dashboard filters and sorts on (`scoutmagic_version`, `is_dev_build`, `active_members`, `active_sections`, `installation_method`, `auto_update_enabled`, `auto_update_level`, `scout_year_label`, `installed_at`, `last_upgraded_at`). **Every denormalised column is nullable, and NULL always means "not reported" — never 0 and never false.** One row per installation; each accepted report overwrites the previous one.

**support_report_rate_limits**: `ip_hash`, `created_at`. Same shape and same rule as `human_check_rate_limits` — the raw address is never stored, only its blind index.

**support_monthly_contributions**: `month`, `installation_id`, unique on the pair. A **working** table only: its rows for a month are deleted when that month is finalised.

**support_monthly_aggregates**: `month` (unique), `installation_count`, `finalized_at`. Immutable once written, kept indefinitely, and holding **no individual identifier** — no installation id, no URL (§8.51).

### 2.6 Encryption strategy

| Category | Storage | Search |
|---|---|---|
| Personal identity | AES-256-GCM → BLOB | Decrypt after SELECT |
| Email (needs match) | BLOB + HMAC blind index | WHERE on blind index |
| Section email (organizational) | Clear VARCHAR | Normal WHERE |
| IDs, FKs, flags, timestamps | Clear | Normal WHERE |
| Secrets (DB, SMTP, statistics) | `secrets.enc` file | N/A |
| Reporting installation URL / id | Clear VARCHAR | Normal WHERE |
| Reporting installation's secret | `password_hash()` only | `password_verify()` |

## 3. Deployment

### 3.1 First install: bootstrap.php
FTP is used only once, to upload the standalone `bootstrap/bootstrap.php` to an empty web folder. It downloads the latest GitHub release, installs into whichever of two layouts fits the host (Layout A: writable parent directory, public/'s contents merge into the document root; Layout B: single tree in the document root, protected by one root `.htaccess`), runs a full acceptance gate (server-side checks + browser-fetched probes proving `storage/`, `core/`, `vendor/`, etc. aren't web-reachable), writes `token.php` only once every check passes, then deletes itself and redirects to the token-gated setup wizard. See ARCHITECTURE.md §9 for the full mechanism.

Every subsequent update goes through §8.17/§8.18 (GitHub release polling + webhook), never FTP again.

### 3.2 Database migration
Backup → introspect → compare to `schema.sql` files → generate DDL → execute.

### 3.3 Release
`release.sh`: default patch increment. `--minor`/`--major` flags. Tag, changelog, GitHub Release with zip.

## 4. Email design

### 4.1 Deliverability
DKIM (RSA 2048), SPF aligned, DMARC, Return-Path, multipart, subject prefix, List-Unsubscribe where applicable.

### 4.2 DNS verification
Per record: type, host, expected value (computed), live status via `dns_get_record()`, copyable value. Adapts to SMTP/local mode.

## 5. Scheduler design

Poor man's cron: check every page visit (>1 min since last), process after response. Atomic claim via UPDATE. No auto-retry. Failures journaled and visible in config page.

## 6. Cookie consent design

### 6.1 Architecture
`CookieConsentService` aggregates cookie declarations from `core/Cookie/CookieRegistry.php` and all active modules' `module.json`. Single source of truth used by the banner and the preferences page; the RGPD page only links to the preferences page rather than consuming this data itself.

### 6.2 Storage
Consent stored in strictly-necessary cookie `cookie_consent` (JSON: `{"functional": true, "analytics": false}`). 13-month expiry.

### 6.3 Enforcement
`CookieConsentService::isAllowed($category)` checked before any `setcookie()` for non-essential cookies. Middleware or helper — never left to individual controllers.

## 7. UX conventions

Rules every later change is expected to follow. They are written down because
each one was decided after seeing the alternative fail somewhere in this
codebase — the rationale matters as much as the rule. The ones marked
**(enforced)** have a test that fails when they are broken:
`tests/Core/View/UxConventionsTest.php`.

### 7.1 Back navigation: the breadcrumb, and nothing else **(enforced)**

**No page carries its own "Retour" control.** `partials/breadcrumb_bar.html.twig`
is rendered once by `base.html.twig`, is visible on every mobile viewport (and
on desktop in an installed PWA), and is the single back mechanism of the site.

Per-page back buttons were removed because they both duplicated it and
disagreed with it: 13 of them existed in 6 different visual treatments
(`btn-outline-secondary`, `btn-secondary`, `btn-primary`, `btn-outline-primary`,
a bare link with `&laquo;`, with or without `bi-arrow-left`), so "going back"
looked and behaved differently depending on which page you were on, and pages
that had one showed two stacked back affordances on mobile while the rest
showed one.

A page therefore has to declare its own place in the hierarchy. Three
mechanisms, in increasing specificity:

| Mechanism | Where | Renders as | Use for |
|---|---|---|---|
| `breadcrumb.parents` | route declaration (`module.json`, or `Router::addRoute()`'s 6th argument) | a button that OPENS that menu | the MENU a page belongs to |
| `breadcrumb_trail` | controller context, `[{label, url}]` | a real link | an ancestor PAGE within the same family |
| `breadcrumb_current` | controller context | the active, non-link item | a dynamic title (an album, a member, a reference) |

`parents` names a menu and only a menu. The bar matches each entry against the
menus the visitor actually has; anything it cannot match degrades to inert grey
text. An ancestor that is a *page* — "Galerie", "Mes locations", "Inscriptions"
— belongs in `breadcrumb_trail`, which is a real link, and is exactly where the
information a removed back button carried should go.

**Every page route declares a breadcrumb.** A page without one renders the bar
as a lone home icon, which reads as a broken component rather than as a page
with no ancestors. Routes that render no page (JSON endpoints, AJAX fragments,
downloads, media streams) declare none, and are listed in the test's
`NON_PAGE_PATTERN`.

Two exceptions, both because the breadcrumb genuinely cannot serve:

- `pwa/offline.html.twig` deliberately does not extend `base.html.twig` (it is
  precached by the service worker and must bake in nothing dynamic), so it has
  no bar at all; in an installed PWA there is no browser back button either,
  which makes its `history.back()` the only way back to the cached page the
  visitor was reading.
- `@mass_mail/_compose_dialog.html.twig`'s "Retour au brouillon" switches a
  dialog between two states. It navigates nowhere.

A page outside the hierarchy — an error page, a confirmation reached from an
email link, a pre-authentication screen — may still offer a way onward. Word it
as the next step ("Se connecter", "Voir les inscriptions", "Aller à l'accueil"),
never as "Retour": it is a call to action, not a way back up a tree the visitor
was never in.

### 7.2 Touch targets: a comfort target, not a conformance threshold

The accessibility requirement is **24×24 px** (WCAG 2.2 SC 2.5.8, level AA).
The 44 px figure this project aims for comes from SC 2.5.5 (level **AAA**) and
Apple's HIG. It is a comfort goal, so treat it as one:

- Apply it where the target is genuinely small or reduced to an icon — icon-only
  buttons, pagination links, checkbox rows, list items.
- Do **not** inflate controls that are already comfortable. Bootstrap's default
  `.btn`, `.form-control` and `.form-select` render at 38 px, which clears the
  AA threshold with room to spare; raising the ~380 default-size form controls
  in this codebase to 44 px would make every form taller for no gain.
- Never express it as an inline `style="min-height:44px"`. An inline style beats
  every stylesheet rule, including the `@media (min-width: 992px)` block that
  restores compact sizes on desktop — so an inline patch that does nothing on
  mobile (`app.css` already handles it) leaves that module's buttons 44 px tall
  on desktop while every other module's are 31 px.

Known wart to fix rather than propagate: under `pointer: coarse` the floor is
applied to `.btn-sm` / `.form-control-sm` / `.form-select-sm` but not to their
default-size siblings, so a *small* control currently renders **taller** (44 px)
than a normal one (38 px). Fix the inversion when touching that block; do not
resolve it by inflating everything.

### 7.3 Feedback and confirmation

- Destructive or irreversible POSTs carry `data-confirm` **on the `<form>`**.
  The delegated handler in `base.html.twig` resolves `e.target.closest('form[data-confirm]')`
  — on a submit event `e.target` *is* the form, so `closest()` never reaches a
  button, and the attribute placed on the button is silently inert.
- Never `onclick=` / `onsubmit=` / `onchange=` in a template. The CSP is
  `script-src 'self' 'nonce-…'` with no `unsafe-inline`, and a nonce never
  covers an `on*` attribute: the handler simply never runs, with no error.
- Prefer the flash message system and in-page error containers to
  `alert()` / `confirm()` / `prompt()`: native dialogs are unstyled, block the
  page, label their buttons in the OS language, and leave no trace to re-read.
- A confirmation states the consequence, not just the verb: "Supprimer cette
  clé ? Vous perdrez ce moyen de connexion." beats "Supprimer cette clé ?".

### 7.4 Mobile layout of rows and blocks

- A row that pairs content with a cluster of controls (`partials/list_editor.html.twig`)
  wraps below `lg`: content and reorder buttons on the first line, the control
  cluster on its own line, right-aligned. Do not try to fit both on one line on
  a 360 px screen — the elastic child (typically a `<select>`) gets crushed to
  about 50 px, narrower than the word it has to show.
- A summary block on the home page uses the same one-line `alert` shape as a
  banner. It is a nudge toward a page, not a second feed: it carries counts and
  one link, never an enumerated list with per-item timestamps.
- Render a wrapper only when it has content. An always-emitted actions
  container costs an empty full-width line per row on mobile for every caller
  that supplies no actions.

### 7.5 Wording

- Vouvoiement throughout, sentences end with a full stop.
- Name things by what the reader recognises, never by the mechanism: "Votre
  session a expiré. Rechargez la page et réessayez." rather than "Jeton CSRF
  invalide."
- Never surface a raw exception message in a flash. Catch it, journal the
  detail, and show a sentence written for the reader.
