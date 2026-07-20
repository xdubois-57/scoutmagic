# Agent rules

This file is automatically loaded by Devin, Cursor, Copilot, and other AI coding agents. These rules are non-negotiable. Before making any change, also read `SECURITY.md`.

## Language

- All code, comments, variable names, function names, class names, table names, column names, commits, PR titles and descriptions: **English**.
- All user-facing text (Twig templates, labels, messages, descriptions, settings labels): **French**.
- No exceptions. A French variable name or an English UI label is always a bug.

## Architecture

Read `ARCHITECTURE.md` in full before any task. Key rules:

- **Layered MVC**: Controller → Service → Repository. No SQL in Controllers. No business logic in Controllers or Views. No `$_SESSION`/`$_POST` access in Services.
- **RBAC guard**: called by the Router, never by a Controller. Every route has `role_min`.
- **Modules**: self-contained under `modules/<name>/`. Never modify `schema/core.sql` for a module-specific need. Each module has its own `schema.sql` (complete current state, not incremental migrations).
- **Single file per concern**: one Controller class per file, one Service, one Repository.

## Security checklist (every PR)

Before submitting any code:

1. ☐ All SQL uses prepared statements (no concatenation).
2. ☐ Every new route has `role_min` in `module.json`.
3. ☐ Personal data fields are `BLOB` + encrypted via `EncryptionService`. Banking data (IBAN, account holder, transaction labels) are always `BLOB` + encrypted too.
4. ☐ No personal data in log entries, error messages, or journal.
5. ☐ File access goes through `FileAccessGuard` (`file_url()` helper).
6. ☐ No uploaded files stored under `public/`.
7. ☐ CSRF token on every form.
8. ☐ Rich text content sanitized before storage.
9. ☐ No secrets in source code.
10. ☐ Sensitive actions logged via `JournalService`.
11. ☐ Non-essential cookies checked via `CookieConsentService::isAllowed()` before being set.

## Cookie consent

- Every cookie used by the site (core or module) must be declared: name, category, purpose (in French), and duration.
- Core cookies are declared in `core/Cookie/CookieRegistry.php`. Module cookies are declared in their `module.json` under the `cookies` section.
- The cookie preferences page and the consent banner must both display the **complete and current** list of cookies, aggregated from the core registry and all active modules. Both surfaces pull from the same source of truth (`CookieConsentService::getAllDeclaredCookies()`). The RGPD public page does not display this list inline — it links to the preferences page.
- When adding, removing, or modifying any cookie anywhere in the codebase, you **must** verify that the declaration is updated accordingly. The cookie preferences page and the consent banner will then reflect the change automatically.
- Never set a non-essential cookie without first checking `CookieConsentService::isAllowed($category)`.

## RGPD page maintenance

The default RGPD content is defined in `Core\View\RgpdContentService::getDefaultContent()`. It must be kept in sync with the actual data processing performed by the codebase. Specifically:

- When adding a new data field to any table that stores personal data → update the "Données collectées" section.
- When adding a new cookie → the cookie list is generated dynamically from declarations (see Cookie consent above).
- When adding a new module that processes personal data → update the AI prompt in `RgpdContentService::buildSystemPrompt()` to describe the module's data processing.
- When adding a new external service integration (API, email relay, etc.) → update the "Sous-traitants" section. This includes a module that only *optionally* sends data to an external service via another module's public API (e.g. a module calling `llm_connector` — see §7.5 of `ARCHITECTURE.md`): the AI provider(s) reachable through it are still a real sous-traitant relationship whenever that path is exercised, regardless of which module initiated the call.
- When changing data retention logic → update the "Durée de conservation" section.

This is not optional. A PR that adds personal data processing without updating the RGPD documentation is incomplete.

## Module creation checklist

When creating a new module:

1. ☐ `module.json` with `id`, `name`, `version`, `routes` (each with `role_min` and `menu`).
2. ☐ `schema.sql` with complete table definitions.
3. ☐ `settings` section with `description` (NOT NULL) on every parameter.
4. ☐ `cookies` section declaring every cookie the module uses, with category, purpose, and duration.
5. ☐ Controllers in `src/Controller/`, Services in `src/Service/`, Repositories in `src/Repository/`.
6. ☐ Views in `views/` with `@module_name` namespace.
7. ☐ Scheduled tasks declared in `scheduled_tasks` section with handler class.
8. ☐ Storage folders declared in `storage` section with `role_min`.
9. ☐ No duplicate of core functionality (auth, session, encryption, journal, mail, scheduler, cookie consent).
10. ☐ RGPD documentation updated if the module processes personal data.
11. ☐ Automated tests written for all module functionality.
12. ☐ If the module has an optional dependency on another module, it must degrade gracefully when that other module is absent or disabled — never a hard coupling (see `ARCHITECTURE.md` §7.5).

## Tests

Automated tests are **mandatory** for every feature, without exception.

- Write tests alongside the code, never as a separate follow-up task.
- `tests/` mirrors the structure of `core/` and `modules/`.
- Every new Service method must have at least one test.
- Every new Controller route must have at least one integration test verifying the correct response and the RBAC boundary (access allowed at `role_min`, denied one level below).
- Every Repository method must be tested against a test database.
- When modifying existing code, update the corresponding tests to match the new behavior.
- When fixing a bug, write a test that reproduces the bug first, then fix it.
- Tests must pass before any PR is submitted. CI runs the full test suite and blocks merge on failure.
- RBAC guard: explicit test coverage on every role boundary.
- Cookie consent: test that non-essential cookies are not set when consent is missing.

## CSS / frontend

- **Mobile-first**: write for mobile by default, add `min-width` breakpoints for larger screens.
- Use Bootstrap 5 components before writing custom CSS.
- Never duplicate a Bootstrap component in custom CSS.
- No frontend build tools (Sass, webpack, npm).
- Minimum 44px touch targets on interactive elements.
- HTML5 input types (`tel`, `date`, `email`) for appropriate keyboard on mobile.

## Database

- Table and column names in English, snake_case.
- Every table that holds member-related data: include `scout_year_id` foreign key.
- Personal data columns: `BLOB` type, encrypted/decrypted only in Repository layer.
- Blind index column alongside any encrypted field that needs exact-match search.
- `schema.sql` is the single source of truth — no incremental migration files.

## Display name convention

Everywhere a member name is shown: `totem ?? first_name`. Use `{{ member|display_name }}` Twig filter. Never hardcode the logic.

## Email

All email sent via `MailService::send()`. Never send email directly. The service handles subject prefix, DKIM signing, multipart, and delivery mode.

## Scheduler

Use `SchedulerService` for any delayed or timed action. Never use `sleep()`, cron-specific code, or ad-hoc timing logic. Declare task handlers in `module.json`.
