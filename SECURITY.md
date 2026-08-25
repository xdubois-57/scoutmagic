# Security

This document defines the non-negotiable security requirements for the project. Every contribution must comply. The RBAC guard, encryption, and file access guard are the three pillars — none may be bypassed.

## Reporting a vulnerability

Report security vulnerabilities privately — not via public GitHub issues — by contacting the
maintainer directly. Include enough detail to reproduce the issue. There is no guaranteed
response time; this project is maintained on a volunteer basis, and fixes are made as time
allows.

## Avertissement

Les exigences de ce document décrivent les pratiques de sécurité visées
par le projet. Leur respect n'emporte aucune garantie de sécurité absolue,
conformément à l'exclusion de garantie de la licence AGPL-3.0 (voir
LICENSE). La sécurité effective d'un déploiement dépend aussi de facteurs
hors du contrôle du projet : configuration de l'hébergement, mises à jour
appliquées, mots de passe et clés gérés par l'unité déployante.

## 1. Database access

- Prepared statements everywhere (PDO). **No SQL concatenation, ever.**
- Repository is the only layer that touches PDO.

## 2. Authentication

- `password_hash()` / `password_verify()` for password storage. No custom hashing.
- Magic link tokens: `random_bytes(32)`, stored hashed, single-use, 15-minute expiry.
- WebAuthn credentials: public key stored, challenge verified server-side, sign count checked. Attestation objects and COSE keys are decoded with a real CBOR parser (`Core\Security\CborDecoder`), never by scanning raw bytes for marker values; the RP ID hash is checked on both registration and assertion; ES256 **and** RS256 are verifiable (RS256 was advertised long before it worked). User verification is requested as `required` and — because a request to the client is not a guarantee — the UV flag is also enforced server-side on both paths, so a passkey never authenticates on user *presence* alone.
- Identical error messages for "unknown email" and "wrong password" — no account enumeration. Identical *cost*, too: the "no such account" and "account without a password" paths deliberately burn a `password_verify()` against a dummy hash of the same algorithm/cost (`Core\Security\PasswordAuthMethod::dummyHash()`, generated once per process from random bytes, never a literal in source), so response time doesn't separate the cases a uniform message just merged.
- The magic-link polling endpoint (`GET /auth/poll/{id}`) is bound to the session that requested the link (`Core\Security\PendingMagicLink`). `magic_links.id` is a sequential `AUTO_INCREMENT` integer, never the emailed secret — it is not a capability, and polling somebody else's id returns the same "not confirmed yet" as an unconfirmed one. Only `AuthService::verifyMagicLink()` checks the real token.
- **Confirming a magic link does not create a session in the browser that opened it.** `GET /auth/verify` proves possession of the emailed token, marks the link used and says so; the session is created in the window that REQUESTED the link, which collects it through the poll endpoint above. A link travels by email and an email is read wherever it is convenient — a shared tablet, a work laptop, somebody else's webmail, a corporate scanner that follows every URL before a human sees it — and each of those used to end up holding a live session for the address. The single exception is the requesting session opening the link itself (`PendingMagicLink::matches()`, the ordinary same-device flow), which grants nothing its own poll was not about to grant. Pinned by `Tests\Integration\MagicLinkWindowIdentityTest`.
- **A magic link identifies the address it was sent to, and only that address.** A link is stored against the blind index of the address actually submitted; confirming it logs in as *that* address, with its own `user_accounts` row (created on first use, never `is_super_admin`) — a member's confirmed secondary address (`member_emails`) is an identity of its own, never an alias for the account behind that member's primary address. Attaching it to the primary account instead, as it once did, turned every secondary address into a full login for somebody else's account — its role, its credentials, and every member linked to it — and, because the rate limiter counts the submitted index, silently exempted those requests from the 5/hour magic-link limit. The address's access is exactly the members it is currently a `valid` address of; deactivating or deleting it ends the session on its next request through the same `SessionRevalidator` membership check. Pinned end to end by `Tests\Integration\SecondaryEmailLoginIdentityTest`.
- Progressive lockout on failed password attempts, along two axes (`Core\Security\LoginThrottler`): per email (5/10/20 failures per hour) and per source IP, at much higher thresholds (30/60/100). The IP axis catches the spray of one password across many accounts that per-email counting is structurally blind to; the raw address is never stored, only its blind index. A successful login clears the email axis only — one attacker succeeding on an account they control must not erase the evidence of the spray they are running from the same address. (This is not the per-IP counter `ARCHITECTURE.md` §8.31 rules out: that concerns the magic-link *request* form, which already has a per-email limiter for the same abuse pattern.)
- Sessions are revalidated against current data on every request (`Core\Security\SessionRevalidator`), never trusted for the lifetime of the 30-day cookie:
  - **Revocation.** Setting or changing a password bumps `user_accounts.sessions_valid_from`; any session issued earlier is dropped on its next request. PHP's file-based sessions have no per-user registry to walk and destroy, so revocation has to be a stamp the session re-checks itself against — without it a password reset "recovered" an account the attacker was still sitting in. A member changing their own password keeps the tab they did it in (`AuthSession::refreshIssuedAt()`); a reset link ends every session including the requester's.
  - **Role.** The effective role is re-resolved each request instead of being snapshotted at login, so a demotion — or losing unit membership entirely, which ends the session, matching the login gate — takes effect on the next click.
- The password-reset link carries its token in the URL **fragment** (`/password-reset/{id}#<token>`), never the query string: fragments are not transmitted, keeping the token out of access logs, proxy logs and `Referer`. The page reads it from `location.hash`, strips it from the address bar, and validates it through `POST /password-reset/{id}/check` (CSRF-protected, and read-only so reloading never burns the token).
- Session ID regenerated at login (`session_regenerate_id(true)`).
- Session cookies: `HttpOnly`, `Secure`, `SameSite=Lax`, 30-day lifetime (`Core\Security\SessionManager`, matching `session.gc_maxlifetime` so server-side session data doesn't expire before the cookie does) — an installed PWA shouldn't demand a fresh magic link every few days. The `Secure` flag follows the connection, and "is this connection HTTPS?" has exactly one answer for the whole codebase — see §9, including the `X-Forwarded-Proto` opt-in a deployment behind a separate TLS terminator needs.

## 3. RBAC

- RBAC guard called by Router **before** any controller code — automatically, for every route.
- Every route must declare `role_min`. A route without `role_min` is rejected at load time — `Core\Module\ModuleManifest` for module routes, and `Core\Http\Router::addRoute()` itself for core ones (the argument is mandatory, and an unrecognised role name raises rather than being silently downgraded to `public` by `Role::fromString()`).
- The RBAC guard is switched off in exactly one place — `setRbacBypassPrefix('/setup')` — and only while `SecretManager::isInitialized()` is false, i.e. the first-run installer, where no database, account or role exists yet. Once initialized, `/setup` is reachable through its own `role_min: superadmin` like any other route, so a bypass there could only ever strip authentication (`GET /setup` leaks database/SMTP/admin settings and issues a CSRF token; `POST /setup/save` rewrites database credentials and the admin account). Pinned by `Tests\Core\Http\SetupRbacBypassWiringTest`.
- New imported functions default to lowest role. An import never silently elevates privileges.
- Role check is always server-side. Menu visibility is a convenience, never a security boundary.
- **`role_min` is a floor, never the whole answer.** Any resource with its own visibility rule must re-check it in the controller or service, because the route only proves the caller's role clears the minimum:
  - Finance accounts carry `role_min_view`; every page resolving them (dashboard, movements, receipts, import, **and the receivables reconciliation page**) filters through it, and a receipt with no account at all is denied rather than left unguarded. Since the treasurer rule, `role_min_view` is only one of **two** conditions, and both are asked in one place — `Modules\Finance\Service\AccountVisibility` (ARCHITECTURE.md §8.69): an account attached to a **section** additionally requires being that section's treasurer, meaning carrying the `Trésorier` badge AND animating that section for the effective scout year (`Service\TreasurerScopeService`, keyed on linked `members.id` so the same rule can serve a file-ownership checker unchanged). An account with no section is the unit's own money and keeps `role_min_view` as its whole answer; `admin`/`superadmin` get every account unconditionally. **A unit that has assigned the badge to nobody this year — which is every unit on the day it updates — has the rule switched off entirely**, and so does deactivating the badge: the service returns `null` for "disabled" and `[]` for "on, and this session is nobody's treasurer", two different types precisely so a caller cannot fail open by mistaking one for the other. The filtered account picker is UI, not the boundary: every route that receives an `account_id` (import, receipt upload, movement update, a movement's attachments, the movement search's explicit account filter) re-asks the same predicate server-side, and the movement search falls back to the caller's own accounts rather than honouring an id they may not use.
  - Calendars: a chief may only create, move or delete events in a calendar inside `CalendarEventService::getEditableCalendars()` — checked on both ends of a move, not just that the calendar exists. That set is narrower than what the page shows: a **section** calendar is writable only by an animateur of that section (`SectionStaffAuthorizationService`, resolved by the controller and passed in), while the month grid and the page's calendar picker keep listing everything the role may *see* (`getViewableCalendars()`). Reading and writing are two different questions here, and only the second one is scoped by section. A **supplementary** calendar has no section, so it passes that half unconditionally and its write role is its whole rule. That write role is `calendar_calendars.edit_role_min` (ARCHITECTURE.md §8.68), the second half of the conjunction and a column of its own: `visibility` says who may SEE the calendar, `edit_role_min` who may MODIFY it, and both must hold. It may never be more permissive than `visibility` — a role that cannot see a calendar must not write in it — which `CalendarService` enforces by *raising the write role along with the audience* when the audience is narrowed, and by refusing only the reverse. The rule lives in the service, not merely in the form. `$viewerRole === null` marks a session-less system caller (`Modules\SosStaff\Service\CalendarSyncService`) and short-circuits the check; an empty staffed-section list otherwise denies every section calendar, so a call site that forgets the argument fails closed.
  - News articles: the visibility gate applies to every representation of an article, the poster PDF included, not only its detail page.
  - Groups: content auto-hidden by moderation is invisible to non-moderators on *write* paths (reactions, reports) as well as reads.
  - Gallery: a delegated album is refused if its storage location has a public URL — re-asserted when serving bytes, not only when the album is created.
  - Ids arriving in a request body are validated against the set the UI actually offers (a form's finance account, the SOS default-number member), never trusted because the route's role was high enough.
  - Section documents: all four writes (`/chefs/staffs/documents*`, `role_min: chief`) re-check that the account really staffs the document's section. `add` validates the body's `section_id`; `update`/`delete`/`reorder` resolve the section **from the stored row**, never from the request. `reorder` names several documents at once and is refused whole when one of them is out of scope — a partially-applied ordering is its own defect.
- `Core\Member\SectionStaffAuthorizationService` ("which sections is this account chief/animateur of") is a Controller-level narrowing on top of the route's `role_min`, not a replacement for it — same pattern as `MemberService::canAccess()` narrowing onto one member. The RBAC guard still gates the route first; this service only answers which resource(s) the already-authorized caller may act on within it. It resolves the account through its Desk address **and** its currently-`valid` secondary addresses (`member_emails`), the same way `MemberService::getLinkedMembers()` and `RoleResolver` do: resolving fewer addresses than the rest of the site denies a legitimate animateur rather than admitting a stranger, but it denies them silently, which is its own kind of failure.

## 4. CSRF

- CSRF token on every form, verified on every POST/PUT/DELETE.
- Token bound to session, regenerated per session.
- Two deliberate exceptions, each authenticated by something other than a session-bound token:
  - `POST /api/webhook/github` (`Core\Http\Controller\WebhookController`) — a machine-to-machine call from GitHub with no session to bind a token to. Authenticated instead by an HMAC-SHA256 signature (`X-Hub-Signature-256`, constant-time `hash_equals()` comparison) against a secret stored only in `secrets.enc`.
  - `POST /api/statistics` (`Modules\SupportDashboard\Controller\StatisticsIntakeController`, ARCHITECTURE.md §8.49) — the usage-statistics intake on the receiving installation. A machine-to-machine call from another ScoutMagic installation, with no session to bind a token to. Authenticated instead by a bearer secret checked with `password_verify()` against a `password_hash()` stored at first registration; the secret itself is never stored in clear, in any column, in the journal, or in a response. The endpoint refuses cleartext transport, caps the body before parsing it, and rate-limits per source IP (stored as a blind index, never in clear). A rejection answers a bare status with no body, so an unknown installation is indistinguishable from a wrong secret. Two properties of what it *stores* matter as much as what it refuses: a value bound for an `INT UNSIGNED` or `DATETIME` column is range-checked in PHP (out of range becomes `NULL`), because a crafted `{"active_members": -1}` would otherwise reach the column and turn a `public` route into an unhandled `PDOException`; and `instance_url` is kept only when its scheme is `http`/`https`, because it is the one reported value the dashboard renders as an `<a href>` and Twig's HTML escaping says nothing about `javascript:`. The journal's "unknown fields" warning is bounded in count and per-name length for the same reason — those names come verbatim from the body.
  - `POST /mass-mail/unsubscribe/{id}` (`Modules\MassMail\Controller\UnsubscribeController`) — the RFC 8058 one-click unsubscribe target, reached from a mail client with no session. Authenticated by a per-recipient token carried in the link and verified constant-time against a stored SHA-256 hash (`hash_equals`). The token is 32 bytes of entropy, so a fast hash is as safe as bcrypt and avoids a per-request bcrypt on an anonymous endpoint. Idempotent, so a mailbox prefetch or a resubmit lands in the same "unsubscribed" state.

## 5. Encryption at rest

### Not personal data, and stored in clear on purpose

The statistics receiver (ARCHITECTURE.md §8.49) keeps each reporting installation's **instance URL** and **installation id** as plain columns. Stored in clear is not the same as trusted: both are rendered through Twig, the URL is linked only when its scheme is `http`/`https`, and no page assembles markup from either of them client-side (§8.50). Neither identifies a natural person: a scout unit is an association, the URL is already public, and the installation id is opaque random bytes derived from nothing about anyone. Both are needed in clear for the dashboard to filter, sort and search across installations — encrypting them would force either a blind index per searchable field or full-table decryption on every page load, buying nothing. The reports themselves carry no member data at all (§8.47), so there is nothing else in that table to protect.

### A subject line in clear, and the condition that makes it admissible

`modules/test_tools`' mail sandbox (ARCHITECTURE.md §8.63) stores each captured message's **subject** as a plain `VARCHAR`. A subject can name a member — "Confirmation d'inscription de …" — so this is a deliberate exception to the rule above, and it is admissible **only because of a condition, which is what has to be written down**: the module cannot load at all unless the installation profile carries `reference_installation` or `local_installation` (§8.49). No deploying unit's installation can hold such a row, whatever is in its database. The exception is to the *rule*, not to the *reason for the rule*.

State the condition, not merely the exception, when citing this as precedent: "the subject is stored in clear in `test_tools`" is not a licence to store a subject in clear in a module that any unit can enable. If a later module wants the same latitude it has to carry the same gate, and the gate is a manifest field anyone can check.

Everything else in that table follows the ordinary rules: the **recipient** is personal data and is a `BLOB` plus a blind index, encrypted and decrypted only in the Repository; the raw message, its two body halves and every attachment are encrypted at rest through `Core\File\EncryptedFileStorageService`; `from_address` and `reply_to` are organisational addresses and stay in clear per design.md §2.6. **No e-mail address ever appears in a journal entry**, including the `security`-level entries the arm switch and the manual emptying write.

### Personal data

All fields identifying a natural person are encrypted (AES-256-GCM) as BLOB:

**Encrypted**: name, surname, totem, quali, date of birth, gender, street, number, box, complement, postal code, city, country, phone, mobile, email, departure comment (`member_years.leaving_comment_encrypted` — often a sensitive reason: conflict, family situation, health).

**In clear**: all IDs, FKs, timestamps, flags, module/role references.

### Implementation

- `EncryptionService`: `encrypt()`, `decrypt()`, `blindIndex()`.
- Two keys (`APP_ENCRYPTION_KEY`, `APP_BLIND_INDEX_KEY`), never in database, never committed. Each is 32 random bytes. `secrets.enc` is JSON and cannot hold raw bytes, so the keys are stored base64-encoded and **decoded back to raw bytes** (`EncryptionService::fromEncodedKeys()`) before use — passing the 44-character base64 string straight to OpenSSL silently truncated it to a 24-byte (192-bit) effective key, so AES-256-GCM now genuinely runs at 256 bits.
- Blind index (HMAC-SHA256) alongside encrypted email for exact-match lookup.
- Only Repositories call `EncryptionService`.

### Files on disk

- Personal data files: encrypted at rest or strictly temporary.
- Desk CSV: **kept**, encrypted at rest via `Core\File\EncryptedFileStorageService`, for a retention expressed in scout years (default 2 — the current season and the previous one), then deleted with everything else that import produced. The plaintext the browser deposited is another matter and is unchanged: it lives in `storage/temp/` for the length of one request and is deleted in a `finally`, success or failure. §13 states the whole rule and why it was revoked.
- Finance receipts (`modules/finance`): encrypted at rest via `Core\File\EncryptedFileStorageService` (same master key as `EncryptionService`) — never written to disk in plaintext. Bank statement CSV files uploaded for import: deleted immediately after processing, success or failure. (That rule used to be stated as "same pattern as the Desk CSV"; it no longer is one, and it stands on its own — a bank statement is re-downloadable from the bank at any time, so keeping a copy would buy nothing and cost a second store of banking data.)
- Public content files: not encrypted.

### Secrets

- `storage/keys/master.key`: `chmod 600`, generated via `random_bytes()`.
- `storage/config/secrets.enc`: AES-256-GCM blob with DB + SMTP credentials, plus the GitHub webhook HMAC secret (`github_webhook_secret`) and the usage-statistics reporting secret (`statistics_secret`, ARCHITECTURE.md §8.47 — 32 random bytes hex, generated lazily on first use, sent only as an `Authorization: Bearer` header, never in a payload body, never in `settings`, never in the journal, never handed to a view). Both are generated once and never stored anywhere they could be read back through the UI.
- Key and blob in separate directories.

## 6. File access

- All non-public files under `storage/` (outside webroot).
- Every download through `FileAccessGuard` via `/files/{id}` — no exceptions. `GET /files/{id}/{variant}` (`Core\Photo\ImageVariantService`, ARCHITECTURE.md §8.39) is a rendition of the same file through the same guard, not a second access path: `{variant}` is validated against a fixed two-name vocabulary before it is ever used to build a filesystem path, so an unknown or path-traversing value is a plain 404 that never touches the filesystem.
- **The one deliberate, narrow exception this used to have (Lot 3, offline mode) is retired.** `GET /api/offline/photo/{member_id}` (`Core\Http\Controller\OfflineController::photo()`) used to serve a square ~160px WebP derivative of a staff member's current photo, generated on demand, specifically because `/files/{id}` had no cacheable, already-small rendition for the offline trombinoscope pre-download to point at. `GET /files/{id}/thumb` now is that rendition, generated once at upload rather than on demand — so the bespoke route and `Core\Photo\StaffThumbnailProcessor` no longer exist, and the offline pre-download (`public/assets/js/offline-prefetch.js`) fetches plain `/files/{id}/thumb`/`/files/{id}/md` URLs, listed for it by `GET /api/offline/manifest`, like any other caller. This is a net reduction of security surface: one fewer route bypassing `FileAccessGuard`'s normal single-path posture.
- Beyond the `role_min` floor, a file may be narrowed by ownership: `files.owner_member_id` (the session must be linked to that member) or the generic `files.owner_type`/`files.owner_id` registry (`Core\File\FileOwnershipCheckerInterface`, ARCHITECTURE.md §8.3). Both are **fail-closed** — an `owner_type` with no registered checker is denied, never allowed by default — and neither has a chief/admin bypass. A checker can only narrow what `role_min` already permits, never widen it, and `owner_type` values must be unique across checkers (the first `supports()` match wins). A module's checker is wired into the composition root only while that module is enabled, and the guard is built after every module block precisely so a missing checker can never silently become "no check".
- File links via `file_url($id)` — never direct paths.
- Upload: true MIME check, random filename, EXIF stripped, size limit, non-executable directory.
- Access denied: 403 + journal entry (security level).
- **`phpinfo.html` inside the support package is generated as `phpinfo(INFO_ALL & ~INFO_VARIABLES & ~INFO_ENVIRONMENT)`, never anything wider.** `INFO_VARIABLES` prints `$_SERVER`, `$_ENV` and `$_COOKIE` — on this page, the still-valid session cookie of the superadmin who just triggered the generation, which is enough to become them. `INFO_ENVIRONMENT` is a **separate** flag printing the process environment, where a host's injected credentials (API tokens, proxy credentials, database passwords) live; excluding only `INFO_VARIABLES` leaves that section intact, which is why both are masked and why a test asserts it against a real `phpinfo()` run rather than trusting the constant's name. The rest of the output is deliberately **not** redacted — guessing which ini directive is sensitive would strip diagnostic value and still miss something — which is exactly why the Support page and the archive's own README both tell the administrator to check the contents before sending it.
- **The diagnostic support package** (ARCHITECTURE.md §8.48) is treated as the most sensitive artefact this codebase produces on demand, because it deliberately contains `phpinfo()` output, server logs and filesystem diagnostics: written under `storage/core/support/`, **encrypted at rest** via `Core\File\EncryptedFileStorageService`, registered with `role_min: 'superadmin'`, and reachable only through `/files/{id}`. Exactly one is kept (generating replaces the previous file and its `FileRecord`) and a daily task deletes it seven days after generation, downloaded or not. It is **never transmitted automatically** — no email, no upload, no pre-filled `mailto:` with an attachment; an administrator sends it by hand or it goes nowhere. Every reason and note written into its `collection-status.json` is scrubbed of every known secret **before** any whitespace normalisation, so a collector's exception quoting a credential cannot smuggle one into an archive destined for email — the needles include the database and SMTP passwords, which may contain a space, and collapsing whitespace first was enough to stop the needle matching. The collection is also bounded as a whole (25 log files / 8 MB, 5 000 `storage/` entries, each truncation stated in the archive), because the guarantee this feature actually makes is that a package is *always* produced, and an unbounded read on a busy host exhausts `memory_limit` and produces none.
- **The mail sandbox introduces no file-access exception, and this is stated because a reader arriving from the genuine one above will look for it.** `modules/test_tools` (ARCHITECTURE.md §8.63) stores the raw `.eml`, both body halves and every attachment through `Core\File\EncryptedFileStorageService` with `role_min: 'superadmin'`, and the detail page links to them as plain `/files/{id}` URLs — so `FileAccessGuard` and transparent decryption apply exactly as to any other file, and there is no bespoke download route, no direct path and nothing that bypasses the guard. The HTML preview is not a download at all: it is an `<iframe srcdoc>` with no `allow-same-origin` and no `allow-scripts` (§9), fed from an already-decrypted string the guard's own role floor gated.
- Finance receipts go through `FileAccessGuard` like any other file, and the file carries its account **twice**, for two different questions. `files.role_min` is set to the account's own `role_min_view` at upload time — not the module's flat `"intendant"` `storage` declaration, which is only the fallback floor for a not-yet-account-scoped case — and every existing receipt file is updated to match whenever that value changes (`ConfigAccountController::syncReceiptFilesRoleMin()`), so the floor stays in sync retroactively. `files.owner_type = 'finance_account'` / `owner_id` name the account itself, which is what lets the file follow the account's **section** rule: `role_min` is hierarchical and cannot express "the Louveteaux section", so without it the screen would be narrowed to the section's treasurer (§3) while a direct `/files/{id}` went on serving the same receipts. `Modules\Finance\File\FinanceAccountOwnershipChecker` delegates to the same `Service\AccountVisibility` predicate the screens use — the file and the screen cannot answer differently — and `role_min` is still enforced first and independently, so ownership only ever narrows. **Receipts stored before the checker existed are backfilled** (`AttachmentRepository::backfillFileOwnership()`, called from the composition root on any page load rather than from a configuration screen no unit is obliged to open); a receipt whose `account_id` was already NULL is stamped as owned by no account and therefore denied, rather than left readable on `role_min` alone. The registry is fail-closed, so disabling `finance` makes its receipts unreachable, never public.

## 7. Content editing

- Configuration mode: session-only, role re-verified on every save.
- Rich text: sanitized (`Core\Security\HtmlSanitizer`) with a strict tag allowlist before storage. URL-bearing attributes (`href`, and an `img`'s `src`) are checked against a **scheme allowlist** (`http`, `https`, `mailto`, `tel`, or no scheme) — never a blocklist, which always misses one (`vbscript:` survived the old `javascript:`/`data:` blocklist). Tab/CR/LF are stripped before the scheme is read so `java\tscript:` can't slip past. Comments, processing instructions and CDATA are removed rather than re-serialized. `<img>` is allowed (so the editor's image button works) with a tight attribute set and a scheme-checked `src`; the client-side twin (`news-form-builder.js`) mirrors the same allowlist.
- Images: MIME validated, EXIF stripped, filename randomized.

## 8. Email

- DKIM signing on every outgoing email.
- SPF, DKIM, DMARC verified live and displayed in configuration.
- Multipart mandatory (HTML + plain text).
- Rate limiting on magic link sends.

## 9. HTTP headers

Every response: `Content-Security-Policy`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Strict-Transport-Security` (if HTTPS), `Referrer-Policy: strict-origin-when-cross-origin`.

The fatal-error fallback page (`Core\Http\ErrorHandler`, §22) re-emits the same header set from its own hardcoded 500 response — an uncaught throwable must never be the one response that ships without them. The two other pages emitted outside the normal `Response` path do the same now: the 413 "payload too large" page and the pre-routing migration-progress page. The migration page carries an inline `<script>` **and** an inline `<style>`, so it builds a per-render **nonce**-based CSP and tags both with it, rather than shipping no CSP at all (which is the only reason that inline script used to run).

### `style-src`, split in three

CSP calls two very different things "inline style", and `style-src` governs both at once: a `<style>` **element** (or a `<link rel=stylesheet>`), and a `style="…"` **attribute**. That is why this codebase carried a blanket `style-src 'self' 'unsafe-inline'` — some 260 `style="…"` attributes across ~90 templates set computed geometry (progress-bar widths, section colours) that nothing else expresses, and one directive had to permit them all.

They are not the same risk. An injected `<style>` **element** restyles the whole page: an overlay over any control, or attribute-selector rules whose `background: url(…)` leaks what they matched. An injected **attribute** reaches the one element the injection already controls. CSP Level 3 separates them, so `Core\Http\Response::buildStyleSrc()` now emits:

```
style-src      'self' 'unsafe-inline'   ← fallback, see below
style-src-elem 'self' 'nonce-…'         ← elements: no inline at all
style-src-attr 'unsafe-inline'          ← attributes: still permitted
```

**The fallback is load-bearing, not belt-and-braces.** A browser that does not know `style-src-attr` ignores it and reads `style-src` instead; drop `'unsafe-inline'` there and every inline style attribute on the site is refused, on real devices still in use. `style-src-attr`/`-elem` arrived in Chrome 75, Firefox 108 and Safari 15.4 — and `style-src-elem` only in **Safari 26.2** — so the fallback is what an older iPad actually reads. The policy is therefore strictly stronger where the split directives are understood, and exactly as strong as before where they are not. It is never weaker anywhere, and the *element* half is closed outright on every browser that understands it.

The consequence for anyone adding a page: **an inline `<style>` element now needs `nonce="{{ csp_nonce }}"`**. Without one the browser refuses the block and renders the page unstyled — no test fails, no request errors, nothing is journaled. `Tests\Security\InlineStyleElementNonceTest` walks every template and both PHP-built pages and fails on a `<style>` without a nonce, because that is not a failure anyone would otherwise notice. Email templates are exempt: a mail client sends no CSP and there is nothing for a nonce to be checked against.

What this does **not** do is retire the attribute half. See §33 — that needs the ~260 attributes gone, and it is tracked there rather than claimed here.

Cross-origin `target="_blank"` links carry `rel="noopener"` (the sanitizer forces it on user content; templates set it directly).

**The mail sandbox's HTML preview needs no new directive, and that was verified rather than assumed.** `modules/test_tools`' detail page (ARCHITECTURE.md §8.63) renders a captured message's HTML half into an `<iframe srcdoc="…" sandbox>` with **no `allow-same-origin` and no `allow-scripts`**, so the frame gets an opaque origin and cannot reach the page, its DOM or its cookies, and nothing inside it executes. A `srcdoc` frame issues no navigation request, so no `frame-src`/`child-src` value is consulted; it instead **inherits the embedding document's CSP**, which is checked in a real headless Chromium against the exact policy `Core\Http\Response::buildCsp()` emits: the frame renders, its script is blocked, and — because the inherited `img-src 'self' data: blob:` applies inside it — a remote tracking pixel in a captured message is blocked too. Since `style-src-elem` stopped allowing inline styles, a captured message's own `<style>` block is refused inside the frame as well: the preview then shows the message as a mail client that strips `<style>` would, which most of them do, and which is why real senders put their styling in `style="…"` attributes — those still apply. Two independent controls, neither relied on alone. The captured HTML is never written into the page itself under any circumstance; Twig's auto-escaping handles the `srcdoc` attribute.

### The files PHP never sees

"Every response" above is emitted by `Core\Http\Response`, and a static file never reaches it. The rewrite rule in `.htaccess` forwards to `index.php` only what does **not** exist on disk, so a request for `/assets/css/app.css` is answered by Apache alone — no PHP, no headers. That was the gap a dynamic scan reported on ~70 URLs, all of them under `/assets/`, and it is a real one rather than a scanner artefact.

Both `.htaccess` files now carry the two headers that make sense for a static file — `X-Content-Type-Options: nosniff` and, over a connection Apache knows is encrypted, `Strict-Transport-Security`. **Both** files, because the two install layouts (§ ARCHITECTURE) reach an asset by different routes: layout A merges `public/` into the document root, so `public/.htaccess` is the root one; layout B keeps a single tree and bootstrap writes its own root `.htaccess` (`bootstrap_htaccess_content()`), which is the file a static request meets first. Covering one and not the other leaves half the installed base uncovered without anything looking wrong.

Three properties are deliberate, and `Tests\Security\StaticAssetHeadersTest` pins all three against both sources:

- **Scoped to static extensions**, never blanket. A blanket `Header always set` would also apply to PHP responses and overwrite what the application built for that request — the CSP carries a per-request nonce, and a fixed copy of it in a config file would go stale and silently invalidate the nonce it no longer matches.
- **`env=HTTPS` on the HSTS line.** Behind a separate TLS terminator mod_ssl sets nothing, and announcing HSTS for an origin whose TLS the server cannot see is how a site locks its own visitors out. That deployment is covered on the PHP side by the `trust_forwarded_proto` opt-in below; here the correct behaviour is to stay quiet.
- **Wrapped in `<IfModule mod_headers.c>`.** mod_headers is not universal on shared hosting, and an unguarded `Header` directive is a 500 on every page of a host that lacks it — much worse than the missing header.

**What this is worth, stated plainly.** The `nosniff` half is the useful one, and it is cheap: it is what stops a browser content-sniffing a file whose declared type it distrusts. Its practical exposure here is small, though — nothing under `public/assets/` is user-supplied (§6 forbids uploads under `public/` outright), and user files are served through `/files/{id}`, which *is* PHP and already carried both headers. The HSTS half is lower value still: HSTS is an origin-wide policy, so any HTML response already covers the assets; this closes only the case of a visitor whose first-ever request to the origin is an asset. Neither is the reason to have done it. The reason is that "every response carries the baseline headers" should be true as written rather than true-except-for-one-directory, and an exception nobody has written down is one that gets extended.

**The DAST harness cannot verify this, and that is not a gap in the fix.** `scripts/dast.sh` serves the instance through `php -S`, which does not read `.htaccess` at all, so a scan will keep reporting both alerts on every asset URL for as long as the harness works that way. They are not filtered out in `tests/dast/zap-*.yaml`: an alert filter there asserts a finding is a false positive, and this one is a true finding about a server the harness is not running. Reading a passive report means knowing that the `/assets/*` instances of those two rules are answered here, and that an instance on any other path is not.

### Deciding whether the request is HTTPS — one method, and one opt-in

Two security controls hang on the answer: the session cookie's `Secure` flag (§2, and the same for the cookie-consent and last-login-method cookies) and the emission of `Strict-Transport-Security` above. **`Core\Http\RequestScheme::isHttps()` is the only place that answers it** — `Core\Http\Request::isHttps()` is the same method reading a request's own captured `$_SERVER` — and every one of those controls calls it. `Core\Http\Response::setHttps()` still overrides the verdict when a caller states the scheme outright.

That convergence is itself the fix for a real inconsistency: the detection used to be copy-pasted at six call sites and one of them had drifted. HSTS looked at `$_SERVER['HTTPS']` alone while the five cookie-flag sites also accepted `SERVER_PORT === 443`, so on a host where only the port says HTTPS the session cookie was `Secure` and Strict-Transport-Security was never sent. `Tests\Security\HttpsDetectionConvergenceTest` walks `core/`, `modules/` and `public/` and fails on a seventh copy, because a re-implementation would restore that class of bug without breaking any behavioural test.

**`X-Forwarded-Proto` is honoured only behind an explicit opt-in.** Behind a separate TLS terminator — a load balancer, a CDN, several shared-hosting setups — the PHP process sees plain HTTP on a non-443 port, so every check above says "not HTTPS" on a site genuinely served over HTTPS, and ScoutMagic then issues its session cookie **without** the `Secure` flag. `trust_forwarded_proto` in `config/app.php` (default `false`) makes `X-Forwarded-Proto: https` count as HTTPS.

It is off by default, and must stay off unless a terminator the administrator controls sits in front of the installation **and** rewrites that header on every request. `X-Forwarded-Proto` is an ordinary request header: trusting it unconditionally would let any visitor claim HTTPS on a cleartext request, producing `Secure` cookies the browser will never send back — a session that silently cannot work — and a spurious HSTS header pinning a host with no working TLS. That is a vulnerability, not a convenience, which is why the value is never inferred from the request.

A unit administrator should turn it on when, and only when, the site is reached over `https://` by visitors but the application still emits non-`Secure` cookies and no HSTS header — the signature of TLS being terminated upstream. The setting lives in `config/app.php` rather than in `SettingService` because detection runs before the database is reachable (the setup wizard and the session bootstrap both need it) and because it describes the deployment's infrastructure, not the unit's preferences; `config/app.php` is excluded from every release artifact, so the value survives an update.

No other proxy header is trusted — not `X-Forwarded-For`, not `Forwarded`, not `X-Forwarded-Ssl`. One header, one opt-in. The header can only ever upgrade the verdict to HTTPS, never contradict a SAPI that already reports an encrypted connection, and a proxy chain's leftmost value is the one read.

## 10. Cookie consent

- Cookies categorized: strictly necessary (no consent), functional (consent required), analytics (consent required).
- Consent checked via `CookieConsentService::isAllowed()` before setting any non-essential cookie.
- Consent stored in a strictly-necessary cookie (13-month expiry per ePrivacy directive).
- Cookie declarations aggregated from core + modules — single source of truth for the banner and the preferences page (the RGPD page links to the preferences page rather than duplicating this list).
- **What the offline content cache (`content-{accountScope}-{version}`, `functional` consent) now stores on the device, and what it still never does** (ARCHITECTURE.md §8.25): with consent and while using the installed app, a full copy of every whitelisted page is kept locally — as of this iteration that includes the member's own page and "Mon compte", so the device now holds whatever those pages already show online: the member's own name/photo, their function(s), and — on their page specifically — the full name and postal address of their section's designated responsable (the same data `Core\Member\MemberPageService` already renders for that viewer online, never anyone else's). Every image is the same reduced-resolution derivative the page itself renders (`Core\Photo\ImageVariantService`), never the original. Still never cached, regardless of consent or device: owner-scoped private documents (`Core\File\FileAccessGuard`'s owner-scoping, §6), finance data, mass-mail content, `/inscriptions`, **the content of a discussion group** (see below), and any admin/configuration page — `Core\Offline\OfflineWhitelist` has no entry for any of them, core or module, and a module is expected to apply the same judgment before declaring its own offline page (see `docs/module-development.md`). Writing to this cache is restricted to the installed app itself (`config.standalone`, computed client-side) — an ordinary browser tab visiting a whitelisted page never writes to it, only reads whatever is already there. Purged entirely on logout and on withdrawing this consent (both already true before this iteration, verified by test).
- **Discussion groups are cached one level only: the list, never a conversation** (`modules/groups/module.json`'s `offline` section, pinned by `Tests\Modules\Groups\ModuleManifestTest`). `/groups` — group names, the moderator-written one-line description of what each group is for, which sections they belong to, and how recently each was active — is whitelisted, so the installed app opens offline showing which groups exist and which have been busy. `/groups/{id}` deliberately is **not**: a group page carries the messages themselves, in a space where minors write, plus the photos attached to them, and a full local copy of that is a materially different proposition from caching the member's own account page. Reaching a group offline therefore falls through to the generic "unavailable offline" dialog like any un-whitelisted page. The narrower half of this line is not an oversight to be tidied up later — adding a `/groups/` child entry is a privacy decision, not a config change, and the test named above exists to make anyone attempting it say so out loud.
- **Every cached page is read-only while offline, visibly so** (`public/assets/js/offline-nav.js`). The submit interception that already refused every form unconditionally is now matched by the UI: each form is marked, its submit controls are disabled, and a banner at the top of the page says the version being read is a stored one. Input fields stay editable on purpose — a member may write a message offline and send it when the connection returns, which some composers cache locally for exactly that reason — so the block is on sending, never on typing.
- **"Vu par" answers only the person who wrote the message** (`Modules\Groups\Controller\PostController::seenBy()`, pinned by `Tests\Modules\Groups\Controller\PostControllerTest`). A read mark (`discussion_group_reads`) records that a member opened a group; the group list already uses it for the member's own unread badge, and a post now shows its own author how many others have opened the group since it was published, with the names one click away. Nobody else can ask that question — a moderator included, since moderation is about what was said and not about who was reading — and asking gets the module's usual 404, never a 403. The mark stays group-level on purpose: there is no per-message read receipt, so the system never claims a particular message was read, only that somebody was in the conversation after it appeared. A search a member runs inside a group (`GroupController::search()`) is likewise never journaled — what somebody looked for in a group is as personal as what they wrote in it.
- **A mention resolves server-side, from the stored text, against the group's own membership** (`Modules\Groups\Service\MentionService`). Writing "@Akéla" in a post or a reply notifies that member. The list of who was named is never taken from the request — a caller cannot assert "this message mentions member 42" and have it believed — it is read back out of the body the database now holds, so an edit, a paste, or a message typed with no JavaScript at all resolves identically. The candidate pool is exactly the group's current members, so an "@" naming somebody outside the group resolves to nobody and can neither reach them nor confirm that they exist. The composer's autocomplete (`GET /groups/{id}/mention-search`) is open to any member of the group — it returns names the members page already shows them — and is scoped to that group, never to the unit at large. Recipients are re-checked as current members at dispatch time like every other group notification, and a mention inside a hidden item carries no text.
- **Moderating a group is a power one address holds, not a property of a member** (`discussion_group_members.moderator_user_account_id`, ARCHITECTURE.md §8.40). Two addresses can reach the same member — their own Desk address, plus a secondary one they confirmed — and the flag used to belong to the member, so granting it to either handed it to both. It now names one `user_accounts` row and `GroupAccessService::canModerate()` compares that id against the session's own; a grant that names nobody moderates nothing. Same rule, same reason as the magic link above: a privilege must not flow between two logins because they happen to share a membership. The members page grants per login and spells the address out — it is the one place this module shows one, because two addresses of the same human carry the same name and the name alone cannot say which is being empowered; it is rendered only inside that control, only to a moderator of that group.
- **A poll stores ids, not identities** (`modules/groups/schema.sql`'s `discussion_group_poll*` tables). A poll is a question and a set of answers written by a member, stored raw and escaped by Twig like a post body, never HTML. A ballot is `(poll_id, option_id, voter_key)` and a timestamp, where `voter_key` is the login or the member the poll's own `vote_scope` counts by (`a:{id}` / `m:{id}`) — the `UNIQUE (option_id, voter_key)` index is what makes one answer per voter per option rather than a second row, and a single-answer poll is the same table with the voter's other answers deleted first, in one transaction. Which member an account answers *for* is re-checked against that account's own members on every vote: the form carries a request, never an authority. That set is the group's own reach, never the account's alone (`GroupAccessService::memberIdsAllowedToVoteAs()`): a **section group** offers only the members it holds, exactly like the composer, while **any other group** offers every member the account reaches — a poll counted per member asks a parent about their children, and a family whose four children all reach one address has four answers to give when the unit asks. That second case is wider than the composer's `memberIdsAllowedToPostAs()`, and the widening is a *set of ballots* one login may cast, which is why it is written here and not only in ARCHITECTURE.md §8.40. It widens no gate: the caller has already been confirmed able to write in this group, each ballot is still recorded against a member that account really reaches, and the `UNIQUE` index still holds one answer per voter. It also inherits §32's temporary member override, which enters through `MemberService::getLinkedMembers()` like every other surface derived from it. Cards show tallies and percentages only: who voted for what is never displayed, and no endpoint returns it. Voting is gated on `GroupAccessService::canParticipate()` (the weaker of the two permissions, ARCHITECTURE.md §8.40 — a group where only moderators publish still asks its members questions), so a closed group or a past scout year refuses it exactly as it refuses a reply — which is why a poll carries no "closed" flag of its own that could disagree.

## 11. Event journal

- Every sensitive action logged. No personal data in entries — reference `member_id` only.
- Journal accessible to `chief` role.

## 12. Secrets management

- No secrets in source code.
- `.gitignore`: `storage/keys/`, `storage/config/`, `.env`, `.sonar-token` (the SonarQube Cloud token `scripts/check-sonar-release.sh` may store locally — see §15), and every `storage/<name>/` subdirectory that holds uploaded or generated content (module storage folders, `storage/core/`, `storage/temp/`, etc. — see `.gitignore` for the current, authoritative list). Adding a new storage subdirectory for uploaded content and forgetting to gitignore it has happened more than once in practice; check `.gitignore` whenever a module gains its own `storage/<name>/` folder.
- CI: secret scanner on every PR.
- SMTP and DB credentials in `secrets.enc`, not in `settings`.
- Before the site is initialized, **every** `/setup` endpoint — not just the wizard page — requires the installation token in `token.php` to have been verified this session (`Core\Security` session flag `setup_token_verified`, enforced by `SetupController::denyUnlessTokenVerified()`). A CSRF token is not a substitute: the gate screen issues one to any anonymous visitor so its own form can post, so gating only the page would leave `/setup/save` and friends open to a stranger. The gate applies pre-initialization only — afterwards `token.php` is deleted and the routes are `role_min: superadmin`.

## 13. Desk import security

- Import page: `role_min: admin` (`/admin/import`, `Core\Http\Controller\ImportController`).
- CSV header validation before processing.
- New functions never auto-assigned to elevated roles.
- Journal stores only metadata — never raw CSV content.

### The roster-replacement barrier

A Desk export can be filtered on one section, and an export filtered by
mistake is a valid CSV holding forty of a unit's two hundred and sixty
people. Imported, it deactivates everyone else, empties the Staff
d'Unité, and takes the `admin` role away from the person who launched it
— who then no longer has Import, Configuration or Maintenance to repair
it with. `Core\Security\RoleResolver` consults
`user_accounts.is_super_admin` first, so a super-admin still gets in; a
chef d'unité who is `admin` by Desk function alone does not.

`Core\Import\RosterReplacementGuard` therefore confronts the parsed CSV
with the roster **before the transaction opens** — the same posture as
the header validation, and for the same reason: refuse before writing,
never repair after.

- **Four signals**, counted against the roster of the scout year being
  imported and nothing else: a file naming one section against a roster
  holding several (no threshold, and the signal this exists for); the
  share of the year's active members the file drops (a commented
  constant in the guard, deliberately not a setting — a threshold on a
  configuration page is one somebody lowers the day it does its job);
  the Staff d'Unité disappearing entirely; the importer losing their own
  `admin` access.
- **Refusal is the default, and passing outside it needs a typed
  confirmation** — the French word `REMPLACER`, checked server-side by
  `ImportController`, exactly like Maintenance's `REINITIALISER` /
  `EFFACER` / `RESTAURER`. The browser only decides whether a button
  looks clickable; the gate is the server. The screen states the counted
  consequences by name rather than asking a generic question, because a
  generic « êtes-vous sûr ? » is a thing people learn to click.
- **One hard invariant no confirmation lifts**: an import that would take
  away the site's last administrative access is refused with the word
  correctly typed. It is scoped to the year access is actually resolved
  against, and it only fires when there *was* an administrator to remove
  — a fresh install's first import, which is what creates the Staff
  d'Unité, must not be refused.
- **A refusal is journaled at `security` level with counters only** —
  how many members would go, how many sections the file names, how many
  administrators would remain. Never a name, never a Desk identifier,
  never a line of CSV.
- **A refused file is not kept.** It is deleted like on any other
  failure, which is why forcing the import means depositing it again.

### The kept file

The CSV an import consumed is kept, so a doubtful import can be
investigated by replaying its report against the exact file that produced
it. This revokes a rule that was written down in seven places, and it is
revoked deliberately rather than eroded.

It is also **the most concentrated personal-data artefact in the
system**: names, dates of birth, addresses, telephone numbers, e-mail
addresses, formation level and handicap for the whole unit, in clear, in
one document. Denser than anything else on this disk. The requirements
are set accordingly.

1. **Encrypted at rest, no exception**, through
   `Core\File\EncryptedFileStorageService` — never durably written to
   disk in clear.
2. **The plaintext window is minimal.** `ImportController` writes the
   deposited file to `storage/temp/` with `move_uploaded_file()`; it is
   encrypted as soon as the parse is done and the plaintext is deleted in
   a `finally`, success or failure alike. The clear copy must never
   survive a crash.
3. **`role_min: 'admin'` on the `FileRecord`**, served exclusively by
   `/files/{id}` under `FileAccessGuard`. No direct path, no dedicated
   route, no exception. The rule is restated in code by
   `Core\Import\DeskImportFileOwnershipChecker` (`owner_type =
   'desk_import'`), so it cannot be lowered by an `UPDATE` on one column.
4. **Every successful download is journaled** — `file_id` and the import
   id only, never content. A deliberate extension: `FileController::
   serve()` journals successful accesses only for owner-scoped files
   (§8.3 of ARCHITECTURE.md). This file earns the same treatment, and the
   reason is written where the code is.
5. **No line of CSV in a journal entry, an error message or a trace**,
   including when the parse fails.
6. **The purge is a physical deletion**: the encrypted blob and its
   `FileRecord` both go. No archive, no recycle bin.
7. **The storage subdirectory is gitignored** — `storage/**` already
   covers `storage/imports/` wholesale, which is exactly why that pattern
   is written the way it is (§12: forgetting has happened here more than
   once).

### The retention, in writing

- **Two scout years by default** — the current one and the previous —
  configurable through the `import_retention_scout_years` setting. In
  seasons and not in a number of imports: `fees` needs November's roster
  snapshot for the deposit invoice and February's for the settlement, and
  a count would silently drop November after half a dozen ordinary
  re-imports. The treasurer would find out in June.
- **The purge runs even if nobody imports any more**
  (`Core\Import\Task\PurgeImportsHandler`, daily, self-rescheduling). A
  retention hung off the moment of the next import would keep its GDPR
  promise only while the unit keeps importing — and a unit that stops
  importing is exactly the one whose kept CSVs should stop being kept.
- **The purge is atomic**: the import row, the file and the roster
  snapshot go together, or none of them goes. Half a dossier answers
  nothing, which is what keeping the file was for.
- **A right-to-erasure request meets a tension worth stating rather than
  discovering.** A member's data is also inside the kept CSVs, and a CSV
  cannot be surgically edited without losing the one property that
  justifies keeping it — being the exact file that produced the report.
  The acceptable answer is deleting the whole file concerned, not
  rewriting it.

## 13bis. Merging two member records

`/admin/doublons` (`role_min: admin`) folds one `members` row into
another when a returning member was re-created in Desk instead of having
their old record reopened (ARCHITECTURE.md §8.80).

- **Never automatic.** Two people can share a surname, a first name and a
  date of birth. The site proposes; a human decides.
- **Nothing is deleted.** Foreign keys are repointed and the abandoned
  row is kept, marked `merged_into_member_id` — which is also what makes
  the operation auditable afterwards.
- **`files.owner_member_id` is repointed with the rest**, deliberately:
  a member's private documents are gated on it (§6), so a merge that
  forgot it would leave the returning member unable to open their own
  papers while the abandoned identity still could.
- **Journaled at `security` level with numeric identifiers and counts
  only** — never a name, never a Desk identifier.
- **Detection decrypts names and birth dates in bulk**, so it runs after
  the import transaction has committed, never inside it, and its result
  is stored rather than recomputed on every page view.

## 14. Dependency security

- `composer audit` in CI on every PR.
- Only a small, explicitly justified set of external dependencies — see the table in `ARCHITECTURE.md` §1 for the complete, current list and each one's justification.
- Bootstrap: compiled files, pinned version.

## 15. Static analysis and SonarQube Cloud

- [SonarQube Cloud](https://sonarcloud.io/project/overview?id=xdubois-57_scoutmagic) (`sonar-project.properties`) analyzes every push to `main` and every PR in CI (`.github/workflows/ci.yml`, `sonarqube` job), complementing — never replacing — PHPStan, PHPUnit, `composer audit`, and CodeQL. In CI, authenticated via the `SONAR_TOKEN` repository secret only. For a local release run, `scripts/check-sonar-release.sh` reads the same token from the environment or from a local `.sonar-token` file (gitignored, written only after `git check-ignore` confirms it, mode 600 — see §12). Never committed to source, never logged, in either case.
- The project's Quality Gate must be `OK`; a failing Quality Gate fails the `sonarqube` GitHub check on the PR/commit.
- `scripts/release.sh` additionally runs a dedicated, fail-closed **SonarQube Cloud release gate** (`scripts/check-sonar-release.sh`) before creating any release commit or tag: any active security finding (SECURITY-impact issue, or an un-triaged Security Hotspot), any active finding at severity `HIGH` or above, a Quality Gate that isn't `OK`, or any inability to reach a definitive answer from SonarQube Cloud (missing token, unreachable host, invalid response, unconfirmed analysis for the release commit) blocks the release. `--skip-sonar-gate` bypasses it for genuine emergencies only (prints a warning, same convention as this script's other `--skip-*-gate` flags). See `AGENTS.md` § Releases.

### Dynamic application security testing (OWASP ZAP)

Static analysis reads the code; nothing above it watches the application actually answer a request. `./scripts/dast.sh --profile=passive` closes that gap: it provisions a throwaway install, serves it over real HTTPS, replays the Playwright end-to-end suite through an OWASP ZAP proxy, and fails on any finding at **Medium or above**. ZAP runs as a Docker container and is development tooling exactly as Playwright already is — nothing here is a Composer or npm production dependency, and none of it ships in a release artifact.

**Why the browser suite rather than a spider.** ZAP's spider cannot follow a magic link out of the browser and back through a mailbox, confirm an address, or register a passkey. The end-to-end suite already drives all three, as several signed-in identities, so replaying it is the most faithful picture of this application's real surface that exists. The corollary is worth stating plainly: **the scan sees exactly what the browser suite visits, and nothing else.** A route no scenario exercises is a route no profile examines.

**What a scanner can and cannot tell you here.** It reads headers, cookies, and response bodies, so it is good at the things this document specifies as *properties of every response* — §9's header set, §2's cookie flags, the absence of an anti-CSRF token on a form. It knows nothing about intent: it cannot tell that `role_min` is a floor rather than the whole answer (§3), that a blind index is what makes an encrypted column searchable (§5), or that a file's owner check is fail-closed (§6). A clean passive scan is evidence that the mechanical guarantees hold on the pages the suite visits — never that the reasoning above is correct.

**No baseline of accepted findings.** A finding is either fixed, or silenced by an **alert filter in the plan YAML** (`tests/dast/`) carrying the written reason it is a false positive — never accumulated in a file of things somebody once decided to live with. Today there is exactly one filter: the absence of an anti-CSRF token on `POST /api/webhook/github`, the deliberate exception §4 documents, authenticated by an HMAC signature the scanner cannot see.

Two rules are deliberately left armed rather than filtered: "Cookie Without Secure Flag" and "Strict-Transport-Security Header Not Set". The scanned instance is served over genuine TLS and told so through `X-Forwarded-Proto` (§9's opt-in, enabled on that throwaway instance and nowhere else), so if either fires it is a defect in that wiring, not a false positive. `scripts/dast.sh` refuses to start the scan at all unless it first sees HSTS on the instance's own response.

## 16. Public form protection

- Every public form submitted by a non-identified session goes through `Core\Security\HumanCheck\HumanCheckService` (`ARCHITECTURE.md` §8.31) — no captcha, no external service, no client-side behavioral analysis.
- Three cumulative barriers: a honeypot field, a minimum-delay-since-render check (capped by a maximum form validity age), and a per-IP sliding-window rate limit. An identified session skips all three unconditionally.
- Stateless: the honeypot field name and render timestamp are HMAC-signed (`EncryptionService::blindIndex()`) and carried inside the form itself — never stored server-side, never session-bound.
- The honeypot is hidden via a CSS class only (`.hc-trap`), never an inline style — an inline `style="display:none"` would violate the CSP (§9). Never `type="hidden"`, which a bot's form-filler skips over; `tabindex="-1"`/`aria-hidden="true"` keep it unreachable by keyboard or screen reader.
- The IP address used for the rate-limit counter is personal data: it is hashed (HMAC, the same blind-index technique as an encrypted field's exact-match lookup) before being stored in `human_check_rate_limits`, and the raw address is never written to that table or to the journal.
- A rejection never reveals which of the three barriers triggered — same generic French message regardless of reason, so a bot can never learn what defeated it. A rejection also never loses the visitor's input: the form is re-rendered with a fresh challenge, never a dead-end error page.
- Every rejection is journaled as `human_check_failed` (`level: security`, context limited to which form was involved — no IP beyond the journal's own standard `ip_address` column, no honeypot content, nothing else that could identify the submitter).
- A magic-link request (`POST /login/magic-link`) applies only the honeypot and minimum-delay barriers — `AuthService::requestMagicLink()` already rate-limits by email blind index (§8); a second, IP-scoped counter for the same abuse pattern would produce inconsistent thresholds. See `ARCHITECTURE.md` §8.31 for the full reasoning.
- The "Mot de passe oublié" request (`POST /password-reset/request`) applies the **full** barrier set, including the per-IP rate limit. Unlike magic-link, its only downstream throttle is per-email — which a loop over random addresses never trips — and each accepted request pays a `password_hash()` and writes a token row before the account is even looked up, so an unthrottled endpoint is an unauthenticated bcrypt/row-growth sink. The per-IP barrier stops that enumeration loop at the front door; a tripped check returns the same generic success as everything else, so nothing about an address is revealed either way.
- **A public form never becomes a content mail relay.** A confirmation email lands at a form-supplied `contact_email` and is sent From the unit, DKIM-signed with its real key — so echoing the submitter's own free-text answers back to that address would let an anonymous visitor deliver arbitrary text to an arbitrary third party, authenticated as the unit's domain. On an **anonymous** submission the answer echo is dropped: the confirmation carries only unit-generated content (the article title and a payment summary), never the attacker's text. An identified member's submission — traceable and rate-limited — still gets the full confirmation. Attacker-supplied free text is also kept out of `Subject` headers (a social-engineering surface).
- **Self-service secondary email is bounded.** A member may hold at most a fixed number of self-added addresses, checked before any confirmation email is sent, so a loop over unique addresses can't become a mail-bomb; the address-domain check is a single best-effort DNS lookup (the previous retry-with-`usleep` loop that held a worker ~600ms on a junk domain — a request-amplification lever — is gone), and it runs only for a genuinely new, within-cap address so a capped member pays nothing.
- **The public retro throttle keys on the client IP**, hashed (blind index) exactly as HumanCheck stores an IP — not on the anonymous cookie/session id, which the client picks and could discard to mint a fresh bucket and call the paid LLM "shorten" endpoint without limit.

## 17. Outbound HTTP requests to user-supplied URLs

Any feature that fetches a URL a member typed in — an album's external link, a shared link preview — is making a server-side request to an address this project does not control, from inside the hosting network. That is textbook SSRF surface: a crafted URL could otherwise reach an internal service (a database, a cloud metadata endpoint, an admin panel bound to localhost) that was never meant to be reachable from outside.

- `Modules\Gallery\Service\OgScraperService` is the **only** place in the codebase allowed to make this kind of request. Every feature that needs one — the gallery external-album og:tags/og:image fetch, and `groups`' link-preview posts — goes through it via `Modules\Gallery\Api`, never a second HTTP client rolled independently. Consuming modules never see raw bytes off the wire; they get parsed tags or a stored `files` row.
- Every resolved address is checked before a connection is opened, not just the literal host in the URL: every A/AAAA record a hostname resolves to must be public, or the whole request is refused — loopback, RFC1918/RFC4193 private ranges, link-local (including the cloud-metadata address `169.254.169.254`), unspecified, and multicast are all rejected, for both IPv4 and IPv6 (an IPv4-mapped IPv6 literal is normalised and re-checked too).
- Only the scheme's default port is allowed (`80`/`443`) and a URL with credentials embedded (`http://user:pass@host/`) is refused outright — both are common SSRF and internal-service-targeting tricks, not something a best-effort scraper needs to support.
- Redirects are followed by the scraper itself, never delegated to the underlying HTTP client — each hop is re-validated exactly like the first (fresh DNS resolution, fresh private-range check, fresh port/credential check) under a fixed hop cap, so a URL that passes validation and then 302s to an internal address is still caught.
- The validated IP is what the connection actually targets (with the original hostname sent only as the `Host` header and, over TLS, as SNI/certificate name) — connecting to a second, later DNS answer for the same hostname is never possible, closing the DNS-rebinding gap a naive "resolve once, connect by hostname" check would leave open.
- The whole redirect chain shares one fixed timeout budget, not one per hop, so a chain of slow responses cannot add up to an unbounded wait.
- Response `Content-Type` is checked against what the caller actually expects (HTML for tag scraping, an image for the cached preview) before the body is used for anything.
- Every rejection reason — private address, bad port, embedded credentials, too many redirects, wrong content type, a genuine network failure — collapses to the same outward result (a generic "could not fetch" message, or a silent fallback to a plain link/no image). Nothing here is ever distinguishable from outside, and nothing here — resolved IP included — is ever written to `JournalService` or any log: that would itself be the kind of internal-network fingerprinting this section exists to prevent.
- A failed or slow fetch never blocks the action that triggered it: an album still saves, a post still publishes — just without the cached title/description/image, or as a plain link.
- Outbound fetches are throttled per member (same `identifier_hash`/short-lived-table/scheduled-purge shape as `retro_rate_limits`, §1) — a member spamming links cannot turn this into an SSRF probing tool or a way to hammer an arbitrary third party through the server.

### Configured endpoints handed to a library HTTP client

A second SSRF surface is a URL the user *configures* rather than one they ask the server to fetch on the spot: a Web Push subscription endpoint (any identified member), and — superadmin-only — an LLM API endpoint and an S3-compatible storage endpoint. These are POSTed/connected to server-side by a library client (WebPush, the AWS SDK), so a crafted value could target an internal service exactly as a scraped link could.

- **`Core\Security\SsrfUrlValidator` is the single guard**, sharing the exact private-range/IP logic the scraper proved out (factored out so the two never drift). It enforces `https` only (no `http://`, which would also send S3 credentials in plaintext), no embedded credentials, and that **every** address the host resolves to is public (loopback, RFC1918/RFC4193, link-local incl. `169.254.169.254`, and multicast all refused, IPv4 and IPv6). It is applied before the endpoint is stored **and** re-checked on use, so a host that resolved public when saved but internal later (DNS rebinding) is still caught.
- **The Web Push endpoint** is validated before it is ever stored, which also neutralises the delete-on-404/410 behaviour as a port/path oracle, and the push client carries a bounded connect/read timeout.
- **What it does not do**: it validates the endpoint, it does not pin the resolved IP for the library client's own later connection — that residual DNS-rebinding window is why the check runs again at use time rather than only at save time. The LLM/S3 endpoints are superadmin-only, the highest-trust role.
- **The statistics destination** (`statistics_destination`, superadmin, ARCHITECTURE.md §8.47) belongs to this family — a configured URL this installation POSTs to server-side, carrying its own bearer secret in a header — and gets an equivalent but deliberately *structural* check rather than `SsrfUrlValidator`: `https` only, and a host that is a real public name (no `localhost`, no bare IP literal, no single-label name, no `.local`/`.test`/`.localhost`/`.invalid`/`.internal`), enforced both when the setting is saved and again before every send. The difference from the three above is why: this one runs inside a background scheduled task on hosting where DNS may be slow or unavailable, and a resolving check would also put the test suite on the network. The value is superadmin-typed, and re-checking at send time is what catches one arriving from a restored backup rather than from the form.

## 18. Sending member-written text to an AI provider

Two modules ask an LLM to look at text a member typed: `retro` (comment moderation and board summaries) and `groups` (a-priori moderation of posts and replies). In both cases the text leaves the hosting network for a third party the unit chose, which makes that provider a sub-processor and makes the exact contents of what is sent a matter of record — not an implementation detail.

- **The call is optional and degrades to nothing.** Each consumer takes `LlmConnectorInterface` as a *nullable* dependency (ARCHITECTURE.md §7.5) and is wired only when `llm_connector` is enabled. With the module off, or with no active provider, no request leaves the server and the feature behaves as if it had never been asked for.
- **An admin switch that really switches off.** `groups_ai_moderation_enabled` (default on) is read inside `Modules\Groups\Service\ModerationService::isAvailable()` — the single gate every caller passes through — so turning it off means no request is made at all, not a request whose verdict is discarded. A switch that still sent the text would be worse than no switch.
- **Fail open, never closed.** No provider, a disabled module, a timeout, an exception, a malformed or schema-violating answer: every one of them means "published without checking". Only an explicit `flagged: true` refuses anything. A courtesy check that fails closed would let a provider having a bad day silence a whole group — and the real protection is member reporting plus a moderator's judgement, which keep working whatever the AI does.
- **The timeout is tight and deliberate.** The call sits inside the member's own publish request, so it uses a timeout well below the provider's default (8 s for `groups`) rather than holding the page open behind a spinner.
- **The member's text is the prompt, never spliced into the system prompt.** That is what keeps a message containing something like "ignore les instructions précédentes" from reading as an instruction rather than as the text under review.
- **A refused message is never stored, journaled or logged.** Its text and the AI's suggested rewording travel back to the person who wrote it — through their own session, for exactly one page render (`Modules\Groups\Support\RejectedDraft`) — and nowhere else. The journal records that moderation happened, by id, never what was said. Journalling the refused text would create a durable, chief-readable record of things the site explicitly refused to publish, which is precisely the record nobody asked for (§11).
- **Rate limiting comes first.** The per-member limit is checked and spent *before* the provider is asked, so a runaway script or a stuck submit button cannot turn a member's keyboard into a stream of billable third-party calls.
- **Disclosure follows the provider, not the module.** The configured provider, its models, its location and its privacy policy are what `Core\View\RgpdContentService` discloses; a module that only consumes `LlmConnectorInterface` adds no new sub-processor of its own.

## 19. Notification payloads

A push notification is the one thing this site sends that renders **outside** its own access control: it lands on a lock screen, is readable by whoever is holding the phone rather than by whoever is logged in, and stays there after the thing it describes has changed or gone. Core already narrows what a payload can carry — discretion mode (`user_accounts.notification_discretion`) substitutes a generic title and an empty body at push-composition time, and `notifications.title`/`body` are encrypted at rest — but what goes *into* a payload is the sending module's decision, and these rules are the module's.

- **Never more than the recipient can already see.** The audience of a notification about a private group is that group's membership, resolved by the module (`ARCHITECTURE.md` §8.24) — `role_min` is a floor `dispatch()` re-checks, never the audience. Handing `dispatch()` every account id for a type whose real audience is a membership would put a private group's content on the whole site's phones.
- **Never the text of content the site has hidden, refused or flagged.** An item hidden by member reports has been taken out of the feed for everyone but the group's moderators; a notification quoting it would be a way around that, and one that outlives the hiding. `Modules\Groups\Service\GroupNotificationService::excerptOf()` is the single place that decides, and it substitutes a neutral French sentence rather than the text. An AI-refused item never reaches a payload at all — it is never stored (§18).
- **Never anything about who reported what.** The report notification names neither the reporter nor the reported text: a moderator opens the item to judge it, which is what the deep link is for, and the reporter is never revealed to anyone (§11's ids-only rule, applied to a second surface).
- **A deep link is a real route, not a fragment.** `#post-123` is resolved by the browser and never reaches the server, so a forwarded notification link would render the page to somebody outside the group before failing to scroll. `GET /groups/{id}/posts/{postId}` re-checks membership — and the item's hidden state — before redirecting, and answers 404 (never 403, which would confirm the group exists) otherwise.
- **A notification never blocks or reverses the action that triggered it.** Every dispatch is wrapped: a post that published perfectly well is not rolled back because a push could not be queued, and nothing about the message's text is logged when it fails.

## 20. Self-update integrity

The self-update path (`Core\Maintenance\Task\InstallUpdateHandler`) downloads an archive and unpacks it over the live PHP tree, so *where* it downloads from is as security-critical as any code in the repository. Two GitHub webhook events feed it — a published release and a dev-mode branch push (`Core\Maintenance\GitHubWebhookService`, §4's signed exception) — and both carry the download URL and the source repository as free-form JSON.

- **The download URL must be a GitHub `https` URL.** `Core\Maintenance\GitHubUrlValidator` is the single allowlist: `https` scheme plus a host in a fixed set (`github.com`, `api.github.com`, `codeload.github.com`, `objects.githubusercontent.com`, `release-assets.githubusercontent.com`). It is checked in three places — before the URL is ever cached from a webhook payload (`processRelease()`), before the first byte is fetched (`download()`), and again on **every redirect hop**.
- **Redirects are followed by hand, never delegated to the HTTP client** (`follow_location => 0`). A GitHub download legitimately redirects across GitHub's own hosts (`api.github.com` → `codeload`, `github.com/…/releases/download` → `objects.githubusercontent.com`), but a redirect to any other host aborts the download rather than being followed — the same posture as the SSRF scraper's per-hop revalidation (§17), under a low fixed hop cap.
- **The webhook event must name the configured repository.** Both handlers gate on `isConfiguredRepository()` — `repository.full_name` compared case-insensitively to `update_github_owner`/`update_github_repo` — so a validly-signed event for an attacker-owned repository (whoever holds the webhook secret and can push to *some* repo) can never point the updater at that repo's code or zipball.
- **A refused release never poisons the manual button.** `update_download_url` (read by the manual "Installer maintenant" action) is written only once a release is confirmed newer *and* its URL passes the allowlist — never straight from the payload, which previously let an ignored/older release overwrite it.
- **What this does not close, and what would.** This authenticates the *source* of the artifact, not its *contents*: a compromise of the configured GitHub repository, or the webhook secret plus push access to it, still yields a trusted install. Fully closing that requires a **signed release** whose signature is verified before extraction — the remaining, larger piece of work, tracked as a known gap here rather than silently assumed away.

## 21. Backup restoration safety

An automatic rollback (and the manual "Restaurer" action) extracts a backup ZIP over the install tree, so a crafted archive is a code-execution vector if extraction is naive. Restore is `superadmin`-only, and the archive is validated entry-by-entry before a single file is written.

- **Restore routes are `superadmin`, not `admin`.** `update/install`, `reset/settings`, `reset/full`, and `reset/restore` were raised to `role_min: superadmin` — an `admin` could otherwise reach a code-overwriting operation and effectively escalate past `superadmin`.
- **Zip-slip and symlink-escape are rejected before extraction** (`Core\Maintenance\BackupService::assertArchiveEntriesAreSafe()`, run before `extractTo()`): absolute paths, Windows drive prefixes, any `..` segment, Unix symlink entries (detected from the entry's external attributes), and anything outside the fixed set of restorable top-level entries (`core`, `modules`, `public`, `storage`, and `database.sql`) all abort the restore.
- **Zip-bomb guard.** The archive's total uncompressed size is capped, so a small malicious archive cannot exhaust the disk during extraction.

## 22. Fatal-error handling

`Core\Http\ErrorHandler` is registered as the process-wide exception, error, and shutdown handler (`public/index.php`), immediately after the autoloader and again once configuration is loaded so it is armed even for a failure during bootstrap itself.

- **No stack trace or credential ever reaches the browser.** `display_errors` is forced off in production; an uncaught throwable or fatal produces a self-contained, hardcoded French 500 page that touches neither Twig nor the database (either of which may be the very thing that failed). The exception detail is written to the error log, never to the response — it is shown in the page only when the app is explicitly in debug mode, and HTML-escaped even then.
- **The 500 page still carries the full security-header set** (§9) — an error response is not an excuse to drop `Content-Security-Policy` or `X-Frame-Options`.
- **Not every uncaught throwable belongs here.** A write the database refused because of the values the request carried is a client error, not a crash, and is answered as one before it ever reaches this handler — see §35.

## 23. Spreadsheet export safety

Values that originate from **unauthenticated public input** — public form answers (`modules/news`, `POST /news/{id}/form/submit` is `role_min: public`) and finance movement labels — are later written into XLSX exports an admin opens in Excel/LibreOffice. A leading `=`, `+`, `-`, or `@` in such a cell is interpreted as a formula (CSV/formula injection).

- **Untrusted cells are written as explicit text**, never as general values: `setCellValueExplicit(..., DataType::TYPE_STRING)` for every attacker-influenceable string column, so a spreadsheet application never evaluates a submitted answer or label as a formula. Genuinely numeric columns (amounts) are written as `TYPE_NUMERIC`, and the one deliberate, code-controlled payment total remains a real formula built from constants — never from user input.

## 24. Non-web entry points

`public/cron.php` is the scheduler's command-line entry point (`ARCHITECTURE.md`'s poor-man's-cron). It runs privileged maintenance work with no session and no RBAC, so it must never be reachable over HTTP.

- **CLI-only, enforced in code and in config.** The script's first executable statement refuses any non-CLI SAPI (`PHP_SAPI !== 'cli'` → `404` and exit), before the autoloader or the scheduler is ever touched. Defense in depth: `public/.htaccess` and the bootstrap-generated `.htaccess` (`bootstrap/bootstrap.php`) also `Require all denied` for `cron.php` on Apache — but the in-code guard is the authority, since `.htaccess` does not apply on nginx.
- **The migration-step endpoint** (`POST /api/system/migration-step`) runs live DDL and is reachable before any session, CSRF token, or routing exists — for the whole upgrade window. It has no session-bound token to check, so it instead requires a custom request header (`X-ScoutMagic-Migration`) that only the update-progress page's own `fetch()` sets. A cross-site page cannot set a custom header on a simple request without a CORS preflight this endpoint never grants, so a forged cross-origin POST is refused — the same reasoning as a classic `X-Requested-With` guard, chosen because it needs no server-side state mid-migration.

## 25. Image decode limits

Decoding an image allocates roughly width×height×4 bytes regardless of how small the compressed file is, so a "decompression bomb" — e.g. a ~500 KB 40000×40000 PNG asking GD for ~6.4 GB — can OOM-kill the request (or the background photo task) before any downscaling runs. Reachable by any member who can upload.

- **A pixel-dimension ceiling is checked before every decode** (`Core\Image\ImageDimensionGuard`, 50 megapixels): a cheap header-only read (`getimagesize`/`getimagesizefromstring`) rejects an oversized image up front, at every GD decode site — the generic `/upload` path, all core photo processors, the gallery photo task, and the finance receipt orientation step — never after allocating for it. The ceiling clears any real photo a phone or camera produces.
- **The PDF rasterizer** (`Core\File\PdfRasterizer`, Imagick → Ghostscript) can't use `getimagesize`, so it caps Imagick's memory/disk/width/height resource limits before reading the page; an untrusted PDF that would rasterize to a huge canvas aborts into a graceful "no thumbnail" instead.
- **The public PDF-thumbnail endpoint** (`GET /files/{id}/thumbnail`, `role_min: public`, gated per file by `FileAccessGuard`) caches the rendered JPEG on disk keyed by the immutable file id, so a repeated hit serves a static file instead of re-running Imagick/Ghostscript — closing the repeatable CPU/RSS sink. The cache is written **only for non-encrypted files**: caching an encrypted file's thumbnail as plaintext would defeat encryption-at-rest, and an encrypted file is never anonymously reachable (its `role_min` gates it to intendant+), so its render cost is bounded by authorised users. **Deploy note**: Imagick shells out to Ghostscript, which can't be passed `-dSAFER` from PHP the way `Core\Pdf\PdfCompressor` does — ship a restrictive ImageMagick `policy.xml` (deny the `PDF`/`PS`/`URL`/`MSL` coders beyond the intended read, cap resources) on the host so an unpatched Ghostscript isn't a `-dSAFER`-bypass surface.

## Serving large files

`Core\Http\Response` can stream its body from a file on disk (`setBodyFile()`, `readfile()` at send time) instead of holding it whole in memory. Without it, a large download had to be materialised as a PHP string first (a ~1 GB RSS spike for a gallery ZIP, up to a 2 GB video read into a string).

- **Plain files stream.** `GET /files/{id}` for a non-encrypted file, and the no-`Range` gallery-media fallback for a local object, stream straight off disk; the finished gallery ZIP is streamed from its temp file (and the Response deletes it after sending) rather than read back into a string. `Range` requests for media were already served in capped 8 MB windows.
- **Encrypted files can't stream, so they're capped.** An AES-256-GCM blob authenticates the whole file against one tag, so the plaintext must exist whole in memory at least once — there is no way to emit verified bytes incrementally without reframing the storage format. Instead the ciphertext size is checked up front and a file past a fixed ceiling is refused, bounding the memory one request can force. Real encrypted files (receipts, documents) are capped far lower at upload.

## 26. Internal redirects only

Several flows echo a `return`/`return_url` parameter or the `Referer` header back into a `Location:` redirect or an `href`. Left unvalidated, `//evil.example` turns that into an open redirect — a phishing primitive that borrows the unit's trusted domain.

- **`Core\Http\SafeRedirect` is the single place that decides what counts as internal.** `internalPath()` accepts only an unambiguous same-site absolute path (a single leading `/`, no scheme/host, no scheme-relative `//` or backslash trick, no control characters) and collapses anything else to a safe fallback. `internalPathFromUrl()` reduces a possibly-absolute `Referer` to its own path so a cross-origin referer can at most redirect within our own site, never off it. Applied to `/upload`'s `return_url` (reflected into both an `href` and the post-upload redirect) and to `ConfigModeController`'s `Referer`-based redirects.

## 27. Uploaded-file type detection

- **The declared MIME type of an upload is never trusted.** The main path (`Core\File\UploadHandler`) reads the type from the file's real content (`finfo`) and validates it against a per-caller allowlist. The secondary upload handlers (section documents, finance receipts and movements) do the same and, on a detection failure, resolve to a non-allowlisted sentinel — never the client-declared `$_FILES['type']`, which is attacker-controlled and would otherwise be stored in `files.mime_type` and reflected as the response `Content-Type`, bypassing the allowlist.

## 28. HTML built in client-side scripts

A few list pages build table rows in an inline `<script>` and interpolate values into attributes (`alt`/`title`/`data-*`). A `textContent`→`innerHTML` escaper handles `& < >` but **not** quotes, so a value containing `"` could break out of a double-quoted attribute and smuggle further attributes (script execution stays blocked by the nonce CSP, but `style=` injection does not). The escapers that feed an attribute context (finance receipts, mass-mail lists) escape quotes explicitly, so a filename, description, or LLM-extracted date can no longer break out. Server-side, this is a non-issue: Twig autoescapes every template value.

## 29. Request-body size limits (chunked uploads)

`.user.ini` limits are per-directory and every route runs through `public/index.php`, so `post_max_size` is **document-root-wide**: a ceiling big enough for a 2 GB gallery video also applied to `/login`, `/password-reset` and `/inscriptions` — and PHP buffers a urlencoded body into **memory** before application code runs, which made the global ceiling an anonymous memory-DoS budget.

- **The global limits are small** (`public/.user.ini`: `upload_max_filesize 32M`, `post_max_size 48M`), sized for the largest remaining single-POST consumer (section documents, 20M) plus multipart overhead. `tests/Security/PostSizeLimitAuditTest` pins them so they can't creep back up.
- **The two large consumers upload in ~8 MB chunks** through their normal, RBAC/CSRF-guarded routes (no new entry point, no second bootstrap of session/auth): gallery media (`POST /gallery/{id}/media` with an `upload_id`) and the backup-restore archive (`POST /config/maintenance/restore-upload-chunk`, superadmin). `assets/js/chunked-upload.js` slices client-side; `Core\File\ChunkedUploadStore` reassembles server-side.
- **The store trusts nothing from the client.** The on-disk name is `sha256(session_id : upload_id)` — the client-chosen id can never be a path and one session can never touch another's partial. Chunks append strictly in sequence under an exclusive lock (a duplicate or out-of-order chunk gets a 409 carrying the real size, so a client can resume). The assembled size is capped **while it grows** against the caller's own ceiling — withholding the "last" flag doesn't buy unbounded disk — and authorisation/CSRF are re-checked on every chunk, so an unauthorised caller can't consume temp space either. Abandoned partials are purged after 24 h.
- **The finished file goes through the exact same validation as a single-POST upload** (real-MIME allowlist, per-type size ceiling, `Core\File\UploadHandler`): chunking changes how bytes arrive, never what is accepted.
- The no-JS fallback for the restore form still works as a classic multipart POST for archives up to the global limit; larger archives need the JS chunked path.

## 30. Bearer tokens at rest, ciphertext binding, and blind-index domains

Three related cryptographic-hygiene layers, all sharing one goal: a database read (SQL injection found later, a stolen backup, a mis-permissioned dump) must yield as little usable material as possible, and a database write must not be convertible into a decryption oracle.

- **Long-lived bearer tokens are encrypted at rest with a blind index for lookup** — calendar ICS feed tokens (`calendar_personal_tokens`, `calendar_unit_feed_token`, per-calendar `calendar_calendars.ics_token`) and retro board tokens (`retro_boards.token`). These live in URLs the user re-displays repeatedly (a feed added to a calendar app; a board share link), so a plain hash — retrievable only once — would break them; encrypt-at-rest + HMAC blind index (the `user_accounts.email` pattern) keeps the URL displayable while the DB never holds plaintext. Lookups verify the decrypted value with `hash_equals()` after the blind-index match. `short_urls.target_url` is encrypted too: retro share links and news edit links are shortened, so the plaintext target column would otherwise hand back the very tokens the other columns now protect. Short codes themselves stay plaintext — they are deliberately short/typeable (low entropy), and using one requires hitting the live site where requests are rate-limited and journaled, unlike an offline DB read.
- **Every `encrypt()` call passes a context string** (`"table.column"`, or a purpose label like `backup_password` for non-DB uses) bound into the AES-GCM tag as additional authenticated data. A ciphertext relocated into a different column or table by an attacker with DB write access — e.g. an LLM API key copied into a user-visible label column to read it back — fails authentication instead of decrypting. Limitation, accepted: the context is per-column, not per-row (row ids don't exist before insert), so swapping two ciphertexts *within* the same column is not detected cryptographically.
- **Every `blindIndex()` call passes a purpose label**, domain-separating index kinds (`email`, `address`, `registration_email`, `finance_iban`, `login_ip`, `push_endpoint`, `news_contact_email`, token purposes, retro vote/rate-limit identities, human-check signatures). The same plaintext under two purposes yields unlinkable indexes. Indexes that are *deliberately* compared across tables share one purpose — the email identity spanning `user_accounts`/`member_years`/`member_emails` is a single `email` domain, because the app joins on it by design.
- `SecretManager` (master key → `secrets.enc`) intentionally keeps its own self-contained AES-GCM: it protects exactly one JSON document at one path, bootstrap-ordered before `EncryptionService` exists, with no relocation surface.

## 31. Email confirmations act on POST, never GET

Mail scanners, Outlook SafeLinks, and chat-app prefetchers follow every GET in an email — a confirmation that fires on GET gets consumed by a bot before the human clicks, silently confirming addresses (and burning single-use tokens). Both email-confirmation endpoints (`/members/emails/confirm/{id}`, `/inscriptions/suivi/emails/confirm/{id}`) now follow `UnsubscribeController`'s shape: the GET verifies the token **without consuming it** and renders a confirm page; only the page's POST (token in a hidden field, re-verified, single-use) mutates. As with unsubscribe, the bearer token is the authentication — these are anonymous flows with no session to bind a CSRF token to.

## 32. Temporary member addition (accepted risk)

The temporary member override (ARCHITECTURE.md §8.41) lets an admin (chef d'unité) add one member to their own list of animés for the lifetime of their session, in order to see the site as that member sees it and act on their behalf. It is a support tool, and it is a deliberate, documented weakening of three boundaries that were previously written down as having no chief/admin bypass. They are recorded here as accepted risk, not as oversights.

**What it does not do.** The session's RBAC role never changes: the admin keeps their own role and route access, and no route's `role_min` is evaluated any differently. Nothing is written to the database — the override is a bare `member_years.id` in the session, cleared on logout, on removal, and whenever configuration mode is deactivated. It is refused outright for any session that is not currently admin, re-checked on every call rather than once at activation, and it only ever applies to the account that set it (asking about a third party's email returns nothing).

**The three widened boundaries**, each reachable only while an override is active:

- **Owner-scoped files** (§6, `FileAccessGuard`): the member's private documents become readable, because `$linkedMemberIds` derives from `MemberService::getLinkedMembers()`.
- **The member's own photo upload** (`MemberService::isLinkedToMember()`): reachable, where previously only the member themselves could replace it.
- **`MemberService::canAccess()`**, which decides `MemberController::show()`'s `$isSelf` and gates `MemberEmailAddressController`: the member page renders its owner-only half, and the member's **secondary email addresses can be added, deleted, and reactivated** on their behalf. Since an active email address is a login identity, this is the sharpest edge of the three.

**A narrow privilege escalation is possible and accepted.** `MemberService::isUnitChief()` derives from `getLinkedMembers()`, so an admin whose own membership is not a chef d'unité function — an account granted the admin role some other way — can temporarily add a chef d'unité and thereby satisfy that check, which gates retro board moderation and the banner module's configuration. The actor must already hold the admin role, so this widens what an admin can do rather than letting a non-admin in. It was accepted knowingly rather than closed by restricting the feature to members without a staff function.

**What limits the damage.** Every activation and removal is journaled at level `security` (`temporary_member_added` / `temporary_member_removed`) with the acting admin's `user_account_id` and the `member_year_id` — never a name or an email — so any action taken under an override can be tied back to the real admin afterwards. A permanent, unmissable banner (*Vous agissez au nom de X*) is rendered on every page while it is active. And the offline manifest deliberately excludes the temporary member, so no data belonging to them is ever written to the admin's device where it would outlive the session.

**If this needs tightening later**, in rough order of value: refuse the override for members holding a staff function (closes the escalation above); exclude the temporary member from `canAccess()` so secondary email management stays owner-only; add a TTL so a forgotten override expires on its own.

## 33. Rental capability tokens and inbound mail

Two new capability tokens and one new class of untrusted file arrive with the rentals and inbound-mail modules. Both follow §30's rules rather than inventing their own; what is written here is what is specific to them.

**The renter's tracking token, and the renter's ICS token, are the same token.** A renter has no account: possession of the URL in their acknowledgement email *is* the authorisation, for their tracking page and for the one-event calendar feed that page offers. It is `bin2hex(random_bytes(32))`, **stored only as a `password_hash()`**, and therefore not recoverable — a lost email is answered by issuing a new one, which invalidates the old. It is never journaled (a journal entry carrying it would be a permanent, readable credential), never shown to anybody but that renter, and never reaches a second booking. Unlike the calendar feed tokens in §30 it is hashed rather than encrypted-with-a-blind-index, because it is re-displayed from an email the renter already has rather than from a page the site must re-render.

**A token gates its own booking and nothing else.** Every route that takes one takes the booking id beside it and verifies the pair; a valid token against another booking's id is a 404, identical to an unknown reference, so the endpoint is never an oracle for which bookings exist.

**Calendar and ICS privacy is applied before serialisation, never by a template.** An ordinary reader's event and a manager's event are built by two separate methods, so the detailed rendering does not exist for an unauthorised reader to reach through a JSON payload, a tooltip or an ICS line. A test asserts the two builders remain distinct, because collapsing them into one with a flag is the natural refactor and the wrong one.

**Every inbound email is untrusted input from anybody who knows the address.** HTML is sanitised once on the way in and stored sanitised — never sanitised on render, which would put the rule in every future template. **Remote images are removed rather than proxied**: a hidden image in a stranger's email is a read receipt, and proxying still fetches it. Attachments go through `UploadHandler` (real MIME sniffed from the bytes, generated storage name, EXIF stripped, outside `public/`, behind `FileAccessGuard`) against a strict allowlist that admits no archives and nothing executable, and the type an email *claims* decides nothing.

**Nothing is ever written to a remote mailbox.** `IncomingMailboxClientInterface` declares only `connect`, `disconnect`, `listFolders`, `folderState` and `fetchSince`; there is no `markSeen`, `move`, `delete` or `createFolder` to call. Bodies are fetched with `FT_PEEK` and folders opened with `EXAMINE`, and a source-level test asserts the IMAP implementation contains none of the calls that would write. Mailbox credentials are encrypted at rest, live on their own object the repository loads only at connection time (so they are structurally absent from listings and rendered stack traces), and are never redisplayed — a blank password field on save keeps the stored one. **An invalid TLS certificate fails the connection**, and there is no configuration path to an unencrypted session or to skipping the check. Failures are recorded as sentences written for an operator, never a library's own exception text, which routinely carries the account name and the server's verbatim rejection of a credential.

**A consumer module never gets arbitrary mailbox access.** Every method of `Modules\InboundMail\Api\InboundMailInterface` is scoped to one consumer id and one business reference; there is no `findAll()`, no `findByMailbox()` and no `search()`, and that absence is the enforcement. A manager who may open a booking does not thereby gain a window onto the unit's correspondence.

## 34. Deferred hardening (known, tracked)

The remaining audit items are understood and intentionally deferred — each is a UX-changing product decision or a broad template rework whose cost currently exceeds the risk it retires. Documented so they are tracked, not forgotten.

- **Magic-link and email-confirmation tokens travel in the query string** (`AuthService`, `MemberEmailService`), unlike the password-reset token which rides in the URL **fragment** (never sent to the server, so it stays out of access/proxy logs and `Referer`).
  *Not fixing exposes:* a token logged in server/proxy access logs — readable by hosting staff or leaked log aggregation — during its short single-use lifetime. Narrow window, dies on first use, and the §31 confirm-page shape means a log-replayer now lands on a page rather than consuming anything.
  *Fixing costs:* the fragment shape needs a JS hop (`location.hash` → POST), which breaks no-JS clients and some in-app mail webviews that mangle fragments — a real regression risk for a parent audience on mobile mail apps. Deferred as low residual value after §31; revisit only if log exposure becomes a concrete concern.
- **CSRF token rotation on login/logout.** `CsrfGuard::generateToken()` reuses an existing session token, so a token minted for an anonymous visitor carries across the login boundary.
  *Not fixing exposes:* nothing currently exploitable — `session_regenerate_id(true)` changes the session id on login, `use_strict_mode` blocks fixation of attacker-chosen ids, and `SameSite=Lax` blocks the cross-site POST that would use a stolen token; every path to exploiting the non-rotation is closed by another layer.
  *Fixing costs:* clearing the token on `AuthSession::login()` is correct for the real flow but collides with the large body of controller tests that use `login()` as a session-setup shim after preparing a CSRF token; the fix pairs the rotation with a test-helper rework. Belt-and-braces only — revisit if `SameSite` is ever loosened.
- **Inline style ATTRIBUTES** — `style-src-attr 'unsafe-inline'`. **The element half of this item is now closed** (§9): `style-src-elem` allows nothing but a nonce, so an injected `<style>` block — the primitive that restyles the *whole* page — is refused outright on every browser that understands the directive. What remains is the narrower half: roughly **260** `style="…"` attributes across **~90 templates** set computed geometry (progress-bar widths, section colours) and static presentation, and `style-src-attr` still permits them. The count is an order of magnitude above the "roughly thirty" this entry used to claim — it was measured, not estimated, when the dynamic scan (§15) put a number on it.
  *Not fixing exposes:* nothing by itself — it is an **amplifier** for a future attribute-injection bug (attacker-controlled CSS on one element: overlay/clickjacking tricks; script execution stays blocked by the nonce CSP). The known attribute-injection bugs are fixed and regression-tested (§7, §28). Note also that on a browser predating `style-src-elem` (Safari before 26.2) the element half falls back to `style-src`, so *there* the amplifier is still page-wide.
  *Fixing costs:* the ~229 **static** attributes are mechanical — they move to classes, though a class can be overridden where an inline style could not, so each is a small cascade decision. The ~32 **computed** ones have no cheap home: a nonce'd `<style>` block collected per request is the only shape that keeps working without JavaScript, and it has to be emitted after the content that registers it. Neither half has any test coverage of rendered geometry, across ninety pages nobody can eyeball in one sitting. Chip away opportunistically — when a template with inline geometry is touched anyway, convert it; `style-src-attr` drops `'unsafe-inline'`, and the `style-src` fallback with it, once the last one is gone.

## 35. Input the database refuses is not a crash

An active dynamic scan (`scripts/dast.sh --profile=deep`) produced **542 uncaught exceptions across 13 statements in 7 modules**. Not one was an injection — every write here is a prepared statement, so a value can never change what a query *does*. Every one was a value the schema refused: an id whose row was gone, a number wider than `INT UNSIGNED`, a path-traversal payload sitting in a `DATE` column, a NUL byte in a date field. PDO raised, nobody caught, and the visitor got the 500 page written for "the application has crashed" — alarming, and untrue.

The scanner also reported six of these as **High: SQL Injection**, which they are not: `(int) "2'"` is `2`, so the quote never reaches SQL and the 500 came from the FK behind it. A finding that is wrong about the cause can still be right that something is broken, and both halves of this one were — see « An id is digits, or it is nothing » below for the other three.

Three layers, in the order they matter.

### `Core\Service\DateInput` — one date parser, and it never throws

PHP offers two ways to read a submitted date, and each has a trap; almost every site here had one of them.

`createFromFormat($f, $v)` returns `false` for `"../../.."`, so ~20 sites wrote `$d !== false && $d->format($f) === $v` and reasonably believed that was total. It is not: a `$v` containing a **NUL byte** raises a `ValueError`. Sending `2026-01-01%00` costs an attacker nothing and turns every one of those sites into a 500.

`new DateTimeImmutable($v)` fails the other way. It throws on `"../../.."` — so it looks stricter — but returns **the current moment** for `""`, `"now"`, `"yesterday"` and `"a\0b"`. An unvalidated field then becomes today's date, is written as if the visitor typed it, and nothing anywhere reports it.

`DateInput` answers one question — is this string the date it claims to be — and returns `null` when it is not. It refuses control characters before parsing, round-trips the result so `2026-02-31` is refused rather than rolled forward to 3 March, and `fromStorage()` refuses MySQL's zero date, which PHP reads as the 30th of November, year -1. `Tests\Security\DateParsingConvergenceTest` fails on any `createFromFormat` anywhere outside that one file — the same convergence argument, and the same shape of test, as `HttpsDetectionConvergenceTest` in §9. The companion ban on `new DateTimeImmutable($v)` is « Every stored date is read through one door » below.

### `Core\Service\IntegerInput` — a floor is half a bound

The idiom was `max(0, (int) $request->getBody('capacity'))`: a floor, never a ceiling. The missing half is the reachable one. Every count, capacity and delay in this schema is `INT UNSIGNED`, so `4294967296` is a value the form accepts, the cast preserves and MySQL refuses. The scan reached that on `capacity`, `min_nights`, `vote_budget` and `member_id` by typing a long number.

`IntegerInput` refuses out of range rather than clamping — storing 4 294 967 295 because somebody typed more records a number they never chose — and refuses to salvage, because `(int) '12 places'` being `12` reads as helpful right up to the day a mistyped field is stored as a number that was never in it. The bounds it names are the storage layer's; nothing in it knows that a scout hall sleeps fewer than four billion people, and a field with a real-world ceiling should state its own.

Where an allow-list was already at hand it is used instead, because it is stricter: the on-call grid now checks each cell's member against the roster the page is displaying, and each cell's date against the month being saved. That closes the out-of-range value, the foreign key **and** a row dated outside the month, which `saveMonth()` would have written once and never cleared.

### An id is digits, or it is nothing

The same scan reported six **High: SQL Injection**. None is one — every statement is prepared, so a value cannot change what a query does — but three of them were pointing at something real.

An active scanner probes for injection by replacing an id with an arithmetic expression that evaluates to the same number: `4/2`, `4-2` where the id was 2. If the response does not change, it concludes the database evaluated the arithmetic. PHP does something else again: `(int) '4/2'` stops at the first non-digit and is **4** — neither the value the visitor sent nor the 2 the expression evaluates to. So `/config/banner/delete`, `/config/banner/role-min` and `/chefs/calendar/event-delete` were each acting on a row nobody had named, and answering as if nothing were odd.

The scanner's conclusion was wrong and its suspicion was not. `Core\Service\IntegerInput::id()` is the fix: digits, within what an `INT UNSIGNED` primary key holds, and never zero — no row has id 0, so a 0 is the signature of a cast that salvaged something. These endpoints now answer **400 « Identifiant invalide. »** rather than acting. No alert filter was written for those three findings: silencing a rule is for a finding that is false, and the response changing is what makes this one stop firing.

The other three (`/groups/*`, on a `2'` payload) were the uncaught-exception class above, reported under the wrong rule — a 500 is an error page, and an error page where the original request succeeded looks to a scanner exactly like a database complaining.

**The same defect exists one size up, and the re-scan found it.** With those six gone, a seventh appeared on `POST /finance/receipts/{id}/associate`: the attacked parameter was not the path id but the **list** `transaction_ids`, read as `array_map('intval', …)`. `intval('2/2')` is 2, so the receipt was being associated with a row nobody had named. `IntegerInput::idList()` now reads the nine sites that take a list of ids that way — reorders of banners, media, form fields, categorisation rules and section documents, the SOS excluded sections, an asset's calendars, a group's media. It is **all or nothing**: dropping the elements that do not parse would carry out a reorder over a subset nobody asked for, and that failure looks like a success — a shorter list, an order the caller never chose, and nothing anywhere reporting it.

That finding had not appeared in the previous run because `finance-receipts.spec.js` had not been replayed that time. It is the clearest illustration of the rule the DAST gate needs: **a spec that fails is not a green area, it is an area nobody looked at.**

### The same rule, one layer out: the router

The other ~230 casts are on **path** parameters — `(int) $params['id']` — and they have the same edge: `/gallery/2-1/edit` used to edit album 2. Nobody named album 2; PHP picked it, and the page then looked entirely normal.

`Core\Http\Router` now matches a placeholder **named** like a row identifier — `{id}`, `{postId}`, `{comment_id}` — against digits and nothing else. That is enforced in the router rather than at 230 call sites because there it cannot be forgotten, and because the right answer for a malformed identifier is exactly what the router already does with a path it does not recognise: **404**. No controller changes, no error path to write, and a route that would have found nothing anyway now says so before any code runs.

**The rule is the name, deliberately, so that there is no opt-out flag anyone can forget.** A parameter that is not a row identifier is not named like one: `/aide/{topic}` carries a help topic's slug and says so — it was `/aide/{id}`, and renaming it was the whole cost of making the rule unconditional. An earlier reading of this said a router rule "would be wrong" for that reason; the rule is right, the *name* was.

Checked against the real route table rather than a sample: `Tests\Core\Http\RouterIdentifierParametersTest` walks every route the application registers and fails on an id-named placeholder a non-numeric value can still reach — 209 routes, and it also checks the other direction, so the rule cannot be "tightened" into matching digits everywhere and silently 404 every slug on the site.

### A display filter must never take a page down

`|date_fr`, `|datetime_fr`, `|french_date` and `|relative_date` used to read their argument with `new DateTimeImmutable((string) $date)`. One unreadable timestamp anywhere on a page therefore produced a **500 for the whole render** rather than a blank field — and an empty string rendered as *today*, which is how a missing value gets believed. All four now read through `DateInput::fromStorage()` and answer what they already answered for null: nothing at all.

### Every stored date is read through one door

**All 161 remaining `new DateTimeImmutable($value)` reads were converted.** They were frozen at first, on the argument that most of them read a `DATETIME` column and MySQL will not let a malformed value into one. That argument is half true, and the wrong half was load-bearing: the zero date passes the column and PHP reads it as the 30th of November, year -1; a nullable column read without a guard gives the empty string, which is ***now***; and a value from a **setting**, an **import** or a **JSON payload** has no column type behind it at all.

Each site was read rather than pattern-matched, and answered one of three ways:

- `DateInput::requireFromStorage($v, $what)` where the schema says the value is always there. A `NOT NULL DATETIME` that does not parse is corrupt storage, and the honest answer to corrupt storage is to stop. What it replaces did something worse than stopping.
- `DateInput::fromStorage($v)` where the value is genuinely optional, or where the reading is presentation and a bad row must not take a page down with it. A camp's headline falls back to its year; a support-package sheet carries the raw value, which is the only evidence of whatever wrote it.
- `DateInput::firstOfMonth($year, $month)` for the eleven copies of `new DateTimeImmutable(sprintf('%04d-%02d-01', …))` in month grids, availability calculators and on-call planners. Eleven places for the same unasked question — what if the month is 13? — now one, and the refusal names the values it was given.

`Tests\Security\StoredDateReadingRatchetTest` is no longer a ratchet but a **ban with four named exceptions**, each carrying its reason in the test and the same reason at the call site:

| Site | Why the constructor is right there |
| --- | --- |
| `Core\Service\DateInput` | The home. Every other reading goes through it, inside the try/catch that is the point of the class. |
| `Core\Scheduler\SchedulerService::rearm()` | Takes a `strtotime` expression (`'tomorrow 05:00'`) from a task handler in this repository. The one edge worth closing — the empty string, which is *now* — is now refused explicitly. |
| `Core\Maintenance\Task\AutoBackupHandler` | A relative expression from a class constant, with a literal fallback. |
| `Modules\InboundMail\Mime\MimeMessageParser` | An RFC 2822 `Date:` header. `fromStorage()` requires the value to open with an ISO calendar date and would refuse every well-formed mail header there is. |

One candidate exception did not survive that rule and was removed rather than written down: `Retro\Service\BoardService` looked up a relative expression in a private four-entry table read only by the one method using it, so the constructor's argument is now a **literal** in a `match` and the `isset()` guard three lines up became a `default => null`. An exception you can delete by writing the code differently was never an exception.

`fromStorage()` refuses a relative expression **on purpose**: a stored moment that reads differently on every read is not a stored moment. Forcing those four through a reader built to refuse them would have made the code look uniform and do less. A fifth exception is a decision somebody has to defend in that file, which is the entire point.

### Proving the conversion changed nothing

A replacement that is safer and subtly different is a regression wearing a security badge. So the equivalence is pinned, not asserted: `Tests\Core\Service\DateInputEquivalenceTest` reads every value a `DATE`/`DATETIME` column can hold — a MySQL `DATETIME`, a bare `DATE`, midnight, a leap day, a fractional second, an explicit offset, an ISO `T`, surrounding whitespace — through both readings and requires `format('Y-m-d H:i:s.u P')` and `getTimestamp()` to be identical. The ten inputs where the two deliberately disagree are enumerated one by one, each with what the constructor used to answer, because an undocumented difference would be indistinguishable from a mistake.

**It earned its keep immediately.** `firstOfMonth()` reads through `createFromFormat()`, which fills every field the format does not mention **from the current time** — so the first of the month came back at whatever o'clock it happened to be, where `new DateTimeImmutable('2026-08-01')` is midnight. Nothing formatted differently and no French date moved. `Modules\Rental\Availability\MonthWindow::previous()` compares `<=` against the first of the current month, and the public calendar quietly stopped disabling its own « mois précédent » arrow — the boundary §6.7 exists to enforce, defeated by microseconds.

The fix is at the source and applies to the whole project: `DateInput::ISO_DATE` and `ISO_DATETIME_LOCAL` now carry the leading `!` that resets the unnamed fields, which is what every caller spelling its own format out already wrote (`'!d/m/Y'`, `'!Y-m-d'`). That also closed a latent bug nobody had reported: `RentalDocumentService::nightsBetween()` reads an arrival and a departure through `iso()` and subtracts them, so while each reading carried the clock of its own call, a pair straddling a second boundary came out **one night short on a contract** — silently, and roughly once every few thousand renders.

`fromStorage()` and `requireFromStorage()` also take an optional `DateTimeZone`, passed through to the constructor unchanged and meaning exactly what it means there: the zone a *naive* value is read in, and nothing at all when the value carries its own offset. That is not a convenience — the ICS export reads an all-day date as UTC and a timed event on the site's own clock, and reading either in the wrong zone shifts an exported event by an hour or a day with nothing failing anywhere.

### `Core\Database\ConstraintViolation` — the floor underneath validation

One class of these cannot be fixed at a boundary at all. Between checking that a member exists and the INSERT that references them, another administrator can delete them. Checking first narrows the race; it never closes it.

So `Core\Http\FrontController` catches around the one place every controller action is invoked, and answers **409** for a conflict (a referenced row that is gone, a duplicate on a unique key) or **400** for a malformed value (too wide, too long, not a date), with a French sentence and the site's normal error page.

**Classified by driver code, never by SQLSTATE, and that is the whole design.** SQLSTATE 23000 covers a foreign key a visitor got wrong *and* a NOT NULL column this codebase forgot to populate. The first is a client error; the second is a bug here, and a bug that stops shouting is a bug that stops being fixed. Only `errorInfo[1]` separates them. Seven driver codes are listed as caller fault (1451, 1452, 1062, 1264, 1292, 1366, 1406); everything else — a missing table, a syntax error, a deadlock, a server that went away, a code with no driver code at all — is rethrown and reaches `ErrorHandler` as a 500, unchanged. `Tests\Core\Http\FrontControllerConstraintViolationTest` pins both halves, because a net that catches too much is worse than the problem.

The driver's own text never reaches the page: MySQL names the table, the column and the constraint, in English. The sentence is written in `ConstraintViolation::message()`, for the reason §9 and `Core\Exception\UserFacingException` both give. The event is written with `error_log` rather than the journal on purpose — the journal is a table, and the one thing just established about this request is that a write to that database did not go through.

**This is a floor, not a replacement for validation.** A request refused with a French sentence next to the offending field beats a generic error page every time. Reaching this handler at all means a boundary check was missing.

## 36. The authorization matrix

`scripts/dast.sh --profile=standard` replays **every route as every role** and checks the answer against the `role_min` the route declares. 528 routes × 6 roles = 3 168 pairs, in about a minute, with no scanner and no browser.

It was meant to be ZAP's "Access Control Testing" add-on, which is not in the `stable` image. Doing it here turned out to be the better home rather than a fallback: the question has one right answer per pair — the application states it in `module.json`, `Core\Security\RbacGuard` enforces it — so this is a comparison, not a heuristic. No payloads, no false positives, and a result that means the same thing on every run.

**The two ways to be wrong are not equally bad.** A role reaching a route it may not is the security hole, and it fails the run. A role refused a route `role_min` admits is reported and never fatal, because a module may legitimately narrow access further than its route declares.

### Replaying 500 POSTs without writing anything

Replaying every route as six roles, for real, would rewrite the instance halfway through its own audit — and every later probe would then be measuring a site the audit itself had changed.

So a POST is sent **without a CSRF token, deliberately**. The guard runs *before* the controller and the CSRF check runs *inside* it, so the two refusals are distinguishable and the authorized case stops at the CSRF wall having changed nothing:

| | anonymous | authenticated |
|---|---|---|
| **RBAC refusal** | 302 → `/login` | 403, `text/html` |
| **CSRF refusal** | 302 → elsewhere | 403, `application/json` |

A 403 is therefore read by its `Content-Type` and a 302 by where it points — never by status alone.

### The two things that would make it lie

Both are guarded, because both would produce a **green** run rather than a red one.

**A session that does not carry the role it claims.** An account whose role failed to resolve still signs in perfectly well — it is simply `identified`. The matrix would then watch every admin route correctly refuse it, report a clean run, and have checked nothing. So each session is asked for a page only its own role may reach, and the run stops if the answer is no. That check caught a wrong assumption the first time it ran.

**A route the inventory cannot see.** A missing route does not make the matrix red; it makes it *shorter*, and a shorter green run reads exactly like a complete one. `Tests\Security\AuthorizationMatrixInventoryTest` therefore runs on every commit, with no server: every `addRoute()` in `public/index.php` accounted for (by count, so a route written in an unfamiliar shape fails the parse loudly), every module's routes present, every parameterised route addressable by a fixture, no fixture group left describing a route that no longer exists, and the role ladder the matrix reasons with identical to the one `Core\Security\Role` enforces — checked in both directions.

Fixtures are keyed by route-pattern prefix, not by placeholder name: `{id}` is a member on `/members/{id}` and a discussion group on `/groups/{id}`. Their values need only be **well-formed, not real** — the guard runs before the controller, so a 404 for a row that is not there still means the caller got past it.

### The result, and how to read it

**Zero over-permissive routes.** 68 refusals are stricter than `role_min`: a member may only edit their *own* record, a file goes through `FileAccessGuard`. That is defence in depth, and the report lists every one — they are to be read, not assumed.
