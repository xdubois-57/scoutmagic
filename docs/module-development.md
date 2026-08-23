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
        "parents": ["Espace animés", "Calendrier"]
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
  ],
  "offline": [
    {
      "path": "/calendar",
      "label": "Calendrier",
      "match": "exact",
      "role_min": "public"
    }
  ]
}
```

## `visible_when` — a module only some installations see

`"visible_when"` (top level, list of installation-flag names, default `[]`) gates a module on the *kind* of installation it is running on (ARCHITECTURE.md §8.49). It replaced the older boolean `receiver_only`, which could not express "the reference installation **or** a local one".

```json
{
  "id": "test_tools",
  "visible_when": ["reference_installation", "local_installation"]
}
```

Absent or `[]` means **always visible** — that is every module's default. When the list is non-empty, `ModuleManager` filters the manifest out of `discoverModules()` on any installation where none of the flags holds, which removes its routes, menu entries, registry listing and scheduled tasks in one stroke.

The known flags — the complete list lives in `Core\Module\InstallationProfile::KNOWN_FLAGS`, and the manifest is validated against it:

| Flag | Holds when |
| --- | --- |
| `statistics_receiver` | this installation is the one usage statistics are reported to (`base_url` matches `statistics_destination`). |
| `reference_installation` | `base_url`'s host is `scoutmagic.be` (a `www.` prefix is equivalent; another subdomain is not). |
| `local_installation` | the host is `localhost`, `127.0.0.1` or `::1`, or its last DNS label is `test`, `local` or `localhost`. |

Four things to know before using it:

- **The semantics are OR.** The module is visible as soon as *any* listed flag holds — never "all of them". A list of two flags widens visibility, it does not narrow it.
- **Every flag is decided from the `base_url` setting**, never from the `Host` header, which is attacker-supplied on every request. An empty or unparseable `base_url` yields no flag at all: "unknown" never reads as "match".
- It is **ergonomic, not a security boundary.** The real protection is that a module which is never loaded registers no routes at all. Do not use it as access control.
- It is **strictly validated.** A non-list, a non-string element, a duplicate, or a flag name that is not in `KNOWN_FLAGS` is a load-time `ModuleException` naming both the offending value and the known set. Getting it wrong hides the module on every installation, and the symptom — a module that silently does not exist — is close to undebuggable, which is precisely why a typo must not be silent.

Two modules use it today: `support_dashboard` (`["statistics_receiver"]`) and `test_tools` (`["reference_installation", "local_installation"]`, ARCHITECTURE.md §8.63). This is not a general "hide a module" mechanism — adding a flag means adding it to `InstallationProfile::KNOWN_FLAGS`, and every flag has to be answerable from `base_url` alone.

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
  - `menu_order`: optional integer, defaults to `100`. **Read this carefully if you're used to an older version of this rule: `menu_order` no longer competes with core or dynamic entries at all, only with other modules.** `Core\View\MenuBuilder::visibleEntries()` sorts every menu by entry type first — dynamic entries (e.g. `espace_animes`'s per-member pages) always first, then core static pages, then every module's pages — and only uses `menu_order` to break ties *within* the module group. Setting a very low value (e.g. `5`) no longer places a module page ahead of a dynamic per-member entry or a core page, however low — it only affects that page's position relative to *other module pages* in the same menu. (An earlier version of this rule let a low `menu_order` do exactly that, and two real modules relied on it — `trombinoscope`/`gallery` both declared `menu_order: 5`/`6` in `espace_animes` expecting to sort before per-member pages that used order 10+; under the current rule they simply sort after every dynamic/core entry regardless, and their explicit `menu_order` only orders them against each other.) **This explicit value is still untouched by the module-to-module reordering described below** — only routes left at the plain default are affected by that.
  - `menu_group`: optional string, naming which **titled column** of its menu the entry is drawn in on the desktop mega-menu. Nothing to do with `menu_order` above: `menu_order` decides where in a list an entry lands, `menu_group` decides which column draws it. The vocabulary is closed per menu and declared once in `Core\View\MenuBuilder::MENU_GROUPS` — `espace_animes`: `mes_membres`, `pages`; `espace_chefs`: `ma_section`, `activites`, `communication`, `gestion`; `espace_admin`: `membres_annee`, `contenu`, `services`, `suivi`; `configuration`: `unite_donnees`, `site`, `modules`, `exploitation`; `notre_unite` is not grouped and accepts none. A value not declared for that route's own `menu` is a load-time `ModuleException`, exactly like an invalid `menu` — a free string would let two modules write "Gestion" and "gestion" and produce two columns meaning the same thing. Columns are drawn in `MENU_GROUPS`' declaration order, never by `menu_order`, which still sorts *within* a column — so a module page and a core page can share one (`finance`'s "Finances" sits under `gestion` beside core pages). Omitting the key is fine and lands the entry in that menu's **last** declared group, where the omission is at least visible; a module page in a real menu should normally declare one. A module's own configuration page under `configuration` belongs in `modules`.

  - Module-to-module ordering (for routes at the default `menu_order`): a superadmin can drag-and-drop reorder modules on the Modules configuration page (`/config/modules`, `module_registry.sort_order`). Each enabled module's position in that order becomes a base offset (`1000 * position`) added to its default-order routes' `menu_order` — so those pages sort by module order relative to each other; this offset, like any other `menu_order` value, only ever matters within the module group (see above), never against core or dynamic entries. See `Core\Module\ModuleManager::loadModule()`.
  - `breadcrumb`: optional. When present, `label` is required (the page's own default breadcrumb label — a Controller can still override it per-request with a `breadcrumb_current` context variable, e.g. for a dynamic member/article title) and `parents` is an optional array of ancestor labels, each naming a *menu* (`Core\View\MenuBuilder`'s `label`, e.g. `"Espace animés"` — core routes derive this string from `Core\View\MenuBuilder::labelFor()` rather than hardcoding it, since `public/index.php` has PHP to call that from; a `module.json` breadcrumb has no such option, being plain JSON, so it keeps its own hardcoded copy of the label text — keep it in sync with `MenuBuilder::MENUS` by hand). A parent is never given its own URL in `module.json` — `partials/breadcrumb_bar.html.twig` matches it against `menus` (the same structure the nav renders from) and links it to that menu's first real page (skipping dynamic per-member entries and any `#` placeholder), or leaves it as plain text when the menu has none, or when the only such page is the one already being viewed (never a self-link, and never an invented URL like `/` or `#`). A route with no `breadcrumb` key is not an error — the breadcrumb bar simply stops at the home icon for that page. Rendered by `partials/breadcrumb_bar.html.twig`, included from `base.html.twig`, and visible at every width — mobile, desktop browser tab, installed PWA alike (pure CSS, see `public/assets/css/app.css` — never a security boundary, same principle as menu visibility, SECURITY §3). Core routes declare the exact same shape as this route's 6th `Core\Http\Router::addRoute()` argument — see the core route table in `public/index.php`.
- **settings**: optional, each entry must have `key`, `type`, `label`, `description`, and may declare `default_value` and `editable` (bool, default `true`).
- **cookies**: optional, each entry must have `name`, `category`, `purpose`, `duration`.
  - `category`: one of `necessary`, `functional`, `analytics`.
- **scheduled_tasks**: optional, each entry must have `key`, `handler` (FQCN).
- **storage**: optional, keys are subdirectory names, values have `role_min`.
- **notifications**: optional, each entry must have `id`, `label`, `description`, `group`, `role_min`, `channels`.
  - `id`: must be prefixed `"{module_id}."` (e.g. `calendar.event_published`).
  - `role_min`: same role list as routes — the minimum role a recipient must currently hold to actually receive it (re-checked at send time, not at whatever moment the caller built the recipient list).
  - `channels`: an object with exactly the keys `in_app`, `push`, `email`, each one of `on` (always sent, member can't opt out), `off` (never sent, member can't opt in), `default_on`/`default_off` (member can override on the preferences page). See the Notifications section below.
- **offline**: optional, each entry must have `path`, `label`, `role_min`. See the Offline pages section below.
- **requires**: optional array of module ids this module cannot function without (hard dependencies). Must be non-empty strings, without duplicates, and never the module's own id. See the Hard dependencies section below.
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

## Contributing menu entries (`Core\Module\MenuEntryProvider`)

A module's own pages get their menu entry from `module.json` automatically — a route with a non-empty `label` becomes one. Use this hook only for entries the manifest cannot express: one per row of your own data, or one that depends on who is looking.

```php
class MyMenuHookService implements \Core\Module\MenuEntryProvider
{
    /** @return \Core\Module\MenuEntry[] */
    public function getMenuEntries(?string $email): array
    {
        // Runs on EVERY request that builds a menu. Keep it to a bounded
        // indexed query or two — never an N+1 walk, never a write.
        return [
            new \Core\Module\MenuEntry(
                \Core\View\MenuBuilder::MENU_NOTRE_UNITE,
                'Local Saint-Georges',
                '/locations/local-saint-georges'
            ),
        ];
    }
}
```

`$email` is the authenticated visitor's address, or **null for an anonymous visitor**. A provider contributing public entries ignores it; one contributing per-visitor entries returns `[]` when it is null.

Wire it in `public/index.php`, inside your module's own conditional block, with `Core\View\DynamicMenuRegistrar` — see ARCHITECTURE.md §7.4 for why the composition root has to call back into `MenuBuilder` there rather than earlier, and use the registrar rather than hand-writing the re-derivation (a copy that drops the active-page refresh yields a correct page with no nav highlight, which no route test catches).

**A menu entry is never a permission.** `MenuEntry::$roleMin` filters display only; the route it points at carries its own `role_min`, and any per-object rule is re-checked server-side in the controller.

## Reacting to a Desk import (`Core\Import\DeskImportListener`)

If your module stores references to `members.id` — a per-object permission grant, an assignment, an ownership — it has derived state to re-sync when the roster becomes authoritative again.

```php
class MyDeskImportListener implements \Core\Import\DeskImportListener
{
    /** @param int[] $activeMemberIds */
    public function onDeskImportCompleted(int $scoutYearId, array $activeMemberIds): void
    {
        // Deactivate, never delete — see below.
    }
}
```

Two rules, both learned the hard way by core itself:

- **Listeners run inside the import transaction**, so a listener that throws rolls the whole import back. Keep the work to bounded, idempotent queries; never a mail send or an HTTP call.
- **Deactivate, never delete.** A member missing from one import (a data-entry slip, a late registration) must come back without an admin re-granting anything, while a member who genuinely left loses access immediately. Journal a **count**, never the member ids — "who has access to what" must not become readable as personal data in the journal.

## Accessing core services

Module controllers receive whatever services they need via constructor injection — there is no fixed list. The composition root (`public/index.php`) is where every controller is actually built and registered: inside the module's `if (in_array('my_module', $moduleManager->getEnabledModuleIds(), true)) { ... }` block, construct the module's repositories/services (passing `$pdo`/`$connection`, `$encryptionService`, `$mailService`, `$schedulerService`, `$sectionService`, or any other already-built core service the module needs), then `$frontController->registerController(MyModuleController::class, new MyModuleController($twig, ...))`. See any existing module block in `public/index.php` for the pattern.

## Scoping a file to your module's own access rule

A module that stores files whose access can't be expressed as a flat `role_min` — readable only by the members of the thing the file belongs to, say — doesn't get a second file route and never bypasses `Core\File\FileAccessGuard`. It plugs into the guard's generic ownership registry (`ARCHITECTURE.md` §8.3). Three steps:

**1. Implement `Core\File\FileOwnershipCheckerInterface`** in your module (e.g. `Modules\MyModule\Service\MyThingOwnershipChecker`):

```php
public function supports(string $ownerType): bool
{
    return $ownerType === 'my_module_thing';
}

public function isAllowed(int $ownerId, Role $currentRole, array $linkedMemberIds): bool
{
    // $ownerId is your own row's id; $linkedMemberIds are the persistent
    // members.id values the current session is linked to.
    return $this->repository->isReadableBy($ownerId, $linkedMemberIds);
}
```

Pick an `owner_type` value prefixed with your module id: the first checker whose `supports()` returns true wins, so two modules claiming the same string would make access depend on registration order. A checker is a pure decision function — never journal from it (`FileController::serve()` already journals denials and owner-scoped accesses) and never pass personal data through it.

**2. Store the pair when you create the file** — the last two parameters of `Core\File\FileRepository::create()`:

```php
$fileId = $fileRepository->create(
    $relativePath, $originalName, $mimeType, $sizeBytes,
    'identified',            // role_min: still the floor, always checked first
    'my_module', $createdBy, false, null,
    'my_module_thing',       // owner_type
    $thing->id               // owner_id
);
```

**3. Get wired in the composition root.** Inside your module's existing `if (in_array('my_module', $moduleManager->getEnabledModuleIds(), true))` block in `public/index.php`, append your checker to the shared array:

```php
$fileOwnershipCheckers[] = new \Modules\MyModule\Service\MyThingOwnershipChecker($myRepository);
```

`FileAccessGuard` is constructed after every module block, so appending there is enough — there is no setter to call and nothing else to register. Two consequences worth knowing: your files are denied to everyone while your module is disabled (the registry is fail-closed — no checker for an `owner_type` means no access, not free access), and your checker can only ever **narrow** access, never widen it, since `role_min` is enforced first and independently. There is no chief/admin bypass either: if you want staff to reach the file, grant it explicitly in your own `isAllowed()`.

## Hard dependencies between modules (`requires`)

A module that genuinely cannot work at all without another one declares it in `module.json`:

```json
"requires": ["trombinoscope"]
```

This is the opposite of the optional dependency described in the next section — use it only when there is nothing to degrade to. If your module can still offer *something* without the other one, it has an optional dependency, not a hard one.

What `Core\Module\ModuleManager` does with it:

- **Loading**: on every request, a module is loaded only when every id in `requires` is present on disk, free of manifest validation errors, and enabled — recursively (a dependency that is itself unsatisfied satisfies nobody, and every module in a dependency cycle is treated as unsatisfiable). An unsatisfied module is simply not loaded: no routes, no settings, no menu entries, no task handlers, never a fatal error. The skip is journaled (`module_requirements_unmet`). A dependency deleted from disk on a live site therefore degrades the dependent module to "not loaded" on the next request, and the site keeps working.
- **Activation**: `activate()` refuses, before running the module's schema migration, with a French message naming the missing module(s).
- **Deactivation**: `deactivate()` refuses while at least one enabled module requires the one being deactivated, naming those modules. Nothing is ever cascaded and no dependency is ever auto-enabled on the admin's behalf — the admin decides, in the order they choose, on `/config/modules`.
- **`enabled_by_default`**: a module with unmet requirements is not auto-activated; it is picked up on the first request where its requirements are satisfied.

The Modules configuration page shows each module's declared dependencies by name and disables the activation toggle while they are unmet.

Nothing about this lives in the database: `requires` is manifest-only, so removing the declaration is enough to remove the dependency.

## Optional dependencies between modules

A module may want to use a capability offered by another module — e.g. `finance` optionally calling `llm_connector` to suggest a receipt's amount/date — without ever hard-depending on it (the other module might be disabled, or not installed at all). This is the same pattern `ARCHITECTURE.md` §7.5 describes for core consuming a module's API, just with a module on both ends:

1. The providing module publishes a stable interface under its own `Api` namespace (e.g. `Modules\LlmConnector\Api\LlmConnectorInterface`) — never require the consuming module to know about the providing module's internal classes.
2. The consuming module's service takes that interface as a **nullable** constructor dependency (`private ?LlmConnectorInterface $llmConnector = null`).
3. Every code path that uses it checks availability first (e.g. `$this->llmConnector?->isAvailable()`) and degrades to "feature simply unavailable" — never an error — when it's `null` or reports unavailable.
4. The composition root (`public/index.php`) is the only place that checks `ModuleManager::getEnabledModuleIds()` and wires the concrete implementation in; it passes `null` when the providing module is disabled.

This keeps both modules independently activatable in any combination without either one breaking.

## Letting another module contribute to yours (a mutable registry)

The third shape, after "core extended by a module" and "a module using another module's capability": a module whose own output another module **contributes to**. `calendar` renders a calendar and `rental` has occupancy that belongs on it; `inbound_mail` reads a mailbox and `rental` knows which of its bookings a message is about. Neither pair can be wired with a plain constructor dependency, because that would be a cycle.

The pattern (`ARCHITECTURE.md` §7.6), in the order you build it:

1. **The extended module publishes the contribution interface** under its own `Api` namespace, plus the value objects it exchanges — `Modules\Calendar\Api\VirtualEventProviderInterface` with `VirtualEvent` and `VirtualEventViewer`; `Modules\InboundMail\Api\MessageConsumerInterface` with `CandidateMessage` and `MessageClaim`. **None of these ever names a contributing module.** If one did, disabling the contributor would break the extended module at autoload time rather than leaving it with one fewer source, and a test should assert that it does not.
2. **The extended module owns a mutable registry** (`Service\VirtualEventRegistry`, `Service\MessageConsumerRegistry`) and hands **the object** — never a snapshot of its contents — to its own controllers and services.
3. **The composition root creates the registry inside the extended module's block**, seeded `null` above it so the variable is defined whichever modules are enabled. The contributing module's block, which runs later in the straight-line script, guards on `!== null` and appends its provider. A contributor registered after the extended module's controllers were constructed still reaches them, which is exactly what breaks the cycle.

Three rules a contribution interface should impose, because the alternative fails quietly:

- **One call per window, never one per entity or per day.** A month view carries dozens of items and a feed hundreds.
- **Rights resolved once per generation**, handed in as one resolved viewer object rather than re-derived per item.
- **Build only what the viewer may see.** Put the privacy decision *before* serialisation, in separate builders rather than one builder with a flag — a field that is never constructed cannot leak through a template, a JSON payload or an ICS line.

And two rules for the registry itself: **swallow a contributor's exception** (one module in trouble must not take the extended module down) and **deduplicate on a stable, contributor-owned identifier** rather than a generated one, so the same underlying thing reaching a reader twice is one item.

Test both directions of disabling. The contributor must degrade to "feature not offered", and the extended module must work with an empty registry — including producing a valid, complete output rather than a truncated one.

## Storing media in a gallery album you own (`Modules\Gallery\Api`)

A module that needs to store photos or videos should not build its own upload, thumbnail, storage-backend and retention machinery — the `gallery` module already has all of it, and its `Api` namespace exposes it as a **delegated album**: a real gallery album that belongs to your module, never listed in the gallery's own pages, whose access rule is entirely yours.

```php
$albumId = $this->delegatedAlbumManager->ensureAlbum('my_module_thing', $thingId, 'Titre', $scoutYearId, $accountId);
$media   = $this->delegatedAlbumManager->addMedia($albumId, $uploadedFile, $accountId);   // $_FILES entry
$all     = $this->delegatedAlbumManager->listMedia($albumId);                              // DelegatedMedia[]
$this->delegatedAlbumManager->deleteMedia($albumId, $mediaId);                             // row + stored objects
$this->delegatedAlbumManager->deleteAlbum($albumId);                                       // the whole album, ditto
```

- **`gallery` is a hard dependency** for this (`"requires": ["gallery"]` — see the section above), because there is no meaningful degradation: a module whose whole feature is posting photos cannot "simply do without them".
- **Authorization is 100% yours.** `DelegatedAlbumManager` performs none: it assumes the caller already checked. You must register **two** checkers, and they must agree — `Core\File\FileOwnershipCheckerInterface` (ARCHITECTURE.md §8.3) so `/files/{id}` is gated, and `Modules\Gallery\Api\DelegatedAlbumAccessChecker` into gallery's own separate registry so the album's media are gated too. Registering only the first leaves the media reachable through gallery's own routes.
- `videoUploadAllowed()` exists so your composer can hide the video option proactively; `addMedia()` refuses one server-side regardless, so it is a UI hint and never the check.
- **From a scheduled task**, do not reassemble gallery's internals — `SchedulerRunner` gives your handler only a `TaskContext`, and gallery's constructors are none of your business. Use `Modules\Gallery\Api\DelegatedAlbumManagerFactory::fromTaskContext($context)`, which builds the manager on gallery's own side of the boundary. `Modules\Groups\Task\PurgeClosedGroupsHandler` is the worked example.
- **A retention purge must delete files, not just rows.** Your module's `ON DELETE CASCADE` cannot reach gallery's tables, let alone an S3 bucket: an orphaned object left behind after its owning row is gone is a retention failure. Delete media through the API **before** deleting the rows that point at them, so a crash halfway leaves a row the next run finds again rather than bytes nothing points at.

The same module publishes `Api\LinkPreviewFetcher` for Open Graph title/description/image of a user-supplied URL. Use it rather than fetching a URL yourself — `Modules\Gallery\Service\OgScraperService` is the only place in this codebase allowed to make an outbound request to a member-supplied address, and it is hardened against SSRF in ways a second implementation would not be (SECURITY.md §17).

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

### The `secret` type

`"type": "secret"` marks a setting whose **value** must never be displayed or exported: it is filtered out of the Paramètres page entirely, and the support package's `configuration-parameters.xlsx` writes `[REDACTED]` in its place while keeping the key and label visible (ARCHITECTURE.md §8.48). Everything else behaves like `text`.

Reach for it only when a credential genuinely has to live in `settings`. The established pattern for a module credential is an encrypted `BLOB` column in the module's own table (`Core\Security\EncryptionService`, decrypted only in the Repository) — `llm_providers.api_key` and the SOS telephony credentials both do this, and neither is ever read by the support package because neither is in `settings`. `secret` is the safety net for the case where that isn't practical, not a reason to stop using encrypted columns.

### `"editable": false` — a setting with its own dedicated UI

`"editable": false` (optional, default `true`) keeps a setting out of Configuration > Paramètres' editable-row list entirely. The setting is still registered, still readable with `$settingService->get()`, and still writable — but only through `SettingService::setInternal()`, which bypasses the `editable` guard on purpose; plain `set()` throws.

Use it when the setting has consequences a plain text field cannot explain, and a page of its own that does. `test_tools`' mail-capture switch is the case it exists for (ARCHITECTURE.md §8.63): armed, no e-mail leaves the server at all, so the switch belongs next to the warning that says so and the list of what was captured, not in a list of options. Like `visible_when`, the field is typed strictly — `"editable": "false"` is a load-time error rather than a truthy string.

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

- `dispatch()` re-checks each recipient's *current* role against the type's `role_min` — for a type whose audience really is "everyone with role X" (the calendar's and the gallery's are), pass every plausible candidate (e.g. every account id via `UserAccountRepository::findAllIds()`) and let it filter, rather than pre-filtering yourself.
- **`role_min` is a floor, never an audience.** If your type's real audience is a membership, an ownership or a subscription — anything narrower than a role — you must resolve it yourself and pass only those recipients. `dispatch()` filters on role and nothing else, so handing it every account id for a type whose audience is "the members of this private group" would notify the entire site. `Modules\Groups\Service\GroupRecipientResolver` is the worked example: it reads membership group-first, resolves each member to an account through the blind index that already backs login (`member_years.email_blind_index` plus any currently-`valid` `member_emails` row — never a new lookup, and no address decrypted on the way), and **deduplicates by `userAccountId`**, because one account linked to several members of the same group must be told once, not once per child.
- Resolve recipients **at dispatch time, never at event time**. Membership read when the post was written is stale by the time the notification goes out; someone who left in between must simply not be in the list, with nothing to invalidate.
- `$actorUserAccountId` only suppresses the actor's *push* — their in-app row is still written, which is right for "something happened in a group you follow" and wrong for a notification whose entire subject is that somebody else acted. For the latter, exclude the actor from the recipient list yourself.
- It always creates the in-app `notifications` row, even for a recipient whose `push`/`email` channel is off for that type.
- Push is never sent synchronously in the request that calls `dispatch()` — it schedules a `core/send_notifications` task (grouped by the recipient's quiet-hours-adjusted send time), which `Core\Notification\Task\SendNotificationsHandler` later batches out via Web Push. Never call anything push-related directly from a controller.
- Never pass personal data as the `title`/`body` beyond what the recipient is already meant to see — both are encrypted at rest, but the type `id` itself is what appears in the journal (`notification_sent`), never the text.
- **Never quote content your module has hidden, refused or flagged.** A push payload lands on a lock screen, readable by whoever is holding the phone, and outlives the item it quotes — so a message that moderation has taken out of the page must not come back through a notification. Decide this in one place rather than at each call site (`Modules\Groups\Service\GroupNotificationService::excerptOf()`).
- **A notification a member can trigger repeatedly needs a debounce.** A reaction, a "like", anything one tap wide will otherwise produce one notification per tap: a post with twelve reactions becomes twelve entries and twelve buzzes for its author. Keep the timing in a small table of your own, keyed by item, and **never read core's `notifications` table** to work out what was already sent — that table is core's. Make the window a `SettingService` key rather than a constant, so "make it stop" has an answer that is not a code change.
- A handful of pre-existing, out-of-scope Maintenance task types (reset/restore/update) use the older, simpler `notify($userAccountId, $title, $body, $url)` instead — single recipient, immediate, no role/channel/quiet-hours resolution. New module types should use `dispatch()`.

## Offline pages (`Core\Offline\OfflineWhitelist`)

A module can make one or more of its own GET pages available for offline viewing in the installed app (ARCHITECTURE.md §8.25) by declaring them under `module.json`'s `offline` section — same aggregation shape as `cookies`/`notifications`: core never hardcodes a module's path, the module declares it, `Core\Module\ModuleManager` registers it into the single shared `Core\Offline\OfflineWhitelist` while loading the module, and a disabled module's page simply never gets registered.

```json
"offline": [
  {
    "path": "/calendar",
    "label": "Calendrier",
    "match": "exact",
    "role_min": "public"
  }
]
```

- Each entry must have `path`, `label` (French — shown nowhere in the UI today, but kept for parity with the rest of the manifest shapes and any future surface that lists whitelisted pages), and `role_min` (same role list as routes — the minimum role a viewer must currently hold for the page to be offered offline; re-checked against the viewer's *current* role every time the list is built, never cached across a role change).
- `match`: optional, defaults to `"exact"` (the literal path only). `"child"` means the path plus **exactly one** additional URL segment — e.g. a hypothetical `/my-module/items/` with `match: "child"` covers `/my-module/items/42` but not `/my-module/items/42/comments/7`. Use this only for a genuine "one page per id" pattern; anything else should be a separate `exact` entry per concrete path.
- **Only declare a page that is actually safe to keep offline.** The whole point of this mechanism is that a module never needs core's permission to add a page — but that also means a module author is the one who has to apply the same judgment ARCHITECTURE.md §8.25 already applies to core pages: never a page carrying financial data, private documents, or content meant for one specific recipient only (mass-mail bodies, anything owner-scoped via `Core\File\FileAccessGuard`). If in doubt, don't declare it — an un-whitelisted page is never a bug, it just isn't cacheable, and falls back to the generic "unavailable offline" dialog/page like any other page a visitor tries to reach without a connection.
- If your page renders any image through `member_photo()`/`section_photo()`/`editable_image()` (§8.39) and you want it to actually show a photo (not just render offline with everything missing), you need a way for `Core\Offline\OfflineManifestService` to know which image URLs to include in `GET /api/offline/manifest`'s response — today this is done ad hoc per core page inside that service (it has no generic per-module hook for this yet). If your module's whitelisted page shows a photo, either reuse an existing image source that service already resolves (e.g. staff photos via `Core\Module\StaffDirectoryProvider`, the same hook that already backs the trombinoscope's offline photos) or raise it with a maintainer — don't grow that service by guessing at conventions ad hoc.
- Whitelisting a page doesn't change its `role_min`, its route, or anything about how it's served online — it only makes it eligible for the service worker's network-first-with-cache-fallback treatment and the pre-download script's proactive warming while offline.

## Protecting a public form (`Core\Security\HumanCheck`)

Any route with `role_min: "public"` that accepts a POST from a visitor who might not be identified — a response form, a contact form, anything anonymous submission-shaped — should go through `Core\Security\HumanCheck\HumanCheckService` (`ARCHITECTURE.md` §8.31) rather than rolling a module-specific anti-spam mechanism. No captcha, no external service, nothing to configure per module beyond the four core `SettingService` keys that already apply site-wide.

A module depends on core here directly (dependency module → core), no interface, no composition-root wiring — `HumanCheckService` is a plain core service like `EncryptionService` or `JournalService`, constructed once in `public/index.php` and passed to whichever controllers need it.

**1. On the GET that renders the form**, generate a challenge for a non-identified session and pass it to the template:

```php
'human_check' => $accountId === null
    ? $this->humanCheck->generateChallenge('my_module_form_key')
    : null,
```

**2. In the Twig template**, inside the `<form>`, one line:

```twig
{% include 'partials/human_check_fields.html.twig' with { human_check: human_check } %}
```

That's the entire integration surface — the partial renders the signed token and the honeypot field (hidden via a CSS class, never inline style — SECURITY.md §9's CSP), styled and made keyboard/screen-reader-unreachable already. Nothing else to add to the template, no extra JS.

**3. On the POST that handles the submission**, verify before doing anything else with the data:

```php
$result = $this->humanCheck->verify(
    'my_module_form_key',           // same key used to generate the challenge
    AuthSession::isAuthenticated(), // or your own "is this session identified" check
    $request->getBodyAll(),         // full body — the honeypot's field name isn't known in advance
    $request->getServer('REMOTE_ADDR', '')
);

if (!$result->accepted) {
    // Re-render the SAME form with a fresh challenge and a generic error
    // message — never a dead-end error page, and never a message that
    // reveals which of the three barriers triggered.
    return $this->render('@my_module/my_form.html.twig', [
        // ...whatever the visitor already entered, so they don't retype it...
        'submit_error' => 'Une erreur est survenue. Veuillez réessayer.',
        'human_check' => $this->humanCheck->generateChallenge('my_module_form_key'),
    ]);
}
```

`verify()` never touches `$_SESSION`/`$_POST` itself — the Controller is the one reading the request and deciding whether the session is identified, then passing both in as plain parameters. An identified session always short-circuits to accepted, skipping all three barriers, so `generateChallenge()`/`verify()` calls for one are a harmless no-op if your form happens to also render for identified visitors.

The one thing a module never needs to declare: `human_check_rate_limits` is a core table (`schema/core.sql`), not something a module's own `schema.sql` touches, and its purge task is a core scheduled task already registered in both entry points — nothing to add to `module.json`'s `scheduled_tasks`.

If your form has its own, differently-scoped rate limiting already (e.g. a per-email counter for a login-adjacent flow), read `ARCHITECTURE.md` §8.31's magic-link example before deciding whether to also enable `HumanCheckService`'s own IP-based counter (`verify()`'s `$enforceRateLimit` parameter, default `true`) — two mechanisms counting the same abuse pattern in two different tables produce inconsistent thresholds and unexplained lockouts.

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
- Automated tests are mandatory for every feature. If your module ships its own `public/assets/js/` behavior that is deterministic and reasonably decoupled from the DOM (not just wiring existing core components like the chip picker together), add a Vitest spec under `tests/js/` exercising it — see `AGENTS.md` § Tests and `ARCHITECTURE.md` § 15. Your module's own PHP-side JavaScript never needs a Node/build dependency to ship — the test tooling stays dev-only.

## Reading a mailbox (`Modules\InboundMail\Api`)

A module that needs the replies people send about its own objects — a booking, an invoice, a registration — should not build IMAP, MIME parsing and attachment handling of its own. The `inbound_mail` module has all of it, and consuming it is the registry pattern above with one extra rule.

1. **Implement `Api\MessageConsumerInterface`.** `consumerId()` returns a stable string (your module id). `claim(CandidateMessage)` answers *which of my objects is this about* — returning `null` for "not mine", **and also for "several of mine"**: attaching a message to whichever of two candidates sorted first is worse than not attaching it, because the person reading the wrong file has no way to know. `onMessageStored(InboundMessage)` runs after the message is written, and is where you do your own bookkeeping (turning attachments into your own documents, for instance).
2. **Try the reliable identifications first.** An explicit reference in the subject, then the thread headers (`InboundMailInterface::findReferenceByThread()` resolves those for you — you cannot look inside the other module's storage, and should not), then anything weaker, bounded. Record which one answered: `Api\LinkOrigin` carries it, and your interface should show a weak match as the guess it is.
3. **Register the consumer in the composition root**, guarded on the registry existing.
4. **Consume `Api\InboundMailInterface` as a nullable dependency** for everything else — listing a thread, detaching, moving, purging. Every method is scoped to your consumer id and one business reference, and there is deliberately no way to ask for anything broader.

Two things this module guarantees so you do not have to: **nothing is ever written to the remote mailbox**, and **a message nobody claims is never stored**. The second is the one to keep in mind while writing `claim()` — a permissive matcher does not just mis-file a message, it turns the site into an archive of somebody's mailbox.

What your own module still owns: whether the *user* in front of you may reach the reference you are asking about. `inbound_mail` cannot know your authorisation rules, so it does not try.
