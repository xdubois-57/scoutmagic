# Design

This document covers UI/UX decisions, data model, and technical design choices.

## 1. UI/UX principles

### 1.1 Mobile-first
Primary device is mobile. Base CSS for mobile, `min-width` breakpoints for larger. Bootstrap 5 compiled files. 44px touch targets. HTML5 input types.

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
SectionPicker → section header (name/code, branch badge, count, edit button) → staff cards. Edit mode: form for name + email.

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

### 2.6 Encryption strategy

| Category | Storage | Search |
|---|---|---|
| Personal identity | AES-256-GCM → BLOB | Decrypt after SELECT |
| Email (needs match) | BLOB + HMAC blind index | WHERE on blind index |
| Section email (organizational) | Clear VARCHAR | Normal WHERE |
| IDs, FKs, flags, timestamps | Clear | Normal WHERE |
| Secrets (DB, SMTP) | `secrets.enc` file | N/A |

## 3. Deployment

### 3.1 FTP deployment
`deploy.sh`: CI build → `lftp mirror` (differential, deletes removed) → trigger migration.

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
