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

**Confirming a link is not logging in.** The session is created in the window that ASKED for the link, and only there; the browser the link happened to be opened in stays anonymous and is told where its session is. A link arrives by email and an email is read wherever it is convenient — a shared tablet, a work laptop, somebody else's webmail, a corporate scanner following links automatically — and none of those should end up holding a session for the address. Opening the link in the very window that asked for it (request on a phone, open the mail app on the same phone) does sign that window in: it was going to collect that session anyway.

**Password**: enter email + password. Available only if user has configured a password.

**Passkey (WebAuthn)**: one-tap with biometrics or security key. No email field (discoverable credentials). Multiple keys per account.

## 3. Menus

Five main menus, visibility by role:

### 3.1 Notre unité (public)
Public pages.

### 3.2 Espace des animés (identified)
**Dynamic entries** (one per member linked to email, named by totem/prénom, section subtitle; if the `registration` module is active, one more per registration request linked to the same email, for as long as that request stays visible there — see §17) + separator + **static entries** from active modules.

### 3.3 Espace des chefs (intendant / chief)
Filtered by role — intendants see only `role_min: intendant` pages, chiefs see all.

### 3.4 Espace admin (chief)
Administrative tools.

### 3.5 Configuration (admin)
Site-wide settings, modules, functions.

### 3.6 Navigation
- **Mobile**: hamburger (left), unit name (right). Offcanvas from left. User card, accordion sub-menus, login/logout. Every sub-page entry starts with an icon, in the same box the per-member entries use for their avatar, so all the labels in a menu line up.
- **Desktop**: horizontal bar, user at right. A menu opens a mega-menu panel under the bar — titled columns (at most four) of text rows, each with its icon; per-member entries keep their avatar and section. Click to open, click/Escape/outside press to close, never hover. No permanent sub-menu row.
- **Breadcrumb**: visible at every width, on desktop as much as on mobile — with no permanent sub-menu row, it is what states the current page's ancestry.

### 3.7 How a person is shown

One shared component draws a person everywhere the site shows one — the member entries in the menu, the connected person in the header and menu, the author of a message in a discussion group, "Mon compte": a circle holding **their photo if one is known, their initials otherwise**.

Two photos, never mixed. A **member's** photo belongs to a scout year and is the one on their member page. A **login's** photo is set from "Mon compte", belongs to the person rather than to a year, and is what appears next to their name and their messages. It is optional (initials otherwise), only its owner can set or remove it — an administrator cannot, from anywhere — it is visible only to identified visitors, and removing it deletes the file.

## 4. Core pages

### 4.1 Notre unité

| Page | Content |
|---|---|
| Accueil | Editable text and photos (configuration mode); optional banner (module) and latest-articles column (news module) when those modules are enabled |
| Contact | Editable text; Staff d'U section's group photo and name/totem roster; Les Scouts federation logo (links to lesscouts.be) with its own editable text |
| Sections | Generated from Desk import; each card shows the section's staff photo, its designated "responsable" name, email, and a small editable text block |
| RGPD | Content set via the RGPD configuration page (§4.5): default reference text, admin-edited custom text, or AI-generated text. Links to the cookie preferences page for the cookie list (no longer embedded inline). |
| Calendrier (module) | Public activity calendar (month/week view); read-only ICS subscription feeds. Accepts `?section={id}` to preselect that section's calendar (used by the member page's own link, §4.2). |
| Actualités (module) | Public news article list/detail, each with an optional registration form (fields, capacity, payment) |
| Inscriptions (module) | Public form to request a spot for a child (open/closed by the admin, optionally on a schedule), with an optional availability display by birth year; a tracking link/page for the family (minimal view by token, full view once identified and linked); see §17 for the staff side |

### 4.2 Espace des animés

| Page | Content |
|---|---|
| {Member display name} × N | One page per linked member, two-column layout (same grid/stacking as Accueil, §4.1). Header: photo (replaceable by the member themselves outside configuration mode), display name, section, scout year. Right column: branch card (federation logo + link, per the member's age branch). Left column, in order: section name/email; section responsable (full legal name + postal address); badges assigned within the section; Staff d'U "Référent {section}" badge holders; next upcoming section event and links to Trombinoscope/Calendrier filtered on that section (both optional modules); the member's own functions this year; recent mass-mail communications with a "view as sent" detail page (module, optional); private documents — self only, never staff-visible (future home of fiscal attestations); photo galleries linked to the member's sections this scout year (module, optional); known contact emails (self-service: add/delete/resend verification/reactivate); all personal info from Desk with a mandatory note that it can't be edited here. Chiefs can adjust a member's scout year offset (+1/0/-1) when age-vs-section mismatch requires it. |
| {Member email detail} | One page per mass-mail email received, reachable only from the member's own page — subject, section, sent date, full body as actually sent |
| Trombinoscope (module) | Every section's chief/chief-d'unité staff, grouped by section, with the section's designated "responsable" highlighted. Accepts `?section={id}` to preselect a section (also used by the member page's own link above). |
| Galerie (module) | Photo/video albums (identified: view; chief: manage — see §4.3). Opening a media fills the screen; **one control** offers to save it at the best quality the site keeps of it. Each file is named after the photo's own name plus its media id — so two photos two phones both called `IMG_1234` are saved as two files rather than one overwriting the other — and the whole album's ZIP names its entries exactly the same way. |
| Groupes (module) | Private discussion groups the caller belongs to, most recently active first, plus an Archives tab for past-year ones. A group page is a feed: pinned posts, then posts newest-activity-first, each with up to four photos/videos, an optional link preview, one level of replies, and six fixed reactions. Members report; moderators restore or delete. See §20. |
| Notifications | Notification centre: list of received notifications (read/unread state, mark read individually or all at once), notification preferences (channel selection per type, quiet hours for push), push subscription management. Unread count shown in header badge. |

### 4.3 Espace des chefs

| Page | Role | Content |
|---|---|---|
| Staffs | intendant | SectionPicker + staff info per section (chief/chief-d'unité only — animés are not shown). Section's staff group photo, editable in configuration mode (one per scout year, falls back to the most recent earlier year). Badges assignable to staff (chief only, see Core\Badge). Section documents (an animateur of that section only, see §15.2): add/reorder/delete/update PDF attachments per section and scout year (e.g. planning, camp info sheets), displayed both on the Staffs page and the member page. Section name/email are configured from Config Desk (§4.5), not here. |
| Finances (module) | intendant | Bank statement import, receivables, receipts, movements |
| Statistiques (module) | chief | Member statistics |
| Calendrier (module) | chief | Chiefs' calendar view (month grid, event edit) |
| Envoi de mails (module) | chief | Mass email to selected members/sections across one or more scout years; a mail-merge mode sending one personalized email per row of an uploaded Excel file (see §24); when the Inscriptions module is also active, an extra predefined, non-editable "Inscriptions {année cible}" list is available (see §18.3) |
| Rétrospectives (module) | intendant | Create/manage post-activity retrospective boards |
| Galerie (module) | chief | Manage photo/video albums |
| Départs (module registration) | chief | Mark which of this year's animés won't be back next scout year, per section — see §18.1 |
| Prévisions (module registration) | chief | Read-only projected headcount for next scout year, per section and unit-wide — see §19.1 |

### 4.4 Espace admin

| Page | Role | Content |
|---|---|---|
| Import Desk | admin | CSV upload/import for current scout year. Year selection. Function mapping status. |
| Journal | admin | Searchable event log. |
| Année scoute | admin | The whole scout-year transition, as a workflow of three phases and fourteen steps (§16.3): preparing next year with the staffs, encoding it into Desk, then updating the site. The order is advice, not a gate; steps are either observed by the site or ticked off by hand, per target year. Steps belonging to a disabled module are absent. Displays effective year, public year, staff year, member/section counts. Public year activation is manual-only and available year-round; a non-blocking warning appears when the current public year is past its end date. When the Inscriptions module is active: the final step is refused server-side while any registration request is still pending/accepted (any target year); the staff-year step shows the same count as a non-blocking warning — see §19.2. |
| Membres | admin | Member search (name/email/phone) for the effective scout year, with detailed view showing all personal data from Desk (contact info, addresses, functions, age), plus effective age calculation with scout year offset. Excel export of all search results or of a checked selection, in the canonical member-export format — reusable as-is as a mail-merge audience (§24). |
| Bannière (module) | admin | Manage homepage banner messages (role-gated visibility, ordered list) |
| SOS Staff d'U (module) | admin | On-call duty roster (month grid), default forwarding number, live redirect status, scheduled redirection list |
| Rétrospectives — Config (module) | admin | Per-board moderation/AI settings restricted to chef d'unité |
| Inscriptions (module) | admin | See §17 — request management: capacities/year code (age brackets are read-only, federation-fixed, shared with the Statistiques module), year selector, capacity-verification table, request list (filter/search), "non rapprochées"/"non clôturées" encarts with bulk refuse/withdraw, and a per-request fiche (status transitions, section prévue, tarif, internal notes, acceptance/refusal emails, manual Desk linking) |
| Passage (module registration) | admin | Split arriving families and promoted animés between sections ahead of next scout year — see §18.2. Chef d'unité only (not a per-section chief), since spreading arrivals across sections needs the whole unit at once |
| Encadrement (module leadership) | admin | Three lists of people to contact, read out of the Desk import — training paths, age-related legal deadlines, steward registrations. See §25 |

### 4.5 Configuration

All pages in this menu require the `superadmin` role, except Maintenance (`admin` — see ARCHITECTURE.md §3/§8.15).

| Page | Content |
|---|---|
| Configuration générale | Badges (transversal roles, e.g. Infirmier/Trésorier, plus one auto-generated "Référent {section}" badge per visible section, assignable only to Staff d'U members — add/rename/activate/deactivate; default badges and badges already assigned can only be deactivated, never deleted). Module registry + configuration mode toggle. |
| Config Desk | Map Desk functions to site roles; rename sections, set section email, and toggle section visibility across the site. Per age branch: federation logo (uploaded, falls back to a shipped default per canonical branch, else nothing) and explanation link (defaults to the Les Scouts federation page), shown on the member page's branch card (§4.2). |
| Paramètres | Key-value settings grouped by module, edit via dialog. |
| Actions planifiées | Scheduled actions list with status. |
| Configuration RGPD | Choose the RGPD page's content mode: default reference text, custom rich text, or AI-generated from an admin-provided prompt (requires an AI connector module to be enabled). Auto-saved on every mode/content change; each mode tracks its own last real content-change date/time (UTC), never "today" on every view. |
| Maintenance | Backups (on-demand + automatic, database-only/config-only/full, encrypted); update from GitHub releases (check/install with automatic rollback on failure); reset actions (settings to defaults, restore a backup, full reinstall) — each destructive action requires typing an exact confirmation word. |
| Support | Usage-statistics switch, with the plain-language explanation of what is reported and an explicit statement that the report is **not** anonymous (it carries the instance URL). A « envoyer un rapport de test maintenant » button that transmits one report immediately and shows the answer. The destination is deliberately **not** on this page, in any form: it is a project-level fact, not a unit-level choice. Read-only state of the last successful and last failed/skipped send. Exact JSON preview of what would be sent, collapsed by default, shown whether reporting is on or off. Diagnostic support package: generate on demand (background task, progress indicator, then a download link), the warning about what an archive can contain, and the configured support address. One package is kept at a time, encrypted at rest, superadmin-only, purged after 7 days, never transmitted automatically. Deliberately no bug-report form (no name, no contact email, no incident description) and no "next scheduled send". |
| Outils de test (module) | Present **only** on the ScoutMagic reference installation and on local development installations — the module declares `"visible_when": ["reference_installation", "local_installation"]` and is filtered out of module discovery everywhere else. Index of the available tools; today one: the **bac à sable e-mail**. Armed, no e-mail leaves the server — each message is assembled by the real mailing library, DKIM signature included, then stored here. The page carries the switch, its state in plain French, and — only when armed — the warning that magic links now arrive on this page. List of captured messages (objet, destinataire, date, taille, pièces jointes, badge DKIM) with search on the subject, on the recipient's exact address, and an opt-in bounded search inside the message bodies; pagination. Detail page in five tabs: aperçu HTML (rendered in a sandboxed frame), texte brut, en-têtes, source MIME, pièces jointes — plus a `.eml` download. Retention by count (500 by default, daily purge) and a danger-zone action to empty the sandbox behind a typed confirmation word. `superadmin` only. |
| Tableau de bord support (module) | Present **only** on the ScoutMagic installation acting as statistics receiver — the module declares `"visible_when": ["statistics_receiver"]` and is filtered out of module discovery everywhere else. Table of the installations reporting in, with filters, free search, sort and pagination; five indicator cards and two current-state charts, all recomputed on the filtered set; a detail dialog carrying every metric plus the exact raw JSON of the last accepted report; XLSX export of the filtered set; manual deletion behind a confirmation; and a monthly-history chart independent of all of the above. `superadmin` only. |
| Finances (module) | Accounts, categories, categorization rules, danger zone |
| Galerie (module) | Storage location (local/S3), default location for new albums. Each local location also shows the space still free on the disk that holds it — with the volume's size and the share in use, and a warning when what is left is smaller than the largest upload the gallery currently accepts. The page states that this is the whole volume, shared with the rest of the site, and not a quota reserved for the gallery; an S3 location shows nothing, its capacity being the provider's. |
| Calendrier (module) | Default view, supplementary calendars, ICS feed links |
| Envoi de mails (module) | Sender/attachment settings |
| SOS Staff d'U (module) | Telephony provider credentials (OVH: application key/secret, consumer key, line selection), excluded sections |
| Intelligence artificielle (module) | AI provider credentials and model selection, consumed optionally by other modules (RGPD text generation, retro moderation, finance receipt extraction, news summaries) |

### 4.6 Pages outside menus

| Page | Content |
|---|---|
| Connexion | Three-tab login (magic link / password / passkey). |
| Aide | Index of every help topic the visitor's role may see (`/aide`), grouped by category with a `?q=` search, plus one page per topic (`/aide/{id}`). Fed by Markdown files shipped in the release (`docs/help/`, `modules/<id>/help/`) — help is product documentation, never unit-editable content. A per-page help button (right of the breadcrumb bar) opens the matching topic(s) in a panel without leaving the page; a topic below the visitor's role does not exist anywhere (404 by direct URL). |
| Mon compte | Name, surname. Password. Passkeys. Notification preferences (link). Cookie preferences (link). |
| Préférences cookies | Cookie categories with toggles. Accessible from banner, RGPD page, and Mon compte. |
| Upload | Generic file upload (drag-drop, file selection, mobile camera). |
| Installation | First-run setup (DB, unit settings, email, admin). Same page as Configuration later. |
| Manifest / Icônes PWA | Progressive Web App manifest (JSON) and adaptive icons (192px, 512px, maskable) for installable app. Offline fallback page. |

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

## 12. Progressive Web App (PWA)

The site is installable as a Progressive Web App on supported browsers/devices.

### 12.1 Manifest
- Dynamic manifest.webmanifest with site name, short name, theme color, background color
- Adaptive icons (192px, 512px, maskable) generated from unit logo with configurable background color
- Icon version tracking for cache invalidation on logo changes

### 12.2 Service worker
- Offline support with network-first strategy for core pages
- Cache versioning tied to app version (GitHub release install triggers cache invalidation)
- Offline fallback page when network unavailable

### 12.3 Configuration
- Theme color and background color configurable via settings (Configuration > Paramètres)
- Icon automatically regenerated from logo when uploaded
- Version number from VERSION file, incremented on each GitHub release

## 13. Notification system

Centralized notification dispatch and delivery across channels.

### 13.1 Channels
- **In-app**: notification centre (bell icon in header, unread count badge). The bell's own panel previews the **five most recent unread** notifications under the count, and says how many more are pending; the full list is one click away.
- **Push**: browser/mobile push notifications (Web Push API, opt-in via subscription)

### 13.2 Notification types
Modules can dispatch typed notifications (e.g., calendar event reminder, news article published, finance receipt processed). Each type has:
- Default channel preferences (in-app, push, or both)
- User-configurable per-type channel overrides
- Role-based visibility (minimum role required to receive)

### 13.3 Preferences
- Per-type channel selection (in-app only, push only, both, none). Reached from the top of the notification centre, above the list.
- Quiet hours for push notifications: filling in a start and an end holds push notifications back between those two hours and delivers them when the range ends (a range crossing midnight works); in-app notifications are never held. Leaving both empty follows the site-wide range.
- Push subscription management (subscribe/unsubscribe)

### 13.4 Delivery
- In-app: stored in database, displayed in notification centre, badge count in header
- Push: sent via Web Push API (VAPID), respects quiet hours, falls back to in-app if subscription missing
- All notifications logged in journal

## 14. Member email management

Members can manage their own secondary email addresses (self-service only, no chief/admin override).

### 14.1 Adding an email
- Enter email address on member page
- Confirmation email sent with unique single-use link (15-minute expiry)
- Email becomes "active" only after confirmation
- Unconfirmed emails can be re-sent or deleted

### 14.2 Email states
- **Pending**: added but not yet confirmed (verification email sent)
- **Active**: confirmed and usable for login
- **Deactivated**: previously active but deactivated by the member (can be reactivated without re-verification)

### 14.3 Operations
- **Add**: enter new email, sends verification email
- **Resend**: re-send verification email for pending addresses
- **Reactivate**: restore a deactivated email to active state
- **Delete**: remove email (pending or deactivated only; active emails must be deactivated first)

### 14.4 Access control
- Member can only manage their own emails (verified on every action, regardless of role)
- No chief/admin bypass — this is strictly self-service
- Public confirmation link (unauthenticated, like password reset)

### 14.5 Logging in with a secondary address
- An active secondary address is a login identity **of its own**, never an alias for the member's main address
- It logs in by magic link (the only method available to it — it starts with no password and no passkey)
- What it gives access to is exactly the member(s) it is currently active on, and the role those members carry — never the other members reachable from the main address, and never that account's own password or passkeys
- Deactivating or deleting it ends that access on the next page load

## 15. Section documents

Chiefs can attach PDF documents to sections (per scout year), displayed on both the Staffs page and member pages.

### 15.1 Use cases
- Section planning documents
- Camp information sheets
- Activity schedules
- Parent information

### 15.2 Management (Staffs page, animateurs of that section only)
- **Who**: an animateur manages the documents of the sections they staff, and only those — the four write operations below are all re-checked server-side against the account's own sections (a chef d'unité manages every section). Reading is unrestricted: every animateur sees every section's documents, whether or not they can edit them.
- **Add**: upload PDF, enter title, select section and scout year
- **Reorder**: drag-and-drop or move up/down to change display order
- **Update**: change title or replace PDF file
- **Delete**: remove document
- Optional compression with Ghostscript if available on server (reduces file size)
- Warning displayed for large files when compression unavailable

### 15.3 Display
- Staffs page: listed per section with download links
- Member page: section's documents shown in member's section card (filtered by member's scout year)
- Only PDF files allowed (enforced by upload handler)
- Scoped by section and scout year (each year has its own set of documents)

## 16. Scout year transition

Annual transition from one scout year to the next, managed through a guided workflow of three phases and fourteen steps.

### 16.1 Year types
- **Public year**: year visible to identified members and public visitors (current year, displayed site-wide)
- **Staff year**: optional override for chiefs/intendants (allows staff to work on next year while members see current year)
- **Preview year**: session-only override for admins (temporary view of any year for verification)

### 16.2 Effective year resolution
For each request, the effective year is determined in order of precedence:
1. Preview year (if set in session by admin)
2. Staff year (if role is chief/intendant and staff year is configured)
3. Public year (fallback for all other users)

### 16.3 Transition workflow (Espace admin > Année scoute)

The page walks a chef d'unité through the whole season, not only the four moments where the site itself changes year. Fourteen steps, grouped into the three phases the season actually has, in this order:

**Phase 1 — Préparation** (*« Idéalement avant le {date d'encodage Desk} »*)

| # | Step | Where it happens |
|---|---|---|
| 1 | Indiquer les animés qui se désinscrivent en {cible} | Départs (§18.1) |
| 2 | Indiquer la section des animés qui changent de branche | Passage (§18.2) |
| 3 | Confirmer les inscriptions et indiquer la branche cible | Inscriptions (§17.6) + « section prévue » |

**Phase 2 — Encodage dans Desk** (*« Plutôt à partir du {date} »*)

| # | Step | Where it happens |
|---|---|---|
| 4 | Encoder les nouveaux staffs dans Desk | Desk (outside the site) |
| 5 | Encoder les animés dans Desk (nouveaux inscrits, passages, sortants) | Desk (outside the site) |
| 6 | Mettre à jour les cotisations | Desk (outside the site) |
| 7 | Prévisualiser le site de l'année prochaine ({cible}) | This page — session-only |
| 8 | Importer les données Desk | Import Desk |

Step 5 carries a note: once the import is done, animateurs can rely on the preview of the target year and download the member list for that year from « Membres par section » — a list that already lets them write to the families by email.

**Phase 3 — Mise à jour du site** (*« Le site est à jour pour {cible} : c'est typiquement au tour des staffs d'agir. »*)

| # | Step | Where it happens |
|---|---|---|
| 9 | Activer l'année {cible} pour les staffs | This page |
| 10 | Encoder les éphémérides de l'année sur le site | Calendrier |
| 11 | Encoder les badges (trésorier·e, infirmier·e, référent·e·s…) | Staffs |
| 12 | Mettre à jour le trombinoscope | Trombinoscope |
| 13 | Mettre à jour les photos de staff | Staffs |
| 14 | Activer pour tout le monde | This page |

**The order is advice, not a gate.** The page highlights the first step still to do, but every control stays usable at all times. Exactly two conditions actually block anything, and both are real:
- **Step 14** requires a staff year to exist first (step 9).
- **Step 14** is refused, server-side, while the Inscriptions module reports any pending/accepted registration request (any target year) — §19.2. Step 9 shows the same count as a plain, non-blocking warning.

Step 9 also warns — without blocking — when nothing has been imported for the target year yet, since the staff would otherwise be shown an empty year.

**How a step becomes done.** Two sources, never a third:
- **Observed by the site**: the preview is active for the target year (7); the target year has members (8); the staff year is the target (9); at least one calendar event falls inside the target year (10); every section of the target year has its own photo for that year, last year's fallback photo not counting (13); the public year is the target (14); no passage is left without a destination section (2).
- **Ticked by a human**: everything else — the work inside Desk, and the judgement calls. A step that also has an observed signal keeps its checkbox as an override, so a unit whose signal will never fire (no passage this year, a calendar kept elsewhere) is never left with a step it cannot finish.

The four steps the site decides for itself (7, 8, 9, 14) have no checkbox at all, and the server refuses to accept a manual tick for them. Ticks are recorded per target year with who ticked and when, they can be un-ticked, and next year's run therefore starts blank on its own — there is no reset step.

**Steps of a disabled module are absent, not greyed out**, and the remaining steps renumber accordingly: steps 1–3 need the Inscriptions module, step 10 the Calendrier module, step 12 the Trombinoscope module. A phase left with no step at all disappears with them.

**The dates are signposts.** One admin-configurable parameter (day and month, 15 August by default) separates the preparation phase from the Desk-encoding phase and marks which of the two the calendar is currently in. It never enables, disables or triggers anything — see §16.4.

### 16.4 Manual transition only

The transition to a new public year is exclusively manual — step 14 is available year-round, and nothing switches the public year automatically. There is no switch window and no date-based fallback transition.

This is deliberate: the "inscriptions" module vetoes step 14 while registration requests are still open (§19.2), and a date-driven automatic transition would have bypassed that veto — a calculated date can't be told "not yet". Manual and automatic are incompatible, so manual wins.

The Desk-encoding date of §16.3 does not reopen this question. It labels phases and nothing else: no step is disabled before it, none is triggered on it, and a malformed value simply falls back to the default rather than affecting the page.

Without an automatic catch-up, a unit that never runs the transition would stay on a stale public year indefinitely with no visible sign. The Année scoute page shows a non-blocking warning when the current public year is past its end date, inviting the admin to run the transition workflow — it never blocks anything and never changes anything on its own.

A freshly installed site with no public year configured yet still starts on a plausible year computed from the current date — this initial determination is unrelated to (and unaffected by) the removal of the automatic switch above.

## 17. Registration module — staff-side request management (module registration)

A request's full life cycle after submission (§4.1's public form/tracking page covers the deposit itself): review, decision, communication with the family, reconciliation with the annual Desk import, and eventual retention/deletion. See ARCHITECTURE.md §8.36 for implementation detail.

### 17.1 States

Three progression states plus two exits:

| State | Meaning | Reached by |
|---|---|---|
| En attente (pending) | Received, not yet decided | Submission, or "revenir en attente" from any other non-final state |
| Acceptée (accepted) | Decided, not yet matched to a Desk member | Staff action |
| Encodée dans Desk (encoded) | Matched to a real, Desk-imported member — final | Automatic reconciliation at import, or manual linking (§17.4) — never a plain status change |
| Refusée (refused) | Decided negatively — final | Staff action |
| Retirée (withdrawn) | Abandoned, by the family or the staff — final | Staff action |

A final state (encodée, refusée, retirée) starts both retention clocks (§17.5). "Revenir en attente" is the only transition leaving a final state, and only from refusée/retirée/acceptée — never from encodée.

### 17.2 The decoupling rule

The tracking page (§4.1) never shows an acceptance or a refusal before the corresponding email (§17.3) has actually been sent — it shows "en attente" until then, regardless of the real internal state. Staff-facing pages always show the real state plus whether/when each email was sent, side by side.

### 17.3 Acceptance/refusal emails

Sent explicitly from a request's fiche, never automatically at the moment of the decision. Each email's body is edited like the module's other emails (§4.1); unlike those, it has no default text — the send button stays disabled until a body has actually been written. Resendable at any time; a delivery failure is shown to the staff member rather than silently recorded as sent.

### 17.4 Reconciliation and manual linking

At every Desk import, accepted requests for that scout year are compared against the freshly imported members by name + birth date. Exactly one match on each side migrates the request automatically: it becomes "encodée", any confirmed secondary tracking email moves to the real member's record, and the request's own page in Espace des animés is replaced by the real member page (the fiche itself stays staff-visible, see §17.5). No match leaves the request as "acceptée", surfaced to staff as unmatched. More than one possible match on either side (e.g. twins, or two families sharing a name) is never guessed — staff resolves it manually by entering the child's Desk "numéro de tiers", which goes through the exact same migration as an automatic match, refusing an unknown number or one already linked to another request.

### 17.5 Retention

Two durations, both admin-configurable and both counted from the moment a request reaches a final state (never from its submission date):

| Setting | Default | Effect |
|---|---|---|
| Disparition de l'Espace des animés | 3 months | A refused/retirée request stops appearing in the family's personal space (an encodée request disappears immediately, replaced by the real member page) |
| Suppression définitive | 2 years | The request row is permanently deleted, regardless of state |

A request still "en attente" or "acceptée" is never purged, however old.

### 17.6 Management page and fiche

**Management page** (Espace admin > Inscriptions, §4.4): year selector (target year by default, plus the current and any past year still in the database — past years are consultation-only), the capacity/year-code configuration (§4.1 form setup; age brackets themselves are shown read-only — they're federation-fixed and shared with the Statistiques module, not something this screen configures), a capacity-verification table (capacity, projected headcount, accepted requests, remaining, and the same availability level shown to the public), the request list (searchable/filterable by state), and two encarts: unmatched accepted requests, and non-final requests with bulk "tout refuser"/"tout retirer" actions (each behind an explicit confirmation showing the exact count affected).

**Fiche** (one per request): fields in the same order Desk itself asks for them. Everything the family submitted is read-only except two staff-only fields — "section prévue" (the section actually offered, distinct from and never shown alongside the family's own "section souhaitée" to the family, restricted to the child's own age branch) and "tarif" (a household-size-based suggestion, always overridable, using the same estimation as an existing member's fee category — counting other accepted/encoded requests at the same address alongside existing members). A free-form internal notes field (never shown to the family) completes the fiche, alongside the status banner and its available transitions.

## 18. Registration module — Départs, Passage, mailing list (module registration)

Two Espace des chefs pages that prepare the next scout year, plus an optional predefined list for the mass-mail module. See ARCHITECTURE.md §8.37 for implementation detail.

### 18.1 Départs

Espace des chefs, role `chief`. Scoped by section: an animateur/chief sees and can only act on the section(s) they staff; `admin`/`superadmin` see and can act on every section. For the selected section, lists this year's animés (never the section's own staff) with, per row, their year within their branch, a "won't be back next scout year" checkbox, and an optional comment that only appears once the checkbox is ticked.

The mark applies to the current scout year only and resets itself automatically at the next Desk import — it never needs to be manually cleared from one year to the next, and the page says so explicitly. Saving is automatic (no save button); the checkbox and the comment save independently of each other, so two people editing the same section around the same time never have one field's save overwrite the other's. The comment is encrypted at rest and never appears in the audit journal, an error message, or a section its author doesn't staff.

### 18.2 Passage

Espace chefs d'U, role `admin`, **not** scoped by section (splitting arrivals across sections needs to see the whole unit — the same reason it sits at the chef d'unité level rather than the same floor as Départs). Always targets the current public scout year **plus one** — never whatever year an admin happens to be previewing, and never a staff year override. This is a deliberate exception to the rule that otherwise governs every page on the site.

Two independent blocks:
- **New registrations**: accepted-but-not-yet-Desk-encoded requests for the target year, with the child's targeted slot, the section requested by the parent, any remarks, each declared sibling together with that sibling's own current section, and a "section prévue" picker — the exact same field shown on that request's own fiche (§17.6): editing it here edits it there, and vice versa.
- **Passages**: existing animés at the last year of their current branch (excluding anyone marked leaving in Départs, and excluding the oldest branch, which has nowhere further to go), grouped by their current section. Each row shows the animé's current year within their branch, everyone else at the **same address** (never called "fratrie" — a shared address can mean roommates or two families at one number just as easily as siblings, and is a different notion from the declared sibling links in the block above), and a destination picker limited to the sections of the branch they're moving into.

The destination chosen for a passage is stored by the module itself (keyed on the member and the target year), never written onto the member's Desk-sourced record — Desk stays authoritative once that year is actually activated and re-imported.

### 18.3 Mailing list for the mass-mail module

When the mass-mail module (§4.3) is also active, its "nouvel email" list picker gains one extra, non-editable entry: **"Inscriptions {année scoute cible}"** — always named after the target year (never reused across years), containing every request that has been both accepted and encoded into Desk for that year (a still-pending, refused, or withdrawn request never appears). Its member list is recomputed at the moment an email actually sends, never fixed when the email is drafted. Available only to a chef d'unité (or above), same as the mass-mail module's own unit-wide lists. If the inscriptions module is disabled, this entry simply doesn't appear and the mass-mail module works exactly as it does today; if the mass-mail module is disabled, nothing changes on the inscriptions side.

Sending both this list and, separately, a section list that now includes the same newly-encoded child will reach that family twice — the same is already true of any two overlapping mass-mail lists today, so this isn't a new failure mode, and no automatic cross-email deduplication exists (or is planned) to prevent it.

## 19. Registration module — Prévisions and the year-transition veto (module registration)

The module's last piece: a read-only headcount projection for next scout year, and a hard block on transitioning the whole site to it while registration requests are still open. See ARCHITECTURE.md §8.38 for implementation detail.

### 19.1 Prévisions

Espace des chefs, role `chief`, **not** scoped by section, same "public year plus one" targeting as Passage. Read-only — nothing on this page writes anything.

The projected headcount for next year combines, without counting anyone twice:
1. Whatever is already re-imported from Desk for next year, if an import has happened — real, certain data.
2. Minus animés marked "won't be back" (Départs).
3. Plus passages, placed in their chosen destination section.
4. Plus accepted-but-not-yet-encoded registrations, placed in their chosen "section prévue".

A registration already encoded into Desk and reimported exists as a real member, not as a pending request any more — it is counted once, through source 1, never also through source 4.

Every number on the page distinguishes what's **certain** (already in Desk) from what's a **hypothesis** (a passage or registration not yet encoded, even once a destination has been picked) — a small icon/label pair next to every count, not just a one-time notice at the top.

A passage without a chosen destination, or a registration without a chosen section prévue, lands in a separate **"Non attribués"** card rather than any real section: still counted in the unit total and in the birth-year pyramid, counted in no real section, split by its two possible origins (passage / registration), with a direct link to the Passage page to resolve it. This card empties out as staff make decisions on Passage — an unintentional indicator of how trustworthy the rest of the page's numbers currently are.

The page shows: four headline numbers (projected total, variation vs. the current year, announced departures, new entries); for each section, a segmented bar showing the split by year within branch (a section top-heavy with its oldest year is a section about to empty out) and a single divided bar for the girls/boys balance with percentages; and one population pyramid by birth year across the whole unit (a dip in a given birth year foretells a section thinning out two or three years from now).

This page's charts are its own — it does not depend on, and works identically whether or not, the Statistiques module (§4.3) is installed or enabled.

### 19.2 Veto on the year transition (Espace admin > Année scoute, §16.3)

While the Inscriptions module is active:
- **Step 14** (activate the public year for everyone) is **refused** — checked on the server, not just by disabling the button — as long as any registration request is still `pending` or `accepted`, whichever scout year it originally targeted. The error message states how many requests are blocking and links to the registration management page, which is where they're resolved individually or in bulk ("tout refuser"/"tout retirer", §17).
- **Step 9** (activate the staff year) shows the same count as a plain, non-blocking warning — it happens much earlier in the season and staff need time to work through the backlog, so it never stops anything.

If the Inscriptions module is disabled or not installed, both steps behave exactly as they did before this module existed — nothing is blocked, no warning appears.


## 20. Groups module — private discussion groups (module groups)

Private group conversations for the unit: one group per section, created automatically, plus invitation groups a chief opens for anything else (a camp staff, a working group, a project). Not a social network — there is no directory, no public group, no self-join and no join request. A group is invisible to anyone who is not in it.

### 20.1 Who is in a group

Two sources, both resolved at every page load rather than copied anywhere:

- **A linked section.** Everyone with a membership period in that section, for the group's scout year. A Desk import that moves a member between sections therefore takes effect immediately, in both directions.
- **An explicit invitation.** A chief invites a member (or a whole section) individually, and chooses whether to notify them — by default an invitation notifies nobody. The same row carries the moderator flag.

A section group is tied to its scout year, which is what keeps last year's group readable by exactly the people who were in it. An invitation group is tied to a year only if the chief wanted it. A site admin is an implicit moderator of every group; a chief who is not a member of a group does not see it.

**A group is read as a member and acted in as a login.** Everything somebody does here belongs to the email address they are identified with: messages and comments are signed by it (there is nothing to choose when publishing), and **the moderator role is granted to one address, not to a member** — two addresses can reach the same member, and naming one moderator never makes the other one too. A member with no account cannot be a moderator: moderating is something one logs in to do.

### 20.2 What a group holds

Posts (up to 5000 characters, up to four photos or videos, an optional poll, and at most one link with an automatic title/description/image preview), one level of replies (up to 2000 characters, at most one image — replies are never nested), and six fixed reactions (👍 ❤️ 😂 😮 😢 👏), one per person per item. There is no separate field for a link: the first URL typed anywhere in the message is detected automatically, previewed live while composing, and removed from the stored text once its preview card is attached — the card represents it from then on. An author may edit their own post or reply for 15 minutes; an author or a moderator may delete one at any time. Publishing brings the new message into view rather than leaving the author looking at the composer.

**Who may publish is the group's own choice**: every member (the default), or its moderators alone — an announcement group, where one voice publishes and everybody answers. The restriction is about starting a conversation and nothing else: commenting, reacting and answering a poll stay open to every member either way. Staff d'U publishes in every group without being granted anything, being an implicit moderator of all of them.

**A group has one pinned message at a time.** Pinning a second takes the pin off the first — the moderator is told which message that is, and chooses how long the new one stays up (a day, a week, a month, or until a moderator takes it down) before it happens. A pin that reaches its deadline lapses on its own, and the message becomes an ordinary one again.

**A group tied to the current scout year carries it in its name** — "Louveteaux (2025-2026)" — wherever it is written: the list, the group's own page, its members and gallery pages, the breadcrumb. A past-year group does not, being already marked as an archive, and a group tied to no year has nothing to add.

Photos and videos live in a gallery album belonging to the group, never listed in the unit's own gallery and readable only by the group's members. "Galerie du groupe" shows them all on one page.

### 20.3 Reporting and moderation

Any member may report a post or a reply, once, from the "…" menu on the item. Past a configurable threshold (2 by default), the item is hidden from everyone except the group's moderators. **Moderators have a page of their own** ("Signalements", reachable from the group's header as soon as there is something on it) listing every reported message — a reported comment appears through the conversation it belongs to — where they hide it by hand before any threshold, ignore the report (which also protects the item from a later automatic hiding), restore a hidden one, or delete it. **A moderator may never restore an item they wrote themselves** — another moderator, or a site administrator, has to; deleting their own content stays allowed. When the reported item was written by one of the group's own moderators, the report additionally reaches every site administrator, so the group's own moderation is never the only judge of a complaint about itself. The item's author is never told their content was reported. **Hiding is the maximum automatic consequence — nothing is ever deleted without a human deciding.** A restored item is never auto-hidden again, however many further reports arrive. The reporter is told nothing about the outcome, and who reported an item is never revealed to anyone.

When the AI connector module is active, a post or reply is checked before publication for personal attacks or disrespectful language, and its author is offered a rewording. The check fails open: no provider, a timeout or an error all mean the message is published unchecked. A refused message is never stored anywhere — it is handed back to its own author and nowhere else.

### 20.3bis Polls

A message may carry a poll: a question and from 2 to 10 answers. The composer always keeps one empty answer box waiting at the end — typing in the last one adds another, clearing one takes the spares back — so a poll grows as it is written and never shows a form full of blanks.

Two choices belong to the poll itself, made when it is written:

- **Who counts as one voter**: the connected person (the default — one answer per email address), or the member. Per member is what a parent of several children needs; when their account is linked to more than one member the group counts, a small dialog asks whose answer is being given at the moment of voting. **Which of their members the group counts is the group's own affair**: a section group asks its section's question, so only the children who are in it may be answered for; any other group — a camp staff, a project, the whole unit — draws no such boundary and offers every member the account reaches, the group's own first. A family of four reachable through one address therefore has four answers to give when the unit asks, and two when their own section does. The dialog says which is which: the members the group holds first, the others under a heading of their own (« Dans ce groupe » / « Hors de ce groupe »), and no heading at all when everybody is on the same side.
- **How many answers each voter may give**: one (the default, changeable at any time) or several, where tapping an answer again takes it back.

Results show how many people (or members) answered, never who answered what.

### 20.4 Notifications

Five types, all optional per member except one: a new post in one of my groups, a reply to my post, a reaction to my post or reply (debounced, so a burst of reactions is one notification), an invitation to a group (sent only when the person inviting asks for it), and — for moderators only — a report needing attention, whose in-app channel cannot be switched off. No email is ever sent for any of them.

### 20.4bis Managing a group

| Action | Who | Rule |
|---|---|---|
| Modifier le groupe | Moderator | Rename it, describe it, and choose who may publish (all members, or moderators only). An **invitation** group may also be linked or unlinked to the current scout year (same effect as the checkbox at creation); a **section** group's year always follows its section and is never editable here. |
| Épingler un message | Moderator | One pinned message per group, for a chosen length of time — see §20.2. |
| Modifier les membres | Moderator | Invite a member or a whole section; grant or revoke the moderator flag; remove an invited member. |
| Quitter le groupe | Any invited member | Deletes their invitation; access is lost immediately and coming back needs a new invitation. A membership that comes from a **linked section cannot be left** — it follows the Desk import. The **last moderator** may not leave until another one is appointed; site admins do not count. Their existing posts and replies stay in the group. |
| Clôturer | Moderator | Read-only from then on, still fully visible. |
| Rouvrir | Moderator or site admin | Only while the group's scout year is still the current one — a past-year group stays a read-only archive. Reopening restarts the inactivity and purge clocks, so the nightly task does not close it straight back. Content already deleted by the retention purge does not come back. |

An administrator caps how many **open, non-section** groups one person may have created (5 by default). Section groups are created automatically and never count; a closed group stops counting. The cap applies to administrators too.

### 20.5 Lifecycle and retention

Four nightly tasks, each with its own admin-configurable duration and none of them overridable per group:

| Task | Rule |
|---|---|
| Create section groups | One group per visible, active section per scout year, Staff d'U included. Idempotent, and also run whenever the group list is opened, so a missing group heals itself. Next year's groups appear as soon as that year is imported, ready for chiefs through the staff-year mechanism (§16). |
| Close inactive groups | A group with no post, reply or reaction for `groups_inactivity_close_months` (12 by default) is closed: read-only, still fully visible to its members. A group that never held anything is counted from its creation. A moderator may also close a group by hand. |
| Purge posts | A post is deleted `groups_post_retention_months` (24 by default) after its last activity, with its replies, reactions, reports, media and cached link image — the files themselves, not only the rows. A pinned post is never purged, at any age; a pin whose deadline has passed is not one any more (§20.2). |
| Purge closed groups | A closed group is deleted `groups_closed_purge_months` (12 by default) after its closure, gallery album included. A group of the current or a future scout year is never purged. |


## 21. Usage statistics, support package and support dashboard (core + module support_dashboard)

Three responsibilities that are deliberately **separate**, because each answers a different question, each has a different audience, and each fails independently of the other two. Conflating them is how a diagnostic archive ends up being transmitted automatically, or a telemetry switch ends up disabling a support tool.

### 21.1 Usage statistics — what an installation reports, once a day

An installation sends a small aggregated report to a configured destination, at most once per 24 hours. It exists so that a maintainer receiving a bug report can answer "what is actually running over there?" — PHP version, database engine, which modules at which versions, which hosting layout, real cron or poor-man's cron.

**It is not anonymous, and the page says so in as many words.** The report carries the instance's own URL, because a report you cannot tie back to a site you are supporting is close to worthless. It carries **no member data of any kind**: no name, no email, no section, no individual activity, no file content, no module configuration value. Counts of active members and active sections are just that — counts.

The switch is on the Support page, on by default, and offered again as a pre-checked box during first-time setup with a "voir ce qui sera envoyé" disclosure. **Turning it off disables sending and nothing else**: the Support page, the JSON preview and the support package all keep working. Nothing is ever sent retroactively for a period during which reporting was off.

**Where the report goes is not a unit-level choice** and appears nowhere in the interface — not as an editable field, not as displayed text. Changing it means standing up another receiver, which is a deployment act by whoever runs that receiver.

**A superadmin can send one report immediately, from the Support page, to check that reporting actually works** rather than waiting a day and re-reading a status panel. The manual send ignores the once-a-day interval and is the one case allowed to send to this installation itself — which is how the receiver's own report reaches its dashboard at all, and the only end-to-end check of the intake endpoint there is. It still respects the switch, still refuses from a non-public host, and still stands aside during maintenance; and it never overwrites the last-send state, which describes the *automatic* reporting.

A send is skipped, never retried within the day, when: reporting is off; the installation is in development-update mode; its `base_url` host is not publicly resolvable (localhost, a bare IP, a `.local`/`.test`/`.localhost`/`.invalid`/`.internal` name); the installation *is* the receiver; a maintenance operation is in progress; or a send already succeeded in the last 24 hours. Skips are journaled with a reason; a skip is not a failure. There is deliberately **no "next scheduled send"** anywhere in the interface.

### 21.2 Support package — a diagnostic archive the unit sends by hand, or not at all

A superadmin can generate a ZIP of diagnostics on demand: the same statistics document, a database **structure** dump with no application rows, the settings, the last 48 hours of the event journal, the declared scheduled tasks, `phpinfo`, a filesystem listing, external-command availability, `.htaccess` files and readable logs.

**Nothing is ever transmitted automatically** — no mail, no upload, no pre-filled attachment. The archive is generated locally, kept encrypted at rest, downloadable only by a superadmin, replaced each time a new one is generated, and purged after seven days. Exactly one is kept.

The page warns, **before** any action, that the archive can contain information specific to the system, that it is not intended to contain personal data but that this cannot be guaranteed, and that its contents should be checked before sending — particularly the PHP information, the access and error logs, and the filesystem diagnostics. `phpinfo` is captured without its variables section, so no live session cookie or environment credential is in it. There is deliberately **no bug-report form**: no name, no contact email, no incident description.

Each collector runs in isolation. A collector that fails, or that cannot run on a given host, is recorded in `collection-status.json` with a reason and never prevents the archive from being produced — on constrained shared hosting several will report "unavailable" permanently, and that is an expected result.

### 21.3 Support dashboard — what the receiver does with the reports

One ScoutMagic installation acts as the receiver. Its intake endpoint authenticates each sender with a bearer secret, registering an unknown installation on first contact and verifying every later report against the stored hash — the secret itself is never stored in clear anywhere. Unknown fields from a newer sender are kept verbatim and never cause a rejection.

The dashboard shows one row per installation, defaulting to the active ones. **No view state is remembered between visits** — no filter, search, sort, page or period — so the page never opens on somebody else's stale filter, and it sets no cookie. **An absent value is shown as "Non renseigné", never as `0` or "Non"**: reports come from installations of differing versions, so "this sender could not measure it" is a permanent, first-class state.

Installations go stale after a configurable number of days (still visible, behind a filter) and are deleted in full after a configurable number of months, or sooner by hand. A separate monthly history counts, per calendar month, how many distinct installations reported at least once. Once a month is finalised its aggregate is **immutable, holds no individual identifier at all, and is kept indefinitely** — a later deletion never rewrites it.

## 22. Rentals module — letting the unit's own assets (module rental)

A unit lets its hall, its ground, its tents, its trailer. The module covers the whole of that, from a stranger finding the page to the deposit going back — and it is built around one asymmetry: **the people asking are not members**. They have no account, no session and no way to log in, and every design decision below follows from that.

### 22.1 Two spaces that never overlap

The **public space** (`/locations`) is for people outside the unit: an index, one page per asset, an availability calendar, a price estimate and a request form. The **managed space** (`/mes-locations`) is for the people responsible: the bookings, the money, the documents, the stay. A visitor never reaches the second, and a manager's page never renders anything the first should not have shown.

**A manager is not a chief.** Asset management is granted per asset to named members, chief or not, and the Staff d'U sees every asset by virtue of their function. That grant is checked server-side on every surface — a hidden button, an absent menu entry and a breadcrumb are never protection.

**There is one authority, and the split between the two spaces is about what is being administered, not about who is trusted.** « Espace chefs d'U > Locations » answers "which assets exist and who runs each one": creating an asset, its general description, its managers, archiving it. Everything that is a property of one asset — its booking rules, its tariff, its deposit, its balance, its security deposit — is set in that asset's own managed space, by the people who run it; the Staff d'U reaches it there like any other manager. The one exception is the accounting **account** an asset's money is expected on, which stays with the Staff d'U: the account list carries the unit's IBANs, and a manager may be a parent or a former leader.

**Designating a manager is a search, not a checklist**: a search-as-you-type box over the unit's members, showing a name, a section and a function — never an email address. Only members of a configurable minimum age (16 by default) are offered: a manager reaches renters' identities, the money and the contracts. The age is the real date of birth, and a member whose birth date is not encoded in Desk stays selectable rather than disappearing without explanation. A member the last Desk import dropped keeps their grant, shown as suspended: it can be removed deliberately, but never by a save that did not display it, and re-saving never silently reactivates it.

### 22.2 What the public sees, and what it never sees

The calendar shows a day as free, occupied or unavailable, and **nothing else**. Not who is in the hall, not what they are paying, not why it is blocked. A manual block and a letting are deliberately indistinguishable: why the unit cannot let its own hall is nobody else's business.

A visitor cannot page into the past, cannot page arbitrarily far forward, and cannot select a range the asset's own rules refuse — a minimum or maximum number of nights, a notice period, a horizon, an arrival weekday, a buffer between stays. **Availability is computed, never stored**: a hold that lapsed a minute ago frees its dates immediately rather than on the next scheduled run.

Assets with a quantity — eight tents — expose how many remain, and a request for more than that cannot be selected. The stock is re-checked at confirmation, so two managers confirming at once cannot both succeed.

### 22.3 Nights or full days

An asset is let either **by night** (a hall: the departure day is free again for the next arrival) or **by full day** (a trailer: the return day is still occupied). One flag, and it decides the calendar, the price and the availability check together — the three would otherwise disagree, and each of them alone looks right.

### 22.4 Price

Money is integers in cents, everywhere. A quote is built from the asset's own tariff — per person per night, per night, per stay, per unit — plus fees and taxes, with a billable minimum and per-renter-category rates. The billing unit is asked at the asset's creation (with a suggestion following the asset type) because it decides the calendar, the price and the availability together; it stays editable with the rest of the tariff. **An asset with no configured rate answers "tarif sur demande"** — never a table adding up to 0,00 € — and the managed and admin spaces both flag it until somebody fills the tariff in. Three amounts never mix: the **estimate** the visitor was shown, the **agreed** price the unit negotiated, and what has actually been **received**. The estimate is frozen at submission and never rewritten, precisely so a later negotiation cannot rewrite what somebody was told.

A line a manager edited by hand is never re-priced, in either direction. When the head count falls below the billable minimum the line says so out loud — « 25 pers. (minimum) » — rather than quietly quoting for more people than are coming.

**No VAT is ever computed.** Prices are what the renter pays, with a per-asset exemption note.

### 22.5 The request, the hold, and the renter's own page

A request holds the dates for a configurable period so two visitors cannot both be told yes. The hold lapsing releases the dates and **refuses nothing** — the request stays waiting, because nobody promised anything.

The renter's acknowledgement email carries a link to their own tracking page. **That link is the authorisation**: they have no account, the token is stored only as a hash, and a lost email is answered by issuing a new one. Their page shows the state of their request, what they owe and the practical information — never an internal comment, never a manager's note, never another booking.

### 22.6 Documents

A contract and an invoice are generated from a per-asset template, in three frozen levels: the asset's template, the booking's own copy of it (taken at first generation, so editing the template afterwards changes no existing booking), and the PDF. **Regenerating never overwrites**: v2 appears beside v1, because v1 may already be signed.

**A standard body ships as the working default.** While nobody has written a template, the editor opens pre-filled with ScoutMagic's standard Belgian contract and invoice (modelled on the Atouts Camps rental contract — a starting point to adapt, never legal advice) and generation uses that same text, so an asset whose managers never opened the editor still produces a complete contract. Saving any edit takes over; a « réinitialiser » action returns to the standard, and the page always says which of the two regimes is in force.

Keyword substitution happens after the rich text is sanitised, and every substituted value is escaped — a renter whose organisation name contains markup would otherwise have it interpreted by the PDF renderer.

**A renter downloads nothing from the site.** "For the renter" is a flag that means *email it to them*, not an access right: their documents reach them by email and only by email.

### 22.7 The stay

Meter readings are integers in thousandths, taken at arrival and departure; a meter that reads backwards is reported, never guessed at. The inventory checklist is snapshotted into the booking at confirmation, label and all, so editing the asset's list later rewrites no past inventory. "Nobody looked" is a distinct state from "somebody looked and it was fine".

**The financial decision on a damage stays human.** An incident is recorded and enters nothing until a manager picks charge, withhold or waive; until then the renter sees nothing of it.

The final settlement never modifies the agreed price — it produces new lines beside it, so the evidence for exactly the disputes it exists to settle survives. Only per-person lines re-scale to the head count that turned up; the hall still costs what renting the hall costs.

### 22.8 The calendar, and who sees what on it

Occupancy can be published onto the unit's own calendar. Nothing is copied: it is computed on every generation, so turning publication off removes it with no cleanup and no orphans. An ordinary reader — member, parent, visitor — sees `Local Saint-Georges — loué` and nothing more; only a manager of that asset or the Staff d'U sees the organisation, the head count and the contact. **That distinction is applied before anything is serialised**, never by a template hiding a field it was given.

A cancelled booking is published as cancelled rather than removed, so a subscriber's calendar drops it instead of keeping it forever.

### 22.9 Correspondence

With the Courrier entrant module, replies are attached to the right booking automatically: by the reference in the subject, then by the thread headers, then by the sender's address inside a window around the stay. **An ambiguous match attaches nothing** — a manager reading the wrong file has no way to know it is the wrong one. Every attachment says how it was made, and a sender match is labelled as the guess it is.

There is no way to attach a message by hand and no surface onto the mailbox at all. Correcting a wrong attachment means detaching it (which deletes it, along with attachments nobody re-classified) or moving it — only to a booking of an asset that manager actually manages.

### 22.10 The paperwork register

Per asset: a free-text label, an optional document, an optional expiry, a remark. **It is a reminder list and not a compliance check.** The module knows no regulation and computes no status: what a hall needs differs by commune, by federation and by year, and a green tick derived from a date would be a legal opinion. Suggested labels live in configuration so a rename costs a text field rather than a release. The page says all of this above everything else on it.

### 22.11 Reminders

Thirteen of them, from "a request nobody answered" to "a deposit still held after the stay". Twelve go to the managers of that asset through the notification centre; **one goes to the renter, by email**, because a renter has no account and a notification would reach nobody. None of them carries a name: a reference and an asset, and a link to the file where everything is.

Nothing fires *on* a date — every rule is "is this true today" — so a host whose scheduled task ran late sends today rather than never. The configuration page says plainly when no real cron is detected, because on shared hosting a reminder can then arrive hours late and a unit that does not know that reads the delay as a bug.

### 22.12 Retention

A booking is deleted in full — lines, documents, files, tokens, readings, inventory, incidents, settlement, attached emails — after a configurable delay **counted from the close of the accounting year the stay fell in**, so every letting of one financial year purges together. A refused or abandoned request is deleted on the same terms.

**One anonymous row survives**: asset, month, days, amount. No identifier, no token, no file, nothing rattachable to a person. It exists so the asset's three figures — requests waiting, days let this year, this year's revenue — stay true after the purge instead of dropping to zero one morning. The default of seven years follows Belgian bookkeeping usage and is offered as an aid, never as legal advice.

### 22.13 What the AI may and may not do

With the AI connector enabled, a manager can ask for help: read a meter index off a photo, propose a category for an attachment, summarise a thread, list what a request does not say, break a tie between two candidate bookings for an email. **Every one of those is a suggestion a human accepts or discards.** The AI never accepts or refuses a booking, changes an agreed price, blames anybody for damage, withholds a deposit, triggers a refund or moves a final financial status — and the code that talks to it has no way to write anything at all.

## 23. Inbound mail module — a read-only mailbox gateway (module inbound_mail)

Not a mail client. A gateway that connects one or more of the unit's own mailboxes **in read-only mode** so other modules can attach the replies that belong to them.

### 23.1 What it never does

It never marks a message read, never moves one, never deletes one, never creates a folder. The client contract has no vocabulary for any of it: bodies are fetched with `PEEK` and folders opened read-only, so a treasurer who works through an unread inbox does not find it emptied by a background task.

### 23.2 What it keeps, and what it throws away

**A message no module recognises is discarded** — not stored, not queued, not listed, not notified. Keeping it would build an archive of the unit's mailbox with no screen to consult it, which is the worst possible position under the RGPD. The read position still advances past it, so one unrecognised newsletter cannot block a mailbox forever.

A claimed message keeps its subject, sender, date and both bodies, all encrypted at rest. The HTML is cleaned once on arrival and **remote images are removed rather than proxied**: a hidden image in a stranger's email is a read receipt, and proxying still fetches it.

### 23.3 Attachments

PDF, images and office documents only — no archives, nothing executable — with the real type read from the file's own bytes rather than its name or what the email claimed. Signature logos are filtered out, and the same file arriving ten times is stored once.

### 23.4 Configuration

`Configuration > Courrier entrant`, **superadmin only**. Several mailboxes at once; a mailbox may feed several modules and a module may read several mailboxes. A manager may *use* a configured mailbox in their workflow but only ever learns its name and whether it is working — never the host, the port or the account. Passwords are encrypted, never redisplayed even partially, and never appear in an error message.

**Gmail connects over IMAP with an app password**, deliberately: a native connector would oblige every unit to pay for an annual security assessment, without which their sync would break every seven days.

### 23.5 What a consuming module gets

Messages for one of its own business objects, and nothing else. There is no "all messages", no mailbox listing and no search — so a manager who may open a booking does not thereby gain a window onto the unit's correspondence.

## 24. Mail merge — publipostage from an Excel file (module mass_mail)

An extra list type in the compose dialog: the recipients of one email come from a chief-uploaded Excel file instead of a mailing list, and **each row of the file is one email** — unlike every other list, where one email is sent per address.

### 24.1 The file

- `.xlsx` only, **first sheet only**, first row = column headers.
- Per row, delivery is resolved in this order: a non-empty **"Tiers"** value designates the member with that Desk identifier — the email goes to every address known for that member; otherwise a non-empty **"Email"** value is the destination (several addresses allowed, separated by `;`); a row with neither, an unknown Tiers, or an invalid address **refuses the whole file**, with an error report naming every offending line at once. Nothing is partially imported.
- Header recognition is alias-based (case/accent-insensitive): "Tiers" ≡ "Identifiant Desk"; "Email" ≡ "Email(s)" ≡ "Contact" ≡ "Adresse email" ≡ "Courriel". The site's own Excel exports (member exports, form responses) are therefore reusable as audiences without editing.
- Every column is a **merge variable**, insertable into the subject and the body from the editor ("Cher {{Prenom}}, tu devras payer {{Montant}} €"). Values are always substituted as text (HTML-escaped in the body). A duplicated Tiers or address across rows is allowed (one row = one email) but reported as a warning at import.
- The uploaded file itself is deleted immediately after parsing; the imported rows are stored encrypted.

### 24.2 Who, and the rest of the flow

- Available to **every chief** (deliberate: the file, not a section list, names the recipients). An imported audience can only be used by the account that imported it, or a chef d'unité; every import is journaled.
- Scout-year selection does not apply — the file defines the audience.
- The normal draft → test → send workflow is unchanged. Test mode adds a **per-recipient preview** paging through the file's rows with the real substituted values (plus warnings for unknown variables and empty values); the test email carries the previewed row's values. The tracking page works as usual; an external recipient (no member) is shown by their address.
- External recipients get the same one-click unsubscribe as members; an unsubscribed external address is remembered (as an irreversible hash) and excluded from every future mail merge.

### 24.3 Retention

Imported audience data is purged automatically **18 months** after the email was sent (fixed, non-editable setting), or 7 days after import if never attached to an email. The send tracking itself survives; the external-unsubscribe list is never purged.

### 24.4 Audience-reusable exports

Every Excel export of people the site produces — member exports ("Membres par section", the admin member search's results/selection export) and, for their contact column, module exports such as form responses — uses headers the mail-merge importer recognizes ("Identifiant Desk" ≡ "Tiers", "Email(s)"/"Contact" ≡ "Email"), so any of them can be re-imported as a mail-merge audience without editing. This is a standing rule for future exports too, not a per-screen coincidence.

## 25. Leadership module — three lists of people to contact (module leadership)

A reading tool for the unit team. It turns the Desk import into three lists and stores almost nothing, which is what stops it ever disagreeing with Desk. Four pages, all reserved to the chefs d'unité, plus one card each member sees on their own page. See ARCHITECTURE.md §8.65 for implementation detail.

### 25.1 What it never says

Two prohibitions shape every page, and both matter more than any feature listed below.

**Never a paperwork status.** The site holds nothing about a CQA or an extrait de casier judiciaire — not the document, not a date, not a state, not a reason, in any table, and not in the unit note either. So no page ever says one is in order, missing, valid or expired, there is no green tick and no "éligible ONE", and the verification itself belongs in Desk.

**Never "en ordre" for somebody who is not flagged.** Desk prefixes a function with « candidat » while obligations are not — or are no longer — in order, and takes the prefix off again by itself. Not being flagged at the last import says what Desk thought then, not what is true now, so somebody absent from the candidates list simply gets no line rather than a reassuring one.

Everything shown carries the date of the import it came from, because nothing here is fresher than that. Every threshold applied is shown with a version and the date a human last checked it against its source.

### 25.2 Formations

The unit's own free-text note (one for the whole unit, never one per member or per section — not encrypted, and the page says so), then:

- **À convaincre de commencer** — exactly two profiles: pionniers in their last branch year, and animateurs in their first year in the unit. Deliberately not a third: somebody starting the path in their fourth year of animation will not finish it while they are still animating, so listing them would misrepresent what starting achieves. With only one imported scout year the first-year half cannot be computed at all, and the page says so instead of showing an empty list.
- **Parcours à terminer** — everybody between T1 and T3, closest to their brevet first.
- **Situation des staffs** — per section: headcount, animateurs, brevetés, then a plain sentence saying what the ONE subsidy rules ask for and whether that is the case today. No colour, no score, no verdict: the module knows the ratio and nothing else — not a derogation, not an animateur borrowed from another section, not what the unit agreed with its ONE contact.
- A collapsible block listing the raw Desk formation levels the site cannot resolve, with the means to attach each to a step. This is the only place the mapping is edited: somebody who has just read "le calcul peut être incomplet" fixes it without leaving the page.

An unresolved level is never counted as a brevet, so the brevet count is a **floor**. The page therefore warns that the count may be incomplete only where that could change the answer — never when the threshold is already met, and never when the section is short of animateurs (no level changes a headcount).

### 25.3 Obligations

**Anniversaires des 20 ans** — animateurs reaching 20 within the alert window, with the days remaining. The only thing on this page that can be seen coming, and therefore the main block: from that age an extrait de casier judiciaire is legally required.

**Candidats au dernier import** — identity, age, function, section. Not a list of arrivals: the flag returns on its own when a CQA or an extrait expires, for an animateur of fifteen years exactly as for a newcomer.

One message per candidate, and nothing more precise, because nothing more precise is knowable: under 20, « CQA à signer » (the extrait is not yet required, so whatever is outstanding is the CQA — first signature or renewal alike, which is what Desk's own single label means); at 20 or over, **and when the birth date is unknown**, « CQA ou extrait — à vérifier dans Desk ». Age can only ever *exclude* the extrait, never name the missing document.

### 25.4 Intendants

From 1 September to 31 May: the registered stewards, longest-running first, with the days elapsed — attention past three weeks, critical past a month, against the free occasional-registration window. From 1 June to 31 August: no countdown at all, and a reminder that a registration then costs a guest fee even for a single day and that stewards should be deregistered after camp. No amount is ever shown.

When Desk holds no start date, the count falls back to the member's first appearance on the site, and **the line says that is what it is** — never a countdown presented as a Desk registration date. With no date at all there is no countdown, and the line says so. Stewards below the minimum age are reported separately, because it is a different question from the days and would be missed inside a list sorted by something else.

### 25.5 On a member's own page

The training path (T1 → T2 → T3 → Brevet) and the next known step, **visible only to the member themselves** — a chief or chef d'unité looking at somebody else's page does not see it, even though they may open the page. A level the site cannot resolve lights up no step and says so, rather than drawing a path nobody verified.

### 25.6 Out of scope

No export of any kind (planned separately), no per-member or per-section note, no "wants to become an animateur" field, no rules engine, no score, no percentage, no widget on the Staffs page, no public route, and no offline caching of any of these pages.
