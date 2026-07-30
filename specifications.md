# Functional specifications

## 1. Context

Belgian scout units in the "Les Scouts" federation manage their members through Desk (federation web platform). This website complements Desk by providing a public-facing and member-facing site for the unit, generated primarily from the Desk CSV export. The same codebase is reusable across units — all unit-specific content is configurable.

## 2. Users and roles

### 2.1 Role hierarchy

| Role | Level | Typical Desk functions | Site access |
|---|---|---|---|
| Public | 0 | — | Public pages only |
| Identified | 1 | All registered members, parents | Member pages, module pages |
| Intendant | 2 | Intendant functions | Partial chief area |
| Chief | 3 | Animateurs | Full chief area |
| Admin | 4 | Chef d'unité | + admin area |
| Superadmin | 5 | Designated site administrators | + Configuration |

"Admin" is displayed as "Chef d'Unité" throughout the UI (e.g. Config Desk's function role picker) — it is never labeled "Admin" to an end user. "Superadmin" is the true top-level site administrator role and has no distinct UI label.

### 2.2 Identity model

- A person is identified by an **email address**.
- One email can be associated with multiple members (e.g. parent email linked to their children).
- Effective role = highest role among all members linked to the email for the current scout year.
- Mapping between Desk functions and site roles is configurable by admins.

### 2.3 Authentication

Three methods, all resolving to the same email:

**Magic link** (default, always available): enter email → receive link → click to confirm (can be on another device). Login page polls for confirmation. Token: single-use, 15-minute expiry.

**Password**: enter email + password. Available only if user has configured a password.

**Passkey (WebAuthn)**: one-tap with biometrics or security key. No email field (discoverable credentials). Multiple keys per account.

## 3. Menus

Five main menus, visibility by role:

### 3.1 Notre unité (public)
Public pages.

### 3.2 Espace des animés (identified)
**Dynamic entries** (one per member linked to email, named by totem/prénom, section subtitle) + separator + **static entries** from active modules.

### 3.3 Espace des chefs (intendant / chief)
Filtered by role — intendants see only `role_min: intendant` pages, chiefs see all.

### 3.4 Espace admin (chief)
Administrative tools.

### 3.5 Configuration (admin)
Site-wide settings, modules, functions.

### 3.6 Navigation
- **Mobile**: hamburger (left), unit name (right). Offcanvas from left. User card, accordion sub-menus, login/logout.
- **Desktop**: horizontal bar, wrapping sub-menu row below. User at right.

## 4. Core pages

### 4.1 Notre unité

| Page | Content |
|---|---|
| Accueil | Editable text and photos (configuration mode); optional banner (module) and latest-articles column (news module) when those modules are enabled |
| Contact | Editable text; Staff d'U section's group photo and name/totem roster; Les Scouts federation logo (links to lesscouts.be) with its own editable text |
| Sections | Generated from Desk import; each card shows the section's staff photo, its designated "responsable" name, email, and a small editable text block |
| RGPD | Content set via the RGPD configuration page (§4.5): default reference text, admin-edited custom text, or AI-generated text. Links to the cookie preferences page for the cookie list (no longer embedded inline). |
| Calendrier (module) | Public activity calendar (month/week view); read-only ICS subscription feeds |
| Actualités (module) | Public news article list/detail, each with an optional registration form (fields, capacity, payment) |

### 4.2 Espace des animés

| Page | Content |
|---|---|
| {Member display name} × N | One page per linked member. All known information. |
| Trombinoscope (module) | Every section's chief/chief-d'unité staff, grouped by section, with the section's designated "responsable" highlighted |
| Galerie (module) | Photo/video albums (identified: view; chief: manage — see §4.3) |

### 4.3 Espace des chefs

| Page | Role | Content |
|---|---|---|
| Staffs | intendant | SectionPicker + staff info per section (chief/chief-d'unité only — animés are not shown). Section's staff group photo, editable in configuration mode (one per scout year, falls back to the most recent earlier year). Badges assignable to staff (chief only, see Core\Badge). Section name/email are configured from Config Desk (§4.5), not here. |
| Finances (module) | intendant | Bank statement import, receivables, receipts, movements |
| Statistiques (module) | chief | Member statistics |
| Calendrier (module) | chief | Chiefs' calendar view (month grid, event edit) |
| Envoi de mails (module) | chief | Mass email to selected members/sections across one or more scout years |
| Rétrospectives (module) | intendant | Create/manage post-activity retrospective boards |
| Galerie (module) | chief | Manage photo/video albums |

### 4.4 Espace admin

| Page | Role | Content |
|---|---|---|
| Import Desk | chief | CSV upload/import for current scout year. Year selection. Function mapping status. |
| Journal | chief | Searchable event log. |
| Bannière (module) | admin | Manage homepage banner messages (role-gated visibility, ordered list) |
| SOS Staff d'U (module) | admin | On-call duty roster (month grid), default forwarding number, live redirect status, scheduled redirection list |
| Rétrospectives — Config (module) | admin | Per-board moderation/AI settings restricted to chef d'unité |

### 4.5 Configuration

All pages in this menu require the `superadmin` role, except Maintenance (`admin` — see ARCHITECTURE.md §3/§8.15).

| Page | Content |
|---|---|
| Configuration générale | Badges (transversal roles, e.g. Infirmier/Trésorier, plus one auto-generated "Référent {section}" badge per visible section, assignable only to Staff d'U members — add/rename/activate/deactivate; default badges and badges already assigned can only be deactivated, never deleted). Module registry + configuration mode toggle. |
| Config Desk | Map Desk functions to site roles; rename sections, set section email, and toggle section visibility across the site. |
| Paramètres | Key-value settings grouped by module, edit via dialog. |
| Actions planifiées | Scheduled actions list with status. |
| Configuration RGPD | Choose the RGPD page's content mode: default reference text, custom rich text, or AI-generated from an admin-provided prompt (requires an AI connector module to be enabled). Auto-saved on every mode/content change; each mode tracks its own last real content-change date/time (UTC), never "today" on every view. |
| Maintenance | Backups (on-demand + automatic, database-only/config-only/full, encrypted); update from GitHub releases (check/install with automatic rollback on failure); reset actions (settings to defaults, restore a backup, full reinstall) — each destructive action requires typing an exact confirmation word. |
| Finances (module) | Accounts, categories, categorization rules, danger zone |
| Galerie (module) | Storage location (local/S3), default location for new albums |
| Calendrier (module) | Default view, supplementary calendars, ICS feed links |
| Envoi de mails (module) | Sender/attachment settings |
| SOS Staff d'U (module) | Telephony provider credentials (OVH: application key/secret, consumer key, line selection), excluded sections |
| Intelligence artificielle (module) | AI provider credentials and model selection, consumed optionally by other modules (RGPD text generation, retro moderation, finance receipt extraction, news summaries) |

### 4.6 Pages outside menus

| Page | Content |
|---|---|
| Connexion | Three-tab login (magic link / password / passkey). |
| Mon compte | Name, surname. Password. Passkeys. Cookie preferences. |
| Préférences cookies | Cookie categories with toggles. Accessible from banner, RGPD page, and Mon compte. |
| Upload | Generic file upload (drag-drop, file selection, mobile camera). |
| Installation | First-run setup (DB, unit settings, email, admin). Same page as Configuration later. |

## 5. Cookie consent

### 5.1 Banner
Displayed on first visit. Options: "Accepter tout", "Refuser tout", "Personnaliser". Choice stored in strictly-necessary cookie (13-month expiry).

### 5.2 Preferences page
Displays all cookie categories with description and toggle. Strictly necessary shown but not toggleable. Cookie list generated dynamically from core declarations + active module declarations.

### 5.3 Access points
- Consent banner ("Personnaliser").
- RGPD public page (link).
- Mon compte page (for identified users).

### 5.4 RGPD page integration
The RGPD page does not embed a cookie list inline — it links to the dedicated preferences page (§5.2) for the full, always-current list. Its own textual content is managed separately (§4.5 Configuration RGPD).

## 6. Configuration mode

- Activated by admin, session-only.
- Banner on all pages.
- Text: click-to-edit rich text editor.
- Photos: click-to-replace via upload page.
- Edits visible to all, journaled.

## 7. Desk CSV import

### 7.1 Pipeline
Upload → validate headers → group by `desk_id` → resolve mappings → upsert → delete CSV → journal.

### 7.2 Mapping tables

| Desk field | Mapping table | Blocks on new |
|---|---|---|
| FONCTION | functions | Yes (security) |
| Branche | age_branches | No |
| Tarif | fee_categories | No |
| Section | sections | No |

Section identity always comes from the "Section" column. The Desk export also has a separate "SECTION" (all-caps) column, which is never used — it can hold incorrect/stale data.

### 7.3 Section configuration
Sections identified by Desk code. Name and email configurable from the Staffs page (chief). Name and site-wide visibility also configurable from Configuration > Config Desk (admin) — a hidden section disappears from every section picker (Staffs, Trombinoscope, public Sections page) until made visible again.

A section with no member in a given import becomes inactive automatically (kept, never deleted) and is likewise excluded from every section picker until a later import gives it members again — see §7.1 pipeline and ARCHITECTURE.md §8.1/§8.8.

## 8. Email system

- Central `MailService`, subject prefixed `[{short_name}]`.
- SMTP relay or local. DKIM signed. Multipart mandatory.
- DNS verification page (SPF, DKIM, DMARC) with live status.
- DMARC report email configurable separately.

## 9. Scheduler

- Schedule at time or after delay. Find and cancel own tasks.
- Real cron or poor man's cron. Atomic execution.
- Configuration page for diagnostics. Failures journaled.

## 10. Settings

Key-value, typed, with label, mandatory description, optional regex. Grouped by module. Edit via dialog. Read-only settings shown greyed.

## 11. SectionPicker

Reusable component. Sections (not branches), branch subtitle. Horizontal scroll mobile, wraps desktop. Default: highest-role member's section.
