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
| Chief | 3 | Animateurs, staff d'unité | Full chief area + admin area |
| Admin | 4 | Designated administrators | Configuration |

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
| Accueil | Editable text and photos (configuration mode) |
| Contact | Editable text |
| Sections | Generated from Desk import |
| RGPD | Editable text + dynamically generated cookie list from all active modules |

### 4.2 Espace des animés

| Page | Content |
|---|---|
| {Member display name} × N | One page per linked member. All known information. |

### 4.3 Espace des chefs

| Page | Role | Content |
|---|---|---|
| Staffs | intendant | SectionPicker + staff info per section. Section name/email editable (chief only). |

### 4.4 Espace admin

| Page | Content |
|---|---|
| Import Desk | CSV upload/import for current scout year. Year selection. Function mapping status. |
| Journal | Searchable event log. |

### 4.5 Configuration

| Page | Content |
|---|---|
| Configuration générale | Module registry + configuration mode toggle. |
| Fonctions | Map Desk functions to site roles. |
| Paramètres | Key-value settings grouped by module, edit via dialog. |
| Actions planifiées | Scheduled actions list with status. |

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
The RGPD page includes a dynamically generated section listing all cookies used by the site, pulled from the same source as the preferences page. This section updates automatically when modules are activated/deactivated or cookies are added/removed.

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
| SECTION | sections | No |

### 7.3 Section configuration
Sections identified by Desk code. Configurable from Staffs page: name, email.

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
