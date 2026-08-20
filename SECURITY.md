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
- Progressive lockout on failed password attempts, along two axes (`Core\Security\LoginThrottler`): per email (5/10/20 failures per hour) and per source IP, at much higher thresholds (30/60/100). The IP axis catches the spray of one password across many accounts that per-email counting is structurally blind to; the raw address is never stored, only its blind index. A successful login clears the email axis only — one attacker succeeding on an account they control must not erase the evidence of the spray they are running from the same address. (This is not the per-IP counter `ARCHITECTURE.md` §8.31 rules out: that concerns the magic-link *request* form, which already has a per-email limiter for the same abuse pattern.)
- Sessions are revalidated against current data on every request (`Core\Security\SessionRevalidator`), never trusted for the lifetime of the 30-day cookie:
  - **Revocation.** Setting or changing a password bumps `user_accounts.sessions_valid_from`; any session issued earlier is dropped on its next request. PHP's file-based sessions have no per-user registry to walk and destroy, so revocation has to be a stamp the session re-checks itself against — without it a password reset "recovered" an account the attacker was still sitting in. A member changing their own password keeps the tab they did it in (`AuthSession::refreshIssuedAt()`); a reset link ends every session including the requester's.
  - **Role.** The effective role is re-resolved each request instead of being snapshotted at login, so a demotion — or losing unit membership entirely, which ends the session, matching the login gate — takes effect on the next click.
- The password-reset link carries its token in the URL **fragment** (`/password-reset/{id}#<token>`), never the query string: fragments are not transmitted, keeping the token out of access logs, proxy logs and `Referer`. The page reads it from `location.hash`, strips it from the address bar, and validates it through `POST /password-reset/{id}/check` (CSRF-protected, and read-only so reloading never burns the token).
- Session ID regenerated at login (`session_regenerate_id(true)`).
- Session cookies: `HttpOnly`, `Secure`, `SameSite=Lax`, 30-day lifetime (`Core\Security\SessionManager`, matching `session.gc_maxlifetime` so server-side session data doesn't expire before the cookie does) — an installed PWA shouldn't demand a fresh magic link every few days.

## 3. RBAC

- RBAC guard called by Router **before** any controller code — automatically, for every route.
- Every route must declare `role_min`. A route without `role_min` is rejected at load time — `Core\Module\ModuleManifest` for module routes, and `Core\Http\Router::addRoute()` itself for core ones (the argument is mandatory, and an unrecognised role name raises rather than being silently downgraded to `public` by `Role::fromString()`).
- The RBAC guard is switched off in exactly one place — `setRbacBypassPrefix('/setup')` — and only while `SecretManager::isInitialized()` is false, i.e. the first-run installer, where no database, account or role exists yet. Once initialized, `/setup` is reachable through its own `role_min: superadmin` like any other route, so a bypass there could only ever strip authentication (`GET /setup` leaks database/SMTP/admin settings and issues a CSRF token; `POST /setup/save` rewrites database credentials and the admin account). Pinned by `Tests\Core\Http\SetupRbacBypassWiringTest`.
- New imported functions default to lowest role. An import never silently elevates privileges.
- Role check is always server-side. Menu visibility is a convenience, never a security boundary.
- **`role_min` is a floor, never the whole answer.** Any resource with its own visibility rule must re-check it in the controller or service, because the route only proves the caller's role clears the minimum:
  - Finance accounts carry `role_min_view`; every page resolving them (dashboard, movements, receipts, import, **and the receivables reconciliation page**) filters through it, and a receipt with no account at all is denied rather than left unguarded.
  - Calendars: a chief may only create, move or delete events in a calendar inside `CalendarEventService::getEditableCalendarsForChief()` — checked on both ends of a move, not just that the calendar exists.
  - News articles: the visibility gate applies to every representation of an article, the poster PDF included, not only its detail page.
  - Groups: content auto-hidden by moderation is invisible to non-moderators on *write* paths (reactions, reports) as well as reads.
  - Gallery: a delegated album is refused if its storage location has a public URL — re-asserted when serving bytes, not only when the album is created.
  - Ids arriving in a request body are validated against the set the UI actually offers (a form's finance account, the SOS default-number member), never trusted because the route's role was high enough.
- `Core\Member\SectionStaffAuthorizationService` ("which sections is this account chief/animateur of") is a Controller-level narrowing on top of the route's `role_min`, not a replacement for it — same pattern as `MemberService::canAccess()` narrowing onto one member. The RBAC guard still gates the route first; this service only answers which resource(s) the already-authorized caller may act on within it.

## 4. CSRF

- CSRF token on every form, verified on every POST/PUT/DELETE.
- Token bound to session, regenerated per session.
- Two deliberate exceptions, each authenticated by something other than a session-bound token:
  - `POST /api/webhook/github` (`Core\Http\Controller\WebhookController`) — a machine-to-machine call from GitHub with no session to bind a token to. Authenticated instead by an HMAC-SHA256 signature (`X-Hub-Signature-256`, constant-time `hash_equals()` comparison) against a secret stored only in `secrets.enc`.
  - `POST /mass-mail/unsubscribe/{id}` (`Modules\MassMail\Controller\UnsubscribeController`) — the RFC 8058 one-click unsubscribe target, reached from a mail client with no session. Authenticated by a per-recipient token carried in the link and verified constant-time against a stored SHA-256 hash (`hash_equals`). The token is 32 bytes of entropy, so a fast hash is as safe as bcrypt and avoids a per-request bcrypt on an anonymous endpoint. Idempotent, so a mailbox prefetch or a resubmit lands in the same "unsubscribed" state.

## 5. Encryption at rest

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
- Desk CSV: deleted immediately after import.
- Finance receipts (`modules/finance`): encrypted at rest via `Core\File\EncryptedFileStorageService` (same master key as `EncryptionService`) — never written to disk in plaintext. Bank statement CSV files uploaded for import: deleted immediately after processing, success or failure, same pattern as the Desk CSV.
- Public content files: not encrypted.

### Secrets

- `storage/keys/master.key`: `chmod 600`, generated via `random_bytes()`.
- `storage/config/secrets.enc`: AES-256-GCM blob with DB + SMTP credentials, plus the GitHub webhook HMAC secret (`github_webhook_secret`) — generated via Configuration > Maintenance, shown to the admin exactly once, never stored in `settings`.
- Key and blob in separate directories.

## 6. File access

- All non-public files under `storage/` (outside webroot).
- Every download through `FileAccessGuard` via `/files/{id}` — no exceptions. `GET /files/{id}/{variant}` (`Core\Photo\ImageVariantService`, ARCHITECTURE.md §8.39) is a rendition of the same file through the same guard, not a second access path: `{variant}` is validated against a fixed two-name vocabulary before it is ever used to build a filesystem path, so an unknown or path-traversing value is a plain 404 that never touches the filesystem.
- **The one deliberate, narrow exception this used to have (Lot 3, offline mode) is retired.** `GET /api/offline/photo/{member_id}` (`Core\Http\Controller\OfflineController::photo()`) used to serve a square ~160px WebP derivative of a staff member's current photo, generated on demand, specifically because `/files/{id}` had no cacheable, already-small rendition for the offline trombinoscope pre-download to point at. `GET /files/{id}/thumb` now is that rendition, generated once at upload rather than on demand — so the bespoke route and `Core\Photo\StaffThumbnailProcessor` no longer exist, and the offline pre-download (`public/assets/js/offline-prefetch.js`) fetches plain `/files/{id}/thumb`/`/files/{id}/md` URLs, listed for it by `GET /api/offline/manifest`, like any other caller. This is a net reduction of security surface: one fewer route bypassing `FileAccessGuard`'s normal single-path posture.
- Beyond the `role_min` floor, a file may be narrowed by ownership: `files.owner_member_id` (the session must be linked to that member) or the generic `files.owner_type`/`files.owner_id` registry (`Core\File\FileOwnershipCheckerInterface`, ARCHITECTURE.md §8.3). Both are **fail-closed** — an `owner_type` with no registered checker is denied, never allowed by default — and neither has a chief/admin bypass. A checker can only narrow what `role_min` already permits, never widen it, and `owner_type` values must be unique across checkers (the first `supports()` match wins). A module's checker is wired into the composition root only while that module is enabled, and the guard is built after every module block precisely so a missing checker can never silently become "no check".
- File links via `file_url($id)` — never direct paths.
- Upload: true MIME check, random filename, EXIF stripped, size limit, non-executable directory.
- Access denied: 403 + journal entry (security level).
- Finance receipts go through `FileAccessGuard` like any other file. Every receipt is tied to an account at upload time (`finance_attachments.account_id`), and its underlying file's `role_min` is set to that account's own `role_min_view` — not the module's flat `"intendant"` `storage` declaration, which is only the fallback floor for a not-yet-account-scoped case. Whenever an account's `role_min_view` is changed, every existing receipt file tied to that account is updated to match (`ConfigAccountController::syncReceiptFilesRoleMin()`), so access stays in sync retroactively.

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

The fatal-error fallback page (`Core\Http\ErrorHandler`, §22) re-emits the same header set from its own hardcoded 500 response — an uncaught throwable must never be the one response that ships without them. The two other pages emitted outside the normal `Response` path do the same now: the 413 "payload too large" page and the pre-routing migration-progress page. The migration page carries an inline `<script>`, so it builds a per-render **nonce**-based CSP and tags the script with it, rather than shipping no CSP at all (which is the only reason that inline script used to run).

Cross-origin `target="_blank"` links carry `rel="noopener"` (the sanitizer forces it on user content; templates set it directly).

## 10. Cookie consent

- Cookies categorized: strictly necessary (no consent), functional (consent required), analytics (consent required).
- Consent checked via `CookieConsentService::isAllowed()` before setting any non-essential cookie.
- Consent stored in a strictly-necessary cookie (13-month expiry per ePrivacy directive).
- Cookie declarations aggregated from core + modules — single source of truth for the banner and the preferences page (the RGPD page links to the preferences page rather than duplicating this list).
- **What the offline content cache (`content-{accountScope}-{version}`, `functional` consent) now stores on the device, and what it still never does** (ARCHITECTURE.md §8.25): with consent and while using the installed app, a full copy of every whitelisted page is kept locally — as of this iteration that includes the member's own page and "Mon compte", so the device now holds whatever those pages already show online: the member's own name/photo, their function(s), and — on their page specifically — the full name and postal address of their section's designated responsable (the same data `Core\Member\MemberPageService` already renders for that viewer online, never anyone else's). Every image is the same reduced-resolution derivative the page itself renders (`Core\Photo\ImageVariantService`), never the original. Still never cached, regardless of consent or device: owner-scoped private documents (`Core\File\FileAccessGuard`'s owner-scoping, §6), finance data, mass-mail content, `/inscriptions`, and any admin/configuration page — `Core\Offline\OfflineWhitelist` has no entry for any of them, core or module, and a module is expected to apply the same judgment before declaring its own offline page (see `docs/module-development.md`). Writing to this cache is restricted to the installed app itself (`config.standalone`, computed client-side) — an ordinary browser tab visiting a whitelisted page never writes to it, only reads whatever is already there. Purged entirely on logout and on withdrawing this consent (both already true before this iteration, verified by test).

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

- Import page: `role_min: chief`.
- CSV header validation before processing.
- New functions never auto-assigned to elevated roles.
- Journal stores only metadata — never raw CSV content.

## 14. Dependency security

- `composer audit` in CI on every PR.
- Only a small, explicitly justified set of external dependencies — see the table in `ARCHITECTURE.md` §1 for the complete, current list and each one's justification.
- Bootstrap: compiled files, pinned version.

## 15. Static analysis and SonarQube Cloud

- [SonarQube Cloud](https://sonarcloud.io/project/overview?id=xdubois-57_scoutmagic) (`sonar-project.properties`) analyzes every push to `main` and every PR in CI (`.github/workflows/ci.yml`, `sonarqube` job), complementing — never replacing — PHPStan, PHPUnit, `composer audit`, and CodeQL. In CI, authenticated via the `SONAR_TOKEN` repository secret only. For a local release run, `scripts/check-sonar-release.sh` reads the same token from the environment or from a local `.sonar-token` file (gitignored, written only after `git check-ignore` confirms it, mode 600 — see §12). Never committed to source, never logged, in either case.
- The project's Quality Gate must be `OK`; a failing Quality Gate fails the `sonarqube` GitHub check on the PR/commit.
- `scripts/release.sh` additionally runs a dedicated, fail-closed **SonarQube Cloud release gate** (`scripts/check-sonar-release.sh`) before creating any release commit or tag: any active security finding (SECURITY-impact issue, or an un-triaged Security Hotspot), any active finding at severity `HIGH` or above, a Quality Gate that isn't `OK`, or any inability to reach a definitive answer from SonarQube Cloud (missing token, unreachable host, invalid response, unconfirmed analysis for the release commit) blocks the release. `--skip-sonar-gate` bypasses it for genuine emergencies only (prints a warning, same convention as this script's other `--skip-*-gate` flags). See `AGENTS.md` § Releases.

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

## 32. Deferred hardening (known, tracked)

The remaining audit items are understood and intentionally deferred — each is a UX-changing product decision or a broad template rework whose cost currently exceeds the risk it retires. Documented so they are tracked, not forgotten.

- **Magic-link and email-confirmation tokens travel in the query string** (`AuthService`, `MemberEmailService`), unlike the password-reset token which rides in the URL **fragment** (never sent to the server, so it stays out of access/proxy logs and `Referer`).
  *Not fixing exposes:* a token logged in server/proxy access logs — readable by hosting staff or leaked log aggregation — during its short single-use lifetime. Narrow window, dies on first use, and the §31 confirm-page shape means a log-replayer now lands on a page rather than consuming anything.
  *Fixing costs:* the fragment shape needs a JS hop (`location.hash` → POST), which breaks no-JS clients and some in-app mail webviews that mangle fragments — a real regression risk for a parent audience on mobile mail apps. Deferred as low residual value after §31; revisit only if log exposure becomes a concrete concern.
- **CSRF token rotation on login/logout.** `CsrfGuard::generateToken()` reuses an existing session token, so a token minted for an anonymous visitor carries across the login boundary.
  *Not fixing exposes:* nothing currently exploitable — `session_regenerate_id(true)` changes the session id on login, `use_strict_mode` blocks fixation of attacker-chosen ids, and `SameSite=Lax` blocks the cross-site POST that would use a stolen token; every path to exploiting the non-rotation is closed by another layer.
  *Fixing costs:* clearing the token on `AuthSession::login()` is correct for the real flow but collides with the large body of controller tests that use `login()` as a session-setup shim after preparing a CSRF token; the fix pairs the rotation with a test-helper rework. Belt-and-braces only — revisit if `SameSite` is ever loosened.
- **`style-src 'unsafe-inline'`** is load-bearing — roughly thirty templates set computed inline geometry (progress bars, chart widths) via `style="…"`.
  *Not fixing exposes:* nothing by itself — it is an **amplifier** for a future attribute-injection bug (attacker-controlled CSS on one element: overlay/clickjacking tricks; script execution stays blocked by the nonce CSP). The known attribute-injection bugs are fixed and regression-tested (§7, §28).
  *Fixing costs:* reworking those templates to nonce'd styles or CSS custom properties, with visual-regression risk and no test coverage of rendered geometry. Chip away opportunistically — when a template with inline geometry is touched anyway, convert it to a `--var`-based style; the CSP directive flips once the last one is gone.
