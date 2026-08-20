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
**Dynamic entries** (one per member linked to email, named by totem/prénom, section subtitle; if the `registration` module is active, one more per registration request linked to the same email, for as long as that request stays visible there — see §17) + separator + **static entries** from active modules.

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
| Inscriptions (module) | Public form to request a spot for a child (open/closed by the admin, optionally on a schedule), with an optional availability display by birth year; a tracking link/page for the family (minimal view by token, full view once identified and linked); see §17 for the staff side |

### 4.2 Espace des animés

| Page | Content |
|---|---|
| {Member display name} × N | One page per linked member, two-column layout (same grid/stacking as Accueil, §4.1). Header: photo (replaceable by the member themselves outside configuration mode), display name, section, scout year. Right column: branch card (federation logo + link, per the member's age branch). Left column, in order: section name/email; section responsable (full legal name + postal address); badges assigned within the section; Staff d'U "Référent {section}" badge holders; next upcoming section event and links to Trombinoscope/Calendrier filtered on that section (both optional modules); the member's own functions this year; recent mass-mail communications with a "view as sent" detail page (module, optional); private documents — self only, never staff-visible (future home of fiscal attestations); photo galleries linked to the member's sections this scout year (module, optional); known contact emails (self-service: add/delete/resend verification/reactivate); all personal info from Desk with a mandatory note that it can't be edited here. Chiefs can adjust a member's scout year offset (+1/0/-1) when age-vs-section mismatch requires it. |
| {Member email detail} | One page per mass-mail email received, reachable only from the member's own page — subject, section, sent date, full body as actually sent |
| Trombinoscope (module) | Every section's chief/chief-d'unité staff, grouped by section, with the section's designated "responsable" highlighted. Accepts `?section={id}` to preselect a section (also used by the member page's own link above). |
| Galerie (module) | Photo/video albums (identified: view; chief: manage — see §4.3) |
| Groupes (module) | Private discussion groups the caller belongs to, most recently active first, plus an Archives tab for past-year ones. A group page is a feed: pinned posts, then posts newest-activity-first, each with up to four photos/videos, an optional link preview, one level of replies, and six fixed reactions. Members report; moderators restore or delete. See §20. |
| Notifications | Notification centre: list of received notifications (read/unread state, mark read individually or all at once), notification preferences (channel selection per type, quiet hours for push), push subscription management. Unread count shown in header badge. |

### 4.3 Espace des chefs

| Page | Role | Content |
|---|---|---|
| Staffs | intendant | SectionPicker + staff info per section (chief/chief-d'unité only — animés are not shown). Section's staff group photo, editable in configuration mode (one per scout year, falls back to the most recent earlier year). Badges assignable to staff (chief only, see Core\Badge). Section documents (chief only): add/reorder/delete/update PDF attachments per section and scout year (e.g. planning, camp info sheets), displayed both on the Staffs page and the member page. Section name/email are configured from Config Desk (§4.5), not here. |
| Finances (module) | intendant | Bank statement import, receivables, receipts, movements |
| Statistiques (module) | chief | Member statistics |
| Calendrier (module) | chief | Chiefs' calendar view (month grid, event edit) |
| Envoi de mails (module) | chief | Mass email to selected members/sections across one or more scout years; when the Inscriptions module is also active, an extra predefined, non-editable "Inscriptions {année cible}" list is available (see §18.3) |
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
| Membres | admin | Member search (name/email/phone) for the effective scout year, with detailed view showing all personal data from Desk (contact info, addresses, functions, age), plus effective age calculation with scout year offset. |
| Bannière (module) | admin | Manage homepage banner messages (role-gated visibility, ordered list) |
| SOS Staff d'U (module) | admin | On-call duty roster (month grid), default forwarding number, live redirect status, scheduled redirection list |
| Rétrospectives — Config (module) | admin | Per-board moderation/AI settings restricted to chef d'unité |
| Inscriptions (module) | admin | See §17 — request management: capacities/year code (age brackets are read-only, federation-fixed, shared with the Statistiques module), year selector, capacity-verification table, request list (filter/search), "non rapprochées"/"non clôturées" encarts with bulk refuse/withdraw, and a per-request fiche (status transitions, section prévue, tarif, internal notes, acceptance/refusal emails, manual Desk linking) |
| Passage (module registration) | admin | Split arriving families and promoted animés between sections ahead of next scout year — see §18.2. Chef d'unité only (not a per-section chief), since spreading arrivals across sections needs the whole unit at once |

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
- **An explicit invitation.** A chief invites a member (or a whole section) individually. The same row carries the moderator flag.

A section group is tied to its scout year, which is what keeps last year's group readable by exactly the people who were in it. An invitation group is tied to a year only if the chief wanted it. A site admin is an implicit moderator of every group; a chief who is not a member of a group does not see it.

### 20.2 What a group holds

Posts (up to 5000 characters, up to four photos or videos, and at most one link with an automatic title/description/image preview), one level of replies (up to 2000 characters, at most one image — replies are never nested), and six fixed reactions (👍 ❤️ 😂 😮 😢 👏), one per person per item. There is no separate field for a link: the first URL typed anywhere in the message is detected automatically, previewed live while composing, and removed from the stored text once its preview card is attached — the card represents it from then on. An author may edit their own post or reply for 15 minutes; an author or a moderator may delete one at any time. A moderator may pin posts to the top of the feed.

Photos and videos live in a gallery album belonging to the group, never listed in the unit's own gallery and readable only by the group's members. "Galerie du groupe" shows them all on one page.

### 20.3 Reporting and moderation

Any member may report a post or a reply, once. Past a configurable threshold (2 by default), the item is hidden from everyone except the group's moderators, who then restore it or delete it. **A moderator may never restore an item they wrote themselves** — another moderator, or a site administrator, has to; deleting their own content stays allowed. When the reported item was written by one of the group's own moderators, the report additionally reaches every site administrator, so the group's own moderation is never the only judge of a complaint about itself. The item's author is never told their content was reported. **Hiding is the maximum automatic consequence — nothing is ever deleted without a human deciding.** A restored item is never auto-hidden again, however many further reports arrive. The reporter is told nothing about the outcome, and who reported an item is never revealed to anyone.

When the AI connector module is active, a post or reply is checked before publication for personal attacks or disrespectful language, and its author is offered a rewording. The check fails open: no provider, a timeout or an error all mean the message is published unchecked. A refused message is never stored anywhere — it is handed back to its own author and nowhere else.

### 20.4 Notifications

Four types, all optional per member except one: a new post in one of my groups, a reply to my post, a reaction to my post or reply (debounced, so a burst of reactions is one notification), and — for moderators only — a report needing attention, whose in-app channel cannot be switched off. No email is ever sent for any of them.

### 20.4bis Managing a group

| Action | Who | Rule |
|---|---|---|
| Modifier le groupe | Moderator | Rename it. An **invitation** group may also be linked or unlinked to the current scout year (same effect as the checkbox at creation); a **section** group's year always follows its section and is never editable here. |
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
| Purge posts | A post is deleted `groups_post_retention_months` (24 by default) after its last activity, with its replies, reactions, reports, media and cached link image — the files themselves, not only the rows. A pinned post is never purged, at any age. |
| Purge closed groups | A closed group is deleted `groups_closed_purge_months` (12 by default) after its closure, gallery album included. A group of the current or a future scout year is never purged. |
