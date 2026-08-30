# Functional specifications

## 1. Context

Belgian scout units in the "Les Scouts" federation manage their members through Desk (federation web platform). This website complements Desk by providing a public-facing and member-facing site for the unit, generated primarily from the Desk CSV export. The same codebase is reusable across units — all unit-specific content is configurable.

### 1.1 Modules, and where each one is specified

Everything beyond the core site is a module (`modules/<id>/`, ARCHITECTURE.md §7). Each one has a section of its own here; the pages a module adds are also listed, per menu, in §4. This table is the index, and it is meant to stay exhaustive — a module without a section in this document is a gap in the document, not a module without a specification.

| Module | Name in the interface | Specified in |
|---|---|---|
| `banner` | Bannière | §36 |
| `calendar` | Calendrier | §27 |
| `camps` | Camps | §26 |
| `fees` | Cotisations | §31 |
| `finance` | Finances | §28, §30 |
| `gallery` | Galerie photos et vidéos | §33 |
| `groups` | Groupes | §20 |
| `inbound_mail` | Courrier entrant | §23 |
| `leadership` | Encadrement | §25 |
| `llm_connector` | Intelligence artificielle | §39 |
| `mass_mail` | Envoi de mails | §24, §29 |
| `member_stats` | Statistiques des membres | §35 |
| `news` | Actualités | §32, §29 |
| `registration` | Inscriptions | §17, §18, §19 |
| `rental` | Locations | §22 |
| `retro` | Rétrospectives | §37 |
| `sos_staff` | SOS Staff d'U | §38 |
| `support_dashboard` | Tableau de bord support | §21.3 |
| `test_tools` | Outils de test | §40 |
| `trombinoscope` | Trombinoscope | §34 |

Two of them never exist on a unit's installation: `test_tools` (reference and development installations only) and `support_dashboard` (the statistics receiver only). Both declare that in their manifest — see §21.3 and §40.

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

### 3.2 Espace membres (identified)
**Dynamic entries** (one per member linked to email, named by totem/prénom, section subtitle; if the `registration` module is active, one more per registration request linked to the same email, for as long as that request stays visible there — see §17) + separator + **static entries** from active modules.

### 3.3 Espace animateurs (intendant / chief)
Filtered by role — intendants see only `role_min: intendant` pages, chiefs see all.

### 3.4 Espace chefs d'U (admin)
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
| Accueil | Editable text and photos (configuration mode). Three optional module blocks, each absent when its module is disabled: one randomly drawn banner (banner module, §36), a column of the most recent **public** articles (news module, §32) and, for an identified visitor with unread groups, a summary of what happened in their groups since they last looked (groups module, §20) |
| Contact | Editable text; Staff d'U section's group photo and name/totem roster; Les Scouts federation logo (links to lesscouts.be) with its own editable text |
| Sections | Generated from Desk import; each card shows the section's staff photo, its designated "responsable" name, email, and a small editable text block |
| RGPD | Content set via the RGPD configuration page (§4.5): default reference text, admin-edited custom text, or AI-generated text. Links to the cookie preferences page for the cookie list (no longer embedded inline). |
| Calendrier (module) | Public activity calendar (month/week view); read-only ICS subscription feeds. Accepts `?section={id}` to preselect that section's calendar (used by the member page's own link, §4.2). |
| Actualités (module) | Public news article list/detail, each with an optional registration form (fields, capacity, payment) |
| Inscriptions (module) | Public form to request a spot for a child (open/closed by the admin, optionally on a schedule), with an optional availability display by birth year; a tracking link/page for the family (minimal view by token, full view once identified and linked); see §17 for the staff side |

### 4.2 Espace membres

| Page | Content |
|---|---|
| {Member display name} × N | One page per linked member, two-column layout (same grid/stacking as Accueil, §4.1). Header: photo (replaceable by the member themselves outside configuration mode), display name, section, scout year. Right column: branch card (federation logo + link, per the member's age branch). Left column, in order: section name/email; section responsable (full legal name + postal address); badges assigned within the section; Staff d'U "Référent {section}" badge holders; next upcoming section event and links to Trombinoscope/Calendrier filtered on that section (both optional modules); the member's own functions this year; recent mass-mail communications with a "view as sent" detail page (module, optional); private documents — self only, never staff-visible (future home of fiscal attestations); photo galleries linked to the member's sections this scout year (module, optional); known contact emails (self-service: add/delete/resend verification/reactivate); all personal info from Desk with a mandatory note that it can't be edited here. Chiefs can adjust a member's scout year offset (+1/0/-1) when age-vs-section mismatch requires it. |
| {Member email detail} | One page per mass-mail email received, reachable only from the member's own page — subject, section, sent date, full body as actually sent |
| Trombinoscope (module) | Every section's chief/chief-d'unité staff, grouped by section, with the section's designated "responsable" highlighted. Accepts `?section={id}` to preselect a section (also used by the member page's own link above). |
| Galerie (module) | Photo/video albums (identified: view; chief: manage — see §4.3). Opening a media fills the screen; **one control** offers to save it at the best quality the site keeps of it. Each file is named after the photo's own name plus its media id — so two photos two phones both called `IMG_1234` are saved as two files rather than one overwriting the other — and the whole album's ZIP names its entries exactly the same way. |
| Groupes (module) | Private discussion groups the caller belongs to, most recently active first, plus an Archives tab for past-year ones. A group page is a feed: pinned posts, then posts newest-activity-first, each with up to four photos/videos, an optional link preview, one level of replies, and six fixed reactions. Members report; moderators restore or delete. See §20. |
| Notifications | Notification centre: list of received notifications (read/unread state, mark read individually or all at once), notification preferences (channel selection per type, quiet hours for push), push subscription management. Unread count shown in header badge. |

### 4.3 Espace animateurs

| Page | Role | Content |
|---|---|---|
| Staffs | intendant | SectionPicker + staff info per section (chief/chief-d'unité only — animés are not shown). Section's staff group photo, editable in configuration mode (one per scout year, falls back to the most recent earlier year). Badges assignable to staff (chief only, see Core\Badge). Section documents (an animateur of that section only, see §15.2): add/reorder/delete/update PDF attachments per section and scout year (e.g. planning, camp info sheets), displayed both on the Staffs page and the member page. Section name/email are configured from Config Desk (§4.5), not here. |
| Finances (module) | intendant | Bank statement import, receivables, receipts, movements, outils (§30). `intendant` opens the module; which **accounts** it opens is narrower — see §28. |
| Statistiques (module) | chief | Member statistics |
| Calendrier (module) | chief | Chiefs' calendar view (month grid, event edit) |
| Camps (module) | chief | Camp sites and the stays made there. Search over places (name, address, postal code, city); "À venir" and "Lieux" lists; a collapsed map of the places that have coordinates. A place sheet shows its stays, the rating of its most recent RATED stay (never an average), an optional AI summary, and — for a chef d'unité only — merge and archive. A stay carries its sections, price, participant count, contacts, links, documents, photos, a free-text note, a review and its own change history. When a dedicated mailbox is configured, a "Courrier non classé" screen lists the inbound mail nobody could attribute. |
| Envoi de mails (module) | chief | Mass email to selected members/sections across one or more scout years; a mail-merge mode sending one personalized email per row of an uploaded Excel file (see §24); when the Inscriptions module is also active, an extra predefined, non-editable "Inscriptions {année cible}" list is available (see §18.3) |
| Actualités (module) | chief | Article list with each one's visibility, editor (rich text, mandatory summary and image, form builder, A4 poster with QR code, optional AI keywords/summary), responses and their payment state, Excel export and « Écrire aux répondants » — see §32. An article belongs to its author: only they and the Staff d'U may edit it |
| Rétrospectives (module) | intendant | Create/manage post-activity retrospective boards (§37) |
| Galerie (module) | chief | Manage photo/video albums |
| Départs (module registration) | chief | Mark which of this year's animés won't be back next scout year, per section — see §18.1 |
| Prévisions (module registration) | chief | Read-only projected headcount for next scout year, per section and unit-wide — see §19.1 |

### 4.4 Espace chefs d'U

| Page | Role | Content |
|---|---|---|
| Import Desk | admin | CSV upload/import for current scout year. Year selection. Function mapping status. |
| Journal | admin | Searchable event log. |
| Année scoute | admin | The whole scout-year transition, as a workflow of three phases and fourteen steps (§16.3): preparing next year with the staffs, encoding it into Desk, then updating the site. The order is advice, not a gate; steps are either observed by the site or ticked off by hand, per target year. Steps belonging to a disabled module are absent. Displays effective year, public year, staff year, member/section counts. Public year activation is manual-only and available year-round; a non-blocking warning appears when the current public year is past its end date. When the Inscriptions module is active: the final step is refused server-side while any registration request is still pending/accepted (any target year); the staff-year step shows the same count as a non-blocking warning — see §19.2. |
| Membres | admin | Member search (name/email/phone), with a membership filter (**actifs** by default, inactifs, tous) and an on-demand widening to every past scout year. Results are grouped by person. Excel export of all results or of a checked selection, in the canonical member-export format — reusable as-is as a mail-merge audience (§24). A result opens **the member's own page**, `/admin/members/{id}` (below). |
| Bannière (module) | admin | Homepage banner messages: rich text, minimum viewer role, active/inactive, delete. One of them is drawn at random on every homepage load — the list's own order is for the administrator's convenience and decides nothing on screen (§36) |
| SOS Staff d'U (module) | admin | On-call duty roster (month grid), default forwarding number, live redirect status, scheduled redirection list |
| Rétrospectives — Config (module) | admin | The rules common to every board: minimum role to create one and to close one, the defaults a new board starts from (word length, vote budget, refresh interval) and the automatic AI moderation mode. Each board then keeps its own settings — see §37 |
| Inscriptions (module) | admin | See §17 — request management: capacities/year code (age brackets are read-only, federation-fixed, shared with the Statistiques module), year selector, capacity-verification table, request list (filter/search), "non rapprochées"/"non clôturées" encarts with bulk refuse/withdraw, and a per-request fiche (status transitions, section prévue, tarif, internal notes, acceptance/refusal emails, manual Desk linking) |
| Passage (module registration) | admin | Split arriving families and promoted animés between sections ahead of next scout year — see §18.2. Chef d'unité only (not a per-section chief), since spreading arrivals across sections needs the whole unit at once |
| Encadrement (module leadership) | admin | Three lists of people to contact, read out of the Desk import — training paths, age-related legal deadlines, steward registrations. See §25 |
| Locations (module rental) | admin | Which assets exist and who runs each one — creating an asset, its general description, its managers, archiving it, and the accounting account its money is expected on. Everything that is a property of one asset is set in that asset's own managed space instead. See §22 |
| Cotisations (module fees) | admin | Checking what the federation bills against the unit's own roster: the season's snapshot, tariff accuracy per household, and the report of an imported invoice. See §31 |

#### The page of one member (`/admin/members/{id}`)

`/admin/members/{id}`, `role_min: admin` — the consolidated view of what the site knows about one person, and the page a search result opens.

It used to render below the search results, as `?member={id}` appended to the query. A route with an identifier is what this codebase does everywhere a person carries this much detail (`/config/inscriptions/demandes/{id}`, `/members/{id}`), and it is what makes the address shareable: `?member=42` bolted onto a search dragged the `q=` along, so a link pasted to a colleague replayed an unrelated search. On a phone the alternative — a dialog holding all of this — would have been a page in less good: no back button, trapped scrolling, and a form that closes when you tap beside it.

**The Desk half is read-only** and keeps its padlock: name, first name, birth date, sex, totem, section, function, address, e-mail as a `mailto:` link, both phone numbers as `tel:` links, disability, supplementary insurance. The member's secondary e-mail addresses are shown here too, **in reading only** — they are strict self-service (ARCHITECTURE.md §8.27), so the page offers no control at all rather than one the server would refuse.

**The three site actions are three cards.** The old « Données du site » heading was dropped: once the page grew, everything past the Desk half is site data.

1. **Année dans la branche** — the −1 / 0 / +1 group and the branch-year pill, recoloured on change. The action itself stays `role_min: chief`, unchanged, and is not aligned onto the page's own `admin` floor.
2. **Départ** — the "leaving next year" box and its optional 1000-character comment, available for **any member found by search, staff included**.
3. **Voir le site à sa place** — the temporary member addition (ARCHITECTURE.md §8.42). This one changes **the reader's session**, not the member, and says so with a « Votre session » label in its header and the full explanation in its body, the last sentence included: *« Aucune modification n'est enregistrée : le retrait ou la déconnexion annule tout. »* Without it nobody dares click. No other visual treatment — the card is identical to its neighbours.

**The core blocks** the page adds: the member's photo, the badges of the scout year, the year's functions, and the **parcours dans l'unité** — every section the person has been in, year by year, read from the membership periods keyed on the persistent member identity so the history survives the years that produced it.

**Les notes internes.** Dated staff notes about the person, each carrying its author and its date — the only free text the site holds about a member. It exists because `registration_requests.internal_notes_encrypted` covers the *request* and stops the day it is accepted: nothing covered the person afterwards.

Deliberately **dated entries and not one field**. A registration request lives a few weeks; a member stays ten years and passes through several staffs. A single field overwrites — the 2026 Baladins chief would silently replace what the Louveteaux chief wrote in 2023, and nobody would know anything had gone.

**Any reader may edit or delete any entry**, not only its author: everyone who can read these is a chef d'unité, so restricting a delete buys nothing and costs something real — a note written on the wrong person has to be able to disappear, or somebody works around it by appending « ignorer la note ci-dessus ». The author and the date stay on screen; an edited entry says so.

Where it lives *is* the decision. On this page, at `role_min: admin`, so **only the Staff d'Unité and the superadmin reach it** and the router's guard is the whole guarantee — there is no per-section compartmenting to apply. The cost is accepted: a chef de section who wants to write something down about one of their own animés has nowhere to do it.

**It is never visible to the member or their parents** — not on their page, not in an export meant for them, not as a mail-merge field. The admin export of `/admin/members` never gains this column either, although all its readers are chefs d'unité: an exported file leaves the site's protections, travels by e-mail, lands in a shared folder and outlives whoever produced it.

**Les paiements.** Ce que la personne doit encore, et — repliés, jamais mêlés — ceux qui sont clos. Les deux viennent du module Cotisations/Finance ; sans lui, le bloc n'existe pas. Ce qui appelle une action est en haut, seul : y mêler l'historique noierait les une ou deux lignes qui demandent quelque chose.

Pas de QR ni d'IBAN ici, contrairement à la page du membre : un chef d'unité regarde **où en est** un paiement, il ne le fait pas à la place de la famille. Une ligne close porte son issue — payé, abandonné, ou **trop-perçu remboursé**, dit précisément parce que « remboursé » laisserait croire que tout est reparti. La liste close est plafonnée aux plus récentes, et **le dit** : une liste tronquée en silence se lit comme une liste complète. L'historique complet reste dans le module Finance, qui est fait pour ça.

**La demande d'inscription d'origine**, quand il y en a une : une ligne, un lien vers la demande, et où elle a abouti. Jamais une copie de son contenu — la demande garde sa page, et en recopier trois champs créerait un second endroit à tenir en phase avec Desk. Les deux pages sont `admin`, donc quiconque voit le lien peut l'ouvrir.

**N'avoir ni créance ni demande d'origine est le cas courant**, pas une anomalie : rien ne s'affiche, plutôt qu'un bloc vide annonçant « aucune donnée ».

**Le parcours**, en trois blocs alimentés par des modules. Le **parcours de formation** — le même que sur la page de l'animateur, ici visible du Staff d'Unité : « Espace admin > Formations » porte déjà exactement le même plancher, donc rien n'y apparaît qu'un lecteur ne puisse ouvrir à un menu de distance. Les **camps** où sa section est partie, du plus récent au plus ancien. Et les **groupes de discussion** auxquels la personne appartient, les ouverts d'abord, avec la mention « Animateur » quand elle en anime un.

**Les camps sont une déduction, et la page ne prétend pas le contraire.** Rien n'enregistre les participants d'un camp un par un : ce sont des **sections** qui partent. Une ligne apparaît donc quand la section de la personne est partie une année où elle en faisait partie. C'est ce que le site sait ; une liste de participants serait inventée.

**Les groupes, c'est l'appartenance et rien d'autre** : aucun message, aucune réponse, aucun décompte. Un chef d'unité a besoin de savoir par quels groupes la personne est joignable ; ce que les gens s'écrivent n'est pas un fait à résumer sur une page de staff.

**Two things are never on this page.** A member's **private documents**: `files.owner_member_id` carries an explicit guarantee (ARCHITECTURE.md §8.3) of no chief and no admin bypass, tax certificates will live there, and listing them here would revoke that guarantee in silence. And a **writable** secondary-address control, for the reason above.

**Searching, and who it proposes.** The repository never filtered on `is_active` and the result has always carried the flag — what was missing was a way to narrow. The filter is **actifs** by default (what is wanted nine times out of ten), with *inactifs* and *tous* one tap away, and it travels with the query so a submit keeps it. The row is unchanged otherwise: the export checkbox, the initials pill, the totem after the first name, the section and function, and the status badge whose exact words stay « inscrit » / « non inscrit », never « actif ». **The two exports coexist** — the whole search, and the checked selection — and are never merged; both follow the filter the screen is showing.

**Widening to the past years is an explicit act.** A search loads and decrypts a whole scout year in PHP — there is no blind index on names, so nothing can be filtered in SQL. At a few hundred members that is fine; across five years it is five times the AES work, on every keystroke. So it is a button carrying the query already typed, never a pre-ticked box and never fired on typing. **Results are grouped by person**, on `members.id` — the persistent identity, never on a name, since two children can share one — and a person found in several years is one line carrying the years they were found in. Someone present only in a past year is marked as a former member, and has no checkbox: the canonical member export is one scout year's columns, and they have no row in this one.

**A former member's page opens.** The old "belongs to the effective scout year, or 404" check is deliberately relaxed — it would refuse exactly the people the widened search exists to find. What replaces it: the `member_years` row must exist. **The page then always shows the member's last known year, and says which** — not the year the search matched. Somebody looking up a former member wants their most recent contact details, and without the year being stated a chef d'unité reads them as current and phones a number that stopped working years ago. A link carrying an older year's identifier normalises onto the latest one.

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
| Finances (module) | Accounts, categories, categorization rules, danger zone. An account's section is what ties it to a treasurer (§28), so leaving it empty makes the account the unit's own. |
| Galerie (module) | Storage location (local/S3), default location for new albums. Each local location also shows the space still free on the disk that holds it — with the volume's size and the share in use, and a warning when what is left is smaller than the largest upload the gallery currently accepts. The page states that this is the whole volume, shared with the rest of the site, and not a quota reserved for the gallery; an S3 location shows nothing, its capacity being the provider's. |
| Calendrier (module) | Default view, supplementary calendars, ICS feed links. Each calendar carries **two** independent settings, one per question: « Vu par » (qui voit le calendrier) and « Modifié par » (qui y écrit). « Modifié par : ses animateurs » keeps a section calendar in the hands of that section's staff; « Modifié par : les chefs d'unité » leaves it visible to everyone who may see it while only the chefs d'unité change its events — an arrangement the single visibility setting could not express. Narrowing the audience to the chefs d'unité raises the write setting with it. |
| Courrier entrant (module) | The unit's own mailboxes, read-only, that other modules attach replies from: several at once, a mailbox may feed several modules and a module read several mailboxes. Passwords encrypted, never redisplayed, never in an error message. See §23 |
| Camps (module) | Default country for a new place; how many past stays a place sheet shows. The dedicated camps mailboxes (empty by default, with the warning that any mailbox listed there must be excluded from the other modules that read mail); automatic creation of a stay from a message; unsorted-mail retention in months. Automatic geocoding of a place's address through OpenStreetMap; AI summaries of what a place's stays and reviews add up to. |
| Envoi de mails (module) | Sender/attachment settings |
| SOS Staff d'U (module) | Telephony provider credentials (OVH: application key/secret, consumer key, line selection), excluded sections |
| Intelligence artificielle (module) | The AI provider and its API key — one provider active at a time, key stored encrypted and never redisplayed. Saving tests the connection and discovers the provider's models; the three capability tiers are assigned automatically, with no model to pick by hand. Consumed optionally by other modules (RGPD text, retro moderation and summaries, finance receipt extraction and categorization, news keywords/summaries, group moderation, camps summaries) — see §39 |

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
Upload → valide les en-têtes → confronte le fichier au roster (barrière anti-export filtré) → groupe par `desk_id` → résout les correspondances → met à jour → **conserve le CSV chiffré** → journal.

Le CSV consommé n'est plus supprimé : il est conservé chiffré, rattaché à
la ligne d'import, pendant une durée exprimée en années scoutes (2 par
défaut). Le fichier déposé en clair, lui, est effacé à la fin de la
requête, succès ou échec. Voir `SECURITY.md` §13.

### 7.1.1 Historique, rapport et conservation

Chaque import laisse une ligne (`import_journal`) à laquelle se rattachent
le fichier CSV chiffré qu'il a consommé et l'instantané du roster qu'il a
figé. Deux écrans en découlent, tous deux en `role_min: admin` :

- **`/admin/import/historique`** — les imports de l'année scoute
  sélectionnée : date, auteur, compteurs, fichier téléchargeable via
  `/files/{id}`, et la durée de conservation en clair.
- **`/admin/import/{id}/rapport`** — ce que cet import a changé, figé au
  moment où il a eu lieu. Ordre de lecture : impact d'accès (rôles
  `admin` gagnés ou perdus, fonctions inconnues arrivées au rôle
  minimum), impact structurel (sections devenues inactives, arrivées,
  départs, changements de section et de fonction), qualité des données
  (membres sans adresse exploitable, sans e-mail, sans fonction ni
  section, lignes non retenues).

Le rapport ne recalcule rien : il lit le diff stocké. Les **points
d'attention** — l'état actuel de l'unité, recalculé à chaque affichage —
sont un écran distinct, précisément pour que l'un puisse être daté et
l'autre vivant.

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

Reusable component. Sections (not branches), branch subtitle. Rendered as a
**select bar** (`partials/select_bar.html.twig`): one full-width row showing
the current section, opening a panel with the full list. Never a horizontal
scroll row and never wrapped chips — a section list is open-ended and its
labels are long. Default: highest-role member's section.

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

Three, and a type may offer any combination of them:

- **In-app**: notification centre (bell icon in header, unread count badge). The bell's own panel previews the **five most recent unread** notifications under the count, and says how many more are pending; the full list is one click away.
- **Push**: browser/mobile push notifications (Web Push API, opt-in via subscription)
- **Email**: the same notification sent to the account's own sign-in address. The channel for somebody who never installed the site and never allowed push. Every type declares it `off` or `default_off`, so it is never a surprise: a recipient opts in, per type. Sent in the background like push, and never twice — each notification carries the moment its email copy left (`notifications.email_sent_at`), claimed before the message is handed to the transport.

A type declares each channel as on, off, `default_on` or `default_off`: the first two are **locked** (the recipient's preference page shows the switch greyed out and cannot change it — a security alert about your own account is never switchable off), the last two are defaults the recipient may override.

### 13.2 Notification types
Modules can dispatch typed notifications (e.g. a calendar event reminder, a new gallery album, a message in one of my groups, a rental request left unanswered). Each type has:
- Default channel preferences, per channel (in-app, push, email), each either locked or a default the recipient may override
- User-configurable per-type channel overrides
- Role-based visibility (minimum role required to receive)
- Optionally, a **second, higher role from which its defaults are actually on**. The type is still offered to everybody from its minimum role up, with the same switches — they simply start off below that second role. It is for a notification one audience wants unprompted and another should be able to ask for: the site installing an update on its own is something whoever runs the site is told about without asking, and something an administrator may switch on.

### 13.3 Preferences
- Per-type channel selection — one switch per channel (in-app, push, email) on each type's row. Reached from the top of the notification centre, above the list. The list of types shown depends on the reader's role and on which modules are active.
- Quiet hours for push notifications: filling in a start and an end holds push notifications back between those two hours and delivers them when the range ends (a range crossing midnight works); in-app notifications are never held. Leaving both empty follows the site-wide range.
- Push subscription management (subscribe/unsubscribe) — granted per device, from « Mon compte » on that device
- **Discretion**: a per-account switch that strips the title and body from the push payload, so a locked screen shows a generic « Nouvelle notification ». The full text stays readable in the notification centre. One choice, applying to all of the account's devices.

### 13.4 Delivery
- In-app: stored in database, displayed in notification centre, badge count in header
- Push: sent via Web Push API (VAPID), respects quiet hours and discretion, and is simply skipped when the account has no subscription — the in-app and email copies are unaffected
- Email: rendered from a core template and sent through the ordinary mail service, to the account's own address — never a member's contact address. Handed to the scheduler (`core/send_notification_emails`), never sent inside the request that triggered the notification
- Quiet hours hold back **push only**. An email is not what makes a device buzz at 3 a.m. in the way a push is, and delaying one by up to nine hours would make a time-sensitive notification useless without making anybody's night quieter
- Discretion applies to push **and** email: with it on, the email carries the generic title, no body, and a link. It is a statement about screens other people can read, and a mail notification lands on the same lock screen a push does
- A send that the transport refuses is journaled and never retried: a retry cannot tell "never left" from "left, then the connection dropped", and the notification is in the recipient's centre either way
- All notifications logged in journal
- An **automatic update** — a new version or a development build the site installed on its own, with nobody there to ask for it — announces itself like any other type: it succeeded, or it failed and what was restored. On by default for superadmins, offered and off by default for administrators. A manual installation still answers the person who started it, and only them: one installation is never announced twice.

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

### 16.3 Transition workflow (Espace chefs d'U > Année scoute)

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

At every Desk import, accepted requests for that scout year are compared against the freshly imported members by name + birth date. Exactly one match on each side migrates the request automatically: it becomes "encodée", any confirmed secondary tracking email moves to the real member's record, and the request's own page in Espace membres is replaced by the real member page (the fiche itself stays staff-visible, see §17.5). No match leaves the request as "acceptée", surfaced to staff as unmatched. More than one possible match on either side (e.g. twins, or two families sharing a name) is never guessed — staff resolves it manually by entering the child's Desk "numéro de tiers", which goes through the exact same migration as an automatic match, refusing an unknown number or one already linked to another request.

### 17.5 Retention

Two durations, both admin-configurable and both counted from the moment a request reaches a final state (never from its submission date):

| Setting | Default | Effect |
|---|---|---|
| Disparition de l'Espace membres | 3 months | A refused/retirée request stops appearing in the family's personal space (an encodée request disappears immediately, replaced by the real member page) |
| Suppression définitive | 2 years | The request row is permanently deleted, regardless of state |

A request still "en attente" or "acceptée" is never purged, however old.

### 17.6 Management page and fiche

**Management page** (Espace chefs d'U > Inscriptions, §4.4): year selector (target year by default, plus the current and any past year still in the database — past years are consultation-only), the capacity/year-code configuration (§4.1 form setup; age brackets themselves are shown read-only — they're federation-fixed and shared with the Statistiques module, not something this screen configures), a capacity-verification table (capacity, projected headcount, accepted requests, remaining, and the same availability level shown to the public), the request list (searchable/filterable by state), and two encarts: unmatched accepted requests, and non-final requests with bulk "tout refuser"/"tout retirer" actions (each behind an explicit confirmation showing the exact count affected).

**Export** (same page, `role_min: admin` — an export takes the role of the page it lives on, never a rung below it): the request list as a spreadsheet, **reflecting exactly what the screen shows** — the year selector, the state filter and the search all apply, and the button carries the number of rows that will leave, because exporting 200 requests while looking at the 12 pending ones is a surprise and the reverse is worse. It carries what the family submitted and what the unit decided: dates, states, identity, address, contacts, the requested and the offered section, the sibling count, the family's own remarks, and whether the decision email went out.

**The staff's internal notes are never in it.** Those are remarks about a family, and an exported file leaves every protection the site has: it travels by email, lands in a shared folder, and outlives whoever produced it. The journal entry records counters and the scout year only — never the search text, which is typically somebody's name. Every cell is written as text, so a remark beginning with `=` (a public form takes anything) opens as a remark and not as a live formula.

**Fiche** (one per request): fields in the same order Desk itself asks for them. Everything the family submitted is read-only except two staff-only fields — "section prévue" (the section actually offered, distinct from and never shown alongside the family's own "section souhaitée" to the family, restricted to the child's own age branch) and "tarif" (a household-size-based suggestion, always overridable, using the same estimation as an existing member's fee category — counting other accepted/encoded requests at the same address alongside existing members). A free-form internal notes field (never shown to the family) completes the fiche, alongside the status banner and its available transitions.

## 18. Registration module — Départs, Passage, mailing list (module registration)

Two Espace animateurs pages that prepare the next scout year, plus an optional predefined list for the mass-mail module. See ARCHITECTURE.md §8.37 for implementation detail.

### 18.1 Départs

Espace animateurs, role `chief`. Scoped by section: an animateur/chief sees and can only act on the section(s) they staff; `admin`/`superadmin` see and can act on every section. For the selected section, lists this year's animés (never the section's own staff) with, per row, their year within their branch, a "won't be back next scout year" checkbox, and an optional comment that only appears once the checkbox is ticked.

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

Espace animateurs, role `chief`, **not** scoped by section, same "public year plus one" targeting as Passage. Read-only — nothing on this page writes anything.

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

### 19.2 Veto on the year transition (Espace chefs d'U > Année scoute, §16.3)

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

**Writing somebody's name calls them.** An "@" followed by a person's name in a post or a reply notifies them, and the site resolves it **from the stored text, server-side, every time** — the composer's autocomplete is a typing aid and nothing more. What an "@" may name is the group's own current membership and never anything wider, so a message can neither notify nor confirm the existence of somebody the writer could not otherwise see; it names the **person**, by the name their account carries ("@Marie Dupont", not a totem), so mentioning a parent of three does not mean knowing which of the three to type, and one message may name at most ten people — past that the writer meant "everybody" and should have said so.

Two dialogs answer "who": **the reaction tally** opens on who reacted and with which emoji, to any member of the group; **« vu par N »** opens on who has opened the group since the post was published, and only its own author may ask. "Seen" is exactly that — the group was opened after the message went up — never a per-message read receipt, which would cost a row per member per message to say something less true than it looks.

**A group tied to the current scout year carries it in its name** — "Louveteaux (2025-2026)" — wherever it is written: the list, the group's own page, its members and gallery pages, the breadcrumb. A past-year group does not, being already marked as an archive, and a group tied to no year has nothing to add.

Photos and videos live in a gallery album belonging to the group, never listed in the unit's own gallery and readable only by the group's members. "Galerie du groupe" shows them all on one page.

**On a wide screen the conversation stops filling it.** From a tablet in landscape upwards, the feed keeps a reading width and the space beside it becomes a side column: the group's search box, its members with their photos and a link to the full list, its latest photos — shortcuts to the "Membres" and "Galerie" pages the header already offers, not pages of their own. A message's photos are shown smaller there for the same reason: one photo used to be nearly as tall as the screen it was read on. On a phone nothing changes — a photo takes the width, which is the right size there.

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

Six types, all optional per member except one: a new post in one of my groups, a reply to my post, a reaction to my post or reply (debounced, so a burst of reactions is one notification), **being named by an "@" in a message or a reply** — the one to keep when the notification for every new post has been switched off — an invitation to a group (sent only when the person inviting asks for it), and — for moderators only — a report needing attention, whose in-app channel cannot be switched off. No email is ever sent for any of them.

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

### 21.3 Support dashboard — what the receiver does with the reports (module support_dashboard)

This is the other end of §21.1, and the whole of the `support_dashboard` module. **It exists on exactly one installation**: the module declares `"visible_when": ["statistics_receiver"]` and is filtered out of module discovery on every installation that is not the configured statistics destination — routes, menu entry, registry row and scheduled tasks all follow from that single filter. Whether an installation is the receiver is decided from its own configured `base_url`, never from the `Host` header of a request, and an unparseable `base_url` matches nothing: "unknown" must never read as "yes".

**The intake.** `POST /api/statistics` is public because its caller is a machine with no session — one of only two deliberate CSRF exceptions in the codebase. It refuses anything that is not HTTPS, caps the raw body before parsing it, rate-limits per source address (storing the address's blind index, never the address), and only then reads the bearer secret and validates the document's structure. Authentication is **trust on first use**: an unknown installation id registers itself, its presented secret is stored hashed, and every later report is verified against that hash. The secret is never stored in clear anywhere — not in a column, not in the journal, not in a response. A stranger can therefore register a fake installation, which is noise a superadmin deletes; nobody can ever take over a real one.

**A newer sender is never a rejection.** An unrecognised field is kept verbatim in the stored raw JSON, warned about in the journal (bounded — a stranger's field names are not mirrored wholesale into the event log) and ignored for processing. A missing field is stored as `NULL`, never `0` and never `false`, and every denormalised column is rewritten on each report including with `NULL`, so a sender that stops being able to measure something stops reporting it rather than freezing its last known value. A value a column cannot hold — a negative counter, an impossible date, an `instance_url` that is not `http`/`https` — is resolved to that same `NULL` rather than turned into an error on a route whose only contract is a status code. The response is a bare 204: an unknown installation and a wrong secret are indistinguishable from outside. A rejection is journaled with the source address and a fixed reason category, never free text.

**One state per installation, and no daily history.** Each accepted report overwrites the previous one.

**The dashboard** (`superadmin`, under Configuration) shows one row per retained installation. **No view state is remembered between visits** — filter, search, sort, page and history period live in the query string and nowhere else, so the page never opens on somebody else's stale filter and it sets no cookie; with no query string it always opens the same way, on the active installations, most recently received first. **An absent value is shown as "Non renseigné", never as `0`, "Non" or "Désactivé"** — it is left out of a total rather than added as zero, and it sorts last in both directions, since burying an unknown at the bottom of a descending sort would be the same lie as showing it as a zero. Reports come from installations of differing versions, so "this sender could not measure it" is a permanent, first-class state.

Filtering, sorting, paging and every aggregate are computed over the whole retained set at once, so the table and its own counters can never disagree. **Exactly five indicator cards and exactly two charts** (version/build distribution, auto-update mode distribution), recomputed on the filtered set: the restraint is part of the specification, and every other breakdown the payload would allow — by module, by installation method, by PHP version, by database engine, by OS — is deliberately out of scope. A dev build is its own slice, `1.0.33 (dev)` beside `1.0.33`, because answering a support question as though a branch build were the release answers it wrongly. The instance URL is always printed and linked only when it really is an `http`/`https` link. Clicking a row opens a dialog rendered server-side carrying every metric plus the exact raw JSON of the last accepted report, including fields this receiver does not understand — nothing on the page assembles markup out of a stranger's values in the browser.

**The XLSX export is of the filtered set, not of the page**, reproducible from the same query string, with one column per defined metric rather than per visible column. It deliberately carries no raw JSON payload, no contact address (a report contains none, and a column named for one would imply otherwise) and no "days since last report" (stale the moment the file is saved, when the timestamp and the status are both already there).

**Active, stale, deleted — three states, two settings.** An installation is active while its last accepted report is newer than `support_active_threshold_days` (14 by default); past that it is stale, which is a display state only — the row stays, behind the status filter, because "we have not heard from them in three weeks" is a support fact worth seeing. `support_retention_months` (6 by default) decides when the record goes **in its entirety** — id, URL, last payload, reception metadata and credential hash — by the daily purge task or by a superadmin acting sooner behind a confirmation. Both settings refuse a zero, negative or blank value and fall back to their default: a threshold of zero would mark the whole fleet stale, and a retention of zero would empty the table on the next purge. One consequence is accepted rather than engineered around: an installation silent past the retention window that starts reporting again is a **new registration**, because its id is no longer known.

**The monthly history answers one question and nothing else**: how many distinct installations reported at least once in a given calendar month. Several reports from one installation in one month are one contribution. A month is finalised the day after it ends — every month that has ended, so a receiver left idle for a quarter catches up on its next run with no special path — and the working rows naming who contributed are **deleted at finalisation**, which is what makes "a finalised aggregate holds no individual identifier" true of the data on disk and not merely of the queries that read it. A finalised aggregate is **immutable and kept indefinitely**: neither retention, nor a manual deletion, nor an installation disappearing rewrites one, and there is deliberately no recompute anywhere. Its chart takes only its own period (6, 12, 24 months or everything, 12 by default) and is independent of every filter above it — a history that moved when somebody typed in the search box would be answering a different question than the one its axis claims.

## 22. Rentals module — letting the unit's own assets (module rental)

A unit lets its hall, its ground, its tents, its trailer. The module covers the whole of that, from a stranger finding the page to the deposit going back — and it is built around one asymmetry: **the people asking are not members**. They have no account, no session and no way to log in, and every design decision below follows from that.

### 22.1 Two spaces that never overlap

The **public space** (`/locations`) is for people outside the unit: an index, one page per asset, an availability calendar, a price estimate and a request form. The **managed space** (`/mes-locations`) is for the people responsible: the bookings, the money, the documents, the stay. A visitor never reaches the second, and a manager's page never renders anything the first should not have shown.

**A manager is not a chief.** Asset management is granted per asset to named members, chief or not, and the Staff d'U sees every asset by virtue of their function. That grant is checked server-side on every surface — a hidden button, an absent menu entry and a breadcrumb are never protection.

**There is one authority, and the split between the two spaces is about what is being administered, not about who is trusted.** « Espace chefs d'U > Locations » answers "which assets exist and who runs each one": creating an asset, its general description, its managers, archiving it. Everything that is a property of one asset — its booking rules, its tariff, its deposit, its balance, its security deposit — is set in that asset's own managed space, by the people who run it; the Staff d'U reaches it there like any other manager. The one exception is the accounting **account** an asset's money is expected on, which stays with the Staff d'U: the account list carries the unit's IBANs, and a manager may be a parent or a former leader.

**None of the managed space is available offline.** Every screen in it is a screen where somebody writes — a price, a reading, a decision — and a write made against a stale offline copy is worse than a page that plainly says it needs the network. The public space is not cached either: it is about availability, which is exactly the thing that must not be stale.

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

The renter's acknowledgement email carries a link to their own tracking page. **That link is the authorisation**: they have no account, and a lost email is answered by issuing a new one. The token is stored **encrypted, not hashed** — a hash can only ever answer « is this the token? », and every email a manager's decision sends has to answer a different question, « what is this booking's link? ». The cost is stated where it is paid (`modules/rental/schema.sql`): the column survives a database copy taken without the application key, and no longer one taken with it — which is where every other identity column of that table already stood. Their page shows the state of their request, what they owe and the practical information — never an internal comment, never a manager's note, never another booking.

**What the visitor agreed to is provable afterwards.** Each tick-box on the request form is recorded with the version *and* a hash of the exact text that was on screen, so re-wording the conditions later never changes what a past renter accepted.

**A booking moves through one lifecycle, and every step is on the booking.** Change requests and proposals are the same object seen from two ends — the renter asks for other dates, or the unit offers them — and either side's answer applies or closes it, never silently. Cancellation is available from every live state, confirmed included, and **computes no refund**: what is owed after a cancellation is a conversation, not an arithmetic rule the module could get right.

**The milestone checklist is derived, never stored.** "Deposit paid", "contract sent", "inventory taken" are computed from the booking's own state every time they are shown, so they cannot drift from it and no scheduled task has to keep them in step. Every change a person makes to a booking is kept as the booking's own history, with the value before and after.

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

**A manual block over an already-booked period is accepted.** It neither fails nor overwrites the booking: a caretaker away during a letting is a real thing to record, and the two simply coexist. The public calendar shows the day as taken either way, which is all it ever says.

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

## 26. Camps module — the places the unit camps on (module camps)

"Où est-on déjà allés, et est-ce que c'était bien ?" is the question a staff asks every winter, and the answer used to live in the outgoing staff's head. This module keeps it: the terrains, the stays made on them, who to call, what it cost, and what the previous staffs thought. Reserved to animateurs; a few actions to the chefs d'unité only. See ARCHITECTURE.md §8.67 for implementation detail.

### 26.1 Two things, and neither is ever deleted

A **lieu** is a plot of land. A **séjour** is one stay on it. A stay that did not happen is *annulé* — never removed, because a place that cancels on its guests six weeks before departure is exactly what a future staff needs to know. A place that has fallen out of favour is *archivé*: gone from every ordinary screen and from search, still readable from the Archives.

Every field of a place is stored in clear: a plot of land is not a natural person, and its name and address are what the search runs on. Everything about the **people** attached to a stay is encrypted.

### 26.2 Dates, or a year

A stay carries real dates, or a bare year — never both, never neither. Half of what a unit remembers about its own past is "on est allés là en 2012", and refusing that would be refusing the memory. A year-only stay stays *à venir* for the whole of its year and becomes past on 1 January; nobody moves it by hand, and there is no status column for it.

### 26.3 The people

Contacts hang off the **stay**, not the place: they freeze the details used at the time of that booking, and a caretaker who has since left is a fact about that camp rather than an error to correct. They are the site's only **external third parties** — owners, caretakers, a neighbour with the key — with no relationship to the unit and no account here.

A contact can be **anonymised** at their own request (chef d'unité). Every row sharing that e-mail anywhere in the module is blanked, and so are the values those rows left in each affected stay's history. The confirmation screen states how many contacts and how many stays before anything happens. What survives is that a contact existed, when and by whom it was added — facts about the stay, not about the person.

### 26.4 The reviews

One review per stay, written and editable by every animateur: a unit speaks with one voice about a field it camped on. A **cancelled** stay may be reviewed — that is often the most useful thing to record — but never rated: nobody camped there. A place shows the rating of its **most recent rated stay**, with its year, and never an average.

The day after a stay ends, the animateurs of its sections are invited to write it — **once, with no reminder**. No section set sends it to the chefs d'unité only, never to everybody.

### 26.5 Finding a place again

Search covers a place's name, address, postal code and city. It deliberately does not reach the contacts or the booking person: those are encrypted, and the search box promises only what it delivers.

A **map** shows the places that have coordinates, collapsed by default and loaded only when opened — drawing it contacts the tile provider with the reader's IP. Coordinates are found automatically from the address, one place at a time in the background; **the moment somebody types or corrects them by hand, the automatic search never touches that place again**.

When a place is created, the ones that may already be it are offered. The comparison is textual first; an AI is asked only when that cannot decide, and it may only ever suggest.

### 26.6 Putting things back together

Two **lieux** that are one plot can be merged (chef d'unité): every stay follows, and the merged place is archived. Two **séjours** of the same place can be merged by any animateur — safe to open that wide because nothing is lost: every value the surviving stay already had is appended to its note, dated.

Archiving a place is **refused** while a confirmed stay is still to come there. Hiding a terrain from search while the unit is booked to leave for it in July is how a staff loses the address of the field they are going to.

### 26.7 The mail, when there is any

With a **shared** mailbox the module claims narrowly: a reply in a known thread, or a known contact writing near their own stay. Never on a word in a subject.

With a **dedicated** mailbox it takes everything, and what it cannot attribute goes to *Courrier non classé*, erased after a configurable delay. Such a mailbox must be excluded from the other modules that read mail.

What a message says is read conservatively — a date range, a single price. **An empty field is filled; a field that already has a value is never overwritten.** The reading is parked beside the value it argues with, with Appliquer and Ignorer, and both answers are recorded.

### 26.8 The summary

With an AI connector active, a place carries a few sentences summing up what its stays and reviews add up to, regenerated daily when something changed and never on a page view. It receives reviews, ratings, prices, statuses, dates, participant counts and sections — **never the contacts, never the received mail**. It is shown dated and explicitly as not a source of truth.

### 26.9 Out of scope

Deposits, payment tracking, contractual deadlines, a reservation workflow, cost per participant, bulk import of old camps, sharing places between units, several individual reviews per stay, sub-scores, rating averages, full-text search inside e-mails, documents or review comments, and deleting a place or a stay.

## 27. Calendrier — voir et modifier, deux réglages (module calendar)

### 27.1 The problem

A calendar had a single setting, « visibilité », that answered two different questions at once: who sees it, and who writes in it. A unit that wanted its "Animateurs" calendar readable by the animateurs but written only by the chefs d'unité had no way to say so — restricting it also removed it from the animateurs' screen. And any chief could create, move or delete an event in *any* section's calendar, including sections they have nothing to do with.

### 27.2 Who may write

Two conditions, both required:

- **The section.** A section's calendar is written by the animateurs of that section. A chef d'unité staffs every section and therefore writes everywhere. A supplementary calendar (the "Animateurs" calendar and any custom one) belongs to no section and is unaffected by this half.
- **The setting.** Each calendar carries its own « Modifié par » — *ses animateurs* (the default, which is how every calendar behaved before) or *les chefs d'unité*.

### 27.3 Who may read

Unchanged. « Vu par » alone decides it, and the month grid still shows the whole unit's activity to every animateur: an animateur of the Baladins goes on seeing what the Louveteaux are doing. Only writing is narrowed.

### 27.4 The two settings together

« Modifié par » may never reach further than « Vu par » — somebody who cannot see a calendar cannot write in it. Narrowing the audience to the chefs d'unité therefore raises « Modifié par » to the chefs d'unité with it, silently and by design; the opposite move, widening the write setting past the audience, is refused with a message.

### 27.5 In the interface

Both settings sit side by side on the superadmin calendar configuration page, on section calendars and supplementary calendars alike — the same labelled pair, in the same card, stacking one above the other on a phone rather than being squeezed onto one line — and each saves on change, with no « Enregistrer » button and a message confirming it. The page says that plainly, since a control that saves itself is indistinguishable from one that does not until you look for the button.

The warning that « Vu par » does not restrict the ICS links is stated once, above every calendar rather than above the supplementary ones only: a section calendar has no individual link, but its events travel in the « Unité complète » feed and in every member's personal feed just the same.

That page configures **one** notification, the reminder before a multi-day event. The evening-before activity reminder goes out at a fixed hour and is each member's own choice in their notification preferences — a second unit-wide setting for it existed on no page and only made the configuration look like it held two. On the chiefs' calendar page, an animateur is offered only the calendars they may actually write in — the "new event" dialog lists those and no others, and a day cell or an event belonging to a calendar they cannot write in does not open the form. An animateur the Desk import left attached to no section at all is told so on the page rather than left wondering why nothing opens; supplementary calendars remain available to them.

### 27.6 Out of scope

Per-event permissions, per-person exceptions, a write role finer than *animateurs* / *chefs d'unité*, and any change to the ICS feeds (which are read-only by nature).


## 28. Finances — le trésorier de section (module finance)

### 28.1 The problem

An account could already be tied to a section, and the module already knew each account's own visibility level. Neither fact was ever consulted together: every intendant saw — and could import into, upload receipts to and recategorise — every section's account, whatever their own section. The « Trésorier » badge existed and meant nothing at all.

### 28.2 Who may reach an account

Two conditions, both required:

- **The account's own visibility level** — intendant, chef, chef d'unité — unchanged.
- **The section.** An account attached to a section is for that section's **treasurer**: whoever carries the « Trésorier » badge and animates that section, for the current scout year. Both at once — the badge alone grants nothing, and animating the section alone grants nothing either.

An account attached to no section is the unit's own money and depends on the first condition alone. Chefs d'unité reach every account unconditionally.

### 28.3 The rule starts switched off

It applies only once the unit has assigned the « Trésorier » badge to somebody **for the current year**. Until then — which is the state of every installation the day it updates, and of every unit that has not yet redone its badges after the year transition — nothing changes and every intendant keeps seeing every account. Deactivating the badge in Configuration > Badges returns to exactly that behaviour, without erasing any past assignment.

This is deliberate and not a detail: without it, updating would lock a whole unit out of its own finances.

### 28.4 Everywhere, not just the account picker

The narrowing is a rule about access, not a filtered dropdown. It holds on the dashboard, the movements list and its search, the receipts, the statement import and the reconciliation page — and it holds for a request aimed straight at an endpoint with somebody else's account number in it, not only for what the screen offers.

### 28.5 The receipts follow too

A receipt is a file, and a file's own access level only knows about roles — it cannot say "the Louveteaux section". Left alone it would have made the whole rule cosmetic: the account disappears from the screen while its receipts stay downloadable by their direct link. Each receipt is therefore attached to its account, and asking for one asks the same question the screen asks.

This covers the receipts already stored before the change, not only the new ones, and it applies from the next page load on any installation — nobody has to open a particular screen for it to take effect. A receipt attached to no account at all — which only very old or imported entries can be — becomes unreachable rather than open to everyone: nothing can say who is allowed to see it.

### 28.6 Out of scope

A treasurer who is not an animator of the section, a per-account list of named people, an accountant role spanning the whole unit but excluded from one section, and reassigning an existing receipt from one account to another.


## 29. Écrire aux répondants d'un formulaire (modules news + mass_mail)

### 29.1 Le besoin

Écrire aux gens qui ont répondu au formulaire d'un article demandait quatre manipulations : exporter les réponses en Excel, ouvrir le publipostage, réimporter le fichier qu'on venait de télécharger, puis composer. Tout ce qu'il fallait existait déjà — sauf le chemin entre les deux.

### 29.2 Ce que fait le bouton

Depuis la page des réponses, « Écrire aux répondants » prépare un **brouillon** de publipostage adressé à tous ceux qui ont répondu, et ouvre l'écran de composition habituel. Rien n'est envoyé : le message reste à écrire, et il part comme n'importe quel autre publipostage.

Chaque champ du formulaire devient une variable de personnalisation, dans le même ordre et avec les mêmes intitulés que l'export Excel — les deux surfaces lisent la même définition, elles ne peuvent pas décrire le même formulaire différemment. Les colonnes de paiement de l'export ne sont pas reprises : ce sont des chiffres de comptabilité, pas quelque chose à insérer dans un mail au répondant.

### 29.3 L'adresse utilisée

Celle avec laquelle la personne a répondu, et elle seule — même quand on la reconnaît comme membre. Écrire à toutes ses adresses connues alors qu'elle en a choisi une précise serait une surprise désagréable. Conséquence à connaître : sa désinscription est enregistrée pour cette adresse, et non sur sa fiche de membre.

Deux réponses venues de la même adresse font un seul destinataire.

### 29.4 Qui peut s'en servir

La page des réponses s'ouvre aux intendants, le publipostage commence aux animateurs. Le bouton n'apparaît donc qu'à partir d'animateur, et la demande est refusée côté serveur pour un intendant même s'il la fabrique à la main. Les règles habituelles du publipostage s'appliquent ensuite : on envoie depuis sa propre section, sauf pour un chef d'unité.

### 29.5 Module de publipostage désactivé

Le bouton n'existe pas et la page des réponses fonctionne exactement comme avant. Rien ne casse, rien n'affiche d'erreur.

### 29.6 Hors périmètre

Choisir un sous-ensemble de répondants, un modèle de message prérempli, un envoi direct sans passer par l'écran de composition, et toute nouvelle règle de conservation — ces audiences sont purgées par le mécanisme existant du publipostage.


## 30. Finances — la page « Outils » (module finance)

### 30.1 Deux outils, une page

Ils ne partagent que leur emplacement. Tous deux répondent à une question qu'un trésorier se pose son téléphone à la main.

### 30.2 Code QR de paiement

Bénéficiaire, IBAN, montant, communication libre → un code QR que n'importe quelle application bancaire européenne scanne pour préremplir un virement. À coller dans un courrier, une affiche ou un e-mail.

**Il ne crée aucune créance.** Fabriquer un QR pour le montrer à quelqu'un n'est pas décider qu'un paiement est dû : enregistrer une attente au passage remplirait la page des paiements attendus d'argent que personne n'a promis, définitivement et sans rien pour y remonter.

L'IBAN est réellement vérifié (longueur et clé de contrôle), pas seulement sa forme — un seul chiffre changé est refusé. Le montant accepte la virgule.

### 30.3 Vérifier une communication

Une communication structurée relevée sur un extrait : est-elle correcte, et à quoi correspond-elle ?

Trois réponses possibles : **invalide** (ce n'est pas une communication structurée belge correcte), **valide mais inconnue ici**, ou **valide et reconnue** — avec alors ce qu'elle concerne, le compte et le montant attendu.

Ce que la page montre suit exactement les règles d'accès aux comptes (§28) : un paiement attendu sur un compte que vous ne pouvez pas ouvrir est traité comme inconnu, sans même vous dire qu'il existe.

Le journal du site retient qu'une vérification a eu lieu et son résultat, **jamais la communication saisie ni le libellé trouvé**.

### 30.4 Hors périmètre

Enregistrer un paiement, créer une créance depuis le générateur, un historique des QR produits, et la recherche par montant ou par nom — la communication est la seule clé.


## 31. Cotisations — vérifier ce que la fédération facture (module fees)

### 31.1 Ce que le module fait, et ce qu'il ne fait pas

Il vérifie. Il confronte ce que l'unité a encodé dans Desk et ce que la
fédération facture, et prépare les corrections à faire.

**Il n'écrit jamais dans Desk** et ne propose aucune action qui prétende le
faire : Desk est la source de vérité, le module la relit. **Il ne demande
pas d'argent aux familles** non plus — appel de fonds, part unité par
section, supplément précamp et page famille sont hors périmètre.

Toutes ses pages sont dans l'espace des chefs d'unité, réservées au chef
d'unité. Aucune route à un rôle inférieur, exports et points d'entrée
internes compris.

### 31.2 La photographie du roster

Une facture reflète l'état de Desk à sa date d'émission. Le site, lui,
réécrit la liste des membres à chaque import : il ne garde aucun état
intermédiaire. Comparer une facture de février à la situation de mars
fabriquerait de faux écarts, et ce sont ces faux écarts qui feraient
abandonner l'outil.

À chaque import Desk, le module fige donc la composition du roster.

**Cette photographie ne contient aucune donnée personnelle** : pour chaque
membre, son identifiant interne et des codes — catégorie tarifaire,
section, rôle de sa fonction, niveau de formation, et le fait qu'un départ
ait été annoncé. Les noms et les dates de naissance restent dans la fiche
annuelle du membre, qui persiste toute l'année même pour quelqu'un devenu
inactif.

Le drapeau « départ annoncé » est enregistré tel quel, jamais utilisé
comme filtre : Desk contient encore la personne, et la fédération la
facture encore.

Deux imports laissent deux photographies. Une photographie ancienne n'est
jamais réécrite : c'est elle qui rend une facture ancienne vérifiable.

### 31.3 La mémoire commence à l'activation

**Les factures antérieures à la première photographie ne seront jamais
vérifiables ligne par ligne.** La page d'accueil du module le dit, plutôt
que de laisser l'utilisateur le découvrir. Opérationnellement, le module
doit être activé avant la facture d'acompte de novembre pour que la saison
soit exploitable.

### 31.4 La page d'accueil

Date de la dernière photographie et nombre de personnes qu'elle contient,
nombre de photographies enregistrées pour l'année scoute en cours, date du
dernier import Desk — rien de ce qui est affiché n'est plus frais que cet
import — et la liste de ce que le module proposera.

### 31.5 Justesse des tarifs

L'écran confronte la catégorie tarifaire encodée dans Desk à celle
qu'implique le nombre de personnes au même foyer. On corrige ensuite dans
Desk ; le site n'y écrit jamais.

**Deux onglets d'action, et c'est la correction d'une erreur de
conception.** Un foyer dont un départ est annoncé n'est *pas* en écart
aujourd'hui : Desk contient encore l'ancienne composition, et c'est celle-là
que la fédération facture.

- **« À corriger dans Desk »** — l'encodage ne correspond pas au compte
  *Desk*. Action immédiate, chiffrée en euros parce qu'elle pèse sur la
  prochaine facture.
- **« À prévoir »** — le foyer est correct aujourd'hui et basculera quand le
  fait sera acté. Chaque carte nomme son déclencheur (« Camille — départ
  annoncé le 06/01/2026 »). Montant étiqueté « à la bascule », jamais « en
  écart », sous un bandeau qui dit de ne pas y toucher maintenant.

Un foyer peut légitimement figurer dans les deux : l'écran affiche
l'arbitrage — corriger maintenant puis à la bascule fait deux modifications,
attendre n'en fait qu'une au prix d'une facture intermédiaire inexacte — et
ne tranche pas à la place du trésorier.

Deux onglets de contexte complètent l'écran : **« Ignorés »** et **« Sans
adresse »**.

Un changement de compte qui ne change pas de catégorie (quatre personnes qui
passent à trois restent « famille ») n'apparaît nulle part : ce ne serait pas
une action.

### 31.6 Ce que chaque foyer affiche

Une ligne de décompte explicite — « 3 membres dans Desk — tarif attendu
Famille » — qui porte l'explication, puis la comparaison membre par membre :
l'encodé barré, l'attendu à côté.

Un membre sur un tarif qui n'est pas un tarif de foyer (animateur, réduit,
iAM) est **compté** dans le foyer — la fédération compte des personnes — mais
n'est jamais comparé ni signalé comme une erreur.

**« Copier pour Desk »** met le foyer dans le presse-papiers, volontairement
bête. **« Ignorer ce foyer »** le met de côté avec un motif libre : c'est la
réponse à la garde alternée et aux colocations, sans construire de fusion ni
de séparation de foyers. Le foyer réapparaît si sa composition change. Un
export tableur reprend les mêmes lignes.

### 31.7 Le barème, et ce qu'il ne fait pas

Trois montants (normal / couple / famille), repliés, saisis à la main, qui ne
servent qu'à traduire un écart en euros. Sans eux, un écart s'affiche sans
montant plutôt qu'avec un montant faux.

Le site devine seul lequel des tarifs Desk de l'unité signifie « couple » ou
« famille » ; le sélecteur n'existe que pour le corriger.

**L'écart va dans les deux sens** et le signe n'est jamais masqué :
sous-déclarer revient dans la facture de régularisation, sur-déclarer non.

### 31.8 Ce que l'écran ne prétend pas savoir

Un foyer sans adresse exploitable n'est ni conforme ni en écart : il a son
onglet, et le résumé ne le compte pas comme vérifié. La date de l'import Desk
sur lequel l'écran se fonde est affichée : rien de ce qui est montré n'est
plus frais que cet import.

### 31.9 Lire une facture de la fédération

Le document est un PDF avec une vraie couche texte. Une ligne de tarif y
porte une référence, une description, une section, un prix unitaire, une
quantité et un montant, suivie de la liste nominative des personnes qu'elle
concerne. Un pied de document donne le total à payer, un IBAN et une
communication structurée.

**Le site refuse plutôt que de deviner.** L'arithmétique est le contrôle :
prix unitaire × quantité = montant sur chaque ligne, nombre de noms listés =
quantité sur chaque ligne qui en a une, somme des montants = total à payer.
Un seul échec et la facture n'est pas lue — le message désigne la ligne
fautive et donne les deux chiffres.

**Une référence tarifaire inconnue ne bloque jamais.** Sa nature se déduit de
sa forme : une ligne sans liste nominative est un ajustement global (la
déduction d'un acompte), un prix unitaire négatif avec liste est une
réduction (l'animateur breveté), un prix positif avec liste est une
cotisation. Les Iama ne sont pas exonérés : leur cotisation locale est une
ligne comme les autres.

**Une répétition au saut de page est une seule ligne.** La même combinaison
(référence, section, prix, quantité, montant) vue deux fois est reconnue
comme telle et ses listes sont fusionnées.

**Ce que le site n'a pas compris est compté.** Un en-tête, un pied de page,
un sous-total : tout ce qui ne correspond à aucune des deux formes est
ignoré, et le nombre de lignes ignorées est rapporté. Un bond de ce compteur
d'une facture à l'autre signale que le gabarit a changé.

Les personnes se rapprochent sur **nom + prénom + date de naissance** : les
jumeaux existent et figurent sur la même facture. Les sections se rapprochent
sur le code Desk, jamais sur le nom affiché — un renommage depuis Config Desk
ne doit rien casser. « Staff d'unité » est un libellé, pas un code, et
correspond au Staff d'U du site.

### 31.10 La séquence de la saison

La fédération n'envoie pas une facture, elle en envoie plusieurs : un acompte
en novembre, les factures finales de janvier et de février, une
régularisation du dernier trimestre. L'écran **Factures** les présente dans
l'ordre où elles sont arrivées, chacune avec son total, son statut de
vérification, et le cumul de ce que l'unité a payé.

**Le cumul additionne le total de chaque document, jamais ses montants
bruts.** L'acompte est déduit à l'intérieur de la facture finale par une
ligne négative : additionner l'acompte au brut de la finale compterait cet
argent deux fois. Le total imprimé, lui, est déjà net.

### 31.11 Importer une facture : trois issues, deux échecs

Il n'y a pas d'écran de correspondance. C'est délibéré.

**Dépôt.** Avant même que le fichier soit choisi, l'écran rappelle la date du
dernier import Desk et celle de la dernière photographie du roster. Ce rappel
est ce qui évite les deux autres états.

**Total incohérent.** La lecture ne retombe pas sur le total du document.
L'écran nomme la ligne où elle décroche et donne les deux chiffres. Rien
n'est enregistré : une facture à moitié lue produirait une vérification à
moitié fausse, ce qui est pire que pas de vérification. C'est le document qui
est en cause, et la réponse appartient à la fédération.

**Roster périmé.** Le document se lit parfaitement et tombe juste, mais il
facture une section que le site ne connaît pas. Ce n'est pas une
correspondance oubliée : c'est que Desk a changé et que le site n'a pas été
réimporté. L'écran le dit dans ces termes et offre un seul bouton — importer
Desk. **Aucun écran ne propose d'associer un code de section à la main** :
cela permettrait de masquer un roster périmé, et toutes les vérifications
suivantes seraient silencieusement fausses.

**Importer deux fois le même document ne fait rien.** Le numéro du document
est son identité. Un trésorier qui ne sait plus s'il a déjà importé janvier
doit pouvoir simplement essayer.

### 31.12 Ce qui est conservé, et ce qui ne l'est pas

Le site enregistre l'en-tête de la facture, ses lignes tarifaires, et — pour
chaque personne qu'une ligne nomme — **uniquement l'identifiant du membre
reconnu**. Ni nom, ni prénom, ni date de naissance ne sont recopiés. Une
personne que le site n'a pas su reconnaître devient une ligne anonyme : le
compte reste juste, et le rapport peut dire « 3 personnes facturées que le
site n'a pas reconnues » sans que cette table ait jamais porté un nom.

Deux membres que le site ne peut pas distinguer — mêmes nom, prénom et date
de naissance — ne sont rapprochés ni l'un ni l'autre. Un mauvais
rapprochement est pire que pas de rapprochement.

**Le PDF conservé.** Si le module Finances est actif, une case permet de
rattacher le PDF à un compte de l'unité : il y devient un justificatif de
dépense ordinaire, chiffré au repos, visible des mêmes personnes que ce
compte. C'est le seul endroit où les noms figurant sur la facture sont
conservés, et c'est précisément à cela qu'il sert. Le rapprochement avec le
mouvement bancaire reste manuel : un rapprochement automatique erroné dans
une comptabilité est pire qu'un rapprochement manuel.

Si Finances est désactivé, la case n'apparaît pas, aucun PDF n'est conservé,
et la vérification fonctionne à l'identique. Finances n'est jamais une
dépendance dure de ce module.

### 31.13 Le rapport de vérification d'une facture

Chaque facture importée s'ouvre sur son rapport. Il compare le document à
la **photographie du roster** prise à l'import Desk le plus proche avant
son émission — pas à la situation d'aujourd'hui.

Deux onglets, parce que ce sont deux questions.

**Lignes reconstituées — combien.** Pour chaque référence et chaque
section : le prix unitaire, la quantité facturée, la quantité que la
photographie contenait, et l'écart chiffré au prix de la ligne. La
quantité attendue vient de la photographie, **jamais d'un calcul de
tarif** : c'est ce qui sépare cette page de « Justesse des tarifs », l'une
vérifiant le compte et l'autre les catégories. Une ligne que le site ne
sait pas juger est affichée sans verdict — référence inconnue, ligne sans
section, ajustement global — parce que le silence n'est pas une
accusation.

Les lignes conformes sont **repliées et comptées**, jamais supprimées. Un
premier écran de quarante lignes « conforme » cache les deux qui ne le sont
pas ; les faire disparaître empêcherait de rapprocher le rapport du
document papier.

**Écarts nominatifs — qui.** Cinq types, parce que chacun désigne une
action différente : *facturé mais parti* (c'est Desk qu'il faut corriger,
pas la fédération), *membre absent de la facture* (l'unité est
sous-facturée, la régularisation le rattrapera), *section différente*,
*catégorie différente*, *réduction breveté non appliquée*.

**Un écart de section ne coûte rien et n'est jamais chiffré.** Le tarif est
identique de part et d'autre ; lui donner un montant mettrait des euros sur
une différence qui n'en est pas. Le site distingue cela de « le site ne
peut pas chiffrer » : ce ne sont pas la même affirmation.

Deux restrictions existent pour que la page reste lisible plutôt que
seulement exacte. Une section que la facture ne couvre pas n'est pas
signalée comme absente en entier — une facture portant sur trois sections
sur cinq n'« oublie » pas les deux autres. Et un document ne comportant
aucune ligne de réduction breveté ne signale personne : la fédération peut
la facturer séparément, et une page de faux positifs est la manière dont un
rapport cesse d'être lu.

**L'écart de dates est affiché.** Si la photographie précède ou suit
l'émission de plusieurs jours, tout ce qui a été encodé entre les deux
apparaîtra comme un écart sans en être un — mieux vaut le dire que le
laisser découvrir.

**Les personnes non reconnues sont comptées.** Un nom que le site n'a pas
rapproché d'un membre compte dans les quantités mais n'apparaît dans aucun
écart nominatif : le site ne conserve aucun nom des factures. Une
vérification de quarante personnes qui en a discrètement contrôlé
trente-quatre est pire qu'aucune vérification, donc le nombre est annoncé
en haut de page.

L'export tableur reprend les deux onglets sur deux feuilles, **dans l'ordre
exact des colonnes de l'écran**.


## 32. Actualités — articles, formulaires et réponses (module news)

The unit's own publishing channel: articles on a public page, a column of the latest ones on the homepage, and — on any article that wants one — a registration form with capacities, a price and a payment reference. It is the module a fête d'unité, a camp registration or a call for volunteers goes through.

### 32.1 An article

Title, a **mandatory one-sentence summary**, a **mandatory image**, and a rich-text body. The summary is not decoration: it is the single line the list shows, the body of the printed poster, and the description a social network picks up when the link is shared — which is why the list never shows a stripped-down beginning of the article instead.

**Visibility is one of five**, and it decides where the article exists at all: `Public`, **`Membres connectés`**, `Animateurs`, `Chefs d'unité`, or **`Lien direct`** — the last one present in no list whatsoever, reachable only by its own address or by the QR code on its poster. An article belongs to its author: only they and the Staff d'U may edit it.

The first four are rungs of the role ladder; **`Lien direct` is not one** — it means *unlisted*, and holding the address is the whole permission. `Membres connectés` is the level the rest of the site is built on: the animés, their parents and the staff, once signed in. It exists so the unit can publish something for its families without publishing it to the world.

**A restricted article has to be reachable by the people it is for**, so the two lists that carry articles are aware of who is asking: the public `/news` list and the homepage column show `Public` articles to an anonymous visitor and `Public` + `Membres connectés` to a signed-in one. That is a narrowing of *content*, never the boundary: `/news/{id}` re-checks the article itself and answers 403, and the cover image's own `role_min` follows the article's visibility so the picture cannot say what the page refuses to.

**A `Membres connectés` article is never indexed, and never carries a link preview.** Both are forced on the server, not hidden in the editor. The reason is that the protection people expect from « réservé aux membres » is not the body alone: an article carries a title, a summary and a cover image, and a link pasted into a public WhatsApp group would render all three for anyone. A crawler never signs in, so an indexed page hands over exactly what the visibility was meant to keep in. `Lien direct` already worked this way for indexing, for a neighbouring reason.

**The poster is part of the feature.** Each article generates a ready-to-print A4 PDF carrying its image, its title, its summary and a QR code pointing at the article through a short URL. **Generating it is gated on the article's own visibility**, exactly like reading the article: the PDF carries the title, the summary and the link, so a chief who may not open an article reserved to the chefs d'unité may not pull those three out of its poster either.

**Search-engine indexing is opt-in per article**, with keywords and an optional stop date past which the page asks not to be indexed any more — evaluated **at each rendering**, so an expired announcement drops out on its own with no scheduled task to run. `Lien direct` and `Membres connectés` both force indexing off, **on the server** and again at rendering time, not by hiding a checkbox: an article deliberately kept out of every list has no business being in a search engine, and one reserved to the unit's own members has no business being previewed to everyone else. When the AI connector module is active (§39), a button proposes keywords and a summary; without it, the buttons simply do not appear.

### 32.2 The form

One form per article, at most. Its fields are built one by one — short and long text, number, date, telephone, email, dropdown, radio, checkbox, switch, a tick-box of conditions, and a plain text block for explanations — each optional or required. A **number** field may additionally carry:

- a **maximum capacity**, which makes the form display how many places are left and close that option once it is full;
- a **unit price**, which is what makes the form a paying one.

The form's own settings are: who may answer (anybody, or identified visitors only), how many answers one person may give (unlimited, one per account, one per member), the opening and closing dates plus a manual force-close, who may read the responses (intendant, chief or chef d'unité), the receiving bank account when the finance module is active, and a daily digest to the author.

**A public form cannot limit answers per person**, and the site enforces it rather than trusting the setting: an anonymous submission is tied to no account and no member, so there is nothing to count against. For the same reason, a field whose options are "the members linked to this account" is unavailable there.

A form open to anybody is protected by the site's own human check (`Core\Security\HumanCheck`); an identified visitor never sees it.

### 32.3 Answering, and paying

The contact email address is always asked and pre-filled for an identified visitor; a form may also ask which of the account's own members is concerned. Remaining places are shown live and a full option disappears while the rest of the form stays usable. On a paying form the total is computed as the fields are filled in.

**Payment is by bank transfer, always, and never by card on the site.** When the finance module is active and an account is selected, submitting generates a **structured communication**, opens the matching receivable in the finance module, and the confirmation page and email both carry the amount, the account, that communication and a payment QR code. Reconciliation then happens through the imported bank statements: a response shows `Payé`, `Partiel` or `Non payé`, and until the statement is imported a payment that really was made still reads as not received — which is an import to do, not a family to chase. A `Partiel` is most often one transfer covering several children.

**Correcting a response never recalculates what is owed.** The amount stays the one from the first answer, and adjusting it is a human act in the Finances module. A silent recalculation would rewrite a debt somebody has already been told about, and possibly already paid.

### 32.4 Correcting a response

A respondent may reopen their own response — answers and contact address — as long as **they were identified when they answered** and the form is **still open**. An answer sent without an account cannot be reopened by anybody claiming to be its author: there is no way to check. A **chef d'unité** may correct any response, including after the form has closed, to fix a manifest error — never to decide in somebody's place.

A capacity may have filled in the meantime: the save is then refused, naming the option that blocks it, and the previous response is kept intact.

### 32.5 The responses

One line per response, one column per question, opened at the role the form's own setting names. On a paying form two more columns appear — **Attendu** (the receivable opened at registration) and **Reçu** (what actually landed on the account) — and the state summarises the two.

**The Excel export is a mail-merge audience as it stands** (§24.4): it downloads the whole set, payment states included, with headers the mail-merge importer recognizes — no editing between the two screens. « Écrire aux répondants » does the same thing without the round trip through a file: it prepares a mail-merge draft addressed to everybody who answered, each form field available as a variable, and leaves the composer open — nothing is sent. It uses the address each person answered with, counts two answers from one address as one recipient, requires the `chief` role (the responses page itself opens at `intendant`), and is absent when the mass-mail module is disabled.

**The daily digest** mails the article's author how many new responses arrived since the last one, and sends nothing at all on a day with none.

### 32.6 Out of scope

No card payment. No automatic re-pricing of a corrected response. No self-service correction of an anonymous answer. No article-level comment thread — a discussion belongs in a group (§20).


## 33. Galerie — albums photo et vidéo (module gallery)

Photo and video albums for the unit and its sections, readable by identified members only. There is no public gallery: these are pictures of children.

### 33.1 Two kinds of album

A **local** album holds files this site stores and serves. An **external** album is a link to somebody else's album; the site scrapes its title, description and image once, keeps its own EXIF-stripped copy of that image, and the card simply opens the other site. The remote image is never hotlinked — that would leak the reader's address to a third party and force the site's content-security policy open.

An album belongs to a **scout year** and either to one **section** or to the whole unit, and that is what decides who sees it: a member sees the unit's albums plus those of the sections they belong to, **for the current and the previous scout year**. A chief manages the albums of the sections they staff and the unit-wide ones; a chef d'unité manages every album — and the check is server-side on every operation, not a hidden button.

### 33.2 Uploading and preparing

Photos (JPEG, PNG, WebP) and, when the site allows videos at all, videos (MP4, MOV, WebM). Maximum size per photo and per video, maximum number of media per album, maximum video duration and whether the original video file is kept are all settings; video upload is switched off automatically when FFmpeg is absent from the server. A large file is uploaded in chunks.

**Renditions are built in the background**, never in the request: a thumbnail, a medium and a large version for a photo, a poster frame and a 720p/1080p pair for a video. Until it is ready a medium shows as being prepared, and it is excluded from downloads and from the album's archive rather than handed out half-made. Every derived photo is a JPEG and every derived video an MP4, whatever was uploaded.

The first medium uploaded becomes the album's cover; any other can be chosen instead, and media are reordered by drag-and-drop.

### 33.3 Saving what you are looking at

Opening a medium fills the screen. **One control** saves it, at the best quality the site keeps of it — not the preview on screen. **Each file is named after the photo's own name plus its media id**, so two photos called `IMG_1234` by two different phones become two files rather than one overwriting the other, and the whole-album ZIP names its entries exactly the same way — a family that saved three photos from the lightbox and then the album recognises the ones they already have.

An album too large for a single archive (512 MB) refuses the ZIP and says to save the media one by one, rather than failing halfway through a download.

### 33.4 Where the files live

Storage locations are configured site-wide (Configuration > Galerie): a local disk or an S3-compatible bucket, and **several may coexist** — adding a new destination for future albums never breaks reading old ones. An album is pinned to the location it was created in and new albums go to the one marked default; animateurs never choose. An album can be **migrated** to another location as a background task: it is unavailable while the copy runs, and a failed migration leaves it working exactly as before, since the source is only released at the very last step.

Each local location displays the free space on the disk that holds it, with the warning that this is the whole volume shared with the rest of the site — backups and updates included — and not a reserve for the gallery. An S3 location displays nothing: its capacity is the provider's business. A location's health is checked on demand and cached, never re-tested on every page view; when an S3 test fails and the AI connector is active, the error can be turned into a plain-language explanation.

### 33.5 Albums this module hosts for somebody else

Another module may own an album that gallery merely stores — a discussion group's photos (§20). Such an album is **excluded from every one of gallery's own listings, pickers and public interfaces**; it is reachable only through its owning module, which is also what decides who may see it. The link-preview cache this module maintains is shared the same way: it caches a URL's metadata, never the image, so that one group's private preview can never be served to somebody with no membership in it.

### 33.6 Notification, and what is out of scope

**Creating an album** notifies every identified member (in-app and push on by default, email off), never its own creator — there is deliberately no per-section targeting, the same simplification the calendar module makes for its events. The notification carries the album's title and opens the gallery, so a member the album is not scoped to lands on a list without it rather than on a page they may not read. Adding media to an existing album notifies nobody.

No public album. No per-album storage choice for animateurs. No image editing beyond the automatic resizing and transcoding. No face recognition, no tagging of people, ever.


## 34. Trombinoscope (module trombinoscope)

Section by section, the faces of the people who run them: each animateur with their photo (or their initials), their name, their function and their badges. The card at the head of a section, framed and marked « Responsable », is that section's reference person.

**Only the encadrement appears** — animateurs and chefs d'unité active this year in each section. Never animés, never parents. **No contact details are shown**: writing to a section is done through the section's email address, on the public Sections page and on the member's own page. The page is reserved to identified visitors: the faces of the unit's leaders are not exposed to anonymous ones.

**Nobody is added or removed by hand.** The wall is rebuilt from the Desk import: somebody missing or wrongly present is a function to correct at the federation, not a row to edit here. The photos are the ones on the members' own pages, which each animateur changes for themselves.

**Which function marks a section's responsable is configured once**, per function, on Configuration > Config Desk — a flag on the function, not a designation per section per year.

**That flag feeds three other places**, through a core hook rather than any direct dependency: the responsable's name on the public Sections page, the same on a member's own page, and the default on-call target of the SOS module (§38). When the module is disabled, each of those simply shows or resolves nothing.

A section can be preselected (`?section={id}`) — which is what the "Trombinoscope de la section" button on a member's page uses. The page is available offline to identified visitors.


## 35. Statistiques des membres (module member_stats)

A photograph of the unit's animés for the scout year in view: four counters (animés, boys, girls, other), then one block per branch — Baladins, Louveteaux, Éclaireurs, Pionniers — with **one bar per year within the branch**, its birth year facing it, and the gender breakdown of that year one tap away. All the bars share one scale, so a thin branch is visible at a glance, and **a hollow birth year is the point of the page**: it announces a section that will empty out in two or three years, in time to do something about it.

**Only animés are counted.** A member whose principal function is an encadrement one is not, whatever their age. A member with no known birth date, or whose age falls outside the four branches, is counted **nowhere** — so the total can be slightly below the federation's own headcount, which is stated rather than hidden.

**Age is computed at the start of the scout year**, never against today, so no member changes branch in the middle of a year, and it takes into account the individual year offset a chief may have set for a child kept back or moved up (§4.2).

The page follows the year the rest of the site is showing — including the staff year during the preparation of the rentrée — and its figures move after each Desk import.

**The four branches and their age ranges are a federation fact, defined once in the core** and shared with the Inscriptions module's capacity brackets (§17): the two screens can never disagree about what a Louveteau is.

The module is **read-only and stores nothing of its own** — no table, no snapshot, no cache — and shows no individual detail of any kind.


## 36. Bannière d'accueil (module banner)

The announcement boxes at the top of the homepage: registrations open, fête d'unité, a call for volunteers.

**One banner is drawn at random on every homepage load**, among those that are active and visible to that visitor — so two visitors, or two refreshes, may see different messages, which is exactly what lets several announcements run at once. When none matches, the homepage shows **nothing at all**, not an empty box. Nothing is cached: a banner deactivated now stops appearing on the next load.

Each banner carries a **minimum audience**: `Public` (everybody, anonymous visitors included), `Identifiés`, or `Animateurs`. That is what makes an internal announcement — "pensez à vos fiches médicales" — possible without exposing it to passers-by. There is deliberately no admin-level banner: a message nobody but the chefs d'unité could see has no audience on a homepage.

The text is rich text stored through the site's own editable-content mechanism, with the same sanitization as every other rich text on the site. **An empty banner shows nothing, even when active.**

A banner is activated or deactivated rather than rewritten — a seasonal announcement comes back the following year untouched — and deleting one is permanent. **The order of the list decides nothing on screen**: it exists so that the administrator can keep their own list tidy.

The page is in the Espace chefs d'U, at `admin`.


## 37. Rétrospectives (module retro)

After an activity, a board where everybody drops a few words and votes, **anonymously**: three columns — ce qui a bien marché, à améliorer, autres suggestions. Participants arrive by a link or a QR code and **no account is needed**.

### 37.1 Anonymity is the feature, not a setting

No table in this module carries anything that could link a word to a person — no member, no account, no session, no address, no cookie value. Deduplication of votes is a one-way fingerprint of (voter, board, comment) which can answer "has this person already voted on this one?" and nothing else; budget mode adds a second one, scoped to the board, which links a voter's own votes **within one board** and yields an unrelated value on any other. The short-lived rate-limiting rows are fingerprinted the same way and purged on a schedule.

The consequence is stated to participants rather than hidden: **a word cannot be edited or withdrawn by its author**, because nothing knows it is theirs. Re-read before adding.

### 37.2 A board's own choices

Made at creation, from site-wide defaults an administrator sets:

- **How the link is meant to be found**: « Espace membres » or « Lien seul », shown on the board's own card. It records the chief's intent — the link is what gives access either way, and nothing on the participation page depends on the flag. The one place a board is genuinely surfaced today is the calendar event it is linked to, and that is governed by the audience setting below, not by this flag.
- **Voting**: unlimited, or a budget of points per person; and whether the counters are visible throughout or only revealed at closing.
- **Anti-duplicate**: per device or per login. Writing a word never requires logging in either way. Per device relies on a functional cookie; when cookies are refused the site falls back on the browsing session rather than blocking participation — and the help page says out loud that changing phone mid-way can hand somebody fresh points, because the exercise is only worth the sincerity of its answers.
- **Length of a word**: 120 to 200 characters, 140 by default.
- **Closing**: automatic after two hours, a day, three days or a week, or by hand.
- Optionally, **a link to a calendar event**, with its own audience for showing the board's link in that event.

The participation link and its QR code are created with the board. **Regenerating the link invalidates the old one immediately for everybody**, those who already scanned it included; the words and votes already collected are kept.

### 37.3 Living, and closing

The board fills in live — it polls for new words and votes at a configurable interval, so a projected board updates itself. Closing stops words and votes for good while leaving everything readable; when the AI connector is active a summary is written **once, at closing, and cached** so it survives the connector being disabled later, and the results can be emailed to a chosen address (the creating chief's own, by default). A closed board can be reopened, then archived.

Creating a board and closing one are **two separate role thresholds** (intendant and chief by default, both configurable): closing ends contributions definitively.

**A board can also create itself**: an event of the Calendrier module flagged for it opens its board at the event's own start time, and does nothing if a board is already linked or the module is disabled in the meantime (§27).

### 37.4 Moderation, and its limits

When the AI connector is active, a word can be checked before publication in one of three modes: **disabled**, **warning** (the author is told and offered a rewording, but stays free to publish as written) or **enforced** (the rewording is required). Without a connector, the choice is greyed out and nothing is checked. A participant whose word is too long can also ask for a shorter version — a preview only, never saved, and bounded so "shorten this novel" is not a way to run up the bill.

**Hiding a word is a chef d'unité's decision and a last resort**: it is reversible, and it never reveals an author, because there is none to reveal. Together with the automatic moderation, that is the entirety of the safety net — which is why the warning mode is the sensible minimum on a board open to young participants.


## 38. SOS Staff d'U (module sos_staff)

The unit's emergency number always rings somewhere. This module decides where, day by day, and programmes the operator's call forwarding itself.

### 38.1 The duty grid

A month grid crossing the days with the Staff d'U members. A cell rotates between three states — blank, **de garde**, **indisponible** — and saves immediately. Nothing is stored for the blank state: no row means available.

The left-hand columns show each day's **section activities**, so that duty is not handed to somebody already away on camp with their section. Which sections appear there is configurable; the Staff d'U section is never among them, being the one that takes the duty.

### 38.2 The default number

Days with nobody on duty forward to a **default number**, and that default is always **a Staff d'U member**, never a free-typed number — a handover must always be attributable to somebody reachable. Only members whose mobile is known are offered, and the number itself is resolved live at each read, so it follows the latest Desk import instead of going stale. Left unset, it resolves on its own to the Staff d'U section's responsable (through the Trombinoscope module, §34) or else to the first member of the roster. The save button stays inert until the value really changes: this field decides where emergencies ring.

### 38.3 Transitions

Saving the grid computes the day-to-day changes and schedules **one background transition per actual change**, at the changeover hour the administrator chose — and a change whose moment has already passed today is applied straight away rather than waiting for tomorrow. A transition is a sequence, not a single call: check the current state, set the forwarding, verify it afterwards, notify, journal. A technical failure is journaled **and** emailed to a superadmin, rather than left as a silent no-op on an emergency line. The person taking over and the one handing over are emailed too, when that setting is on.

The page shows the **real** state of the forwarding, read live from the operator — not what the site believes it set — and the list of the transitions still to come. A forwarding that no longer matches the grid usually means the background tasks are not running: without a real cron at the host, they run late.

Duty data older than a year — the assignments and the transitions scheduled for them — is purged whenever the grid is saved; there is no separate nightly task for it, and nothing to run by hand.

### 38.4 The operator

The telephony provider is pluggable; OVH is the one implemented. Its credentials are entered once, in three guided steps — the application key and secret, then an authorization to validate at the operator, then the choice of the line among the account's. They are **stored encrypted and never redisplayed**, and the authorization granted covers programming call forwarding and nothing else. A "test the connection" action checks the whole chain; an expired authorization at the operator is the usual cause of a forwarding that stopped following the grid, and it is replayed without touching the rest.

### 38.5 On the calendar

Consecutive duty days appear as single read-only events on the animateurs' calendar (§27) — **computed live from the duty grid** as virtual events, never written into the calendar's own storage, so the grid stays the single source of truth and the two can never disagree. They are consequently not editable from the calendar page: the duty grid is where the rota changes, and a calendar-side edit could never reach the telephony forwarding anyway. With the Calendrier module disabled, this does nothing at all.


## 39. Connecteur IA (module llm_connector)

One module owns every provider-specific detail — endpoints, request shapes, model names, error handling — so that no other module ever contains any. It gives the site an assistant for narrow tasks: reading an invoice or a receipt, suggesting an accounting category, summarising a retrospective, proposing keywords for an article, checking a message for personal attacks, explaining a storage error. **The AI proposes; it never decides**: every suggestion waits for a human, and without this module those features simply stay quiet — nothing breaks.

### 39.1 What a consuming module sees

Three capability **tiers** — economical, capable, document-reading — and nothing else. No model name, no provider name, ever, on the consuming side. A module asks whether the connector is available (a question that never throws: a provider whose stored key can no longer be decrypted, after a backup was restored onto another installation, counts as unavailable, not as an error), asks whether **its own tier** is available, and sends its request. Everything else — which provider, which model, retries, error wrapping — is this module's business.

### 39.2 Configuring it

One provider is active at a time; the page lists those supported with, for each, **where its servers are and its privacy links** — some host in the European Union, some do not — because the texts sent leave the unit's server. The API key is pasted once, **stored encrypted and never redisplayed** (leaving the field empty on a later edit keeps the current one), and it is the "enregistrer et activer" button alone that activates: changing the selection in the list only refreshes the display.

Saving **tests the connection and discovers the provider's models**, then assigns the three tiers **automatically** — by asking the provider's cheapest model to sort its own catalogue, and falling back on rule-based detection when that answer is missing or unusable. There is no manual model choice anywhere, and a scheduled task refreshes the catalogue afterwards. Switching provider goes through the same button, and the previous one stops being called immediately.

### 39.3 What it never does

**Never logs a prompt or a response.** Only metadata — which tier, which model, success or failure — reaches the journal. It is also where the site's outbound AI calls are bounded: every request carries a maximum answer length rather than relying on a provider's own default, which can silently truncate a long answer with no way to detect it.

The site displays **no usage counter and no budget**: the provider bills the unit directly, on its own tariff. Two settings elsewhere can generate noticeably more calls than the rest — the finance module's AI categorization rule and automatic moderation in groups — and the help page says so.

**A provider is a sous-traitant in the RGPD sense.** Enabling this module is a data-processing decision, and the site's RGPD page has to reflect it — its AI-generated mode knows how to take the chosen provider into account (§4.5), and the same holds for a module that only *optionally* reaches a provider through this one.


## 40. Outils de test — le bac à sable e-mail (module test_tools)

A toolbox that exists **only** on the project's reference installation and on developers' machines: the module declares `"visible_when": ["reference_installation", "local_installation"]` and is filtered out of module discovery everywhere else, so no deploying unit's installation ever loads it. Every route is `superadmin`, under Configuration. **That condition is what makes everything below admissible** — read it as a condition, not as a claim.

Its first tool is the **mail sandbox**.

### 40.1 Armed, nothing leaves the server

When capture is armed, every outgoing message is assembled by the real mailing library — recipient validation, MIME assembly, attachments, headers, **DKIM signature included** — and then stored here instead of being handed to SMTP. What is inspected is therefore the actual message the site would have sent, not a reconstruction.

**Capture is all or nothing.** No "capture and also send", no allowlist of addresses that really go out, no per-recipient exception: an operator who has to work out which half of the mail left the server cannot answer "what did this feature actually send?". For the same reason nothing records **which** feature sent a message — the subject and the timestamp are the identification, deliberately.

A message the library fails to assemble is **captured too, with its error, and the failure still reaches the caller**: a test tool that silently swallows a broken mail is worse than useless.

Three conditions must hold for capture to be wired at all — a reference or local installation, the module enabled, and the switch armed — and the switch is toggled from the sandbox page and nowhere else. It never appears among the ordinary settings: a switch that changes the sending behaviour of the whole site does not belong in a list of text fields. **Every toggle is journaled, in both directions.**

### 40.2 The page

A state block carries the switch, its state in plain French and — **only when armed** — the warning that magic links now land on this page, so the operator should sign in with a password or a passkey rather than lock themselves out of the switch.

Below it, the captured messages: subject, recipient, date, size, attachment count, DKIM badge, newest first, paginated, with the whole view state in the query string and no cookie. **Two searches that cost different things**: the subject is stored in clear and searched plainly; the recipient is encrypted and therefore matched **only on an exact address** — a partial address finds nothing rather than quietly scanning everything; and an opt-in third search decrypts and reads the bodies, **bounded** (200 messages) because there is no index to lean on, which the page states rather than silently stopping.

A message opens in five tabs — HTML preview, plain text, headers, raw MIME source, attachments — plus an `.eml` download. **The HTML preview always renders in a sandboxed frame** with neither scripts nor same-origin access, never inline in the page. Attachments and the `.eml` download through the site's ordinary file access, so nothing here introduces a file-access exception.

### 40.3 Storage and retention

The raw message, each body half and each attachment are stored **encrypted at rest** as `superadmin` files; nothing is ever written to disk in clear. Attachment names, types and sizes are read off the message at capture time, so the sandbox never has to parse MIME to offer a download.

**Retention is bounded by count, not by age** — 500 messages by default, the excess deleted daily, oldest first — because the bound that matters is the one that keeps the decrypted body search affordable: a sandbox left armed overnight collects thousands of messages that "older than thirty days" would not remove. A non-positive setting falls back to the default rather than meaning "keep everything". Rows and their encrypted files are always deleted together.

**"Empty the sandbox" is a danger-zone action**: the operator types the confirmation word and **the server compares it** — a check the caller can skip is not a control — and emptying is journaled, like the switch.

### 40.4 RGPD

**Deliberately unchanged.** The module cannot load on a deploying unit's installation, so it processes no data any unit is controller for. The absence of an RGPD entry for it is the correct outcome, not an oversight.
