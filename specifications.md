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
| Calendrier (module) | Public activity calendar (month/week view); read-only ICS subscription feeds. Accepts `?section={id}` to preselect that section's calendar (used by the member page's own link, §4.2). |
| Actualités (module) | Public news article list/detail, each with an optional registration form (fields, capacity, payment) |

### 4.2 Espace des animés

| Page | Content |
|---|---|
| {Member display name} × N | One page per linked member, two-column layout (same grid/stacking as Accueil, §4.1). Header: photo (replaceable by the member themselves outside configuration mode), display name, section, scout year. Right column: branch card (federation logo + link, per the member's age branch). Left column, in order: section name/email; section responsable (full legal name + postal address); badges assigned within the section; Staff d'U "Référent {section}" badge holders; next upcoming section event and links to Trombinoscope/Calendrier filtered on that section (both optional modules); the member's own functions this year; recent mass-mail communications with a "view as sent" detail page (module, optional); private documents — self only, never staff-visible (future home of fiscal attestations); photo galleries linked to the member's sections this scout year (module, optional); known contact emails (self-service: add/delete/resend verification/reactivate); all personal info from Desk with a mandatory note that it can't be edited here. Chiefs can adjust a member's scout year offset (+1/0/-1) when age-vs-section mismatch requires it. |
| {Member email detail} | One page per mass-mail email received, reachable only from the member's own page — subject, section, sent date, full body as actually sent |
| Trombinoscope (module) | Every section's chief/chief-d'unité staff, grouped by section, with the section's designated "responsable" highlighted. Accepts `?section={id}` to preselect a section (also used by the member page's own link above). |
| Galerie (module) | Photo/video albums (identified: view; chief: manage — see §4.3) |
| Notifications | Notification centre: list of received notifications (read/unread state, mark read individually or all at once), notification preferences (channel selection per type, quiet hours for push), push subscription management. Unread count shown in header badge. |

### 4.3 Espace des chefs

| Page | Role | Content |
|---|---|---|
| Staffs | intendant | SectionPicker + staff info per section (chief/chief-d'unité only — animés are not shown). Section's staff group photo, editable in configuration mode (one per scout year, falls back to the most recent earlier year). Badges assignable to staff (chief only, see Core\Badge). Section documents (chief only): add/reorder/delete/update PDF attachments per section and scout year (e.g. planning, camp info sheets), displayed both on the Staffs page and the member page. Section name/email are configured from Config Desk (§4.5), not here. |
| Finances (module) | intendant | Bank statement import, receivables, receipts, movements |
| Statistiques (module) | chief | Member statistics |
| Calendrier (module) | chief | Chiefs' calendar view (month grid, event edit) |
| Envoi de mails (module) | chief | Mass email to selected members/sections across one or more scout years |
| Rétrospectives (module) | intendant | Create/manage post-activity retrospective boards |
| Galerie (module) | chief | Manage photo/video albums |

### 4.4 Espace admin

| Page | Role | Content |
|---|---|---|
| Import Desk | admin | CSV upload/import for current scout year. Year selection. Function mapping status. |
| Journal | admin | Searchable event log. |
| Année scoute | admin | Scout year management: preview any year (session-only), activate a staff year for chiefs/intendants, transition the whole site to the next public year (4-step workflow: preview, import, activate for staff, activate for everyone). Displays effective year, public year, staff year, member/section counts. Public year transition is manual-only and available year-round; a non-blocking warning appears when the current public year is past its end date. |
| Membres | admin | Member search (name/email/phone) for the effective scout year, with detailed view showing all personal data from Desk (contact info, addresses, functions, age), plus effective age calculation with scout year offset. |
| Bannière (module) | admin | Manage homepage banner messages (role-gated visibility, ordered list) |
| SOS Staff d'U (module) | admin | On-call duty roster (month grid), default forwarding number, live redirect status, scheduled redirection list |
| Rétrospectives — Config (module) | admin | Per-board moderation/AI settings restricted to chef d'unité |

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
- **In-app**: notification centre (bell icon in header, unread count badge)
- **Push**: browser/mobile push notifications (Web Push API, opt-in via subscription)

### 13.2 Notification types
Modules can dispatch typed notifications (e.g., calendar event reminder, news article published, finance receipt processed). Each type has:
- Default channel preferences (in-app, push, or both)
- User-configurable per-type channel overrides
- Role-based visibility (minimum role required to receive)

### 13.3 Preferences
- Per-type channel selection (in-app only, push only, both, none)
- Quiet hours for push notifications (no push during specified time range)
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

## 15. Section documents

Chiefs can attach PDF documents to sections (per scout year), displayed on both the Staffs page and member pages.

### 15.1 Use cases
- Section planning documents
- Camp information sheets
- Activity schedules
- Parent information

### 15.2 Management (Staffs page, chief only)
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

Annual transition from one scout year to the next, managed through a guided 4-step workflow.

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

**Step 1: Preview next year**
- Admin selects next year in dropdown and activates preview (session-only)
- Admin sees the site as it will appear after transition
- Other users unaffected
- Can be cleared to return to current year

**Step 2: Import Desk data**
- Admin imports CSV for next year via Import Desk page
- New year's members become available
- Step marked complete when member count > 0 for target year

**Step 3: Activate staff year**
- Admin activates next year for chiefs and intendants
- Staff immediately see next year on login
- Identified members and public still see current year
- Allows staff to prepare (configure sections, plan activities) while site remains stable for members

**Step 4: Activate public year**
- Admin transitions entire site to next year (permanent)
- All users (staff, members, public) now see next year
- Staff year automatically deactivated
- Available at any time — not restricted to a particular period

### 16.4 Manual transition only

The transition to a new public year is exclusively manual — step 4 is available year-round, and nothing switches the public year automatically. There is no switch window and no date-based fallback transition.

This is deliberate: a future "inscriptions" module needs to be able to veto step 4 while registration requests are still open, and a date-driven automatic transition would bypass that veto — a calculated date can't be told "not yet". Manual and automatic are incompatible, so manual wins.

Without an automatic catch-up, a unit that never runs the transition would stay on a stale public year indefinitely with no visible sign. The Année scoute page shows a non-blocking warning when the current public year is past its end date, inviting the admin to run the transition workflow — it never blocks anything and never changes anything on its own.

A freshly installed site with no public year configured yet still starts on a plausible year computed from the current date — this initial determination is unrelated to (and unaffected by) the removal of the automatic switch above.
