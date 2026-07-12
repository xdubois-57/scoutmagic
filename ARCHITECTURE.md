# Architecture

This document is the architectural reference for the project. Every contribution — human or agent-generated — must conform to it. When in doubt between a "simpler" solution and one that respects this document, this document takes priority.

## 1. Overview

### Purpose

Open-source PHP website for Belgian scout units ("Les Scouts" federation). Same codebase reusable across units — all unit-specific data is configurable, never hardcoded. Deployable via FTP on shared hosting with a MySQL database.

### Dependencies

Only three external dependencies are justified:

| Dependency | Justification |
|---|---|
| Twig | Auto-escaping prevents XSS across all templates; reimplementing a secure template engine with inheritance and contextual escaping is too large and too risky. |
| Bootstrap 5 | Mobile-first responsive grid, forms, navigation, and table components; compiled CSS+JS files only — no Sass, no webpack, no npm on the server. |
| PHPMailer | SMTP authentication, STARTTLS, and DKIM signing; reimplementing email delivery correctly is a project in itself with real deliverability risks. |

Everything else is written in-house. Composer is used for autoloading and dependency resolution during CI build — `vendor/` is built by CI and deployed via FTP; Composer is never required on the hosting server.

No frontend build tools (Sass, webpack, npm). Bootstrap is loaded from compiled files (CDN or `public/assets/vendor/bootstrap/` fallback).

### License

AGPL-3.0. Intent documented in README: this project is made available for scout units and the community, with the expectation that all usage remains open source.

## 2. Layered MVC architecture

Every request traverses the same layers in the same order:

```
Front controller (public/index.php)
      │
      ▼
Router                         → resolves route from path
      │
      ▼
RBAC guard                     → checks role BEFORE instantiating the controller
      │
      ▼
Controller                     → orchestrates, contains no business logic
      │
      ▼
Service                        → module business logic
      │
      ▼
Repository                     → database access (PDO, prepared statements only)
      │
      ▼
View (Twig)                    → rendering, automatic escaping
```

**Absolute rule**: the RBAC guard is never called manually from a controller. It is invoked systematically by the Router for every route, before executing anything. A controller may re-check a fine-grained permission inside an action (e.g. "can this member edit this specific activity"), but this is never the primary protection — route access itself is always filtered upstream.

## 3. Roles and menus

Three-level system: **function** (configurable per unit, e.g. "Animateur Baladins", "Trésorier") → **role** (fixed, hierarchical) → **menu**.

| Role | Level | Associated menu (minimum) |
|---|---|---|
| `public` | 0 | Notre unité |
| `identified` | 1 | Espace des animés |
| `intendant` | 2 | Partial Espace des chefs |
| `chief` | 3 | Full Espace des chefs + Espace admin |
| `admin` | 4 | Configuration |

Hierarchy is **cumulative**: a role at level N sees all menus at level ≤ N.

Functions themselves (name, associated role) are managed via a core page (`Configuration > Fonctions`), not via a module.

Routes within "Espace des chefs" declare `role_min: "intendant"` or `role_min: "chief"` individually — the menu appears for intendants but with filtered content.

## 4. Identity model

### Email → members → role

A person logs in with an email address, not with a member account.

- `user_accounts` contains only an email (unique) + optional info (name, surname).
- At login, the system finds all `member_years` for the current scout year whose email matches.
- Effective role = the **highest** among the functions of all linked members.
- The person can then navigate between "their" members (e.g. a parent sees the page of each of their children).

### Display name convention

Everywhere on the site: `totem ?? first_name`. A global Twig helper (`{{ member|display_name }}`) applies this rule once.

### Scout year

A scout year runs from September to August (e.g. 2025-2026). All member-related tables carry a `scout_year_id`. History is preserved across years — the `members` table holds the persistent identity (`desk_id`), while `member_years` holds the annual snapshot.

## 5. Authentication

Three methods, all resolving to the same email address:

| Method | Fields required | Requires prior setup |
|---|---|---|
| Magic link (default) | Email only | No |
| Password | Email + password | Yes (set in "Mon compte") |
| Passkey (WebAuthn) | None (discoverable) | Yes (registered in "Mon compte") |

Magic link is always available as a fallback. Password and passkey methods can be enabled/disabled site-wide via settings.

Magic link tokens: cryptographically random, single-use, 15-minute expiry, stored hashed in database. The login page polls (or uses SSE) for confirmation — the link can be clicked on a different device.

### Login security

- Identical error message for "unknown email" and "wrong password" (no account enumeration).
- Progressive lockout on failed attempts.
- Session ID regenerated at login.
- Cookies: `HttpOnly`, `Secure`, `SameSite`.

## 6. Cookie consent

### 6.1 Consent model

The site categorizes cookies into:

| Category | Consent required | Examples |
|---|---|---|
| Strictly necessary | No (always active) | Session cookie, CSRF token, cookie consent choice |
| Functional | Yes | Configuration mode preference, last selected section |
| Analytics | Yes | Usage statistics (if a module adds this) |

Modules can register additional cookie categories via their `module.json`.

### 6.2 Consent banner

Displayed on first visit (no cookie consent choice stored yet). Offers: "Accepter tout", "Refuser tout", "Personnaliser". Choice stored in a strictly-necessary cookie (`cookie_consent`, JSON, 13-month expiry per ePrivacy directive).

### 6.3 Cookie preferences page

Accessible from:
- The consent banner ("Personnaliser" button).
- The RGPD public page (link).
- The "Mon compte" page (for identified users).

Displays each cookie category with description and toggle. Strictly necessary category shown but not toggleable (always on, explained).

### 6.4 Enforcement

Before setting any non-essential cookie, code must check consent via `CookieConsentService::isAllowed($category)`. Modules that add cookies must declare them in their `module.json` with category, name, purpose, and duration.

## 7. Module system

### Structure

```
modules/
  <module_name>/
    module.json      Manifest (see §7.1)
    schema.sql       Complete current schema for this module's tables
    src/
      Controller/
      Service/
      Repository/
    views/           Twig templates namespaced (@<module_name>/...)
```

### 7.1 Module manifest (`module.json`)

```json
{
  "id": "calendar",
  "name": "Calendrier des activités",
  "version": "1.2.0",
  "routes": [
    {
      "path": "/calendar",
      "controller": "Modules\\Calendar\\Controller\\CalendarController",
      "action": "index",
      "menu": "espace_animes",
      "role_min": "identified"
    }
  ],
  "settings": [...],
  "storage": {...},
  "scheduled_tasks": [...],
  "cookies": [
    {
      "name": "calendar_view",
      "category": "functional",
      "purpose": "Mémorise le type d'affichage choisi (mois/semaine)",
      "duration": "1 an"
    }
  ]
}
```

Validation rules enforced by `ModuleManager` at load time:
- `role_min` is **mandatory** on every route. A route without `role_min` is rejected (fail-safe: no access by default).
- `menu` must be one of: `notre_unite`, `espace_animes`, `espace_chefs`, `espace_admin`, `configuration`.
- A route may require a stricter role than its menu's minimum, never more permissive.
- A disabled module: all routes return 404, menu entries disappear, but data and schema remain.

### 7.2 Module registry

```
module_registry: id, module_id (unique string), enabled, installed_version, enabled_at, enabled_by
```

### 7.3 Module lifecycle

Activation: run schema migration → create default settings → register routes → log activation.
Deactivation: unregister routes → log deactivation. **Never** touch tables or settings — data stays intact.

## 8. Core services

### 8.1 Desk CSV import

- Lines grouped by `desk_id`.
- Raw values resolved via mapping tables (functions, branches, tariffs, sections).
- New functions default to lowest role — require admin confirmation.
- CSV deleted immediately after import.
- Import journaled (metadata only).

### 8.2 Editable content system (configuration mode)

- Session-only flag, admin role re-verified server-side on every save.
- Rich text sanitized with HTML tag whitelist before storage.
- Images: MIME validated, EXIF stripped, filename randomized.

### 8.3 File access guard

All files outside `public/assets/` stored under `storage/` (outside webroot). Every file access through `/files/{id}`. No exceptions.

### 8.4 Settings system

Generic key-value with type, label, description (NOT NULL), optional regex validation. Grouped by module. Editable via dialog.

### 8.5 Scheduler

Two triggers (real cron + poor man's cron), atomic task claim. Modules declare handlers in `module.json`.

### 8.6 Event journal

Central `JournalService::log()`. No personal data in entries. Modules never write their own log tables.

### 8.7 Mail service

Subject prefixed `[{short_name}]`. PHPMailer: SMTP or local, DKIM signed, multipart mandatory. DNS verification page for SPF/DKIM/DMARC.

### 8.8 SectionPicker component

Reusable Twig partial. Shows sections (not branches). Default: section of highest-role member linked to account.

### 8.9 Cookie consent service

`CookieConsentService::isAllowed($category)`: checks stored consent before any non-essential cookie is set. Consent stored in strictly-necessary cookie. Aggregates cookie declarations from core and all active modules for the preferences page.

## 9. Installation / bootstrap

First access without `secrets.enc` → setup page (no auth required, works once). Collects DB credentials, unit settings (including short name ≤5 chars), email config, initial admin email. Same page accessible later from Configuration as normal admin page.

## 10. Database schema management

No incremental migration files. `schema/core.sql` + each module's `schema.sql` = source of truth. Deploy script compares and generates DDL.

## 11. Responsive interface (mobile-first)

Bootstrap 5 compiled files. Mobile-first CSS. Hamburger left, unit name right. Offcanvas from left on mobile, horizontal bar with wrapping sub-menu on desktop. Hybrid "Espace des animés" menu (dynamic member entries + static module pages). 44px touch targets. HTML5 input types.

## 12. Project structure

```
core/
  Http/            Router, Request, Response, FrontController
  Security/        RbacGuard, Session, Csrf, PasswordHasher, Encryption, WebAuthn
  Database/        PDO connection, SchemaIntrospector, MigrationRunner
  View/            Twig bootstrap, helpers, partials
  Mail/            MailService, DkimManager, DnsVerifier
  Module/          ModuleManager
  Config/          SettingService
  Scheduler/       SchedulerService, SchedulerRunner
  Journal/         JournalService
  File/            FileAccessGuard, UploadHandler
  Cookie/          CookieConsentService

modules/
  <module_name>/

config/
  app.php

schema/
  core.sql

storage/           (outside webroot)
  keys/
  config/
  core/
  modules/
  temp/

public/
  index.php
  assets/

scripts/
  deploy.sh
  release.sh

docs/
  specifications.md
  design.md
  rgpd.md

tests/             Mirrors core/ and modules/ structure

.github/
  workflows/
  CODEOWNERS
  PULL_REQUEST_TEMPLATE.md

ARCHITECTURE.md
SECURITY.md
AGENTS.md
README.md
CONTRIBUTING.md
LICENSE (AGPL-3.0)
```

## 13. Code conventions

- Namespace PSR-4: `Core\` → `core/`, `Modules\<ModuleName>\` → `modules/<module_name>/src/`.
- **All code, comments, variable names, table names, column names, commits, PRs: English.**
- **All UI text (labels, messages, Twig content): French.**
- Controller: reads request → calls Service → picks view. No SQL, no business logic.
- Service: never accesses `$_SESSION`/`$_POST` directly.
- Repository: only layer that touches PDO. Prepared statements only.
- View: never calls Repository or Service.

## 14. What Devin must never do

- Call `RbacGuard` from a controller instead of letting the Router do it.
- Write a route in a `module.json` without `role_min`.
- Put business logic or SQL in a Controller or View.
- Duplicate core functionality in a module (auth, session, encryption, journal, mail, scheduler, cookie consent).
- Create an incremental migration file — update the module's `schema.sql`.
- Modify `schema/core.sql` for a module-specific need.
- Write custom CSS that duplicates a Bootstrap component.
- Introduce a frontend build tool.
- Store personal data in `VARCHAR` — use `BLOB` via `EncryptionService`.
- Write `WHERE` on an encrypted field — use blind index.
- Put personal data in logs, journal, or error messages.
- Link to a file with a direct path — use `file_url(id)`.
- Store uploaded files under `public/`.
- Write a module's own log table — use `JournalService`.
- Use French in code/comments/table names.
- Use English in UI text.
- Set a non-essential cookie without checking `CookieConsentService::isAllowed()`.
- Submit code without corresponding automated tests.

## 15. Tests

`tests/` mirrors the structure of `core/` and `modules/`. Automated tests are mandatory for every feature and must be kept up to date as the codebase evolves. The RBAC guard must have explicit test coverage on every role boundary. Every page/component must be visually verified at mobile (~375px) and desktop (~1280px) widths.
