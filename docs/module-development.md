# Developing a module

This guide explains how to create a module for ScoutMagic. Modules are self-contained features that integrate with the core system via a standardized manifest and lifecycle.

## Directory structure

```
modules/my_module/
  module.json          # Manifest (required)
  schema.sql           # Database tables (optional)
  drops.sql            # Reviewed column/FK/table drops (optional)
  src/
    Api/               # Public contract: interfaces + value objects + your
                       # user-facing exception — the ONLY namespace other
                       # modules and core may name (the architecture test
                       # enforces it)
      MyCapabilityInterface.php
    Controller/
      MyModuleController.php
      ConfigController.php
    Service/
      MyModuleService.php
    Repository/
      MyModuleRepository.php
    Task/
      MyScheduledHandler.php   # module.json scheduled_tasks handlers
  views/
    index.html.twig
    config.html.twig
  help/
    my-module.md       # Contextual help topics (Markdown + front matter)
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
        "parents": ["Espace membres"],
        "ancestors": [{ "label": "Calendrier", "path": "/calendar" }]
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
  "emails": [
    {
      "id": "calendar.multiday_event_reminder",
      "label": "Rappel d'évènement sur plusieurs jours",
      "description": "Envoyé au staff de la section quelques jours avant un évènement qui dure plus d'une journée.",
      "default_subject": "Rappel — {{ event_title }}",
      "template": "@calendar/email/multiday_event_reminder.html.twig",
      "variables": [
        { "name": "event_title", "label": "Titre de l'évènement", "example": "Week-end de section" }
      ]
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
  - `menu_icon`: optional string, the Bootstrap Icons class drawn beside the entry — `"bi-calendar3"`, `"bi-mortarboard"`. Validated at load time against `/^bi-[a-z0-9-]+$/`, so a typo is a `ModuleException` rather than an invisible glyph, and an empty string is the same as omitting the key. **Declare one on every labelled route**: every sub-page entry starts with an icon, in the same box the per-member entries use for their avatar, so all the labels in a menu line up — an entry without one leaves a hole in that column, which reads as a rendering defect rather than as a choice. Pick from the vocabulary already on screen where one fits (`bi-people`, `bi-cash-coin`, `bi-images`, `bi-calendar3`); §7.4 of design.md fixes only the four action icons, not these.
  - `menu_group`: optional string, naming which **titled column** of its menu the entry is drawn in on the desktop mega-menu. Nothing to do with `menu_order` above: `menu_order` decides where in a list an entry lands, `menu_group` decides which column draws it. The vocabulary is closed per menu and declared once in `Core\View\MenuBuilder::MENU_GROUPS` — `espace_animes`: `mes_membres`, `pages`; `espace_chefs`: `ma_section`, `activites`, `communication`, `gestion`; `espace_admin`: `membres_annee`, `contenu`, `services`, `suivi`; `configuration`: `unite_donnees`, `site`, `modules`, `exploitation`; `notre_unite` is not grouped and accepts none. A value not declared for that route's own `menu` is a load-time `ModuleException`, exactly like an invalid `menu` — a free string would let two modules write "Gestion" and "gestion" and produce two columns meaning the same thing. Columns are drawn in `MENU_GROUPS`' declaration order, never by `menu_order`, which still sorts *within* a column — so a module page and a core page can share one (`finance`'s "Finances" sits under `gestion` beside core pages). Omitting the key is fine and lands the entry in that menu's **last** declared group, where the omission is at least visible; a module page in a real menu should normally declare one. A module's own configuration page under `configuration` belongs in `modules`.

  - Module-to-module ordering (for routes at the default `menu_order`): a superadmin can drag-and-drop reorder modules on the Modules configuration page (`/config/modules`, `module_registry.sort_order`). Each enabled module's position in that order becomes a base offset (`1000 * position`) added to its default-order routes' `menu_order` — so those pages sort by module order relative to each other; this offset, like any other `menu_order` value, only ever matters within the module group (see above), never against core or dynamic entries. See `Core\Module\ModuleManager::loadModule()`.
  - `breadcrumb`: optional. When present, `label` is required (the page's own default breadcrumb label — a Controller can still override it per-request with a `breadcrumb_current` context variable, e.g. for a dynamic member/article title). A route with no `breadcrumb` key is not an error — the bar simply stops at the home icon for that page. Rendered by `partials/breadcrumb_bar.html.twig`, included from `base.html.twig`, visible at every width (pure CSS — never a security boundary, same principle as menu visibility, SECURITY §3). Core routes declare the exact same shape as this route's 6th `Core\Http\Router::addRoute()` argument — see the core route table in `public/index.php`. **Two optional keys name the intermediate steps, and they are not interchangeable:**
    - `parents`: an array of ancestor labels each naming a *menu* (`Core\View\MenuBuilder`'s `label`, e.g. `"Espace membres"` — core routes derive this string from `MenuBuilder::labelFor()` rather than hardcoding it, since `public/index.php` has PHP to call that from; a `module.json` breadcrumb has no such option, being plain JSON, so it keeps its own hardcoded copy — keep it in sync with `MenuBuilder::MENUS` by hand). **A parent is never a link to a page**: clicking one opens that menu instead (the desktop mega-menu panel, or the mobile offcanvas with the matching section expanded), because most menu categories have no landing page at all and the ones that do would send everyone to an arbitrarily chosen member of the set. A label matching no current menu renders as plain text, never an invented URL.
    - `ancestors`: an ordered, outermost-first array of `{label, path}` naming real ancestor **pages** — `[{"label": "Calendrier", "path": "/calendar"}]` above an activity, `[{"label": "Actualités", "path": "/news"}]` above an article. **These do render as real links**, which is the whole point: a detail page otherwise offers no way back up to its list, and the site has no back button by convention (`design.md` §7.3). Declare **one fixed ancestor**, statically — a page reachable from several lists shows the one you chose here, with no referrer sniffing and no session state. `path` must be a concrete page: a relative path or a `{placeholder}` is a load-time `ModuleException`, since an ancestor is a list URL and there is nothing to resolve a placeholder against. Point it at the **bare list**, never at a filtered or searched variant of it — the browser's back button already restores that state. A step whose route the visitor's role cannot reach is dropped by `Core\Http\Router::ancestorTrailFor()` rather than linking to a 403, and so is one naming no declared route at all (a module the admin disabled degrades quietly). `tests/Core/Http/BreadcrumbAncestorRoutesTest` fails on an ancestor naming no route, or one stricter than the page under it.
    - When the ancestor genuinely depends on the row being shown — a booking under *its* asset, a form's responses under *their* article — it cannot be declared here. Pass a `breadcrumb_trail` context variable from the Controller instead (`array<int, array{label: string, url: string}>`); it renders after the declared ancestors, in the same link style.
- **settings**: optional, each entry must have `key`, `type`, `label`, `description`, and may declare `default_value` and `editable` (bool, default `true`).
- **cookies**: optional, each entry must have `name`, `category`, `purpose`, `duration`.
  - `category`: one of `necessary`, `functional`, `analytics`.
- **scheduled_tasks**: optional, each entry must have `key`, `handler` (FQCN).
- **storage**: optional, keys are subdirectory names, values have `role_min`.
- **notifications**: optional, each entry must have `id`, `label`, `description`, `group`, `role_min`, `channels`.
  - `id`: must be prefixed `"{module_id}."` (e.g. `calendar.event_published`).
  - `role_min`: same role list as routes — the minimum role a recipient must currently hold to actually receive it (re-checked at send time, not at whatever moment the caller built the recipient list).
  - `channels`: an object with exactly the keys `in_app`, `push`, `email`, each one of `on` (always sent, member can't opt out), `off` (never sent, member can't opt in), `default_on`/`default_off` (member can override on the preferences page). See the Notifications section below.
  - `default_on_role_min`: optional, must be at or above `role_min`. The role from which this type's `default_on` channels actually start on — below it the type still appears on the preferences page with the same switches, all starting off. For a type that is genuinely useful to two audiences with opposite expectations: core's `core.update_installed` is `role_min: "admin"` + `default_on_role_min: "superadmin"`, so whoever runs the site is told automatic updates happened without asking, and an admin can ask. Omit it (the usual case) and the declared defaults apply to everybody the type is offered to.
- **emails**: optional, each entry must have `id`, `label`, `description`, `default_subject`, `template`.
  - `id`: must be prefixed `"{module_id}."` (e.g. `calendar.multiday_event_reminder`).
  - `editable`: optional boolean, defaults to `true`. `false` for any authentication e-mail — the refusal is enforced server-side, not by hiding a button.
  - `variables`: optional list of `{name, label, example}`. `name` is a lowercase identifier, unique within the entry; it is substituted as a plain string and never evaluated. See the E-mails section below.
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

## CSRF token in a form (`csrf_field()`)

Every `<form method="post">` carries one, no exceptions (`AGENTS.md`
§ Security checklist). The Twig function writes the whole hidden input:

```twig
<form method="post" action="/mon-module/enregistrer">
    {{ csrf_field()|raw }}
    …
</form>
```

The function is registered with `['is_safe' => ['html']]`
(`Core\View\TwigFactory`), so **`|raw` is a no-op** and plain
`{{ csrf_field() }}` produces exactly the same markup. Write the filter
anyway, every time: a reader auditing which templates emit raw HTML greps
for `|raw`, and a call site without it is one that grep misses — including
the ones that are safe by construction. Precisely because nothing breaks
when the two forms drift apart, they drift, so this is the one form and
`Tests\Core\View\UxConventionsTest::testCsrfFieldIsAlwaysWrittenWithTheRawFilter`
keeps it that way.

Do NOT hand-write the input: the token itself comes from
`Core\Security\CsrfGuard::generateToken()`, and a template composing the
markup around it is a template that has to remember to escape it.
`csrf_token()` exists for the one case that genuinely needs the bare value
(a `<meta>` tag a fetch() reads).

The controller side is `$this->guardCsrf($request, '/where/to/go/back')`
— see `AbstractController`; the guard is never called by the router.

## Selection components: select bar and nav rail

The site has **two** selection components, for two genuinely different
needs. They share no markup and no JS. Picking between them is not a
preference:

> **The rule**: *fixed set, declared in code, short labels → nav rail.
> Open-ended set, coming from the database → select bar.*

| Need | Component | Shape |
|---|---|---|
| Pick a piece of **data** — a section, a calendar, an account, a rentable asset, badges. The list is data-driven, grows with the unit, labels are long. | **Select bar** (`partials/select_bar.html.twig`) | One full-width row: field name + current value + chevron, opening a disclosure panel with the full list. |
| Move between the fixed **sub-pages or views of one page** — finance pages, rental management pages, groups tabs, a status filter declared in code. The set is small, fixed, short-labelled. | **Nav rail** (`partials/nav_rail.html.twig`) | One horizontally-scrollable row of underlined tabs, never wrapped, never folded, selected tab auto-centred. |

Both render **every item server-side**. Neither hides anything: no `+N`
overflow chip, no client-side fold, no post-render DOM measurement. The
single component these replaced did all three, and on a phone it served
both needs badly — `/finance` could show four rows of chips before the
first line of content.

Do **not** add a use-case-specific parameter to either one (no
`is_section`, no `for_finance`). If a call site seems to need one, it is
using the wrong component — re-read the rule above.

### Select bar (`partials/select_bar.html.twig`)

```twig
{% include 'partials/select_bar.html.twig' with {
    picker_id: 'my-picker',
    label: 'Section',
    items: [
        { id: 1, label: 'Louveteaux', sublabel: 'Meute', color: '#f5a623', badge: null, selected: true },
        { id: 2, label: 'Éclaireurs', sublabel: 'Troupe', color: '#4a90d9', selected: false }
    ],
    mode: 'single',
    base_url: '/my-page?item=',
    empty_text: 'Aucun élément disponible.'
} %}
```

**Item fields**: `id` and `label` required. `sublabel` and `badge` are
optional and appear on the panel row. `color` (optional hex string) draws
a small dot; always source it from whatever this app's single color source
of truth is for your data (e.g. `Core\Member\SectionService::colorForSection()`
for anything section-derived) — never recompute or hardcode a color here.
`selected` (bool) marks the current selection(s).

**Other parameters**: `label` is the field name shown as a caption above
the current value. `extra_query` is appended to every row's href.
`empty_text` replaces the whole control when `items` is empty.
`none_selected_text` is the trigger's value when nothing is selected.
`count_label` is the plural noun the `multi` trigger uses when more than
one item is selected (« 2 badges »).

**The panel is a native `<details>`/`<summary>`** — never a Bootstrap
offcanvas, a modal, or a `<select>`. The precedent is
`modules/groups/views/list.html.twig`, which uses one because it "opens,
closes and announces its own state with no JavaScript at all". That is
what preserves the JS-off guarantee: an offcanvas panel could never be
opened at all with JS off, which would break every page in
`Core\Offline\OfflineWhitelist` (`/calendar`, `/trombinoscope`,
`/notifications`). The panel is anchored under the bar with its own
`max-height` and scroll — not a fixed bottom sheet, so there is no
backdrop, no scroll-lock and no iOS safe-area handling to get wrong.

**Modes**:
- `single` — panel rows are `<a href="{{ base_url }}{{ item.id }}{{ extra_query|default('') }}">`.
  Selection needs no JS at all: a plain click, a screen reader, a JS-off
  browser and a cached offline page all already work. `aria-current="true"`
  marks the selected row.
- `multi` — panel rows are `<button aria-pressed>` toggled by
  `public/assets/js/select-bar.js`, which dispatches a `select-bar:change`
  `CustomEvent` (`detail: { selectedIds }`) on the picker container after
  every toggle. **The partial and its JS never persist a selection
  themselves** — listen for that event and do whatever your case needs (a
  cookie, a form submit, a fetch call). The panel stays open across
  toggles, which is native `<details>` behaviour and needs no code. The
  trigger summarises the selection (« Aucun badge » / « Infirmier » /
  « 2 badges ») rather than drawing every pick.

`window.SelectBar.setSelected(pickerId, id, selected)` is the escape hatch
for a `multi` caller that must correct a selection from outside a user
click — typically reverting its own optimistic toggle after the server
rejects it. It applies the same visual update a click does but **never
dispatches `select-bar:change`**, so correcting a rejected toggle cannot
loop back into your own listener.

**Degenerate cases**: zero items renders `empty_text` and no control at
all. A single item in `mode: single` renders as static text — no chevron,
no `<details>` — because navigating to the only option is a no-op; its
sublabel, badge and colour dot still show, since they describe the value
rather than being part of choosing one. `mode: multi` keeps its control at
one item, because "assigned or not" is still a real choice.

### Nav rail (`partials/nav_rail.html.twig`)

```twig
{% include 'partials/nav_rail.html.twig' with {
    picker_id: 'my-page-picker',
    items: [
        { id: '/my/page', label: 'Vue A', selected: true },
        { id: '/my/page/other', label: 'Vue B', selected: false }
    ],
    base_url: '',
    extra_query: '?filter=3',
    aria_label: 'Pages de mon module'
} %}
```

Bootstrap's own `nav nav-underline` + `flex-nowrap` + `overflow-auto`, so
nothing here duplicates a Bootstrap component. `id` and `label` are
required, `selected` and `color` optional. The link is
`{{ base_url }}{{ item.id }}{{ extra_query }}`, so an `id` may equally be
a numeric id or a full path. `aria-current="page"` marks the selected tab.
`public/assets/js/nav-rail.js` scrolls that tab into view (honouring
`prefers-reduced-motion`) and does nothing else — the rail is complete and
operable without it.

Underlined tabs here are a deliberate, approved **partial reversal of
UX-convergence decision #4** ("nav-pills → chips"); see `design.md` §7.6.
Chips remain wrong for sub-navigation — but so were pills.

### `picker_id`

Unique per instance on a page, for either component: it becomes the DOM
id, and it is what `window.SelectBar.setSelected` and your own scripts
grip. Two instances on one page need two different values (the Staffs
page uses `'badge-picker-' ~ member.memberYearId`).

### Touch sizing

Never write `style="min-height:44px"` in either component or in a mapping
layer over it. The `<summary>` and the rail's tabs carry `.tap-target`,
which `app.css`'s `pointer: coarse` block sizes — that block is the only
place touch sizing lives (`design.md` §7.2), and an inline style would
override it including the desktop restore.

### Thin mapping layers — the pattern to copy

Three shipped examples, none of which owns any presentation of its own;
each only maps its domain data into the generic item format and includes
the component:

- `core/View/templates/partials/section_picker.html.twig` — sections →
  select bar (`mode: single`).
- `modules/calendar/views/partials/calendar_picker.html.twig` — calendar
  options → select bar (`mode: single`), keeping `extra_query` so the
  displayed month survives switching calendar.
- `core/View/templates/partials/page_picker.html.twig` — a `pages[]` list
  → nav rail, with the longest-match selection rule (`/finance/movements/12`
  selects « Mouvements », never also « Tableau de bord »).

Their include signatures are the point: a layer's callers never change
when the component underneath does. All eight call sites of
`section_picker` and `page_picker` were untouched when both were moved
off the old chip picker. If you find yourself editing a call site to
accommodate a component change, the layer's signature has drifted and
that is the bug.


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

## Contributing an attention point (`Core\Attention\AttentionPointProvider`)

The page **Espace chefs d'U > Points d'attention** shows what is currently not right about the unit — a badge nobody holds, a section no longer supervised in sufficient numbers, a household whose tariff has become wrong. Nothing on it is stored and nothing is ever acknowledged: a point disappears because it stopped being true, never because somebody clicked. If your module can answer a question of that shape, it contributes one.

```php
class MyAttentionProvider implements \Core\Attention\AttentionPointProvider
{
    public function sourceLabel(): string
    {
        return 'Cotisations'; // shown as the chip above each of your points
    }

    /** @return \Core\Attention\AttentionPoint[] */
    public function collect(int $scoutYearId): array
    {
        return [new \Core\Attention\AttentionPoint(
            title: '6 foyers portent une catégorie tarifaire devenue fausse',
            why: 'Écart estimé de 87,75 € sur la prochaine facture de la fédération.',
            actionLabel: 'Ouvrir la justesse des tarifs',
            actionUrl: '/admin/fees/tarifs',
            dueDate: null,                 // optional: renders "dans N jours" and sorts to the top
        )];
    }
}
```

Wire it in the composition root's own block for your module, by appending to `$attentionProviders` — the same registry shape as `$fileOwnershipCheckers`; the service is built once every module block has run.

Three rules, and the first is why this is not `DeskImportListener`:

- **It is called when the page is displayed, not during the import, and its exceptions are caught.** `DeskImportListener` runs inside the import transaction and a listener that throws rolls the whole import back — right for reconciling derived state, catastrophic here. A module that is merely *wrong about* the unit must never be able to stop an import or break a page. A provider that throws is listed on the page as unable to contribute; every other provider still renders.
- **Stay bounded.** The page is opened on demand and every provider runs on every display. Aggregate queries only — a provider that decrypts the whole roster each time will make the page unusable, slowly, as the unit grows.
- **Most modules have nothing to contribute, and that is the normal case.** Do not implement this "for consistency": an empty implementation is dead code a reviewer cannot tell apart from "not done yet". `DeskImportListener` has existed for a long time and one module out of twenty implements it. The question to ask when creating a module is *"does this module have an attention point to report?"* — and the answer is usually no.

Each point carries four things, and they are the four a reader needs: what is wrong, why it matters, what to do about it, and — when there is one — by when. Write `why` as the consequence, never a restatement of the title.

## Declaring your sub-processors (`Core\Module\SubProcessorProvider`)

If your module's configuration can engage an external data processor — an
object-storage provider, an AI provider, any third party the unit's data
reaches — implement `Core\Module\SubProcessorProvider` so the RGPD page's
generation prompt states it without core ever reading your tables:

```php
class MyStorageSubProcessorService implements \Core\Module\SubProcessorProvider
{
    /** @return list<\Core\Module\SubProcessorView> */
    public function getSubProcessors(): array
    {
        // Inspect the REAL configuration: a potential sub-processor that
        // nothing is configured to reach is NOT a sub-processor, and the
        // empty list is the ordinary answer.
        return [new \Core\Module\SubProcessorView(
            \Core\Module\SubProcessorView::CATEGORY_MEDIA_STORAGE,
            'Hetzner Object Storage (Allemagne/Finlande, UE)',   // name + location, in French
            'Hébergement des photos et vidéos de la galerie'
        )];
    }
}
```

- **Dynamic, always.** Answer what is effectively active right now — the
  S3 provider actually configured, the AI provider actually enabled and
  its assigned models — with its data location worded for the RGPD
  document. Declaring a merely *possible* processor would make the
  document claim a data flow that does not exist.
- `category` comes from the closed `SubProcessorView::CATEGORY_*`
  vocabulary, because the generation prompt has to branch on it — the
  same "small set of constants core declares" rule as the member-page
  views below. A new kind of processor means adding a constant, a product
  decision rather than a free string.
- The implementation lives in your module's `Service\`, not its `Api\`
  (the interface is core's — §7.4). Wire it in the composition root,
  inside your module's own block:
  `$rgpdContentService->addSubProcessorProvider(new MyStorageSubProcessorService(...));`
- Two shipped examples: `Modules\Gallery\Service\GalleryStorageSubProcessorService`
  (one view per configured S3 provider, none for local storage) and
  `Modules\LlmConnector\Service\LlmSubProcessorService` (the active AI
  provider and its assigned models, none when unconfigured).
- This complements — never replaces — AGENTS.md § RGPD page maintenance:
  the *default* RGPD content is still a static document to keep in step.

## Filling a block on a member's page (`Core\Module\…Provider`)

Two core pages consolidate what the site knows about one person — a member's own page (`/members/{id}`) and the Staff d'Unité's page for them (`/admin/members/{id}`). Neither knows anything about your module. Each block they can show comes from a small interface core declares (ARCHITECTURE.md §7.4), which your module optionally implements; the composition root injects it **nullable**, so a disabled module means the block is not built at all — never an error, never an empty card.

The ones that exist today:

| Interface | Answers | Shown on |
|---|---|---|
| `Core\Module\FormationPathProvider` | where this animateur is in their training | the member's own page, self only |
| `Core\Module\MemberPaymentProvider` | `getOpenPayments()` — what is still owed | both pages |
| `Core\Module\MemberPaymentProvider` | `getSettledPayments()` — what is over, capped at `SETTLED_LIMIT`, most recent first | the admin page only |
| `Core\Module\MemberRegistrationOriginProvider` | which registration request this member came from | the admin page only |
| `Core\Module\MemberCampStayProvider` | which stays this member's sections went on, capped at `LIMIT` | the admin page only |
| `Core\Module\MemberDiscussionGroupProvider` | which discussion groups this member belongs to | the admin page only |
| `Core\Module\SectionResponsableProvider` | who runs this member's section | the member's own page |

Before writing a new one, check whether one of these already asks your question. The shape to copy, when none does:

```php
interface MyThingProvider
{
    public function getMyThing(int $memberId): ?MyThingView;   // or list<MyThingView>
}
```

- **One interface per module, one question per method.** Resist a generic "tell me everything about this member" hook: it becomes exactly the coupling point §7.4 exists to prevent, and the first module with nothing to say still has to implement it. `MemberPaymentProvider` carries two methods, and that is a deliberate exception with a reason: open and settled receivables are two genuinely different questions, and widening `getOpenPayments()` with a flag would have rippled through callers — the member's own page and the homepage band — that asked for nothing.
- **The identifier is a `members.id`**, the persistent identity, never `member_years.id`. A debt does not expire when the scout year turns, and a registration request produced a person rather than one year's row.
- **A read DTO beside the interface**, `public readonly` properties and no logic. Return `?XxxView` for one object, `list<XxxView>` for a collection, never an improvised associative array.
- **Presentation-ready, in the module's own words.** Core owns the template but not the domain: hand over a label and an amount in cents, not your internal vocabulary. The exception is anything core has to *branch* on — a status that picks a badge colour — which is a small set of constants core declares, and your module maps onto.
- **Say what `null` means** in the docblock: module absent, or no data. And treat "no data" as the ordinary case — most members owe nothing and came from no request, so the page draws nothing rather than « aucune donnée ».
- **Say what your answer INFERS, when it infers anything.** `MemberCampStayProvider` is the example: nothing records a camp's participants one by one, so "went on" means "their section went, in a year they were in it". Write that in the interface's docblock. A hook that quietly claims more than the tables hold is the kind of wrong nobody catches, because the page looks right.
- **The hook decides nothing about who may look.** The page has already answered that. A provider that re-derived its own audience would be a second answer waiting to disagree with the router's.
- **The implementation lives in your module's `Service\`, not its `Api\`.** `Api\` is where a module *publishes* an interface of its own for others to consume (§7.5); here the interface is core's and you are implementing it (§7.4). Wiring it is ONE line in your module's own `if ($isEnabled('my_module'))` block: `$moduleHooks->register(TheHookInterface::class, $yourService);` — the consumer resolves it per request through `Core\Module\HookRegistry::getOptional()`, so there is no constructor argument to thread, no null-seed, and no controller re-registration. (Only a hook that takes SEVERAL contributors — menus, RGPD sub-processors, Desk-import listeners — uses its own dedicated registry instead.)

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

**The `Api\` namespace is strict** (ARCHITECTURE.md §7.5): interfaces, immutable value objects, and the module's user-facing exception when the contract can throw one — nothing else, and every type an `Api\` signature or `@throws` mentions must itself live in `Api\` or in core. A concrete service never goes there, even one another part of the codebase consumes: a core-hook implementation lives in `Service\` behind the core interface it implements. The one sanctioned concrete class is `Modules\Gallery\Api\DelegatedAlbumManagerFactory` — a factory that assembles the module's internals *for* the consumer — and it is a model to copy for that exact need, not a precedent for anything else.

## Letting another module contribute to yours (a mutable registry)

The third shape, after "core extended by a module" and "a module using another module's capability": a module whose own output another module **contributes to**. `calendar` renders a calendar and `rental` has occupancy that belongs on it; `inbound_mail` reads a mailbox and `rental` knows which of its bookings a message is about. Neither pair can be wired with a plain constructor dependency, because that would be a cycle.

The pattern (`ARCHITECTURE.md` §7.6), in the order you build it:

1. **The extended module publishes the contribution interface** under its own `Api` namespace, plus the value objects it exchanges — `Modules\Calendar\Api\VirtualEventProviderInterface` with `VirtualEvent` and `VirtualEventViewer`; `Modules\InboundMail\Api\MessageConsumerInterface` with `CandidateMessage` and `AnalysisResult`. **None of these ever names a contributing module.** If one did, disabling the contributor would break the extended module at autoload time rather than leaving it with one fewer source, and a test should assert that it does not.
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
$moved   = $this->delegatedAlbumManager->moveMedia('my_module_thing', $fromAlbumId, $toAlbumId); // merge two of yours
```

- **`gallery` is a hard dependency** for this (`"requires": ["gallery"]` — see the section above), because there is no meaningful degradation: a module whose whole feature is posting photos cannot "simply do without them".
- **Authorization is 100% yours.** `DelegatedAlbumManager` performs none: it assumes the caller already checked. You must register **two** checkers, and they must agree — `Core\File\FileOwnershipCheckerInterface` (ARCHITECTURE.md §8.3) so `/files/{id}` is gated, and `Modules\Gallery\Api\DelegatedAlbumAccessChecker` into gallery's own separate registry so the album's media are gated too. Registering only the first leaves the media reachable through gallery's own routes.
- `videoUploadAllowed()` exists so your composer can hide the video option proactively; `addMedia()` refuses one server-side regardless, so it is a UI hint and never the check.
- **From a scheduled task**, do not reassemble gallery's internals — `SchedulerRunner` gives your handler only a `TaskContext`, and gallery's constructors are none of your business. Use `Modules\Gallery\Api\DelegatedAlbumManagerFactory::fromTaskContext($context)`, which builds the manager on gallery's own side of the boundary. `Modules\Groups\Task\PurgeClosedGroupsHandler` is the worked example.
- **A retention purge must delete files, not just rows.** Your module's `ON DELETE CASCADE` cannot reach gallery's tables, let alone an S3 bucket: an orphaned object left behind after its owning row is gone is a retention failure. Delete media through the API **before** deleting the rows that point at them, so a crash halfway leaves a row the next run finds again rather than bytes nothing points at.

- **`moveMedia()` merges two albums you own** — for when the entities behind them merge. It re-parents every media and moves its renditions server-side (an S3 `CopyObject`, a filesystem copy), never re-uploading anything. It refuses, changing nothing, when either album is not yours, when both ids are the same album, or when the two sit on **different storage locations** — a rendition's bytes cannot cross backends without passing through PHP, and the location is resolved from the album, so a media left behind would be unservable the moment it landed. Do not work around that by moving rows yourself: a rendition's key embeds the album it was written under, and album deletion clears storage by prefix.

Core publishes `Core\Http\LinkPreviewFetcher` for Open Graph title/description/image of a user-supplied URL, and `gallery` provides its one implementation. Take it as a nullable dependency and use it rather than fetching a URL yourself — `Modules\Gallery\Service\OgScraperService` is the only place in this codebase allowed to make an outbound request to a member-supplied address, and it is hardened against SSRF in ways a second implementation would not be (SECURITY.md §17).

## Database

- Create a `schema.sql` in the module root with complete table definitions (not incremental migrations).
- Table names should be prefixed with the module id to avoid collisions (e.g., `calendar_events`).
- All table/column names in English, snake_case.
- Personal data fields must use `BLOB` type and be encrypted/decrypted via `EncryptionService`.
- Include a `scout_year_id` foreign key on member-related data tables.
- A module that stores confidential *files* (not just database fields) — receipts, private documents, anything that must never be readable directly off disk — should use `Core\File\EncryptedFileStorageService` (`store()`/`retrieve()`/`delete()`) instead of `UploadHandler`. It uses the same master key as `EncryptionService` and integrates transparently with `FileAccessGuard`/`/files/{id}` — the caller never handles decryption itself.
- **Editing `schema.sql` for a module that may already be enabled somewhere (i.e. any change after the module's first release — new column, new table, changed default, etc.)? Bump `version` in `module.json` in the same change.** `ModuleManager::loadEnabledModules()` only re-diffs and re-applies a module's `schema.sql` when the manifest's `version` compares greater than the version recorded in the module registry (`ModuleManager.php`, the "Auto-migrate when module version is newer than installed version" block). Editing `schema.sql` without bumping `version` is silently a no-op on every already-enabled installation — the new column/table only ever gets created for a *fresh* activation, never retrofitted onto an existing one. This has caused real `Unknown column` / `PDOException` production errors from schema changes that looked complete in code review but were never actually applied to the running database. There is no separate reminder or lint for this — bumping the version is the only signal that triggers migration, so treat "I touched schema.sql" and "I bump version" as inseparable.

### Timestamps: one clock, `Europe/Brussels`

**Every naive `DATETIME` in this database is Belgian local time, and both the PHP side and the database side are held to it.** The invariant has three parts, and a module author has to know all three:

1. **PHP runs on `Europe/Brussels`.** `Core\Config\AppClock::apply()` is the first thing `public/index.php`, `public/cron.php` and `tests/bootstrap.php` do. So `date('Y-m-d H:i:s')`, `new \DateTimeImmutable('now')`, `new \DateTimeImmutable('today')` and `'tomorrow 04:00'` all mean what a Belgian reader thinks they mean. (`bootstrap/bootstrap.php` is the standalone FTP installer, not the running app — it has nothing to do with this.)
2. **The MySQL session agrees.** `Core\Database\Connection::getPdo()` executes `SET time_zone = '<PHP's current numeric offset>'` on every connection it opens, so `NOW()` and a column's `DEFAULT CURRENT_TIMESTAMP` land on the same clock as PHP. A *numeric* offset deliberately, not `'Europe/Brussels'`: the named form needs the server's `mysql.time_zone*` tables, which shared hosting routinely does not load, and would fail the connection outright.
3. **SQLite cannot join in, so PHP writes the timestamp.** The in-memory SQLite database the test suite runs on (`Tests\DatabaseTestHelper`) has no session timezone and its `CURRENT_TIMESTAMP` is UTC, full stop. **Any column whose value is ever compared against a PHP-computed instant is therefore written from PHP** — bound as a parameter, never left to the column default and never `NOW()` in the SQL. Keep the `DEFAULT CURRENT_TIMESTAMP` in `schema.sql` as a safety net for hand-written SQL; just don't rely on it.

What that means when you write a module:

- **Do** compute both ends of any window in PHP: `$since = (new \DateTimeImmutable('-10 minutes'))->format('Y-m-d H:i:s');` compared against a `created_at` your own `INSERT` supplied. Every rate limiter in the tree is built this way — `Core\Security\HumanCheck\HumanCheckRateLimitRepository` is the reference shape.
- **Do** render with `|date_fr`, `|datetime_fr`, `|french_date`, `|relative_date`. They read a stored value under the default timezone and print it as-is, which is now already correct — no conversion at render time.
- **Don't** write `gmdate()`, and don't parse a stored value with `new \DateTimeZone('UTC')`. Both used to be right, back when the whole application ran on UTC; both are now off by an hour or two. If you need a value that cannot drift when a caller changes the ambient timezone underneath you, name the zone explicitly with `AppClock::zone()` / `AppClock::now()` — `Modules\Groups\Support\Timestamps` is the worked example, and the module's edit-window tests exist to keep it honest.
- **Don't** seed a test fixture with SQL time (`datetime('now', '-1 minute')`, `NOW()`) and then assert against a PHP-computed instant. That compares two clocks and passes or fails by accident; write the fixture the way the repository under test writes it.
- **Don't** round-trip a date through a Unix timestamp when a library will read it back as UTC. `ExcelDate::PHPToExcel(strtotime('2015-03-21'))` produced 2015-03-20 the moment PHP stopped running on UTC; pass a `DateTimeInterface` instead, which is read by its calendar components (`Core\Member\Export\MemberExportService`).
- Timestamps that leave the installation — an API payload, an `.ics` file, a support package — stay explicit UTC/ISO-8601 and convert at the boundary. That is a serialisation format, not storage.

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
- **Another module's capability is a `getOptional()` call, never a construction.** A handler that needs what another module publishes asks `$context->getOptional(\Modules\LlmConnector\Api\LlmConnectorInterface::class)` and treats `null` — module absent, disabled, or the capability never registered — exactly like a nullable constructor dependency on the HTTP path: the feature is simply unavailable (ARCHITECTURE.md §7.5, applied to the scheduled path). Never `new` another module's repositories or services from the PDO: those classes stop existing the moment the module is removed from an install, and building them skips the enablement check the HTTP path guarantees. The available capabilities are the `Api\` interfaces registered in `public/scheduler-bootstrap.php` (shared by both entry points); a module publishing a new `Api\` capability that a task handler should reach registers its factory there. `$context->isModuleEnabled('module_id')` answers the bare enablement question when there is no capability to fetch — never query `module_registry` yourself.

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
- **A type nobody individually opted into needs `recipientsForType()`, not `findAllIds()`.** `dispatch()` always writes the in-app row (above), so handing it every account would put a row in the centre of somebody who had switched the type off. `NotificationService::recipientsForType('your.type')` returns exactly the accounts the type's `role_min` allows whose in-app channel resolves enabled for their own role and preferences — the recipient list for an announcement with no requester to answer (`Core\Maintenance\Task\InstallUpdateHandler` uses it for an update nobody asked for). It is not for a type whose audience is a membership or an ownership: that is still yours to resolve, per the `role_min` floor rule above.
- A handful of pre-existing, out-of-scope Maintenance task types (reset/restore) use the older, simpler `notify($userAccountId, $title, $body, $url)` instead — single recipient, immediate, no role/channel/quiet-hours resolution. The update handler still uses it for the one case it fits, a manual install answering its own requester, and `dispatch()` for everything else. New module types should use `dispatch()`.

## E-mails

Every automatic e-mail your module sends must be declared in `module.json` under the `emails` section. The declaration is aggregated with core's own (`Core\Mail\Template\EmailTemplateRegistry`) into one list — the inventory an administrator reads, and the source the renderer answers from once your sender is migrated onto it.

```json
"emails": [
  {
    "id": "calendar.multiday_event_reminder",
    "label": "Rappel d'évènement sur plusieurs jours",
    "description": "Envoyé au staff de la section quelques jours avant un évènement qui dure plus d'une journée.",
    "default_subject": "Rappel — {{ event_title }}",
    "template": "@calendar/email/multiday_event_reminder.html.twig",
    "editable": true,
    "variables": [
      { "name": "event_title", "label": "Titre de l'évènement", "example": "Week-end de section" },
      { "name": "start_date",  "label": "Date de début",        "example": "14 mars 2027" }
    ]
  }
]
```

- **`id` must be prefixed with your module id**, exactly like a notification type. Two modules can then never collide, and the page groups by module without parsing anything else. An unprefixed id is a load-time `ModuleException`.
- **`description` is required and is French prose saying *when* the e-mail goes out**, not what it contains. It is the only thing an administrator has to decide whether the e-mail they are about to reword is the one they meant.
- **`template` is the Twig file you ship**, under your module's Twig namespace (`@your_module/email/…`). It stays what is rendered as long as nobody has customised the e-mail. Ship the `.text.twig` twin beside it as before — multipart is mandatory (`SECURITY.md` §8).
- **`variables` are the placeholders an administrator may insert**, written `{{ name }}` and substituted as **plain strings** — the stored content is never evaluated as Twig. Give each a French `label` (the wording of the insertion button) and a realistic `example` (what the preview and the test send show). A `name` is a lowercase identifier; anything else is refused at load time.
- **Declare flat, substitutable values.** `{{ reference }}`, not `{{ booking.reference }}`: string substitution has no notion of an object, so a variable an administrator can insert has to be a value you can hand over as a string.
- **Never declare `site_name`.** The header, the footer and the unit's name belong to `email/base.html.twig`, which stays code and is not customisable.
- **`editable` is optional and defaults to `true`.** Declare `false` for an authentication e-mail — anything carrying a login link, a password reset or an address confirmation. An administrator who broke one would shut somebody out with no way back in, and the refusal is enforced on the server, not merely by hiding a button.
- Declaring an e-mail changes nothing about how you send it: keep rendering your Twig and calling `MailService::send()`. The declaration is what puts it in the inventory.

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

## Help topics (`Core\Help\HelpRegistry`)

A module documents its own pages by shipping Markdown topics in a `help/`
directory at the module root — same aggregation shape as `cookies`/
`notifications`/`offline`: `Core\Module\ModuleManager` registers the
directory into the single shared `Core\Help\HelpRegistry` while loading
the module, and a disabled module's topics simply never exist. **No
manifest section is needed**: dropping a `.md` file into `help/` is the
whole integration. The optional `module.json` section only renames the
directory:

```json
"help": { "dir": "help" }
```

One file per topic, named `{id}.md`, front matter between `---` lines
(`key: value` per line, lists comma-separated — deliberately not YAML):

```
---
id: reserver-un-local
title: Demander la location d'un local
summary: Choisir des dates libres et envoyer une demande.
category: Premiers pas
role_min: public
paths: /locations/*
related: suivre-ma-demande
---

Corps en Markdown…
```

- `id` must match the file name and be unique across core + every module
  — a collision is a load error, and `tests/Core/Help/HelpInvariantsTest`
  pins the whole corpus.
- `role_min`: below it the topic exists nowhere (panel, index, search,
  direct URL — 404). Same role vocabulary as routes. Declare the floor of
  the page the topic documents, not the audience you had in mind: a topic
  above its page's floor leaves the people who can open that page without
  help. `tests/Core/Help/HelpMenuCoverageTest` fails on that for a menu
  page; below one, it is on you.
- `category`: one of the five the corpus uses — `Premiers pas`,
  `Espace membres`, `Espace animateurs`, `Espace chefs d'U`,
  `Configuration` (`Core\Help\HelpService::CATEGORY_ORDER`, which is also
  the order `/aide` shows them in). An unknown category still renders,
  alphabetically after those five; introducing one is a product decision,
  not a shortcut around picking the right existing one.
- `paths`: pages the topic covers, in three forms — exact
  (`/locations`), direct child (`/locations/*`, the path plus exactly one
  segment; `offline`'s exact/child semantics), and a segment pattern
  where a `*` stands for one whole segment anywhere
  (`/mes-locations/*/reglages`, `/locations/suivi/*/*`). Use the third
  for a page hanging off an id, which the first two cannot name at all.
  A pattern matches segment count for segment count, so a rule for a page
  never also claims the pages under it. Every declared path must
  correspond to a real registered GET route, or the invariant test fails.
  Empty is valid: the topic is then only reachable from `/aide`.
- Body sections start at `##` (the title already is the page's `<h1>`).
  Write to the editorial charter in design.md §7.11 — vouvoiement, the
  §7.1 lexicon, ~400 words, at most one `> ` warning callout, no external
  link but the federation's.
- What the renderer understands, and nothing else: `##` headings,
  paragraphs, bullet lists, numbered lists, an indented continuation line
  that joins the item above it, `**gras**`, `*italique*`, `` `code` ``,
  one `> ` callout, and an image under `/assets/`. No tables, no code
  fences, no nested lists — and **no relative links**: only an absolute
  `http(s)://` URL becomes an `<a>`, so `[voir](/aide/x)` renders as its
  own source text. Point at another topic with `related`, and name a page
  by its label rather than its route.
- A new end-user-facing page should ship with a topic covering it, in the
  same change (AGENTS.md § Module creation checklist).

## Recording an entity's change history (`Core\Audit`)

When your module owns something a chief will ask questions about later — "who changed this price, and when?" — record the changes and render the timeline instead of inventing a per-module events table. `Core\Audit` is that table generalised (ARCHITECTURE.md §8.66).

```php
$this->auditService->record(
    'my_module_thing', $thingId, 'price',
    '2 450 €', '2 650 €',                      // ALREADY formatted for a reader
    AuditSource::Human,
    'Prix révisé par le propriétaire',          // optional sentence
    null,                                       // optional opaque source reference
    $actorUserAccountId                         // null = automatic
);
$page = $this->auditService->page('my_module_thing', $thingId, 1, AuditService::DEFAULT_PER_PAGE);
```

```twig
{% include 'partials/audit_timeline.html.twig' with {
    entity_type: 'my_module_thing', entity_id: thing.id,
    audit_page: audit_page, labels: { price: 'Prix' }, collapsed: true
} only %}
```

- **Not the journal.** `JournalService` is the installation's administrative log and forbids personal data; this is a timeline on one entity's page and may hold the values. Use both for a sensitive action: one line for the administrator, one for the entity.
- **Pass values already formatted.** The component never formats, parses or compares them — that is what lets one table serve prices, dates and free text. It also means it cannot tell a real change from a no-op: do not call `record()` when nothing changed.
- **Every value is encrypted, always**, including ones that look harmless. The consequence to plan around: **the history is not searchable or filterable**.
- **Register an access checker in `public/index.php`**, inside the block that knows your module is enabled: `$auditAccessResolver->register('my_module_thing', fn(int $id): bool => ...)`. An unregistered entity type is **denied** — forgetting this does not open the timeline, it closes it, which is the intended failure direction. The route's `role_min: chief` is a floor, not your answer.
- `field_key` is a machine name (`price`, `status`), never the label: the `labels` map owns the wording, so rewording a field never rewrites its history.
- **When a person asks to be erased**, call `anonymiseValues($entityType, $entityIds, $fieldKeys)` rather than deleting rows. That a field changed, when and by whom is not the personal data — the values were.

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

1. **Implement `Api\MessageConsumerInterface`.** Seven methods, and most of them are one line:

   - `consumerId()` — a stable string, your module id.
   - `analyze(CandidateMessage): AnalysisResult` — what you make of a message **as it arrives**. Return `MessageLink`s for what you are sure of and `MessageCandidate`s for what you are not; `AnalysisResult::nothing()` for "not mine", which is the ordinary answer. **Every consumer is asked and every answer is applied** — there is no first-claim-wins any more, so recognising a message no longer takes it away from anybody.
   - `analyzeStored(InboundMessage): AnalysisResult` — an optional **second pass**, run by a scheduled task after the message and its attachments are on disk. Everything expensive belongs here: extracting a PDF's text, reading an amount. `CandidateMessage` deliberately carries attachment **metadata only**, because reading bytes during a synchronisation would blow through `max_execution_time` on shared hosting — and a synchronisation that dies leaves the cursor unmoved, so the same doomed run repeats on every tick.
   - `onLinked(InboundMessage, MessageLink)` / `onUnlinked(InboundMessage, MessageLink)` — after an association is created, and after one is removed. `onLinked()` is where you turn attachments into documents of your own; **`onUnlinked()` is where you take them back**, and skipping it is a real bug: a message moved from one object to another used to leave its documents on the first, invisible to whoever manages the second.
   - `canRead(string $businessReference, array $linkedMemberIds, string $role): bool` — whether this requester may download an attachment of a message associated with that object of yours. `inbound_mail` does not know your authorisation rules and must not learn them, so it asks; answer with the same rule your own screens use. **Throwing is a refusal, never a grant.**
   - `describeEvidence(): array`, `triageAudienceLabel()`, `triageAudienceCount()` — French declarations shown on the mailbox configuration screen: what you propose on, and who would see the mail you sort. See point 5.

2. **Optionally, declare what you want kept** by also implementing `Api\MessageRetentionPreference` — two methods, both about the messages *you* claim:

   - `wantsRawHeaders(): bool` — keep the message's raw header block, encrypted and truncated. `Authentication-Results`, `Received-SPF`, `DKIM-Signature` and the chain of `Received` lines are where a mail diagnosis lives, and nothing else stored keeps them. They also carry **IP addresses and server names**, which is why nobody gets them by default.
   - `wantsBody(): bool` — return `false` if what people wrote is none of your business. A probe watching whether the site's own mail comes back needs the envelope and the verdict, not the correspondence. The body columns are then written empty rather than made nullable.

   **A consumer that does not implement this interface behaves exactly as it always has** — body kept, headers not — so there is nothing to add to an existing consumer. Where several consumers claim the same message the **wider** answer wins, each undeclared consumer answering the default: one module's frugality must not delete what another needs off the same mail.

3. **Ambiguity is silence, never a guess.** Several of your objects matching equally is a proposition at best and nothing at worst — never a link. Attaching a message to whichever of two candidates sorted first is worse than not attaching it, because the person reading the wrong file has no way to know it is the wrong file.

4. **Try the reliable identifications first, and say which one answered.** An explicit reference in the subject, then the thread headers (`InboundMailInterface::findReferenceByThread()` resolves those for you — you cannot look inside the other module's storage, and should not), then anything weaker, bounded. `Api\LinkOrigin` carries the answer, and your interface should show a weak match as the guess it is.

5. **Links versus propositions is a question of who decides.** A `MessageLink` is a certainty you are willing to act on unattended. A `MessageCandidate` is a guess you hand to somebody — so it carries a French label, a French explanation and an `evidenceType`, because « correspondance faible (sender_window) » tells a chief nothing they can decide on. A proposition somebody sets aside is set aside **for good**: `dismissed_at` is final, and re-emitting the same guess never resurrects it.

6. **There is no central rule about how strong a proposition must be**, and that is deliberate. The discipline belongs to you; the price of it is `describeEvidence()`, which publishes the signals you propose on so a superadmin reads what your module will do with a shared mailbox before opening it to you. `triageAudienceCount()` must count real people **for the scout year in effect** — the warning that shows it is the only guard-rail on that choice, so an estimate or a frozen figure makes it a lie.

7. **Analysis happens once, on arrival, plus that one deferred pass.** Nothing re-analyses a stored message on its own, ever: propositions appearing and disappearing as modules are updated, with nobody able to say why, is worse than none. A superadmin who enables your module on a box that has been collecting for months asks for a re-analysis explicitly.

8. **Register the consumer in the shared builder** in `public/scheduler-bootstrap.php` — one closure feeds both inbound-mail tasks, and two copies of that wiring would be two places for it to drift. If your `canRead()` needs request-scoped state (a session's email, the current scout year), register a **factory** into the web path's read registry in `public/index.php` instead of an eager instance: a page view must not assemble the cross-module graph to answer a question it is not asking.

9. **Consume `Api\InboundMailInterface` as a nullable dependency** for everything else — listing a thread, associating, detaching, moving, purging. Every method is scoped to your consumer id and one business reference, and there is deliberately no way to ask for anything broader.

10. **`probeAddressesFor()` is the one method that hands you an address**, and the exception is narrow enough to be worth stating: `listMailboxSummaries()` gives a manager a name and a state precisely so the account address stays out of a picker, while this answers a *destination* — a diagnostic probe that cannot be addressed is not a probe. It answers for one consumer, about the enabled boxes that consumer may already analyse and no others, and a username that is not an e-mail address is left out rather than guessed at. Whatever you publish it through must be authenticated: an open route repeating this answer is a route handing out the installation's mailbox addresses.

Two things this module guarantees so you do not have to: **nothing is ever written to the remote mailbox**, and **an attachment is owned by its message**, so `/files/{id}` asks you before serving it rather than trusting a flat `role_min` floor.

What your own module still owns: whether the *user* in front of you may reach the reference you are asking about. `inbound_mail` cannot know your authorisation rules, so it does not try.
