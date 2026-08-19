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
- WebAuthn credentials: public key stored, challenge verified server-side, sign count checked.
- Identical error messages for "unknown email" and "wrong password" — no account enumeration. Identical *cost*, too: the "no such account" and "account without a password" paths deliberately burn a `password_verify()` against a dummy hash of the same algorithm/cost (`Core\Security\PasswordAuthMethod::DUMMY_HASH`), so response time doesn't separate the cases a uniform message just merged.
- The magic-link polling endpoint (`GET /auth/poll/{id}`) is bound to the session that requested the link (`Core\Security\PendingMagicLink`). `magic_links.id` is a sequential `AUTO_INCREMENT` integer, never the emailed secret — it is not a capability, and polling somebody else's id returns the same "not confirmed yet" as an unconfirmed one. Only `AuthService::verifyMagicLink()` checks the real token.
- Progressive lockout on failed attempts.
- Session ID regenerated at login (`session_regenerate_id(true)`).
- Session cookies: `HttpOnly`, `Secure`, `SameSite=Lax`, 30-day lifetime (`Core\Security\SessionManager`, matching `session.gc_maxlifetime` so server-side session data doesn't expire before the cookie does) — an installed PWA shouldn't demand a fresh magic link every few days.

## 3. RBAC

- RBAC guard called by Router **before** any controller code — automatically, for every route.
- Every route must declare `role_min`. A route without `role_min` is rejected at load time.
- New imported functions default to lowest role. An import never silently elevates privileges.
- Role check is always server-side. Menu visibility is a convenience, never a security boundary.
- `Core\Member\SectionStaffAuthorizationService` ("which sections is this account chief/animateur of") is a Controller-level narrowing on top of the route's `role_min`, not a replacement for it — same pattern as `MemberService::canAccess()` narrowing onto one member. The RBAC guard still gates the route first; this service only answers which resource(s) the already-authorized caller may act on within it.

## 4. CSRF

- CSRF token on every form, verified on every POST/PUT/DELETE.
- Token bound to session, regenerated per session.
- One deliberate exception: `POST /api/webhook/github` (`Core\Http\Controller\WebhookController`) — a machine-to-machine call from GitHub with no session to bind a token to. Authenticated instead by an HMAC-SHA256 signature (`X-Hub-Signature-256`, constant-time `hash_equals()` comparison) against a secret stored only in `secrets.enc`.

## 5. Encryption at rest

### Personal data

All fields identifying a natural person are encrypted (AES-256-GCM) as BLOB:

**Encrypted**: name, surname, totem, quali, date of birth, gender, street, number, box, complement, postal code, city, country, phone, mobile, email, departure comment (`member_years.leaving_comment_encrypted` — often a sensitive reason: conflict, family situation, health).

**In clear**: all IDs, FKs, timestamps, flags, module/role references.

### Implementation

- `EncryptionService`: `encrypt()`, `decrypt()`, `blindIndex()`.
- Two keys (`APP_ENCRYPTION_KEY`, `APP_BLIND_INDEX_KEY`), never in database, never committed.
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
- Rich text: sanitized with strict tag whitelist before storage.
- Images: MIME validated, EXIF stripped, filename randomized.

## 8. Email

- DKIM signing on every outgoing email.
- SPF, DKIM, DMARC verified live and displayed in configuration.
- Multipart mandatory (HTML + plain text).
- Rate limiting on magic link sends.

## 9. HTTP headers

Every response: `Content-Security-Policy`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Strict-Transport-Security` (if HTTPS), `Referrer-Policy: strict-origin-when-cross-origin`.

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
