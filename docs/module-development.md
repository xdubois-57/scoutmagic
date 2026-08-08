# Developing a module

This guide explains how to create a module for ScoutMagic. Modules are self-contained features that integrate with the core system via a standardized manifest and lifecycle.

## Directory structure

```
modules/my_module/
  module.json          # Manifest (required)
  schema.sql           # Database tables (optional)
  src/
    Controller/
      MyModuleController.php
      ConfigController.php
    Service/
      MyModuleService.php
    Repository/
      MyModuleRepository.php
  views/
    index.html.twig
    config.html.twig
```

The directory name **must** match the `id` field in `module.json`.

## module.json — full annotated example

```json
{
  "id": "calendar",
  "name": "Calendrier des activités",
  "version": "1.2.0",
  "routes": [
    {
      "path": "/calendar",
      "method": "GET",
      "controller": "Modules\\Calendar\\Controller\\CalendarController",
      "action": "index",
      "menu": "espace_animes",
      "role_min": "identified",
      "label": "Calendrier"
    },
    {
      "path": "/calendar/event/{id}",
      "method": "GET",
      "controller": "Modules\\Calendar\\Controller\\CalendarController",
      "action": "show",
      "menu": "espace_animes",
      "role_min": "identified",
      "label": "",
      "breadcrumb": {
        "label": "Activité",
        "parents": ["Espace des animés", "Calendrier"]
      }
    },
    {
      "path": "/config/calendar",
      "method": "GET",
      "controller": "Modules\\Calendar\\Controller\\ConfigController",
      "action": "index",
      "menu": "configuration",
      "role_min": "admin",
      "label": "Calendrier"
    }
  ],
  "settings": [
    {
      "key": "default_view",
      "default_value": "month",
      "type": "select",
      "label": "Vue par défaut",
      "description": "Type d'affichage par défaut du calendrier"
    },
    {
      "key": "show_past_events",
      "default_value": "1",
      "type": "boolean",
      "label": "Afficher les activités passées",
      "description": "Affiche les activités passées dans le calendrier"
    }
  ],
  "cookies": [
    {
      "name": "calendar_view",
      "category": "functional",
      "purpose": "Mémorise le type d'affichage choisi (mois/semaine)",
      "duration": "1 an"
    }
  ],
  "scheduled_tasks": [
    {
      "key": "send_reminders",
      "handler": "Modules\\Calendar\\Task\\SendRemindersHandler"
    }
  ],
  "storage": {
    "attachments": {
      "role_min": "identified"
    }
  },
  "notifications": [
    {
      "id": "calendar.event_published",
      "label": "Nouvelle activité",
      "description": "Un nouvel évènement a été ajouté au calendrier.",
      "group": "Calendrier",
      "role_min": "identified",
      "channels": { "in_app": "default_on", "push": "default_on", "email": "default_off" }
    }
  ]
}
```

## Manifest validation rules

- **id**: required, must match directory name.
- **name**: required, non-empty string (displayed in UI, in French).
- **version**: required, semver format (`x.y.z`).
- **routes**: each entry must have `path`, `controller`, `action`, `menu`, `role_min`.
  - `menu`: one of `notre_unite`, `espace_animes`, `espace_chefs`, `espace_admin`, `configuration`.
  - `role_min`: one of `public`, `identified`, `intendant`, `chief`, `admin`, `superadmin`.
  - A route's `role_min` must not be more permissive than its menu's minimum role.
  - `method`: optional (defaults to `GET`).
  - `label`: if non-empty, the route is added to the menu with this label.
  - `menu_order`: optional integer, defaults to `100`. Controls where the menu entry sorts relative to other pages in the same menu (lower = earlier). Core pages typically use 10–40; in `espace_animes` specifically, dynamic per-member entries use order 10+index and the separator before static pages sits at order 50 — a module page with the default 100 always appears after them. Set a lower value (e.g. `5`) to appear before the dynamic member entries instead. **This explicit value is absolute and untouched by the module reordering described below** — only routes left at the plain default are affected.
  - Module-to-module ordering (for routes at the default `menu_order`): a superadmin can drag-and-drop reorder modules on the general configuration page (`/config/general`, `module_registry.sort_order`). Each enabled module's position in that order becomes a base offset (`1000 * position`) added to its default-order routes' `menu_order` — so those pages sort by module order, always after core's own hardcoded (≤ ~50) values. See `Core\Module\ModuleManager::loadModule()`.
  - `breadcrumb`: optional. When present, `label` is required (the page's own default breadcrumb label — a Controller can still override it per-request with a `breadcrumb_current` context variable, e.g. for a dynamic member/article title) and `parents` is an optional array of ancestor labels, each naming a *menu* (`Core\View\MenuBuilder`'s `label`, e.g. `"Espace des animés"`). A parent is never given its own URL in `module.json` — `partials/breadcrumb_bar.html.twig` matches it against `menus` (the same structure the nav renders from) and links it to that menu's first real page (skipping separators, dynamic per-member entries, and any `#` placeholder), or leaves it as plain text when the menu has none, or when the only such page is the one already being viewed (never a self-link, and never an invented URL like `/` or `#`). A route with no `breadcrumb` key is not an error — the breadcrumb bar simply stops at the home icon for that page. Rendered by `partials/breadcrumb_bar.html.twig`, included from `base.html.twig`, visible only when the site runs as an installed PWA (pure CSS `@media (display-mode: standalone)`, see `public/assets/css/app.css` — never a security boundary, same principle as menu visibility, SECURITY §3). Core routes declare the exact same shape as this route's 6th `Core\Http\Router::addRoute()` argument — see the core route table in `public/index.php`.
- **settings**: optional, each entry must have `key`, `type`, `label`, `description`.
- **cookies**: optional, each entry must have `name`, `category`, `purpose`, `duration`.
  - `category`: one of `necessary`, `functional`, `analytics`.
- **scheduled_tasks**: optional, each entry must have `key`, `handler` (FQCN).
- **storage**: optional, keys are subdirectory names, values have `role_min`.
- **notifications**: optional, each entry must have `id`, `label`, `description`, `group`, `role_min`, `channels`.
  - `id`: must be prefixed `"{module_id}."` (e.g. `calendar.event_published`).
  - `role_min`: same role list as routes — the minimum role a recipient must currently hold to actually receive it (re-checked at send time, not at whatever moment the caller built the recipient list).
  - `channels`: an object with exactly the keys `in_app`, `push`, `email`, each one of `on` (always sent, member can't opt out), `off` (never sent, member can't opt in), `default_on`/`default_off` (member can override on the preferences page). See the Notifications section below.
- **enabled_by_default**: optional boolean, defaults to `false`. When `true`, the module is activated automatically the very first time it is discovered on disk (no `module_registry` row yet) — no admin action needed. An admin's later explicit deactivation always sticks; this never re-activates a module that already has a registry row.

## Controller conventions

- Extend `Core\Http\Controller\AbstractController`.
- Use `$this->render('@my_module/index.html.twig', [...])` for views (Twig namespace matches module id).
- Receive the Twig `Environment` via constructor.
- All controllers are instantiated by the FrontController using the registered Twig environment.

Example:

```php
<?php

declare(strict_types=1);

namespace Modules\Calendar\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Twig\Environment;

class CalendarController extends AbstractController
{
    public function __construct(protected Environment $twig)
    {
    }

    public function index(Request $request, array $params): Response
    {
        return $this->render('@calendar/index.html.twig', [
            'events' => [],
        ]);
    }
}
```

## Chip picker (`partials/chip_picker.html.twig`)

The site's one selection component — mobile-friendly wrapping chips with a
"+N" overflow chip opening a bottom sheet for the full list. Core,
content-agnostic (same precedent as `partials/list_editor.html.twig`): it
knows nothing about what an item *is*. Any module that needs a picker
(a filter, a multi-select toggle, anything selecting from a list of
labeled things) maps its data to the item format below and includes this
partial — no new CSS, no new JS, same appearance and behavior everywhere.
If your case forces you to override its style or duplicate its JS, you're
using it wrong — ask whether the component needs a new *generic*
parameter instead (never a use-case-specific one — no `is_section`, no
`for_calendar`).

```twig
{% include 'partials/chip_picker.html.twig' with {
    picker_id: 'my-picker',
    items: [
        { id: 1, label: 'Louveteaux', sublabel: 'Meute', color: '#f5a623', badge: null, selected: true },
        { id: 2, label: 'Éclaireurs', sublabel: 'Troupe', color: '#4a90d9', selected: false }
    ],
    mode: 'single',
    base_url: '/my-page?item=',
    sheet_title: 'Choisir un élément',
    empty_text: 'Aucun élément disponible.'
} %}
```

**Item fields**: `id` and `label` required. `sublabel` and `badge` are
optional and only ever appear in the bottom sheet — a chip has no room
for them. `color` (optional hex string) draws a small dot on both the
chip and the sheet row; always source it from whatever this app's single
color source of truth is for your data (e.g.
`Core\Member\SectionService::colorForSection()` for anything
section-derived) — never recompute or hardcode a color here. `selected`
(bool) marks the current selection(s).

**Modes**:
- `single` — chips and sheet rows are `<a href="{{ base_url }}{{ item.id }}{{ extra_query|default('') }}">`, the exact same link both places. Selection needs no JS at all (a plain click, or a screen reader, already works) — `public/assets/js/chip-picker.js` only handles truncation and opening the sheet.
- `multi` — chips and sheet rows are `<button>` elements toggled by `chip-picker.js`, which dispatches a `chip-picker:change` `CustomEvent` (`detail: { selectedIds }`) on the picker container after every toggle. The partial and its JS never persist a selection themselves — listen for that event and do whatever your case needs (a cookie, a form submit, a fetch call). The bottom sheet stays open across toggles in this mode; a dedicated "Fermer" button closes it.

**`picker_id`** must be unique per instance on a page — it becomes both
the picker's DOM id and its offcanvas sheet's id
(`{picker_id}-sheet`), so two picker instances on the same page need two
different values.

**Truncation** (2 lines, "+N" overflow chip opening the sheet) is entirely
client-side, measured from each chip's real `offsetTop` after render —
never a hardcoded chip count — and only activates below the `lg`
breakpoint (992px); at `lg` and up chips wrap fully with no cap, matching
this site's existing desktop picker behavior. Selected chips are always
moved to the front before measuring, so truncation can never hide the
current selection; if the selection itself spans more than 2 lines, the
cutoff extends rather than hiding part of it. The partial always renders
every item unconditionally (no chip is ever hidden server-side) — a
visitor with JS disabled, or before it has run, sees and can operate
every item exactly as if truncation didn't exist.

**Existing callers, as reference implementations of the "thin mapping
layer" pattern**: `core/View/templates/partials/section_picker.html.twig`
(mode `single`, sections → items) and
`modules/calendar/views/partials/calendar_picker.html.twig` (mode
`single`, calendar options → items). Neither owns any chip/sheet/
truncation logic itself — each only maps its own domain data into the
generic item format and includes `chip_picker.html.twig`.

## Accessing core services

Module controllers receive whatever services they need via constructor injection — there is no fixed list. The composition root (`public/index.php`) is where every controller is actually built and registered: inside the module's `if (in_array('my_module', $moduleManager->getEnabledModuleIds(), true)) { ... }` block, construct the module's repositories/services (passing `$pdo`/`$connection`, `$encryptionService`, `$mailService`, `$schedulerService`, `$sectionService`, or any other already-built core service the module needs), then `$frontController->registerController(MyModuleController::class, new MyModuleController($twig, ...))`. See any existing module block in `public/index.php` for the pattern.

## Optional dependencies between modules

A module may want to use a capability offered by another module — e.g. `finance` optionally calling `llm_connector` to suggest a receipt's amount/date — without ever hard-depending on it (the other module might be disabled, or not installed at all). This is the same pattern `ARCHITECTURE.md` §7.5 describes for core consuming a module's API, just with a module on both ends:

1. The providing module publishes a stable interface under its own `Api` namespace (e.g. `Modules\LlmConnector\Api\LlmConnectorInterface`) — never require the consuming module to know about the providing module's internal classes.
2. The consuming module's service takes that interface as a **nullable** constructor dependency (`private ?LlmConnectorInterface $llmConnector = null`).
3. Every code path that uses it checks availability first (e.g. `$this->llmConnector?->isAvailable()`) and degrades to "feature simply unavailable" — never an error — when it's `null` or reports unavailable.
4. The composition root (`public/index.php`) is the only place that checks `ModuleManager::getEnabledModuleIds()` and wires the concrete implementation in; it passes `null` when the providing module is disabled.

This keeps both modules independently activatable in any combination without either one breaking.

## Database

- Create a `schema.sql` in the module root with complete table definitions (not incremental migrations).
- Table names should be prefixed with the module id to avoid collisions (e.g., `calendar_events`).
- All table/column names in English, snake_case.
- Personal data fields must use `BLOB` type and be encrypted/decrypted via `EncryptionService`.
- Include a `scout_year_id` foreign key on member-related data tables.
- A module that stores confidential *files* (not just database fields) — receipts, private documents, anything that must never be readable directly off disk — should use `Core\File\EncryptedFileStorageService` (`store()`/`retrieve()`/`delete()`) instead of `UploadHandler`. It uses the same master key as `EncryptionService` and integrates transparently with `FileAccessGuard`/`/files/{id}` — the caller never handles decryption itself.
- **Editing `schema.sql` for a module that may already be enabled somewhere (i.e. any change after the module's first release — new column, new table, changed default, etc.)? Bump `version` in `module.json` in the same change.** `ModuleManager::loadEnabledModules()` only re-diffs and re-applies a module's `schema.sql` when the manifest's `version` compares greater than the version recorded in the module registry (`ModuleManager.php`, the "Auto-migrate when module version is newer than installed version" block). Editing `schema.sql` without bumping `version` is silently a no-op on every already-enabled installation — the new column/table only ever gets created for a *fresh* activation, never retrofitted onto an existing one. This has caused real `Unknown column` / `PDOException` production errors from schema changes that looked complete in code review but were never actually applied to the running database. There is no separate reminder or lint for this — bumping the version is the only signal that triggers migration, so treat "I touched schema.sql" and "I bump version" as inseparable.

Example `schema.sql`:

```sql
CREATE TABLE IF NOT EXISTS calendar_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scout_year_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    event_date DATE NOT NULL,
    description TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (scout_year_id) REFERENCES scout_years(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## Settings

- Declared in `module.json` under the `settings` section.
- Registered automatically when the module is activated.
- Appear in the Paramètres page, grouped by module.
- Access in code: `$settingService->get('default_view', 'calendar')`.
- Access in Twig: `{{ param('default_view', 'calendar') }}`.

## Cookies

- Declared in `module.json` under the `cookies` section.
- Automatically appear in the consent banner and the preferences page (the RGPD page only links to the preferences page).
- Before setting a cookie: always check `$cookieConsentService->isAllowed('functional')`.

## Scheduled tasks

- Declared in `module.json` under the `scheduled_tasks` section.
- The `handler` field is a fully qualified class name implementing `Core\Scheduler\TaskHandlerInterface`.
- The handler receives `array $payload` and `Core\Scheduler\TaskContext $context`.
- `TaskContext` provides: `$context->connection`, `$context->encryption`, `$context->mailService`, `$context->journal`, `$context->settings`, `$context->userAccounts`, `$context->storagePath` (root of `storage/`, for handlers that need `Core\File\EncryptedFileStorageService` or similar file access), `$context->notifications` (`?Core\Notification\NotificationService` — see the Notifications section below for `dispatch()`).

Example handler:

```php
<?php

declare(strict_types=1);

namespace Modules\Calendar\Task;

use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;

class SendRemindersHandler implements TaskHandlerInterface
{
    public function handle(array $payload, TaskContext $context): void
    {
        // Use $context->mailService to send reminders
        // Use $context->journal to log the action
    }
}
```

Schedule a task from a service:

```php
$schedulerService->schedule('calendar', 'send_reminders', $runAt, ['event_id' => 42]);
```

## Notifications

- Declared in `module.json` under the `notifications` section — every type a module can send must be declared there before it can be dispatched (an undeclared `type_id` throws).
- Declared types are aggregated with core's own (`Core\Notification\NotificationRegistry`) into a single registry, exposed on the member-facing preferences page (grouped by `group`) and used to validate every `dispatch()` call.
- Send a notification from a service or controller — never in-request for push (see below), but the call itself is the same either way:

```php
$notificationService->dispatch(
    'calendar.event_published',
    [
        ['userAccountId' => 12, 'memberId' => null],
        ['userAccountId' => 13, 'memberId' => null],
    ],
    [
        'title' => 'Nouvelle activité',
        'body' => $event->title,
        'url' => '/calendar',
    ],
    $actorUserAccountId // optional — the acting user never gets a push for their own action, but still sees the row in their own centre
);
```

- `dispatch()` re-checks each recipient's *current* role against the type's `role_min` — pass every plausible candidate (e.g. every account id via `UserAccountRepository::findAllIds()`) and let it filter, rather than pre-filtering yourself.
- It always creates the in-app `notifications` row, even for a recipient whose `push`/`email` channel is off for that type.
- Push is never sent synchronously in the request that calls `dispatch()` — it schedules a `core/send_notifications` task (grouped by the recipient's quiet-hours-adjusted send time), which `Core\Notification\Task\SendNotificationsHandler` later batches out via Web Push. Never call anything push-related directly from a controller.
- Never pass personal data as the `title`/`body` beyond what the recipient is already meant to see — both are encrypted at rest, but the type `id` itself is what appears in the journal (`notification_sent`), never the text.
- A handful of pre-existing, out-of-scope Maintenance task types (reset/restore/update) use the older, simpler `notify($userAccountId, $title, $body, $url)` instead — single recipient, immediate, no role/channel/quiet-hours resolution. New module types should use `dispatch()`.

## Module lifecycle

1. **Discovery**: The `ModuleManager` scans `modules/` and reads `module.json` files.
2. **Activation**: Admin toggles the module on via Configuration générale. This runs `schema.sql`, registers default settings, and marks the module as enabled.
3. **Loading**: On every request, enabled modules have their routes, settings, cookies, menu pages, and task handlers registered.
4. **Deactivation**: Admin toggles the module off. Routes become 404, menu entries disappear. **Data and settings are never deleted.**

## Important rules

- **Any edit to a module's `schema.sql` must bump `version` in that module's `module.json` in the same change** — otherwise the migration never runs against an already-enabled install (see Database section above). Check this before finishing any task that touches a module's schema.
- Never duplicate core functionality (auth, session, encryption, journal, mail, scheduler, cookie consent).
- Never modify `schema/core.sql` for module-specific needs.
- Never write your own log table — use `JournalService`.
- Never access `$_SESSION` or `$_POST` directly in services.
- All SQL must use prepared statements (no concatenation).
- Every route must have `role_min`.
- All code and comments in English; all UI text in French.
- Automated tests are mandatory for every feature.
