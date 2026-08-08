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
| aws/aws-sdk-php | S3-compatible object storage backend for the gallery module (`Modules\Gallery\Service\Storage\S3StorageBackend`) — correctly implementing multipart upload, presigned URLs, and provider-specific auth quirks (AWS S3, and S3-compatible providers) by hand is not worth it for one storage backend among several. |
| minishlink/web-push | Web Push (RFC 8030/8291) sending for `Core\Notification\NotificationService`: VAPID ES256 JWT signing, ECDH key agreement, and the RFC 8291 `aes128gcm` payload encryption. Reimplementing this elliptic-curve crypto by hand is real security-sensitive surface area (a subtly wrong HKDF/AEAD derivation silently breaks or, worse, weakens delivery) for a well-defined, narrow protocol a maintained library already gets right. |
| ifsnop/mysqldump-php | Pure-PHP `mysqldump` reimplementation (`Core\Database\DatabaseDumper`, §8.15.1) that dumps over a PDO connection it opens itself — no `mysqldump` binary, no shell-execution function, and none of the shared-hosting failure modes (missing `$PATH`, `disable_functions`, a `libmysqlclient` build that can't load the `mysql_native_password` auth plugin) a shelled-out dump used to hit. |

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

`Core\File\FileAccessGuard` additionally supports owner-scoping, generic to any file (not just one feature): `files.owner_member_id` is a nullable FK to `members.id` (persistent identity, not `member_years.id` — ownership outlives a single scout year); when set, `FileAccessGuard::check()` requires the requesting session's linked member ids (resolved via blind-index email match, same rule as `MemberService::canAccess()`) to include it, on top of the usual `role_min` floor. Deliberately no chief/admin bypass — a higher `role_min` never substitutes for being the actual owner, so an owner-scoped file stays inaccessible to staff who aren't themselves linked to that member. First consumer: member-page private documents (§8.22). `FileController::serve()` journals every successful access to an owner-scoped file (`owner_scoped_file_accessed`, level `info`, `file_id`/`owner_member_id` only — never personal data) — deliberately only owner-scoped ones, so ordinary `/files/{id}` traffic (public assets, other role-gated content) stays unlogged noise-free; denied access to any file (owner-scoped or not) was already journaled as `file_access_denied`.

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

Configuration > Maintenance page (`role_min: admin`, matching the spec's explicit choice even though every other Configuration-menu route in this codebase uses `superadmin`) for FTP/shared-hosting deployments that have no server-level backup mechanism of their own. `BackupService` is a deliberately "pure mechanical" service — no `BackupRepository`/`FileRepository` dependency — reusable by future update/reset iterations, not just this one: `createDatabaseDump()` (full dump, every table structure + data), `createConfigOnlyDump()` (every table's structure, but row data for only a hardcoded whitelist of config-only tables — `settings`, `module_registry` — deliberately narrow rather than a broad "config tables" guess, since module settings all funnel through the generic `settings` table already), `createFileBackup(bool $includeGallery)` (zips `core/`/`modules/`/`public/`/`storage/`, always excluding `storage/keys/`, `storage/config/`, `storage/temp/`), and `createFullBackup(string $scope, string $password)` (combines a dump with a `ZipArchive::EM_AES_256`-encrypted file backup; returns both paths — `array{zipPath, dbDumpPath}` rather than the single path a naive reading of "one backup" might suggest — because the `backups` schema needs a separate `db_dump_file_id`, and bookkeeping/registration is deliberately left to the caller). Both dump methods go through `Core\Database\DatabaseDumper` (`Ifsnop\Mysqldump\Mysqldump`, a pure-PHP mysqldump reimplementation that dumps over PDO) rather than shelling out to a `mysqldump` binary — see §8.15.1. `supportsZipEncryption()` self-tests the runtime's libzip crypto support with a throwaway zip so the UI can fail cleanly (disable the full-backup option with an explanatory message) on environments without it, rather than generating a silently-unencrypted archive.

#### 8.15.1 Database dumping without a `mysqldump` binary (`Core\Database\DatabaseDumper`)

Every database dump in this codebase — `BackupService`'s two dump methods above, and `MigrationRunner`'s pre-migration safety backup (§3, `attemptBackup()`) — used to shell out to the `mysqldump` CLI via `Core\System\ShellExecutor`/`ExecutableLocator`. That broke on a real production host (scoutmagic.be, LWS shared hosting): its `mysqldump` client build couldn't load the `mysql_native_password` authentication plugin (`Got error: 2059`), even though PHP's own PDO connection to the exact same MySQL server worked instantly — PDO's `mysqlnd` driver doesn't depend on that plugin at all, only the separate `libmysqlclient`-based CLI tools do. No combination of `disable_functions`/`$PATH` workarounds fixes a missing shared library, so the shelled-out approach was replaced entirely rather than patched further.

`Core\Database\DatabaseDumper::dump(host, port, dbName, user, password, outputPath, ?skipDataForTables)` wraps `ifsnop/mysqldump-php` (`Ifsnop\Mysqldump\Mysqldump`), a pure-PHP mysqldump reimplementation that connects and dumps entirely over a PDO connection it opens itself from the given DSN — no shell, no external binary, no `disable_functions`/`$PATH`/auth-plugin failure modes at all. `$skipDataForTables` is the library's `'no-data'` setting given a table list instead of `true`: every table's structure is always dumped, but row data is skipped for the tables named — used by `BackupService::createConfigOnlyDump()` to keep every table's schema while excluding member/business data (it computes "skip data for every table except the config whitelist" via `SchemaIntrospector::getTables()` + `array_diff()`). `'add-drop-table' => true` is set explicitly to match real mysqldump's default `--opt` behavior, so a dump can be restored over an already-migrated schema without "table already exists" errors.

Restoring a dump (`BackupService::restoreDatabase()`) still shells out to the `mysql` CLI client via `ExecutableLocator`/`ShellExecutor` — out of scope for this fix, since it's a different binary with a different (PDO-independent) failure mode, and restores are comparatively rare, admin-triggered, error-visible operations rather than something that silently degrades every page load the way the old daily-polling `mysqldump` backup did.

A full backup runs as a background scheduled task (`core`/`create_backup`, `CreateBackupHandler`, registered directly in `public/index.php` rather than through a module's `scheduled_tasks` — the first core-level, non-module task handler) so a large gallery-inclusive zip doesn't block the request: the controller encrypts the user-supplied password with `EncryptionService` and embeds it (base64) in the scheduled task's payload, decrypted only inside the handler right before use. On completion the handler registers both files via `FileRepository` (`role_min: 'admin'`, unencrypted-at-rest since the zip's own AES-256 password is the protection mechanism here, deliberately not layered with `EncryptedFileStorageService`), purges backup rows beyond the 5 most recent (deleting their files too), and notifies the requesting admin via `Core\Notification\NotificationService` (`$context->notifications`) — reusing `requested_by_user_account_id` (§8.16) to know who to notify without the payload needing to carry it explicitly. The Maintenance page polls `GET /api/maintenance/backup-status/{id}` (same `setInterval` pattern as the magic-link login poll) until the task completes or fails.

A separate, unencrypted `auto_backup` type (`Task\AutoBackupHandler`) covers recurring, unattended backups — frequency (`none`/`daily`/`weekly`/`biweekly`/`monthly`, default `monthly`) is a `SettingService` key editable from the same page, gallery always excluded (no admin present to opt into it), no push notification (no human requester). It self-reschedules at the end of every run rather than being a first-class recurring task — same pattern as `Modules\LlmConnector\Task\RefreshModelsHandler`'s weekly refresh, since `Core\Scheduler` has no first-class recurring-task concept — re-reading the frequency setting each time so a change takes effect on the next run. Shares the same 5-backup quota as every other type via `BackupRepository::findBeyond()`.

### 8.16 `requested_by_user_account_id` on scheduled tasks (`Core\Scheduler`)

`scheduled_actions` carries a nullable `requested_by_user_account_id` FK, and `SchedulerService::schedule()`/`scheduleAfter()` accept it as a trailing optional param. `SchedulerRunner::processOverdue()` always merges it into the payload under the reserved key `requested_by_user_account_id` (int or null) before calling a handler, regardless of what the original caller put in the payload — so any `TaskHandlerInterface::handle()` can read who (if anyone) asked for a given run without every caller having to thread it through its own payload shape by convention. Introduced for backup completion/failure notifications (§8.15) but generic to any future "notify the person who asked for this" scheduled task.

### 8.17 Update from GitHub (`Core\Maintenance`, "Mise à jour")

Extends §8.15's Configuration > Maintenance page for FTP/shared hosting with no SSH/Git/Composer: `VersionFile` (`Core\Maintenance\VersionFile::read()`/`write()`) reads/writes a plain `VERSION` file at the project root — the installed-version source of truth, deliberately a filesystem fact rather than a database row, since a fresh deploy must be able to report its version before the DB is even configured. `scripts/release.sh` commits this file as part of every tagged release so it always matches the release artifact it ships inside.

`Core\Maintenance\GitHubReleaseClient` (behind `GitHubReleaseClientInterface`, so callers are testable without the network) is a minimal unauthenticated REST client — same `file_get_contents()`/`stream_context_create()` approach as `Modules\LlmConnector\Provider\AnthropicProvider`, the only other outbound-HTTP precedent in this codebase, no new Composer dependency. `GET /repos/{owner}/{repo}/releases/latest` already excludes drafts and prereleases on GitHub's side. The owner/repo are `Core\Config\SettingService` keys (`update_github_owner`/`update_github_repo`), never hardcoded, so a fork can point at its own repository.

Version detection is primarily **webhook-driven**, not polled: `POST /api/webhook/github` (`Core\Http\Controller\WebhookController`, the only `role_min: 'public'` route in the codebase with no CSRF check at all — GitHub is a machine caller with no session, so a shared-secret HMAC-SHA256 signature, `X-Hub-Signature-256`, verified in constant time via `hash_equals()`, is what authenticates it instead) replaces an earlier daily-polling task handler entirely. The stable channel (patch/minor/major) additionally has a daily fallback poll (`Task\CheckStableUpdateHandler`, below) in case a webhook delivery is missed or never configured — development mode has no such fallback, since it has no equivalent "poll for a new commit" cadence worth reusing. The signing secret is `random_bytes(32)` hex, generated/regenerated from the Maintenance page (`MaintenanceController::generateWebhookSecret()`) and stored **only** in `secrets.enc` (`Core\Security\SecretManager`) — shown to the admin exactly once, in the generation response, never persisted anywhere it could be read back, and never in `Core\Config\SettingService` (a `settings` row would leak it, even dimmed, to any superadmin on the generic Paramètres page). An invalid or missing signature journal-logs `github_webhook_signature_invalid` at `level: 'security'` and returns 403; every other outcome — including an event this site doesn't act on — returns 200, since GitHub retries on non-2xx and there's nothing to retry for an intentionally-ignored event.

`Core\Maintenance\GitHubWebhookService` handles the two events the webhook cares about. A `release` event (`action: 'published'`) always refreshes the same `SettingService` cache the old polling handler used to (`update_latest_version`/`update_release_notes`/`update_release_html_url`/`update_download_url`/`update_checked_at`/`update_dependencies_changed`, the last via the same `GET .../compare/{installed}...{latest}` composer.lock check, "assume changed" on any compare failure) so `MaintenanceController::index()` keeps working unchanged — then, only if `auto_update_enabled` is on, `auto_update_level` isn't `'dev'` (development mode, below), and the release's version-bump type (`patch`/`minor`/`major`, derived by comparing dotted version components) is within that level, schedules `Task\InstallUpdateHandler` (`core`/`install_update`, `reference: 'scheduled_install'` so a newer release arriving before the slot fires cancels and replaces it) for the next occurrence of the configured weekday+time (`auto_update_day`/`auto_update_time`) — pushed a full week out if that occurrence is under 5 minutes away, so the webhook's own arrival time can never coincide with an immediate install. A `push` event only acts when `auto_update_enabled` is on and `auto_update_level === 'dev'`, and the pushed branch matches `dev_update_branch`: it schedules an **immediate** install (`scheduleAfter(..., 0, ...)`, no weekly slot, no level gate) from the pushed commit's zipball (`https://api.github.com/repos/{owner}/{repo}/zipball/{sha}`), with `update_history.version_to` set to `dev-{7-char short sha}` rather than a release tag. Neither path has a human requester (`requested_by: null`, §8.16) — a webhook-triggered install never sends a push notification, only journal entries (`auto_update_scheduled`, `level: 'info'`).

Development mode is a 4th `auto_update_level` value (`'dev'`, alongside `patch`/`minor`/`major`) selected from the same "Mises à jour automatiques" radio group and gated by the same top-level `auto_update_enabled` switch — not a separate danger-zone toggle with its own confirm-keyword flow, as an earlier iteration had it. That extra friction never added real protection: configuring the GitHub webhook at all already requires repo admin rights, so the confirm-keyword step was redundant. Selecting `'dev'` swaps the "Moment d'installation" (day/time) fields for a "Branche à surveiller" text field (`dev_update_branch`), since installs happen immediately per-commit rather than on a schedule. While `'dev'` is selected, a published release is deliberately never auto-installed (`GitHubWebhookService::handleReleaseEvent()` short-circuits when `auto_update_level === 'dev'`) — development mode and the stable weekly-slot path are mutually exclusive by construction of the radio group, not merely by convention.

All 5 of these settings (`auto_update_enabled`/`auto_update_level`/`auto_update_day`/`auto_update_time`/`dev_update_branch`) are real `SettingService` rows (so `ResetSettingsHandler`/§8.18 resets them like anything else) but are filtered out of `SettingsController::index()`'s generic Configuration > Paramètres rendering via a small hardcoded exclusion list — that page's plain editable-row UI has no room for the semver explainer or webhook status block this section needs, so it's managed exclusively from the Maintenance page instead.

`POST /config/maintenance/update/check-now` (`MaintenanceController::checkForUpdatesNow()`) is an on-demand equivalent of the webhook for an admin who doesn't want to wait — since detection is otherwise purely webhook-driven with no polling fallback. It checks whichever channel `auto_update_level` currently selects: `GitHubReleaseClient::getLatestRelease()` for the stable channel (also refreshing the same settings cache the webhook would, via `setInternal()`, since these are non-editable rows), or the new `GitHubReleaseClient::getLatestCommit(branch)` (`GET /repos/{owner}/{repo}/commits/{branch}`, returning a `CommitInfo` DTO — `sha`/`message`/`htmlUrl` — mirroring `ReleaseInfo`'s shape) for the development channel. Never schedules an install itself; the page shows a confirm dialog and, if accepted, calls the existing `installUpdate()` endpoint. That endpoint now branches the same way: the stable-channel path is unchanged (re-validates from the settings cache), while the dev-channel path (`installDevBranchUpdate()`) re-fetches the branch's current head itself rather than trusting anything the client echoed back from the check-now response — the same "never trust the client's idea of the target version" principle applied to a source that has no settings cache to re-validate against — then schedules exactly the same immediate/no-slot/no-level-gate install `GitHubWebhookService::handlePushEvent()` would for that commit.

`Task\InstallUpdateHandler` (scheduled either by `MaintenanceController::installUpdate()` for a manual install — which re-validates server-side that a newer version is actually cached before scheduling, never trusting the client — or automatically by `GitHubWebhookService` above) walks `update_history.status` through `backing_up` → `downloading` → `installing` → `migrating` → `completed`, each transition persisted so the polling route (`GET /api/maintenance/update-status/{id}`, same pattern as `backup-status`) can report progress. The safety backup (step 1, `Core\Maintenance\BackupService::createDatabaseDump()` + `createFileBackup(true)`, registered as a `backups` row with `type='auto_update'`) is not a shortcut — it is the only thing an automatic rollback can restore from, so any throwable from downloading through the `VERSION` write triggers `BackupService::restoreDatabase()` + `restoreFiles()` against that exact backup, and `update_history.status` becomes `rolled_back` rather than `failed`. A throwable during the backup step itself cannot be rolled back (nothing was changed yet). The "installing" step copies every top-level entry of the extracted artifact over the live install except `storage/` (all live uploads/keys/config/temp) and `VERSION` (written separately, last, only once everything else succeeded) — nothing else needs excluding for a release artifact, since unit-specific files (`config/app.php`, `.env`) were already excluded from the artifact itself by `scripts/release.sh`. A branch install (`payload['source_type'] === 'branch'`) has one extra step first: GitHub's branch/commit zipball always wraps its contents in a single top-level `{owner}-{repo}-{sha}/` directory (unlike `scripts/release.sh`'s artifact, `zip -r artifact.zip .`, which has none), so `resolveBranchArchiveRoot()` descends into that one directory before the install copy runs — gated strictly on the explicit `source_type`, never inferred from entry count alone, so a release artifact is never mistakenly stripped even if it coincidentally had one top-level entry. **A real production incident, found and fixed**: `Core\View\TwigFactory` compiles templates to `storage/temp/twig_cache` with `auto_reload` off outside debug mode, so it never re-checks a compiled template's freshness against its `.twig` source — and since `installFiles()` deliberately never touches `storage/` (live uploads/keys/config), a template-only change installed correctly to disk but the server kept executing the pre-update compiled version indefinitely, with no visible sign anything had failed. `clearCompiledTemplateCache()` now deletes `storage/temp/twig_cache` immediately after `installFiles()` on every install, release or branch; `TwigFactory` recreates the directory lazily on the next request. The "migrating" step reuses `Core\Database\MigrationRunner` unchanged against the newly-installed `schema/core.sql` — no separate update-specific migration logic.

`public/cron.php` (the real crontab entry point) was fixed in an earlier iteration: it was missing `create_backup`'s handler registration entirely (background backups silently failed with "No handler registered" whenever a real cron ran them instead of the poor-man's-cron in `public/index.php`) and never constructed a `NotificationService` at all (so push notifications from any background task never fired via real cron). Both are wired identically to `public/index.php`. There is no longer a `check_update` handler to keep in sync between the two entry points, since the webhook replaced it — but `check_stable_update` (below) is a new one that must be, and is.

`Task\CheckStableUpdateHandler` (`core`/`check_stable_update`, `reference: 'daily'`) is the stable channel's webhook fallback: each run, if `auto_update_enabled` is on and `auto_update_level !== 'dev'`, it calls the new `GitHubWebhookService::checkForNewRelease()` — a live `GitHubReleaseClient::getLatestRelease()` lookup that delegates to the same private `processRelease()` the webhook's `handleReleaseEvent()` uses internally, so both paths refresh the settings cache and schedule installs identically; the only difference is where the release info comes from (a webhook payload vs. a direct API call). A failure (GitHub unreachable, rate-limited, etc.) is caught and journaled (`auto_update_check_failed`, `level: 'info'`) rather than left to crash the scheduler run. It then unconditionally self-reschedules for the next day at 01:00 plus a fresh `random_int(0, 3600)`-second jitter — spreading every installation's daily API call across an hour rather than every site on earth polling at exactly the same second — using the same "no first-class recurring task" pattern as `Task\AutoBackupHandler`/`Modules\LlmConnector\Task\RefreshModelsHandler`: re-check via `SchedulerService::find('core', 'check_stable_update', 'daily')` before scheduling, so a run that happens to overlap an already-pending future occurrence never duplicates it. `public/index.php` seeds the very first occurrence immediately at bootstrap (same one-time `if find() === null` pattern as the other self-rescheduling tasks), so a fresh install starts checking without waiting a full day for the first jithttered 01:00 slot.

The "Détection des nouvelles versions" webhook status block on the Maintenance page (config/webhook-not-configured warning included) is now shown **only** while `'dev'` is selected — the stable channel no longer depends on the webhook being configured at all, thanks to this daily fallback, so surfacing that warning outside development mode would be misleading noise. `MaintenanceController::index()`'s `webhook_warning` computation reflects this: `$autoUpdateEnabled && $level === 'dev' && !$webhookConfigured`.

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

### 8.22 Member page ("Espace des animés", `Core\Member\MemberPageService`)

`Core\Http\Controller\MemberController::show()` (`/members/{id}`, `role_min: identified`, further scoped by `MemberService::canAccess()` — chief/admin or the member themselves) orchestrates only; every data need is built by `Core\Member\MemberPageService::buildPageData()`, a per-request core service with four nullable optional-module dependencies (§7.5 pattern): `Modules\MassMail\Api\MassMailQueryInterface`, `Modules\Gallery\Api\GalleryAlbumProvider`, `Modules\Calendar\Api\CalendarEventLookupInterface` (reused as-is — no new interface — for "next upcoming event"), and `Core\Module\SectionResponsableProvider` (§7.4, same trombinoscope-backed hook as the public Sections page, §8.21). Each corresponding page block degrades to "not displayed" when its dependency is null.

The page's own section is resolved from the member's main function's Desk code (`SectionService::findByDeskCode()` — `MemberFunctionInfo` carries only a Desk code, not a numeric id). The section's responsable is shown via a dedicated `full_name` Twig filter (first + surname, deliberately never `display_name`/totem) together with a real postal address — `SectionResponsableProvider` implementations are built on `SectionService::hydrateMemberProfile()`, which never loads addresses, so `MemberPageService` re-resolves the responsable's full profile via `MemberService::findProfileByMemberAndYear()` specifically for this need. "Référent {section}" badge holders are Staff d'U members, not members of the target section, so they can't be found through `SectionService::getSectionStaff($sectionId)` — `MemberBadgeRepository::findMemberYearIdsForBadgeAndYear()` looks them up directly by badge id.

**Photo, replaceable by the member outside configuration mode**: `member_photo()` (TwigFactory) takes an explicit `bool $editable` parameter — `config_mode` remains the trigger at every other call site, this page is the only one that also passes `is_self`. The real authorization boundary is server-side, in `UploadController::isUploadAuthorized()`: the `member_photo` context is allowed either through configuration mode (unchanged) or when the requesting account is linked to the member the upload key names (`MemberService::isLinkedToMember()`, blind-index email match) — no chief/admin bypass. `/upload`'s route `role_min` was loosened from `superadmin` to `identified` to let this through; every other context (`editable_image`, `section_photo`, the new `age_branch_logo`) keeps its own explicit check in that same method, so the loosened route floor changes nothing for them. The upload always lands on the *current* scout year even when the displayed photo came from `MemberPhotoService`'s own earlier-year fallback, because the key `member_photo()` builds for the editable overlay uses `effective_scout_year_id`, never the year the fallback actually resolved.

**Branch card** (right column): the member's age branch's federation logo + explanation link, configurable per branch from Configuration > Config Desk (`age_branches.logo_file_id`/`explanation_url`, auto-migrated core-schema columns). Fallback order: uploaded (`role_min: public`, generic `/upload` flow, new `age_branch_logo` context) → shipped default under `public/assets/img/branches/`, matched via `AgeBranchRepository::defaultLogoFilename(sortOrder)` (keyed off `canonicalSortOrder()`, never a free-text label comparison — Staff d'U/Iama/unknown branches have no default) → nothing (no empty box, same principle as `section_photo()`). The explanation link itself always renders even with no logo at all.

**Private documents** (self only, no staff bypass): `core.sql`'s `member_documents` table (member id, scout year, title, `file_id`) is metadata-only — `Core\Member\MemberDocumentService`/`MemberDocumentRepository` just list rows; the actual access boundary is `files.owner_member_id` enforced by `FileAccessGuard` (§8.3). Storage/listing only this iteration — no generation, no admin upload UI yet.

**Mass-mail email detail**: `MassMailQueryInterface::getRecentEmailsForMember()` now returns each recipient's `id` alongside subject/date/section, and a new `findEmailDetailForMember(memberId, recipientId): ?array` resolves the full subject+body. The detail route itself (`GET /members/{id}/emails/{recipient_id}`) lives in the mass_mail module (`Modules\MassMail\Controller\MemberEmailController`, declared in `module.json`), not in core's `MemberController` — the content is entirely the module's own data; core only ever links to it via the recipient id `MemberPageService` already exposes. The controller takes core's `MemberService` directly (module→core dependencies need no interface indirection, unlike the reverse) to re-verify server-side that the requesting account is chief/admin or the member themselves, on top of `findEmailDetailForMember()`'s own re-check that the recipient row really belongs to this member.

### 8.23 Installable PWA — app shell only (`Core\Photo\PwaIconProcessor`/`PwaIconService`, `Core\Http\Controller\PwaController`, `public/sw.js`)

Lot 1: the site installs to the home screen on Android/iOS and opens standalone, offline shows a fallback page instead of the browser's own error — no push notifications, no content caching yet (those are later lots). `PwaController::manifest()` (`GET /manifest.webmanifest`, `role_min: public`) builds every field from `SettingService` — `pwa_theme_color`/`pwa_background_color` (new, editable, defaults `#0d6efd`/`#ffffff`) alongside the existing `site_name`/`short_name` — never a hardcoded unit name, per §1. `start_url` is `/?source=pwa`; `display: standalone`; the `icons` array lists only 192/512/512-maskable (each `?v={version}` for cache-busting) — the 180px apple-touch icon is a separate `<link>` in `base.html.twig`'s `<head>`, not part of the manifest.

**Icons live outside `Core\File\FileRepository` entirely.** A manifest/home-screen icon is fetched with no session at all, so SECURITY §6's session/role-aware `/files/{id}` single-download-path rule doesn't apply — and storing them under `public/` (SECURITY §6's usual rule for anything session-gated) isn't the concern here either, the actual reason is keeping an admin-uploaded asset out of the codebase's own tree. `Core\Photo\PwaIconService` has no DB row and no `files` table entry: four fixed filenames (`icon-192.png`/`icon-512.png`/`icon-512-maskable.png`/`icon-180.png`) under `storage/core/pwa/` (git-ignored, `storage/core/` already covered) are the entire state — "has a custom icon" is `is_file()`. `GET /pwa/icon-{size}.png` (`role_min: public`) serves that override if present, else the shipped default under `public/assets/img/pwa/`, with `Cache-Control: public, max-age=31536000, immutable` — the version query string, not the header, is what actually invalidates a stale copy once a superadmin uploads a new logo (`pwa_icon_version`, a non-editable `SettingService` row bumped by `storeUploadedLogo()`).

`Core\Photo\PwaIconProcessor` (GD, same before-storage precedent as `SectionPhotoProcessor`, §8.10) center-crops the source to a square, then derives all four sizes in one pass: 192/512/180 are plain alpha-preserving resizes, and 512-maskable follows the W3C maskable-icon convention — the logo is scaled to 80% of the canvas and centered on an opaque `pwa_background_color`-filled canvas, so Android's own circular (or any other) mask can crop up to ~20% off each edge without cutting into the logo. `UploadController`'s `pwa_icon` context (superadmin-only, its own explicit branch in `isUploadAuthorized()`) short-circuits before the generic upload pipeline — it never touches `UploadHandler`/`FileRepository`, calling `PwaIconService::storeUploadedLogo()` directly instead.

`public/sw.js` is hand-written (~100 lines, no Workbox, no build step — ARCHITECTURE's existing "only three external dependencies are justified" stance extends to this) and must stay at the web root (`/sw.js`) to control the whole site; moving it under `/assets/` would silently scope it to that subtree. It precaches an explicit whitelist only — Bootstrap, the site's own CSS/JS, the four icons, `/offline` — cache-first for exactly those, network-only (including every `/files/{id}` download and every authenticated page) for everything else. The one behavior beyond that: a failed navigation falls back to the cached `/offline` page, which is airplane-mode's actual payoff. Versioning is entirely the query string: `base.html.twig` registers `/sw.js?v={{ app_version }}` (`Core\Maintenance\VersionFile`, the same file §8.17's install writes as its last step) with `updateViaCache: 'none'`; the cache name derives from that same value, so a release bump makes the browser see a byte-different worker, which installs and its `activate` handler deletes every cache not matching the new name. `offline.html.twig` deliberately does not extend `base.html.twig` — it's precached once and can be served back much later with zero network, so nothing session/DB-derived (`menus`, `current_user_*`, `config_mode`) may ever be baked into it; it's a fully standalone document linking only already-precached CSS.

CSP (SECURITY §9) gained `manifest-src 'self'; worker-src 'self';`. The app-shell cache is declared in `Core\Cookie\CookieRegistry` (`necessary`, no consent gate — that arrives with actual content caching in a later lot) even though it's Cache Storage, not an HTTP cookie, so the cookie preferences page/banner stay a complete accounting of the site's local storage footprint.

### 8.24 Notification centre and Web Push, Lot 2 (`Core\Notification`)

A type registry (parallel to `Core\Cookie\CookieRegistry`) — core types in `Core\Notification\NotificationRegistry::getCoreTypes()`, module types declared under `module.json`'s `notifications` section (id prefixed `"{module_id}."`, validated by `Core\Module\ModuleManifest::validateNotification()`) and aggregated into the same registry by `Core\Module\ModuleManager::loadModule()` → `NotificationService::registerModuleTypes()`. Each type declares a `role_min` and a `channels` object (`in_app`/`push`/`email`, each `on`/`off`/`default_on`/`default_off` — `on`/`off` are locked, ignoring any member override entirely). See `docs/module-development.md`'s Notifications section for the module-author-facing contract.

`NotificationService::dispatch($typeId, $recipients, $payload, $actorUserAccountId)` is the send pipeline every declared type goes through: re-checks each recipient's *current* role against the type's `role_min` (never trusting the role at whatever moment the caller built the list — degrades to "always allowed" only when constructed without the optional `RoleResolver`/`ScoutYearService`, a documented narrow-unit-test escape hatch, never the real composition root), always creates the in-app `notifications` row even when the recipient's `push` channel is off, and never pushes to `$actorUserAccountId` (the row still appears in their own centre). Push is never sent synchronously — recipients are grouped by their quiet-hours-adjusted target send time into one `core/send_notifications` scheduled task per distinct time (not per recipient), processed by `Task\SendNotificationsHandler` under a 20-second budget that reschedules any remainder immediately. The older, simpler `notify($userAccountId, $title, $body, $url)` (single recipient, immediate, no role/channel/quiet-hours resolution, writes under an internal undeclared `core.system` type id) is kept unchanged for a handful of pre-Lot-2 Maintenance task handlers (reset/restore/update) that were never part of this lot's scope — new callers use `dispatch()`.

**Quiet hours and discretion mode**: per-account override (`user_accounts.quiet_hours_start`/`_end`, both-or-neither) else global `SettingService` defaults (22:00–07:00); `resolvePushRunAt()` returns "now" outside the window or the window's own end instant otherwise (handles overnight wrap). Discretion mode (`user_accounts.notification_discretion`) substitutes a generic push title/empty body at push-composition time only — the stored `notifications` row and the in-app centre always show the real text.

**Web Push**: reuses `minishlink/web-push` (kept over hand-rolling RFC 8291/VAPID crypto — see the dependency table in §1) — `WebPush::queueNotification()`/`flush($batchSize)` gives the spec's "batches of ~20" without hand-rolled `curl_multi`. `MessageSentReport::isSubscriptionExpired()` (404/410) prunes dead `push_subscriptions` rows; both `endpoint` and the subscription keys are `BLOB`-encrypted at rest, looked up via a blind index (`endpoint_blind_index`) same as every other encrypted-lookup field in this codebase.

**Migration safety**: `notifications.type_id` (new `NOT NULL`) and `push_subscriptions.endpoint_blind_index` (new `NOT NULL`) can't be backfilled from Iteration 1's plaintext rows, so `public/index.php` runs a one-time `TRUNCATE` of both tables (gated by a `notifications_v2_migrated` setting checked via raw PDO, before `SettingService` exists at that point in bootstrap) immediately before the schema migration — accepted as safe since both tables are purely ephemeral operational state, never a legal record.

**Service worker consolidation (a real pre-existing bug, found and fixed while building this)**: Lot 1's `public/sw.js` (§8.23) and an earlier, separate `public/sw-push.js` (registered independently by `push-notifications.js`) both resolved to the same default scope and silently fought for control of every page — whichever registered last won, meaning either push or the offline app-shell cache was non-functional depending on script execution order. Fixed by merging push/notificationclick/`navigator.setAppBadge()` handling into the single `sw.js` and deleting `sw-push.js`; `push-notifications.js` now just awaits `navigator.serviceWorker.ready` instead of registering its own worker.

**Surfaces**: an avatar badge (server-rendered `unread_notifications_count` Twig global, refreshed by `notification-badge.js` via 60s polling plus an immediate refresh on the service worker's `postMessage({type: 'push-received'})`), the app-icon badge (`navigator.setAppBadge()`, called only from the service worker's own `push` handler — the only place that can update it while the installed app is closed), the notification centre (`/notifications`, grouped by day), a preferences page (`/notifications/preferences`, per-type channel toggles — the push column starts disabled until `Notification.permission === 'granted'`, and this page never itself calls `requestPermission()`, only "Mon compte" does), and a superadmin config page (`/config/notifications` — device count, VAPID rotation which disconnects every existing subscription, a test-send button, and a "no real cron detected" warning driven by a new `cron_last_run` setting updated only by `public/cron.php` itself, distinct from the pre-existing `scheduler_last_run` which `index.php`'s poor-man's-cron updates on every web hit and is therefore not a reliable signal of real crontab configuration).

**Retention**: `Task\PurgeNotificationsHandler` (daily, self-rescheduling) deletes only *read* notifications older than a configurable `notifications_retention_days` (default 90) — unread notifications are never auto-purged regardless of age.

**Magic link, same lot**: the login page's confirmation poll now also re-checks immediately on `visibilitychange` (`auth.js`) rather than relying solely on its 3s `setInterval`, which a backgrounded tab (especially an installed PWA) can have throttled or fully suspended by the browser/OS while the user is away confirming in their mail client. `Core\Security\SessionManager` also moved from a session-only cookie (`cookie_lifetime: 0`, wiped on every browser/app close on many mobile platforms) to a 30-day cookie with a matching `gc_maxlifetime`, so an installed app no longer demands a fresh magic link every few days.

**Early session lock release**: `public/index.php` calls `session_write_close()` right after `SecretManager`/`EncryptionService` are built — before the DB connection and schema migration — rather than at the very end of the request. PHP's default file-based session handler holds an exclusive lock on the session file for as long as the session stays open; a slow request (a schema migration on first run after a real change, at minimum) previously held that lock for its full duration, and every other request from the same browser (another tab, a background fetch, the next click) would queue at `session_start()` for that entire time — a real production incident on MariaDB-backed hosting, traced to `SchemaComparator` never reaching a "nothing to do" steady state (see MariaDB-specific type/default normalization in `Core\Database\ColumnDefinition`/`SchemaComparator`) compounding with this lock into requests that looked completely stuck. After the early close, `session_status()` reports `PHP_SESSION_NONE` for the rest of the request, but `$_SESSION` itself stays populated and readable in memory — only *persisting a write* needs the session reopened. `Core\Security\SessionStore` is the single place that touches `$_SESSION` for writes/removals (`get()`/`set()`/`remove()`/`ensureWritable()`), reopening internally on every write so no call site has to remember the guard — application code should never write to `$_SESSION` directly. This replaced an earlier pattern of repeating `if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }` at each of ~15 call sites across `AuthSession`/`CsrfGuard`/`FlashMessage`/`WebAuthnService`/`ConfigurationMode`/`ScoutYearSession`/`SetupController`, several of which were missed on the first pass (`CsrfGuard::validateToken()`/`FlashMessage::get()` were still gating their *reads* on `PHP_SESSION_ACTIVE`, which — now that the session is closed by the time dispatch reaches them on every real request — made CSRF validation and flash messages fail unconditionally; `WebAuthnService`/`SetupController` had writes with no guard at all, silently dropped instead of persisted).

### 8.25 Offline content caching, Lot 3 (`Core\Offline`, `public/sw.js`)

Extends Lot 1's app-shell-only service worker to a whitelisted set of content pages, network-first with cache fallback. `Core\Offline\OfflineWhitelist::getPaths()` is the single server-side declaration (public pages, `/calendar`, `/notifications`, `/trombinoscope` — never `/files/{id}`, never the member's own page, finance, member documents, or mass-mail content) — exposed as a Twig global and serialized into a `#offline-config-data` JSON blob in `base.html.twig` (`type="application/json"`, needs no CSP nonce since it never executes), consumed on both ends: `public/assets/js/offline-nav.js` greys out and makes inert any on-page link outside the whitelist while `navigator.onLine` is false (re-checked on `online`/`offline` and `visibilitychange` — an installed app resumes from a frozen state with a stale connectivity assumption), and `public/assets/js/offline-cache.js` relays the same data to the service worker via `postMessage({type: 'offline-config', ...})` on every page load. Never a second, hand-maintained copy of the list in JS.

**Activation**: gated entirely on `functional` cookie consent (`Core\Cookie\CookieRegistry`'s new `content-{accountScope}-{version}` entry) — no separate on/off setting. The service worker persists the delivered config (staleness days, consent flag, whitelist, account scope, app version) in its own small Cache Storage entry (`offline-config`, one synthetic `Response` holding JSON) rather than a plain JS variable, since the browser can terminate and restart the worker between the `postMessage` that delivers it and the next `fetch` event that needs it — no new browser API, just the same Cache API already used for the app shell.

**Cache naming and scope**: `content-{accountScope}-{version}`, where `accountScope` is the signed-in `user_accounts.id` or `'guest'`. A different member logging in on the same device gets a different cache name outright; `Task\PurgeNotificationsHandler`-style residue avoidance goes further — logout (`offline-cache.js`, hooking every `form[action="/logout"]`) and withdrawing functional consent (the `/cookies` preferences form and the consent banner's "reject all" button, both in `cookie-consent.js`/`offline-cache.js`) send an explicit purge, deleting every `content-*` cache outright rather than just letting the old scope go unused. Withdrawal specifically sends a full `offline-config` update with `consent: false`, not just a bare purge message — a bare purge alone would empty the caches but leave the worker's *stored* config still saying consent was granted, so it would start writing to the cache again on the very next whitelisted page visited in the same session.

**Staleness**: `offline_cache_staleness_days` (`SettingService`, default 7, `core`-level not module-level) — read into the same config blob, never hardcoded in `sw.js`. On a cache-fallback hit, the worker compares `Date.now()` against the cached `Response`'s own `Date` header (the timestamp the page was actually fetched, not when it's being served); past the threshold it serves the generic `/offline` page (Lot 1) instead of a plausible-but-wrong schedule. The same `Date` header is also read to build the "Version hors ligne du {day} {month}, {H} h {MM}" banner injected via a `<body>`-tag string replace on cache-hit responses — a real sentence at the top of the page, not a badge, and injected by the worker (not server-rendered) since the identical HTML is served whether the request succeeds live or is replayed from cache, and only the worker knows at serve time which one this is.

**A redirected response is never cached** (`response.redirected` check before `cache.put()`): an identified-only whitelisted page (`/notifications`, `/trombinoscope`) hit with a stale/expired session bounces to `/login`, and `fetch()` follows that redirect transparently — caching the result under the *original* whitelisted URL would silently serve the login page back as if it were the real content the next time it's read from cache while offline.

**Staff photos**: the trombinoscope's offline pre-download needs faces, not initials, for every section (not just the viewer's own) — but `/files/{id}` is never cacheable (SECURITY §6). `Core\Photo\StaffThumbnailProcessor` (mirrors `SectionPhotoProcessor`'s GD crop/orientation logic, §8.10) derives a square ~160px WebP on demand from a member's already-stored photo — never persisted server-side, same "cheap enough on demand, long `Cache-Control`" precedent as `FileController::thumbnail()`. `GET /api/offline/photo-manifest` and `GET /api/offline/photo/{member_id}` (`Core\Http\Controller\OfflineController`, both `role_min: identified`) are deliberately distinct routes from `/files/{id}` — the one, narrow, explicitly-documented exception to "every download goes through `FileAccessGuard` via `/files/{id}`," confined to one identified, low-sensitivity resource (staff faces already visible to any identified member via `/trombinoscope`) and not a precedent for bypassing the guard elsewhere. Both routes still gate on `Core\Module\StaffDirectoryProvider` (new core-hook interface, same §7.4 pattern as `SectionResponsableProvider` — implemented by the trombinoscope module, wired nullable so a disabled module degrades to an empty manifest) *and* re-run `FileAccessGuard::check()` on the underlying photo file — a member id that isn't currently eligible staff can never be used to fetch an arbitrary member's photo through this narrower route. Pre-download timing: first launch of the installed app, or (if no one is logged in yet) deferred to first login — never fired anonymously.

### 8.26 Scout year transition (`Core\ScoutYear`, Espace admin)

Annual transition from one scout year to the next through a guided 4-step workflow (`Core\Http\Controller\ScoutYearController`, `/admin/scout-year`, `role_min: admin`). Three year types exist simultaneously: **public year** (the year visible to identified members and public visitors), **staff year** (optional override for chiefs/intendants, letting staff prepare the next year while members see the current year), and **preview year** (session-only override for admins to view any year).

`Core\ScoutYear\ScoutYearResolver::getEffectiveYear()` determines which year a request sees, in precedence order: preview year (if set in session via `ScoutYearSession::setPreview()`) → staff year (if role is chief/intendant and `ScoutYearAdminService::getStaffYearId()` returns non-null) → public year (fallback). The public year is stored in `SettingService` (`current_scout_year_id`), the staff year likewise (`staff_scout_year_id`), and preview is purely session state — never persisted.

**Transition workflow** (Espace admin > Année scoute page): admin selects the next year, previews it session-only (step 1 — other users unaffected), imports Desk CSV for that year (step 2 — verified by member count > 0), activates it for chiefs/intendants only via `ScoutYearAdminService::activateStaffYear()` (step 3 — identified members and public still see current year), then transitions the entire site via `activatePublicYear()` (step 4 — staff year automatically cleared). Step 4 is only allowed during the **switch window** (August 1 – September 29, `ScoutYearService::isSwitchWindow()`); outside that window the transition happens automatically on September 30 via date arithmetic (`ScoutYearService::calculatePublicYearId()`), preventing accidental early transitions. Each step is journaled (`scout_year_staff_activated`, `scout_year_public_activated`, etc., level `security`).

**Member search** (`Core\Member\Controller\MemberSearchController`, `/admin/members`, same Espace admin menu) searches members of the effective scout year only — name/email/phone via `MemberSearchService::search()`, with optional detail view showing full personal data and effective age (birth year + scout year offset). The offset adjustment (`POST /members/{id}/scout-year-offset`, `role_min: chief`) is the one chief-level override for age-vs-section mismatch (`member_years.scout_year_offset`, −1/0/+1) — journaled as `scout_year_offset_updated`.

### 8.27 Member email management (`Core\Member`, self-service only)

Members manage their own secondary email addresses (`Core\Http\Controller\MemberEmailAddressController`, routes under `/members/{id}/emails/*`, `role_min: identified` but further gated by `MemberEmailService::isOwnMember()` on every action — no chief/admin bypass). `Core\Member\MemberEmailRepository` stores addresses in `member_emails` (`BLOB`-encrypted via `EncryptionService`, blind-indexed for lookup), with three states: **pending** (added but not yet confirmed — verification email sent), **active** (confirmed and usable for login), **deactivated** (previously active but deactivated by the member — can be reactivated without re-verification).

**Adding an email**: `POST /members/{id}/emails` sends a verification email via `MailService` with a unique single-use link (15-minute expiry, `MemberEmailToken` table, token hashed). `GET /members/emails/confirm/{id}` (`role_min: public`, unauthenticated — same pattern as password reset) validates the token and activates the address. **Resend**: re-sends verification for pending addresses. **Reactivate**: restores a deactivated email to active without re-verification. **Delete**: removes pending or deactivated emails (active emails must be deactivated first to prevent accidental deletion). All mutations journaled (`member_email_added`, `member_email_confirmed`, `member_email_deactivated`, level `info`, never the email text itself — only the member id).

`Core\Security\RoleResolver::resolveForEmail()` already checked all active `member_emails` rows alongside `member_years.email` for role calculation — this feature simply exposed the self-service UI for a table that was already consulted at login.

### 8.28 Section documents (`Core\Member\SectionDocumentService`, Staffs page)

Chiefs attach PDF documents to sections per scout year (`Core\Http\Controller\SectionDocumentController`, routes under `/chefs/staffs/documents/*`, `role_min: chief`) — displayed on the Staffs page and each member's own page (filtered by the member's scout year and section). `Core\Member\SectionDocumentRepository` stores metadata (`section_documents`: section_id, scout_year_id, title, file_id, sort_order); the actual PDF is a `files` row with `role_min: 'identified'` uploaded via `UploadHandler` (MIME validation, only `application/pdf` allowed).

**Operations**: `POST /chefs/staffs/documents` (add — upload + title + section/year selection, optional Ghostscript compression if available on server, warning displayed for large files when compression unavailable), `POST /chefs/staffs/documents/reorder` (drag-and-drop or move up/down to change `sort_order`), `POST /chefs/staffs/documents/{id}` (update — change title or replace PDF file), `POST /chefs/staffs/documents/delete` (delete — removes both the `section_documents` row and the underlying file). All mutations journaled (`section_document_added`/`updated`/`deleted`, level `info`).

Documents are scoped by section and scout year — each year has its own set. `Core\Pdf\PdfCompressor` (wraps Ghostscript `gs` if present, fallback no-op when absent) reduces file size via `PdfCompressor::compress($sourcePath, $targetPath)` before storage; `PdfCompressor::isAvailable()` self-tests the binary once per request and caches the result. The Staffs page lists documents per section with download links (`/files/{id}`), and `Core\Member\MemberPageService::buildPageData()` includes them in the member page's section card (via `SectionDocumentService::findBySection AndYear()`).

### 8.29 Mobile header avatar and PWA breadcrumb bar

**Header avatar** (`partials/account_avatar.html.twig`): the initials-circle avatar + unread-notifications badge markup, extracted out of the mobile offcanvas's user card (`partials/nav.html.twig`) into a shared partial parameterized by `size`/`font_size`, so the two render sites stay visually identical without duplicated markup. The partial renders only the visual circle and badge (`.notification-badge` class, same as before) — never the surrounding link — since the two call sites point it at different destinations: the offcanvas card still links the avatar to `/notifications` (unchanged), while the mobile header's new right-side avatar (rendered only when `is_authenticated`, right after the site name span, `me-auto` on the hamburger keeps both flush right) links straight to `/account` — a tap opens "Mon compte" directly, not the offcanvas. `notification-badge.js` already updates every `.notification-badge` element on the page via `querySelectorAll` (poll + service worker `postMessage`), so both instances stay in sync with no JS change needed — reusing the shared class was the point. The public (unauthenticated) mobile header is untouched: no avatar, no added element. Desktop nav's own avatar+badge markup (`partials/nav.html.twig`'s `#desktopNav`) is separate and intentionally left alone (out of scope).

**Breadcrumb bar** (`partials/breadcrumb_bar.html.twig`, included in `base.html.twig` right after the header): visible only when the site runs as an installed PWA. Visibility is 100% CSS (`public/assets/css/app.css`'s `.breadcrumb-bar` rule, `display: none` by default, `display: flex` under `@media (display-mode: standalone)`) — no server-side flag, no JS detection, never a security boundary (SECURITY §3, same principle as menu visibility). The first segment (a home icon linking to `/`) is always hard-coded, never sourced from `SettingService` or any config.

Each route — core or module — can optionally declare a breadcrumb: `Core\Http\Router::addRoute()`'s 6th argument (`?array{label: string, parents: array<string>}`), or module.json's `routes[].breadcrumb` (validated by `Core\Module\ModuleManifest::validateBreadcrumb()`, same optional-key pattern as `notifications`, §8.24) — a single mechanism for both, since `Core\Module\ModuleManager::loadModule()` feeds a module's declared breadcrumb through the exact same `Router::addRoute()` call core routes use directly in `public/index.php`. `parents` are plain-text ancestor labels, never links — most menu categories (e.g. "Espace des animés") have no single landing page, so none is invented for them. A route with no `breadcrumb` key is not an error: the trail simply stops at the home icon. `Core\Http\FrontController::handle()` sets the `route_breadcrumb` Twig global right after the RBAC check succeeds (never before — a 403 page must never leak the trail of a page the visitor couldn't reach) and right before invoking the controller action, so it's available by the time the template (including the included breadcrumb partial) renders. A Controller can override the route's static `label` per-request with a `breadcrumb_current` context variable for a dynamic title (e.g. `MemberController::show()` passes the member's `getDisplayName()`).

## 9. Installation / bootstrap

### 9.1 First install: bootstrap.php

FTP is used exactly once: the operator uploads a single self-contained `bootstrap/bootstrap.php` to an empty web folder and opens it in a browser. It has no Composer dependency (it must run before `vendor/` exists) and never touches `Core\Maintenance\BackupService`, `InstallUpdateHandler`, `RestoreBackupHandler`, or `FileAccessGuard` — it is their first-run twin, not a rewrite of them: same `VERSION` format (`Core\Maintenance\VersionFile`), same archive-root handling as `InstallUpdateHandler::resolveBranchArchiveRoot()` (decided from source type, never entry count), same "no new dependency" constraint.

It resolves the latest published GitHub release (hardcoded repo, never read from the request), picks one of two supported layouts, runs an 11-step POST-driven install (the browser drives a short poll loop — a single long request would time out on shared hosting), then a full acceptance gate, then writes `token.php` and deletes itself.

**Layout A — "Natural" (preferred)**: the document root sits inside a writable parent directory. The artifact's `public/` contents are merged directly into the document root; everything else (`core/`, `modules/`, `storage/`, `vendor/`, `schema/`, `config/`) goes into the parent. The result is the exact project tree in §12, with the document root simply *being* `public/`.

**Layout B — "Single-tree" (fallback)**: used whenever the parent isn't writable, doesn't physically contain the document root (chrooted/symlinked hosting), or looks like a filesystem root. The entire artifact is installed into the document root, and exactly one root `.htaccess` (never per-directory deny files — those miss module `storage/<name>/` folders created long after install, and get overwritten by every update since they'd live inside the artifact) denies `storage/|core/|modules/|config/|schema/|vendor/|tests/|scripts/` and dotfiles — gated on the request actually resolving to a real file/directory (`-f`/`-d`), since `config` also doubles as the app's own admin route namespace (`/config/maintenance`, `/config/notifications`, …); an earlier ungated version of this rule 403'd every one of those routes outright. Static assets under `public/` are rewritten across directories to be served directly (no PHP involved, so no risk); PHP execution is never rewritten across directories — instead `bootstrap.php` writes one small `index.php` stub directly in the document root (`require __DIR__ . '/public/index.php';`) and the `.htaccess` routes everything else to that same-directory file. A same-directory PHP rewrite is the one universally-supported case across Apache + PHP-FPM/FastCGI configurations; rewriting PHP execution straight across a directory boundary (an earlier version of this file did exactly that, in a two-hop chain through `public/`'s own `.htaccess`) is a well-documented trap on some hosts, where `SCRIPT_FILENAME` is computed from the request's original path rather than the rewritten target and the request fails with a raw "File not found." from PHP-FPM even though the target file genuinely exists. The `.htaccess` also forces `DirectoryIndex index.php` explicitly: some hosts (observed on OVH-style mutualized hosting) ship a placeholder page and configure their own vhost-level `DirectoryIndex` to list it ahead of `index.php`, and `mod_dir`'s directory-index resolution for the bare `/` request can win against this `.htaccess`'s own rewrite rules depending on the host's Apache module hook ordering — without the explicit override, the host's placeholder gets served instead of the site.

Either way, no code elsewhere in the codebase is aware of which layout is in use — `dirname(__DIR__)` from `public/index.php` resolves to the same project root in both cases by construction. The one narrow exception is the token-gate check in `SetupController`, which tries the two fixed candidate locations for `token.php` itself (see §9.2) — not a general path-resolution abstraction.

**Acceptance gate**: before the wizard is reachable, `bootstrap.php` runs server-side checks (`VERSION`, `vendor/autoload.php`, `schema/core.sql`, `storage/` subdirectories and permissions, no `.htaccess` shipped in the artifact, temp dir cleaned up) and has the *browser itself* fetch a set of canary files it just wrote (a positive control, `token.php` executing as PHP rather than being served as source, `storage/keys/`, a `storage/` subdirectory created moments earlier, `vendor/autoload.php`, a docroot dotfile, no `storage/` directory listing) to prove what's and isn't web-reachable — trusting the browser's report is deliberate here, the same precedent `MaintenanceController::installUpdate()` sets for accepting client-observed results server-side, and it's safe because only the operator running the install can forge the verdict and doing so grants no privilege. Any failure rolls back the entire installed tree — not just the failing piece — and no `token.php` is ever written. Full report also saved to `storage/config/install-report.json` on success.

### 9.2 Setup wizard token gate

Until `secrets.enc` exists, `/setup` (`Core\Http\Controller\SetupController`) is gated behind `token.php`, which `bootstrap.php` writes to the document root only after its acceptance gate fully passes. `SetupController` never generates a token itself: if `token.php` is missing, it refuses and displays the exact file content to create over FTP; if present, it compares a submitted value against the file's own content via `hash_equals()`, with session-based progressive lockout (there is no database yet at this point, so `Core\Security\LoginThrottler`'s DB-backed pattern doesn't apply) escalating to a hard stop after ~10 attempts. On successful completion of first-time setup, `token.php` is deleted; a persistent journal entry (and a flash message) warns the operator if deletion fails, since leaving it in place is an unnecessary — though not by itself exploitable — risk.

Collects DB credentials, unit settings (including short name ≤5 chars), email config, initial admin email. Once `secrets.enc` exists, `/setup` reverts to being a normal `superadmin` Configuration page under `RbacGuard`, unchanged — the token gate never applies again.

## 10. Database schema management

No incremental migration files. `schema/core.sql` + each module's `schema.sql` = source of truth. Deploy script compares and generates DDL — this diff never drops a column/table it finds in the database but is no longer declared (data-loss safety net), it only warns. The one narrow exception: a sibling `drops.sql` next to a schema file (e.g. `schema/drops.sql`) can declare reviewed `ALTER TABLE <table> DROP COLUMN <column>;` statements — `MigrationRunner::applyExplicitDrops()` runs each only while the column still exists, so it's idempotent and safe on every request. Still not incremental: once applied everywhere, delete the line from `drops.sql`. `migrate()` runs on every request (there's no separate deploy step that triggers it), so re-introspecting the whole live schema just to conclude "nothing to do" is wasted work on every page load between real schema changes — it short-circuits entirely when a SHA-256 hash of the schema file(s) + sibling `drops.sql` matches a hash cached from the last run that both applied cleanly and found nothing left to do (stored as an internal, non-editable `settings` row, keyed per schema-file-set so core and each module track independently). The cache is only written on a clean, warning-free pass, so a crash or partial failure never gets masked — the next request just re-checks in full. First-time setup also exposes this directly: the setup wizard's database step now runs the real migration itself (`SetupController::installDatabase()`, `POST /setup/install-database`) rather than deferring it to the final form submission, so a slow first schema creation (35+ statements from scratch) gets its own visible step instead of being bundled invisibly into "Installer" — `handleFirstTimeSetup()` still calls `migrate()` itself too, as a defensive no-op safety net, made cheap by the same cache.

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
  Member/        SectionService, MemberYearService, UnitStaffSectionService, MemberProfile, MemberEmailService, SectionDocumentService
  Badge/         BadgeService, MemberBadgeRepository
  Photo/         MemberPhotoService, SectionPhotoService, SectionPhotoProcessor (§8.10)
  ScoutYear/     ScoutYearResolver, ScoutYearAdminService, ScoutYearSession (§8.26)
  Notification/  NotificationService, NotificationRegistry, notification centre + Web Push (§8.24)
  Maintenance/   BackupService, VersionFile, GitHubReleaseClient, GitHubWebhookService (§8.15–§8.18)
  Import/        Desk CSV import pipeline (§8.1)
  Pdf/           PosterPdfService, PdfCompressor (A4 poster generation, Ghostscript compression)
  Url/           Generic short-URL service
  Offline/       OfflineWhitelist (§8.25)
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

bootstrap/
  bootstrap.php    Standalone first-install script (§9.1) — published as its
                    own release asset, excluded from the main artifact

scripts/
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
