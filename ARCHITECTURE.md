# Architecture

This document is the architectural reference for the project. Every contribution — human or agent-generated — must conform to it. When in doubt between a "simpler" solution and one that respects this document, this document takes priority.

## 1. Overview

### Purpose

Open-source PHP website for Belgian scout units ("Les Scouts" federation). Same codebase reusable across units — all unit-specific data is configurable, never hardcoded. Deployable via FTP on shared hosting with a MySQL database.

### Dependencies

Only a small, explicitly justified set of external dependencies is allowed:

| Dependency | Justification |
|---|---|
| Twig | Auto-escaping prevents XSS across all templates; reimplementing a secure template engine with inheritance and contextual escaping is too large and too risky. |
| Bootstrap 5 | Mobile-first responsive grid, forms, navigation, and table components; compiled CSS+JS files only — no Sass, no webpack, no npm on the server. |
| PHPMailer | SMTP authentication, STARTTLS, and DKIM signing; reimplementing email delivery correctly is a project in itself with real deliverability risks. |
| smalot/pdfparser | Extracts a PDF's embedded text layer (`Core\File\PdfTextExtractor`) — parsing PDF's internal object/stream structure correctly (compression filters, font encodings, cross-reference tables) is not something worth hand-rolling for one feature (Modules\Finance\Task\ExtractReceiptDataHandler: reading a receipt/invoice PDF's real text is exact and provider-agnostic, versus sending the file to a vision model and hoping it's supported/accurate). |
| dompdf/dompdf | Renders HTML+CSS to PDF for printable A4 posters (`Core\Pdf\PosterPdfService`); pure PHP, no system binaries required on shared hosting. |
| endroid/qr-code | Generates QR code images (PNG/SVG) server-side for posters and SEPA payment codes; pure PHP, no external API call. |
| phpoffice/phpspreadsheet | Reads and writes XLSX files with formula and style support; needed for form response export (`Modules\News`) and future financial spreadsheet imports. |

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

| Role | Level | UI label | Associated menu (minimum) |
|---|---|---|---|
| `public` | 0 | — | Notre unité |
| `identified` | 1 | — | Espace des animés |
| `intendant` | 2 | Intendant | Partial Espace des chefs |
| `chief` | 3 | Chef | Full Espace des chefs |
| `admin` | 4 | Chef d'Unité | + Espace admin |
| `superadmin` | 5 | — (top site administrator) | + Configuration |

Hierarchy is **cumulative**: a role at level N sees all menus at level ≤ N. `admin` and `superadmin` are both defined in `Core\Security\Role` (`level()`) — `admin` is displayed as "Chef d'Unité" in the UI (e.g. Config Desk's function role picker), never as "Admin"; `superadmin` has no distinct UI label beyond gating the Configuration menu. A route may set a `role_min` stricter than its menu's own minimum but never more permissive — one core exception is documented at §8.15 (the Maintenance page sits in the `superadmin`-gated Configuration menu but its own routes are `role_min: admin`, a deliberate spec choice, not a module-manifest-validated route so the usual stricter-only rule doesn't apply).

Functions themselves (name, associated role) are managed via a core page (`Configuration > Config Desk`), not via a module. The same page also manages section name/visibility (see §8.8).

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
- A route's optional `menu_order` (int, default 100) controls where its menu entry sorts within its menu — lower sorts earlier. In `espace_animes`, dynamic per-member entries use 10+index and the separator before static pages sits at 50, so the default 100 always lands after them; a module can set a lower value to appear before them instead. That explicit value is absolute, unaffected by the module reordering below.
- Modules can be reordered on the general configuration page (`/config/general`, superadmin — drag-and-drop, or up/down arrows on mobile, same list component as the banner list). This persists to `module_registry.sort_order` and determines both that page's own listing order and, for routes left at the default `menu_order`, a base offset (`1000 * module position`) added on top — so those pages sort by module order across every menu, always after core's own hardcoded page order.
- A module's optional top-level `enabled_by_default` (bool, default false) auto-activates it the very first time it is discovered on disk (no `module_registry` row yet). An admin's later explicit deactivation always sticks.
- A disabled module: all routes return 404, menu entries disappear, but data and schema remain.

### 7.2 Module registry

```
module_registry: id, module_id (unique string), enabled, installed_version, enabled_at, enabled_by
```

### 7.3 Module lifecycle

Activation: run schema migration → create default settings → register routes → log activation.
Deactivation: unregister routes → log deactivation. **Never** touch tables or settings — data stays intact.

### 7.4 Core hooks for module-provided configuration

A module that needs to extend a *core* configuration page (e.g. attach flags to a core entity) must not be depended on by core code directly. Instead, core defines a small interface (e.g. `Core\Module\FunctionFlagsProvider`, used by the Config Desk page to let a module declare a per-function flag without the core page hardcoding any module or function name), the module implements it, and the composition root (`public/index.php`) wires the concrete implementation into the core controller only when that module is enabled. Same precedent as `Core\Scheduler\TaskHandlerInterface`.

### 7.5 Core optionally consuming a module's public API

The reverse direction: a module that offers a capability other code may want to *use* (not extend) publishes a stable public interface under its own `Api` namespace (e.g. `Modules\LlmConnector\Api\LlmConnectorInterface`) rather than core defining the interface. Code that wants to use the capability declares a nullable constructor dependency on that interface and must degrade gracefully (feature simply unavailable) when it is `null`. The composition root (`public/index.php`) instantiates the module's concrete implementation and injects it only when the providing module is enabled in `ModuleManager::getEnabledModuleIds()`; the consumer never references the module's classes when it is disabled. Two examples in the codebase: `Core\View\RgpdContentService` (core consuming `llm_connector`, to generate RGPD text) and `Modules\Finance\Service\ReceiptExtractionService` (one module consuming another, to suggest a receipt's amount/date/merchant) — the pattern is identical either way, since a *module* consuming another module's API optionally is the same shape as core doing it.

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

Reusable Twig partial. Shows sections (not branches). Default: section of highest-role member linked to account. `Core\Member\SectionService::getAllWithBranches()` excludes hidden (`sections.is_visible = false`, admin toggle) and inactive (`sections.is_active = false`, automatic — see §7.4-adjacent Desk import note below) sections by default — every call site (Staffs, Trombinoscope, the public Sections page) gets this filtering for free; only the Config Desk admin page (which manages both) passes `includeHidden: true`. Name, color, and visibility are configurable from Configuration > Config Desk. `sections.color` is an explicit override (hex); when unset, `Core\Member\SectionService::colorForSection()` derives it from the section's branch (`Core\Member\MemberYearService::colorForBranchSortOrder()`), or a dedicated color for Staff d'U — every color-coded picker/list across the site (Staffs, Trombinoscope, the calendar module, statistics) calls this single source of truth.

A section with no member in the current Desk import becomes inactive automatically — never deleted, just hidden from every section picker until a later import gives it members again. `MappingResolver::deactivateAllSections()` marks every section inactive at the start of each import; `resolveSection()` reactivates each one actually referenced (same deactivate-then-reactivate pattern as `member_years.is_active`, see §8.1).

### 8.9 Cookie consent service

`CookieConsentService::isAllowed($category)`: checks stored consent before any non-essential cookie is set. Consent stored in strictly-necessary cookie. Aggregates cookie declarations from core and all active modules for the preferences page.

### 8.10 Photo per person/year (`Core\Photo`)

Generic, reusable component: a photo (`member_photos`: member_id, scout_year_id, file_id) is tied to a member AND a scout year. `MemberPhotoService::resolveFileId()` returns the photo for a given year, falling back to the most recent earlier year, else null. The `member_photo()` Twig function (registered in `TwigFactory`) renders it — an initials-in-a-circle avatar (same style as the account menu) when none exists — and, in configuration mode, the same click-to-replace overlay as `editable_image()` (upload context `member_photo`, key `"{memberId}:{scoutYearId}"`, handled by `UploadController`). Not specific to any module.

`Core\Photo\SectionPhotoService`/`SectionPhotoRepository` are the section-keyed twin of the above (`section_staff_photos`: section_id, scout_year_id, file_id — one group photo per section per year, same per-year-with-earlier-year-fallback resolution). `Core\Photo\SectionPhotoProcessor` center-crops the uploaded image to a fixed 4:3 landscape ratio (correcting EXIF orientation first) and caps the width at 1600px before storage, via a `UploadController::processSectionPhoto()` step that runs before the generic `UploadHandler::handle()`. The `section_photo()` Twig function mirrors `member_photo()`'s rendering/overlay behavior but has no initials-style fallback — with no photo and outside configuration mode it renders nothing at all, so a section that never had a group photo uploaded shows no empty box. Used on the Staffs page and, unauthenticated, on the public Contact and Sections pages (`section_photo($sectionId, ...)`).

### 8.11 Badges (`Core\Badge`)

Transversal roles assignable to chiefs/chief-d'unité (e.g. Infirmier, Trésorier) — a global concept (`badges`: name, is_default, is_active) configured once from Configuration générale, with assignment scoped per member per scout year via `member_badges.member_year_id` (so history across years is preserved automatically, the same way `member_years` already works). Badges are plain text/name only — no logo/icon.

Default badges (Infirmier, Trésorier) are seeded idempotently by `BadgeService::ensureDefaults()` (called on every `/config/general` request, same pattern as `SettingService::register()`) and can never be deleted or renamed, only deactivated. Any badge already assigned to a member — even in a past year — can likewise never be deleted, only deactivated: `BadgeService::delete()`/`update()` refuse both cases, preserving historical data; the admin UI reflects this by disabling the delete button and making the name read-only rather than letting the request round-trip and fail. A deactivated badge is invisible everywhere (assignment picker, trombinoscope) but existing `member_badges` rows are untouched, so reactivating it brings past assignments back.

`Core\Member\SectionService::hydrateMemberProfile()` fetches a member's active badges into `MemberProfile::$badges` — the single hydration path shared by the Staffs page and (via `SectionService::hydrateMemberProfile()` reuse, see §8.8-adjacent Trombinoscope note) the trombinoscope module, so badges surface in both without either needing its own plumbing. `SectionService::getSectionStaff()` also filters to chief/admin-role functions only — a section's animés carry the same `section_id` on their `member_functions` row, so this filter is what keeps the Staffs/badge-assignment page staff-only.

**Referent badges**: `badges.referent_section_id` (nullable FK to `sections`) marks a badge as an auto-generated "Référent {section name}" badge, one per currently-visible non-STAFFDU section. `BadgeService::syncSectionReferentBadges()` is idempotent and self-healing — called on every relevant page load (general config, Config Desk, Staffs) plus directly after a section name/visibility change for immediate effect: it creates a badge only for a visible section (never for a hidden one) and renames it on a name mismatch, but never touches `is_active` after creation, so a manual activate/deactivate is never silently overwritten by a later sync. Referent badges are `is_default = true` (read-only name, same non-deletable treatment as Infirmier/Trésorier above) but — unlike other default badges — their "Actif" toggle stays editable. Assignment is restricted to members currently in the Staff d'U section: enforced both in the UI (`StaffsController` filters the picker) and server-side in `BadgeService::toggleAssignment()` via `SectionService::isMemberYearInSection()`, so the restriction holds regardless of which page's picker triggers the request.

### 8.12 "Staff d'U" section (`Core\Member\UnitStaffSectionService`)

Chef-d'unité staff (role `admin`) are represented by a real `sections` row (`desk_code = 'STAFFDU'`, branch `"Staff d'U"`, canonical `sort_order = 50` between Pionniers and Route — see `AgeBranchRepository::canonicalSortOrder()`) — not a virtual/synthetic entry. Because membership is expressed the same way as any other section (`member_functions.section_id` pointing at it), it flows through `SectionService::getAllWithBranches()`/`getSectionStaff()`, the SectionPicker, and the trombinoscope with zero special-casing anywhere. `UnitStaffSectionService::ensureSection()` idempotently creates the branch/section and forces it active — it must survive `MappingResolver::deactivateAllSections()` even though no Desk CSV row ever references `STAFFDU` directly (§8.8's deactivate-then-reactivate pattern only reactivates sections a CSV row actually names).

Because a function's role is only known once an admin confirms it on Config Desk (`FunctionsController::update()`) — never at raw Desk import time, where new functions always import as `role = 'identified'` — membership must be (re)synced in two places: `UnitStaffSectionService::syncMembership($scoutYearId)` runs at the end of `DeskImportService::import()` (covers already-confirmed `admin` functions) and again after every `FunctionsController::update()` role change (covers promotions/demotions to/from `admin` post-import). It's a bulk, idempotent two-step select/update: any unassigned `admin`-role function gets `section_id = STAFFDU`; any `STAFFDU`-assigned function whose role is no longer `admin` gets `section_id` cleared back to `NULL`.

`Core\View\SectionRepository` (the public "Notre unité > Sections" read-model) explicitly excludes `desk_code = 'STAFFDU'` — the section is real and generic for internal purposes but never listed publicly.

### 8.13 Generic list editor (`partials/list_editor.html.twig`) and rich text field (`partials/rich_text_field.html.twig`)

Reusable, content-agnostic core components for any module that manages a configurable, orderable list (introduced by the `banner` module — homepage banners — but not specific to it; a future FAQ or useful-links module reuses both unchanged). `list_editor.html.twig` owns only the list chrome: add button, native HTML5 drag-and-drop reordering, an active toggle, and delete (with an optional `can_delete`/`delete_blocked_reason` per item — same "used elsewhere, deactivate instead" pattern as Badges/§8.11). It knows nothing about what an item *is* — the caller supplies each item's body via Twig's `{% embed %}` block-override mechanism (`item_content`, declared as a real `{% block %}` inside the partial's `{% for item in items %}` loop, not a `{{ block() }}` call — only a genuine block declaration lets `{% embed %}` re-render the caller's override per iteration with `item` in scope). `public/assets/js/list-editor.js` persists reordering silently in the background; add/delete reload the page, since those change the item set itself.

`rich_text_field.html.twig` is the child content component the banner module plugs into `item_content`: a preview + "Modifier" button reusing the exact same modal/toolbar markup as `editable()`'s configuration-mode overlay (`partials/rich_text_editor.html.twig`, `document.execCommand`-based, no editor dependency) and the same rich-text engine (`Core\Security\HtmlSanitizer` via `Core\View\EditableContentService`) — but never gated behind configuration mode, since the admin page embedding it already provides its own `role_min` authorization. It saves via the generic core endpoint `POST /api/rich-text-content` (`EditableContentController::updateField()`, role_min superadmin) — same body shape as `/api/editable-content` but without the configuration-mode check — so a module reusing this partial needs no save endpoint of its own for the text itself. `public/assets/js/rich-text-field.js` is safe to load alongside `editable.js` on the same page: both bind to the shared `#richTextEditorModal`, but `editable.js` only loads when configuration mode is active, which a dedicated admin config page never is.

The banner module's list items themselves (`banners`: id, is_active, sort_order, role_min — no text column) illustrate the intended split: dynamic collection metadata lives in the module's own table, while each item's formatted text is stored in the core `editable_contents` table under a per-item key (`banner_content_{id}`) — reusing the exact same storage/sanitization as any other `editable()` content instead of a second rich-text mechanism. `role_min` (`'public'`/`'identified'`/`'chief'`) gates a banner's visibility the same way route `role_min` does everywhere else (`Core\Security\Role`'s hierarchy) — deliberately capped at `chief`, since `admin`/`superadmin` alone would have no realistic audience on the always-public homepage.

`Core\Module\HomeBannerProvider` is the module-into-core hook (same §7.4 precedent as `FunctionFlagsProvider`) letting the banner module inject a randomly-chosen active banner's HTML onto `PageController::home()` without core depending on the module: `getRandomBannerHtml(string $viewerRole)` picks fresh (never cached) on every call, only among banners whose `role_min` the viewer satisfies, and returns `null` when there are no active banners or none visible at that role, which the homepage template treats as "render nothing," not an empty box.

### 8.14 Encrypted file storage (`Core\File\EncryptedFileStorageService`)

Generic, module-agnostic counterpart to `Core\File\UploadHandler` for files that must never touch disk in plaintext (introduced for the `finance` module's receipts, but not specific to it). Uses the same master key as `Core\Security\EncryptionService`: `store()` encrypts the given content and writes it under a caller-chosen subdirectory of `storage/`, then registers a `FileRecord` via `Core\File\FileRepository::create()` with `encrypted = true`; `retrieve()` reads the file back and decrypts it; `delete()` removes both the encrypted file and its `FileRecord` — a genuine physical deletion, unlike a module's own "archive, never delete" convention for the record that *references* the file. `Core\Http\Controller\FileController::serve()` is the single integration point: it already runs every request through `FileAccessGuard`'s `role_min` check, and now branches on `FileRecord::$encrypted` to call `EncryptedFileStorageService::retrieve()` instead of reading the file directly, so decryption is transparent to any caller downloading via `/files/{id}` regardless of which module owns the file. A task handler that needs file access reconstructs this service from `TaskContext::$storagePath` (added for this purpose) the same way it reconstructs any other repository/service from `TaskContext::$connection`.

### 8.15 On-demand backups (`Core\Maintenance`)

Configuration > Maintenance page (`role_min: admin`, matching the spec's explicit choice even though every other Configuration-menu route in this codebase uses `superadmin`) for FTP/shared-hosting deployments that have no server-level backup mechanism of their own. `BackupService` is a deliberately "pure mechanical" service — no `BackupRepository`/`FileRepository` dependency — reusable by future update/reset iterations, not just this one: `createDatabaseDump()` (full `mysqldump`), `createConfigOnlyDump()` (`mysqldump --no-data` structure + `--no-create-info` for a hardcoded whitelist of config-only tables — `settings`, `module_registry` — deliberately narrow rather than a broad "config tables" guess, since module settings all funnel through the generic `settings` table already), `createFileBackup(bool $includeGallery)` (zips `core/`/`modules/`/`public/`/`storage/`, always excluding `storage/keys/`, `storage/config/`, `storage/temp/`), and `createFullBackup(string $scope, string $password)` (combines a dump with a `ZipArchive::EM_AES_256`-encrypted file backup; returns both paths — `array{zipPath, dbDumpPath}` rather than the single path a naive reading of "one backup" might suggest — because the `backups` schema needs a separate `db_dump_file_id`, and bookkeeping/registration is deliberately left to the caller). `supportsZipEncryption()` self-tests the runtime's libzip crypto support with a throwaway zip so the UI can fail cleanly (disable the full-backup option with an explanatory message) on environments without it, rather than generating a silently-unencrypted archive.

A full backup runs as a background scheduled task (`core`/`create_backup`, `CreateBackupHandler`, registered directly in `public/index.php` rather than through a module's `scheduled_tasks` — the first core-level, non-module task handler) so a large gallery-inclusive zip doesn't block the request: the controller encrypts the user-supplied password with `EncryptionService` and embeds it (base64) in the scheduled task's payload, decrypted only inside the handler right before use. On completion the handler registers both files via `FileRepository` (`role_min: 'admin'`, unencrypted-at-rest since the zip's own AES-256 password is the protection mechanism here, deliberately not layered with `EncryptedFileStorageService`), purges backup rows beyond the 5 most recent (deleting their files too), and notifies the requesting admin via `Core\Notification\NotificationService` (`$context->notifications`) — reusing `requested_by_user_account_id` (§8.16) to know who to notify without the payload needing to carry it explicitly. The Maintenance page polls `GET /api/maintenance/backup-status/{id}` (same `setInterval` pattern as the magic-link login poll) until the task completes or fails.

A separate, unencrypted `auto_backup` type (`Task\AutoBackupHandler`) covers recurring, unattended backups — frequency (`none`/`daily`/`weekly`/`biweekly`/`monthly`, default `monthly`) is a `SettingService` key editable from the same page, gallery always excluded (no admin present to opt into it), no push notification (no human requester). It self-reschedules at the end of every run rather than being a first-class recurring task — same pattern as `Task\CheckUpdateHandler`'s daily check (§8.17) — re-reading the frequency setting each time so a change takes effect on the next run. Shares the same 5-backup quota as every other type via `BackupRepository::findBeyond()`.

### 8.16 `requested_by_user_account_id` on scheduled tasks (`Core\Scheduler`)

`scheduled_actions` carries a nullable `requested_by_user_account_id` FK, and `SchedulerService::schedule()`/`scheduleAfter()` accept it as a trailing optional param. `SchedulerRunner::processOverdue()` always merges it into the payload under the reserved key `requested_by_user_account_id` (int or null) before calling a handler, regardless of what the original caller put in the payload — so any `TaskHandlerInterface::handle()` can read who (if anyone) asked for a given run without every caller having to thread it through its own payload shape by convention. Introduced for backup completion/failure notifications (§8.15) but generic to any future "notify the person who asked for this" scheduled task.

### 8.17 Update from GitHub (`Core\Maintenance`, "Mise à jour")

Extends §8.15's Configuration > Maintenance page for FTP/shared hosting with no SSH/Git/Composer: `VersionFile` (`Core\Maintenance\VersionFile::read()`/`write()`) reads/writes a plain `VERSION` file at the project root — the installed-version source of truth, deliberately a filesystem fact rather than a database row, since a fresh deploy must be able to report its version before the DB is even configured. `scripts/release.sh` commits this file as part of every tagged release so it always matches the release artifact it ships inside.

`Core\Maintenance\GitHubReleaseClient` (behind `GitHubReleaseClientInterface`, so `Task\CheckUpdateHandler` is testable without the network) is a minimal unauthenticated REST client — same `file_get_contents()`/`stream_context_create()` approach as `Modules\LlmConnector\Provider\AnthropicProvider`, the only other outbound-HTTP precedent in this codebase, no new Composer dependency. `GET /repos/{owner}/{repo}/releases/latest` already excludes drafts and prereleases on GitHub's side. The owner/repo are `Core\Config\SettingService` keys (`update_github_owner`/`update_github_repo`), never hardcoded, so a fork can point at its own repository.

`Task\CheckUpdateHandler` runs daily (self-rescheduling at the end of every run via a `reference: 'daily'` scheduled action — same pattern as `Modules\LlmConnector\Task\RefreshModelsHandler`'s weekly refresh, since `Core\Scheduler` has no first-class recurring-task concept), compares the latest release to `VersionFile::read()` via `version_compare()`, and — only when a newer version exists — calls `GET .../compare/{installed}...{latest}` to detect whether `composer.lock` changed. The result (latest version, release notes, release URL, download URL, checked-at, dependencies-changed flag) is cached in `SettingService` so `MaintenanceController::index()` never re-queries GitHub on page load, only `Task\CheckUpdateHandler` does.

`Task\InstallUpdateHandler` (scheduled by `MaintenanceController::installUpdate()`, which re-validates server-side that a newer version is actually cached before scheduling — never trusts the client) walks `update_history.status` through `backing_up` → `downloading` → `installing` → `migrating` → `completed`, each transition persisted so the polling route (`GET /api/maintenance/update-status/{id}`, same pattern as `backup-status`) can report progress. The safety backup (step 1, `Core\Maintenance\BackupService::createDatabaseDump()` + `createFileBackup(true)`, registered as a `backups` row with `type='auto_update'`) is not a shortcut — it is the only thing an automatic rollback can restore from, so any throwable from downloading through the `VERSION` write triggers `BackupService::restoreDatabase()` + `restoreFiles()` against that exact backup, and `update_history.status` becomes `rolled_back` rather than `failed`. A throwable during the backup step itself cannot be rolled back (nothing was changed yet). The "installing" step copies every top-level entry of the extracted artifact over the live install except `storage/` (all live uploads/keys/config/temp) and `VERSION` (written separately, last, only once everything else succeeded) — nothing else needs excluding, since unit-specific files (`config/app.php`, `.env`) were already excluded from the artifact itself by `scripts/release.sh`. The "migrating" step reuses `Core\Database\MigrationRunner` unchanged against the newly-installed `schema/core.sql` — no separate update-specific migration logic.

`public/cron.php` (the real crontab entry point) is fixed as part of this iteration: it was missing `create_backup`'s handler registration entirely (iteration 2's background backups silently failed with "No handler registered" whenever a real cron ran them instead of the poor-man's-cron in `public/index.php`) and never constructed a `NotificationService` at all (so push notifications from any background task — backups, update checks, update installs — never fired via real cron). Both are now wired identically to `public/index.php`.

### 8.18 Reset and restore (`Core\Maintenance`, "Réinitialisation")

The third and final Configuration > Maintenance section: three destructive actions, each requiring the admin to type an exact confirmation word (`REINITIALISER`/`EFFACER`/`RESTAURER`) — checked server-side in `MaintenanceController` (a JS check only gates the submit button's `disabled` state, never the actual authorization), with "Réinitialisation complète" additionally requiring an explicit "I understand" checkbox. All three run as background scheduled tasks and take their own automatic `backups` row (`type='auto_reset'`) first via `BackupService`, exactly like §8.17's update install.

Progress polling (`GET /api/maintenance/reset-status/{id}`) deliberately reuses `scheduled_actions` itself rather than a dedicated history table — `{id}` is the id `SchedulerService::scheduleAfter()` already returns, and `Core\Scheduler`'s own `pending`/`processing`/`done`/`failed`/`canceled` status is sufficient for this section's needs (unlike §8.17's `update_history`, nothing here needs InstallUpdateHandler's richer per-step status). `Core\Config\SettingRepository::resetAllToDefaults()` (backing "Paramètres par défaut") resets every setting's `setting_value` to its `default_value` column — populated at every `register()` call, including a self-heal `UPDATE` on rows that already existed, since `Task\ResetSettingsHandler` trusts this column blindly and a setting registered before this column existed must not silently reset to empty.

`Task\FullResetHandler` ("Réinitialisation complète") is the most destructive code in this codebase: after its safety backup, it truncates every table (`TRUNCATE` + `SET FOREIGN_KEY_CHECKS=0/1` on MySQL; `DELETE FROM` per table on SQLite — dual-pathed specifically so its own tests can exercise the real wipe logic without a live MySQL server), deletes `secrets.enc`, and empties `storage/` except `storage/keys/master.key` (the encryption key must survive so existing encrypted backups — including the one just taken — stay decryptable) before recreating the empty directory structure and moving the safety backup's two files back under `storage/maintenance/`. Those files end up orphaned (no `backups`/`files` row survives the truncate to reference them) — retrievable only via FTP, which is an accepted, spec-literal trade-off of "keep the file, not the bookkeeping" for an operation whose entire point is an empty database. The final `event_log` write (`'full_reset_performed'`) is deliberately the first row of the "new" installation's journal, with a `null` `user_account_id` — logging the real requester would violate `fk_el_user`, since `user_accounts` was just wiped. `Core\Maintenance\BackupServiceInterface` exists solely so `FullResetHandler` accepts an injectable fake in tests (`?BackupServiceInterface $backupService = null`, defaulting to a real `BackupService`) — every other Maintenance handler still constructs `BackupService` directly, this is not a general DI abstraction.

`Task\RestoreBackupHandler` ("Restaurer un backup") accepts two sources: an existing `backups` row (`source=server`) or a previously-downloaded backup zip re-uploaded by the admin (`source=upload`, saved to `storage/temp/`, validated by attempting to read `database.sql` from within it before anything else runs, deleted in a `finally` regardless of outcome). `BackupService::restoreFiles()` gained an optional `$password` parameter for this iteration — needed because the user-triggered `full_config`/`full_no_gallery`/`full_with_gallery` backup types (§8.15) are AES-256-encrypted zips, unlike the automatic `auto_update`/`auto_reset`/`database` types, which never are. Like `InstallUpdateHandler`, it takes its own pre-restore safety backup first and automatically rolls back to it (`restoreDatabase()` + `restoreFiles()`) if the restore itself fails at any step.

### 8.19 Retro module (`modules/retro`)

Post-activity "rétrospectives" boards: a chief/intendant (`retro_role_min_create_board` setting, default `intendant`) opens a board manually or lets `Task\AutoCreateRetroHandler` create one automatically at a linked calendar event's start time (opt-in per event, `calendar_events.auto_create_retro`, only when the `calendar` module is enabled — a genuine optional module-to-module dependency, §7.5) — animés then post free-text comments and vote (a fixed per-member vote budget, `retro_default_vote_budget`) without needing an account beyond the same identified-member session used everywhere else. `Task\AutoCloseHandler` closes a board past its configured duration and emails a closing digest; `Task\PurgeRateLimitHandler` clears `retro_rate_limits` rows past their window. `Service\ModerationService` is an optional `llm_connector` consumer (§7.5) with three modes (`retro_moderation_mode`: disabled/warning/enforced) — a flagged comment either posts anyway with a private warning to its author, or is rejected with an AI-suggested, more respectful rewording, degrading silently to "no moderation" when `llm_connector` is disabled or has no active provider. `Api\RetroEventLinkLookupInterface` is retro's own public API (§7.5, module-to-module direction) letting the calendar module resolve an event's linked board without depending on retro's internal classes.

### 8.20 SOS Staff d'U module (`modules/sos_staff`) and scheduler `TaskContext`/`cron.php` fixes

Keeps the unit's single "SOS" emergency phone number always forwarded to whichever Staff d'U member is on duty. `Provider\PhoneProviderInterface` (`readForwardingState()`/`setForwarding()`/`testConnection()`/`listLines()`) is a pluggable-provider abstraction; `Provider\Ovh\OvhTelephonyProvider` + `OvhApiClient` implement it against the OVH Télécom API (request signing, no new Composer dependency — same raw-`curl`-style precedent as `Modules\LlmConnector\Provider\AnthropicProvider`). `Service\OnCallService` builds/saves a month's duty grid (sparse storage — no row means "available") and computes day-to-day forwarding-target transitions, scheduling one `Task\ApplyRedirectHandler` run per actual change via `SchedulerService`; `Service\RedirectService::apply()` is the read-set-confirm-notify sequence, journaling every outcome and alerting the resolved super-admin (`Core\Security\UserAccountRepository::findFirstSuperAdmin()`) on technical failure. `Service\CalendarSyncService` is an optional `calendar` module consumer (§7.5, reuses the module's default public "Animateurs" calendar) that mirrors duty periods as read-only events, degrading to a no-op when `calendar` is disabled.

Building this module surfaced two real gaps in the generic scheduler, fixed at the core level (not worked around): `Core\Scheduler\TaskContext` was never actually constructed in production — neither `public/index.php`'s poor man's cron nor `public/cron.php` called `SchedulerRunner::setTaskContext()`, so any module's real task handler crashed (`RuntimeException('TaskContext not set')`) the moment an overdue task reached it — and `public/cron.php` never built a `ModuleManager` either, so `SchedulerRunner::setModuleManager()` was never called there, meaning every module-registered task silently failed ("No handler registered") whenever run via a real crontab instead of a page visit. Both entry points now build the same `ModuleManager` and `TaskContext` (`TaskContext` also gained `$userAccounts`, needed for the super-admin alert above) before processing overdue tasks.

### 8.21 Public Sections page (`Core\Http\Controller\PageController::sections()`)

Each card on the public "Notre unité > Sections" page (`role_min: public`, same audience reasoning as Contact below) shows the section's staff photo (§8.10), its designated "responsable" name, its email, and a small per-section editable text block reusing `editable()` unchanged (key `"section.{desk_code}.text"`). The "responsable" name comes through `Core\Module\SectionResponsableProvider` (`getResponsable(int $sectionId, int $scoutYearId): ?MemberProfile`) — the same core-hook precedent as `FunctionFlagsProvider`/`HomeBannerProvider` (§7.4/§8.13): core defines the interface, the trombinoscope module implements it (reusing its own "lead" resolution, itself backed by `FunctionFlagsProvider`'s per-function flag), and the composition root wires the concrete instance into `PageController` only when `trombinoscope` is enabled — a section simply shows no responsable name when it isn't. The public Contact page (`PageController::contact()`) similarly shows the Staff d'U section's group photo and a name/totem roster (`SectionService::getSectionStaff()`), both routes deliberately public even though the closest analogous page (`/trombinoscope`) requires `identified`, on the reasoning that a prospective parent/member needs contact information before they've registered.

## 9. Installation / bootstrap

First access without `secrets.enc` → setup page (no auth required, works once). Collects DB credentials, unit settings (including short name ≤5 chars), email config, initial admin email. Same page accessible later from Configuration as normal admin page.

## 10. Database schema management

No incremental migration files. `schema/core.sql` + each module's `schema.sql` = source of truth. Deploy script compares and generates DDL — this diff never drops a column/table it finds in the database but is no longer declared (data-loss safety net), it only warns. The one narrow exception: a sibling `drops.sql` next to a schema file (e.g. `schema/drops.sql`) can declare reviewed `ALTER TABLE <table> DROP COLUMN <column>;` statements — `MigrationRunner::applyExplicitDrops()` runs each only while the column still exists, so it's idempotent and safe on every request. Still not incremental: once applied everywhere, delete the line from `drops.sql`.

## 11. Responsive interface (mobile-first)

Bootstrap 5 compiled files. Mobile-first CSS. Hamburger left, unit name right. Offcanvas from left on mobile, horizontal bar with wrapping sub-menu on desktop. Hybrid "Espace des animés" menu (dynamic member entries + static module pages). 44px touch targets. HTML5 input types.

## 12. Project structure

```
core/
  Http/          Router, Request, Response, FrontController
  Security/      RbacGuard, Session, Csrf, PasswordHasher, Encryption, WebAuthn, Role, SecretManager
  Database/      PDO connection, SchemaIntrospector, MigrationRunner, Connection
  View/          Twig bootstrap, helpers, partials, SectionRepository, EditableContentService
  Mail/          MailService, DkimManager, DnsVerifier
  Module/        ModuleManager, module-into-core hook interfaces (FunctionFlagsProvider, HomeBannerProvider, HomeNewsProvider, SectionResponsableProvider — §7.4)
  Config/        SettingService, ScoutYearService
  Scheduler/     SchedulerService, SchedulerRunner, TaskContext, TaskHandlerInterface
  Journal/       JournalService
  File/          FileAccessGuard, UploadHandler, EncryptedFileStorageService
  Cookie/        CookieConsentService, CookieRegistry
  Member/        SectionService, MemberYearService, UnitStaffSectionService, MemberProfile
  Badge/         BadgeService, MemberBadgeRepository
  Photo/         MemberPhotoService, SectionPhotoService, SectionPhotoProcessor (§8.10)
  Notification/  NotificationService (Web Push)
  Maintenance/   BackupService, VersionFile, GitHubReleaseClient (§8.15–§8.18)
  Import/        Desk CSV import pipeline (§8.1)
  Pdf/           PosterPdfService (A4 poster generation)
  Url/           Generic short-URL service
  Service/       Cross-cutting helpers (e.g. TextNormalizerService)

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
  module-development.md

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
specifications.md
design.md
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
