# Design

This document covers UI/UX decisions, data model, and technical design choices.

## 1. UI/UX principles

### 1.1 Mobile-first
Primary device is mobile. Base CSS for mobile, `min-width` breakpoints for larger. Bootstrap 5 compiled files. 44px touch targets. HTML5 input types.

### 1.2 Navigation

**Mobile**: hamburger left, unit name right. Offcanvas from left: user card (initials, display name, role, member count), accordion sub-menus (one open), login/logout at bottom.

**Desktop**: horizontal bar (unit name left, menus center, user right). No permanent sub-menu row: a menu opens a **mega-menu panel** floating under the bar — titled columns of text rows, one column per declared group (`MenuBuilder::MENU_GROUPS`), at most four. Opens on click, never on hover; closes on a second click, on Escape (focus returns to the tab), or on a press outside the bar. The tab of the menu the current page belongs to keeps its underline whatever is open. An ungrouped menu ("Notre unité") draws one untitled column.

The row this replaced wrapped to three lines at Configuration's nineteen entries, changed height with the active menu, and gave "Maintenance" and "Galerie" exactly the same weight.

**Espace membres panel**: a "Mes membres" column (dynamic member entries — avatar, totem/prénom, section) beside a "Pages" column of static and module pages.

**Breadcrumb**: visible at every width, desktop included — with the sub-menu row gone, it is the only thing on screen naming the current page's ancestry (§7.3).

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
Fixed at the bottom of the screen while no consent has been given — except on the /cookies page itself, where the server never includes it (it would cover the very preferences it links to). Actions, in this exact order: "Tout refuser" then "Tout accepter", two identical primary (`btn-primary`) buttons — refusing is exactly as prominent as accepting — then "Personnaliser", a discreet link (`btn-link`) to /cookies. Does not block content: the page body is padded by the banner's height so everything below stays reachable. Disappears after choice.

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

## 7. UI conventions

Rules the whole site follows. Several are enforced mechanically by
`tests/Core/View/UxConventionsTest.php` — a convention no test defends
drifts back into divergence within months (it already happened once).

### 7.1 Lexicon

One word per person, everywhere on screen:

- **animé** — a child, member of a section.
- **animateur** — a section leader (canonical on-screen word; « chef » is
  never used alone). A section's animateurs form its **staff de section**.
- **chef d'unité** — a unit leader; together they form the **Staff
  d'Unité**, hierarchically above the animateurs. « Staff d'U » is the
  accepted short form where space is tight.
- Menus: « Espace membres » (identified visitors), « Espace animateurs »,
  « Espace chefs d'U », « Configuration ».
- Configuration pages are named by task, never by layer: « Édition du
  site » (/config/general), « Installation & serveur » (/setup),
  « Réglages » (/config/settings).

URLs (`/chefs/...`, `espace_chefs`, role `chief`) are code identifiers and
deliberately keep their historical names — renaming them breaks bookmarks
and integrations for zero user benefit.

### 7.2 Touch targets

44px is a comfort **goal** for small controls (icon-only buttons, `.btn-sm`,
checkboxes via their `<label>`), not a universal floor: WCAG 2.2 AA requires
24×24 (2.5.8); 44px is the AAA criterion. Bootstrap's default 38px controls
pass comfortably. Concretely:

- The `pointer: coarse` block in `app.css` is the only place touch sizing
  lives. Never `style="min-height:44px"` in a template — an inline style
  overrides every stylesheet rule, including the desktop restore.
- Never inflate standard inputs/selects to 44px — it lengthens forms for
  zero gain (this exact recommendation was made and retracted once).
- A checkbox grows its tappable zone through its `<label>`, never by
  resizing the box.

### 7.3 Going back

The breadcrumb bar is the site's **only** back affordance, and it shows at
every width — mobile, desktop browser, installed PWA alike. (It used to be
hidden on a desktop browser, where the permanent sub-menu row stated the
current section; that row is gone.) No « Retour » buttons — a destination
that matters belongs in the breadcrumb trail (`parents` for menu sections,
`breadcrumb_trail` for real ancestor pages). Documented exceptions live in
`UxConventionsTest::BACK_BUTTON_EXCEPTIONS`. A `parents` entry must exactly
match a `MenuBuilder` label, or it renders as dead text.

### 7.4 Buttons

Four variants, nothing else:

- **Primary action** — `btn-primary`, at most one per screen. Creation
  actions (« Nouvel article », « Créer le bien »...) are always primary,
  never `btn-success`.
- **Neutral / secondary** — `btn-outline-secondary`. « Annuler » is always
  exactly `btn btn-outline-secondary`, same size as the action it sits
  next to.
- **Destructive trigger** — `btn-outline-danger` (opens the confirmation).
- **Destructive confirmation** — `btn-danger`, only inside a confirmation
  surface (modal footer, danger zone).

A full-width mobile button is `w-100 w-sm-auto`, never `w-100` alone.
Icon vocabulary is fixed: `bi-trash` delete, `bi-pencil` edit, `bi-plus-lg`
add, `bi-three-dots-vertical` overflow menus, `bi-magic` "let the AI do
this field for me". Only icons present in the **vendored** Bootstrap Icons
release exist: a class from a newer upstream version renders nothing at
all, silently (`bi-tent` shipped that way in the Camps menu), and
`UxConventionsTest::testEveryIconClassExistsInTheVendoredStylesheet()`
now catches it.

An AI helper attached to a field or a result is icon-only, through
`partials/ai_button.html.twig`: the wand, with the French wording moved to
`title` and `aria-label`. « Générer avec l'IA » beside an input is wider
than a phone can spare — on the article editor it squeezed the summary
field, the one that needed the room, down to a few characters. This is for
helpers; a page-level primary action that happens to call an AI
(« Générer le contenu » on the RGPD page) keeps its label.

### 7.5 Feedback

- Flash messages: types `success` | `error` | `warning` only
  (`Core\Http\FlashMessage`); `danger` is not a type.
- Destructive POSTs carry `data-confirm` **on the `<form>` element** — the
  global handler in `base.html.twig` listens on `submit` and looks at
  `e.target.closest('form[data-confirm]')`; the attribute on a button is
  silently inert. Rule of thumb: every POST that deletes, removes, refuses
  or revokes carries one; nothing else does. Messages state the
  consequence: « {Verbe} {objet} ? {Conséquence concrète}. »
- Never `on*=` attributes in templates — the CSP (`script-src 'self'
  'nonce-…'`) makes inline handlers dead code, silently.
- **Behaviour lives in `public/assets/js/`, never in a template's own
  `<script>` block.** A template is the one place JavaScript cannot be
  tested — Vitest imports files, not Twig output — and every duplicated
  behaviour this codebase has found was living in one. Server data goes
  in a `<script type="application/json" id="…">` island, read with
  `window.ScoutMagicApi.pageData(id)`: data to the parser, so a value
  containing `</script` cannot end the block mid-statement, and no nonce
  is needed. Pinned by
  `UxConventionsTest::testBehaviourLivesInFilesNotInTemplates`; the two
  exceptions (the anti-FOUC theme bootstrap and the service-worker
  registration, both in `base.html.twig`) are listed there with reasons.
- Never `alert()`/`confirm()`/`prompt()`, in a template or in
  `public/assets/js/`. The site has one of each:
  - **`window.ScoutMagicToast.show(message, {variant})`** for a result —
    variants `success` | `error` | `warning` | `info`, matching the flash
    vocabulary. It announces itself to screen readers; a native box does
    not.
  - **`window.ScoutMagicConfirm.ask(message)`** → `Promise<boolean>` for a
    question. Options: `{message, title, confirmLabel, cancelLabel,
    variant}`; `variant` is `danger` (default) or `primary` for a
    confirmation that destroys nothing. Always name the action on the
    button (« Supprimer », « Délier », « Appliquer ») rather than leaving
    « Confirmer » — the label is where the visitor reads what they are
    agreeing to. Focus lands on « Annuler », never on the destructive
    button.

  A native dialog renders the origin above the message
  (« 127.0.0.1:8000 dit : »), labels its buttons in the browser's language
  rather than the page's, and gives a permanent deletion exactly the same
  two buttons as a harmless question.
- Session-expiry text is a single constant: « Votre session a expiré.
  Rechargez la page et réessayez. » — never « Jeton CSRF invalide. ».

### 7.6 Page structure

- `base.html.twig` already renders `<main class="container py-3">`. A view
  never opens another `.container`. Page width is one of the shared width
  classes (`page-narrow`, `page-medium`, `page-wide`) — never an inline
  `max-width`.
- Exactly one `<h1>` per page, one size site-wide (the `page_header`
  partial's). The `<h1>` matches the page's `<title>` — eight pages all
  titled « Finances » is a bug, not a convention.
- A page whose figures belong to one scout year says which, in the
  header: `page_header`'s `badge`, beside the title and never inside it
  (so « Statistiques » stays « Statistiques » in the tab, the breadcrumb
  and the menu). A subtitle sentence is not a substitute — on the three
  pages that pushed for this the year was already there, mid-paragraph in
  grey small text, and read by nobody before the numbers.
- Every `<table>` sits in a `.table-responsive` wrapper (or a documented
  overflow container).

### 7.7 Empty states

Rendered through the `empty_state` partial, never hand-rolled. Canonical
copy: « Aucun(e) {objet}. » followed, whenever the visitor has the right
to create the missing thing, by the creation action (« Créez le premier
album ! »). The variant without an action is only for visitors who
genuinely cannot act.

### 7.8 Colour scheme

Three states, one control: the ◐ toggle in the navigation cycles
`light` → `dark` → `auto`, where `auto` follows the operating system live.
`data-bs-theme` on `<html>` carries the resolved choice,
`public/assets/js/theme.js` owns it (`window.ScoutMagicTheme`), and an
inline script in `base.html.twig`'s `<head>` applies the stored value
before first paint — without it the page flashes white on every load.

The preference is stored in `localStorage` under `theme_preference`, which
makes it a functional cookie in the RGPD sense: it is declared in
`core/Cookie/CookieRegistry.php` and written only when
`CookieConsentService::isAllowed('functional')` says so. Without consent
the toggle still works for the session; it simply does not persist.

Write colours as Bootstrap semantic utilities (`text-body-secondary`,
`bg-body-tertiary`, `border`) — never `bg-white`/`text-dark`, which are
the same colour in both themes and produce black-on-black.

### 7.9 Form fields

Rendered through the `form_field` partial (`password_field` for passwords,
`rich_text_form_field` for rich text). It renders label, control, help text
and the required marker as one unit, with `aria-describedby` wired from the
control to its help text — which of 136 hand-written help texts, exactly
one did.

Two sizes and no more: the default, and `size: 'sm'` for a dense table or
a repeated row, which shrinks the label and the control together. The
fourteen label-class combinations this replaced were the disease; a third
size would be the relapse.

A field's `id` is what JavaScript and tests grip. Renaming one is a
breaking change — grep `public/assets/js/` and `tests/` before touching it.

Four escape hatches, each for a real shape and none for a preference:
`field_name` is optional (a JS-driven panel reads its fields by id and
posts them itself — a stray `name` only invites a future GET to carry
it); `data: {…}` puts `data-*` on the control, and on an `<option>`,
because half the site's fields are gripped by a script through one;
`wrapper_class` replaces the default `mb-3` for a field inside a grid
column that supplies its own spacing; `label_visually_hidden` renders the
label and hides it, for a repeated row the row itself names — the label
is hidden, never dropped, and still says WHICH row it belongs to.
`control_class_extra` takes layout classes the caller owns (`w-auto`, a
script's hook) and never a size: that is `size`'s job.

Genuinely out of reach, and why: `setup/index.html.twig` (the installer
renders before the theme exists), a label carrying markup (« Tapez
**EFFACER** pour confirmer »), and a `<select>` with `<optgroup>`.

### 7.10 Files and lists

`partials/drop_zone.html.twig` is the one « déposez un fichier ici » zone
— dashed border, centred icon, one padding scale of two (`md`, `lg`), and
`border-primary` while a file hovers. Its behaviour is
`window.ScoutMagicDropZone.bind(zone, onFiles, {input, pickOnClick})`
(`public/assets/js/drop-zone.js`). Three screens used to draw and wire it
separately, and only one of the three remembered that `dragover` must
call `preventDefault()` — without it the browser refuses the drop and
opens the file in a new tab, so the zone looks alive and does nothing.

`window.ScoutMagicSortable.bind(container, {itemSelector, axis,
draggingClass, onReorder})` (`public/assets/js/sortable.js`) is the one
drag-and-drop reordering. It saves on `dragend`, never on the item's own
`drop`: `drop` only fires when the pointer is released ON a sibling, so a
release just outside the list left two of the three previous
implementations visually reordered and the server none the wiser. Every
sortable list also offers up/down buttons — dragging is not available to
a finger or a keyboard (§7.2).

### 7.11 Contextual help

One help button per page, always visible, at the right of the breadcrumb
bar (`partials/help_button.html.twig` — the bar shows at every width, so
this single placement covers mobile, desktop and installed PWA). When a
topic covers the page it opens the help panel
(`partials/help_panel.html.twig`: bottom sheet on mobile,
right-hand drawer at lg and up), whose content is server-rendered into
the page so it works offline; otherwise it links to `/aide`, which the
mobile offcanvas footer also links, next to connexion/déconnexion.
Topics are Markdown files in `docs/help/` (core) or `modules/<id>/help/`
(modules) — see ARCHITECTURE.md §8.64; a new end-user-facing page must be
covered by a topic, existing or new (AGENTS.md checklists).

**Charte rédactionnelle** — enforced mechanically where possible by
`tests/Core/Help/HelpInvariantsTest.php`, by review otherwise:

- Vouvoiement, phrases courtes, voix active. Le ton d'un collègue qui
  explique, jamais d'un manuel.
- Vocabulaire du §7.1 : **animé**, **animateur**, **chef d'unité**,
  **Staff d'Unité**. Jamais « chef » seul, jamais « utilisateur ».
- On décrit ce que la personne contrôle et ce qui se passe à l'écran.
  Jamais un nom de classe, de table, de route ou de réglage technique.
- ~400 mots par sujet maximum. Au-delà, c'est deux sujets.
- Les sections commencent à `##` (le titre du sujet est déjà le `<h1>`
  de la page) ; jamais de `#` seul.
- Pas de capture d'écran en v1 : elles périment à chaque évolution de
  l'UI et alourdissent l'artefact. Le rendu supporte les images
  (`/assets/` uniquement) pour le jour où un écran est réellement
  inexplicable en mots.
- Un encadré d'avertissement (`> `) par sujet au maximum, réservé à ce
  qui est irréversible ou contre-intuitif.
- Pas de lien externe, sauf vers le site de la fédération
  (lesscouts.be).

### 7.12 Rich text

The « lien » button in every rich-text toolbar goes through
`window.ScoutMagicRichText.insertLink()`
(`public/assets/js/rich-text-link.js`). Five toolbars used to implement it
separately, so none of them ever fixed the three things it gets right: the
selection survives the dialog (a modal takes focus, and a contenteditable
that loses focus loses its range), a bare host becomes `https://…` rather
than a relative link that 404s, and a `javascript:` URL is refused with a
reason rather than silently stripped later by the server-side sanitiser.

### 7.13 Saving: a button, or on change

Two shapes, and which one a control gets is not a preference:

- **One independent control** — a switch, a select, a checkbox in a
  repeated row — saves **on change**, with a `ScoutMagicToast` confirming
  it. No button. That is how the notification preferences, the module
  toggles, the backup frequency, the SOS default number, the passage and
  départs rows, and each calendar's « Vu par » / « Modifié par » already
  work.
- **A group of fields that only means anything together** — the event
  defaults (title + hours + place), a reminder's switch + delay, any real
  form — gets one « Enregistrer » button and saves as a unit. Saving
  half of a coherent set on each keystroke is not autosave, it is a
  half-applied form.

An autosaving page **says so**, once, near the controls: « L'enregistrement
est automatique — il n'y a pas de bouton "Enregistrer". » A visitor cannot
tell the two shapes apart by looking, and someone who assumes the other
one either loses their change or hunts for a button that does not exist.
