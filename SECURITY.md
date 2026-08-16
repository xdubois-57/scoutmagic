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
- Identical error messages for "unknown email" and "wrong password" — no account enumeration.
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
- `.gitignore`: `storage/keys/`, `storage/config/`, `.env`, and every `storage/<name>/` subdirectory that holds uploaded or generated content (module storage folders, `storage/core/`, `storage/temp/`, etc. — see `.gitignore` for the current, authoritative list). Adding a new storage subdirectory for uploaded content and forgetting to gitignore it has happened more than once in practice; check `.gitignore` whenever a module gains its own `storage/<name>/` folder.
- CI: secret scanner on every PR.
- SMTP and DB credentials in `secrets.enc`, not in `settings`.

## 13. Desk import security

- Import page: `role_min: chief`.
- CSV header validation before processing.
- New functions never auto-assigned to elevated roles.
- Journal stores only metadata — never raw CSV content.

## 14. Dependency security

- `composer audit` in CI on every PR.
- Only a small, explicitly justified set of external dependencies — see the table in `ARCHITECTURE.md` §1 for the complete, current list and each one's justification.
- Bootstrap: compiled files, pinned version.

## 15. Public form protection

- Every public form submitted by a non-identified session goes through `Core\Security\HumanCheck\HumanCheckService` (`ARCHITECTURE.md` §8.31) — no captcha, no external service, no client-side behavioral analysis.
- Three cumulative barriers: a honeypot field, a minimum-delay-since-render check (capped by a maximum form validity age), and a per-IP sliding-window rate limit. An identified session skips all three unconditionally.
- Stateless: the honeypot field name and render timestamp are HMAC-signed (`EncryptionService::blindIndex()`) and carried inside the form itself — never stored server-side, never session-bound.
- The honeypot is hidden via a CSS class only (`.hc-trap`), never an inline style — an inline `style="display:none"` would violate the CSP (§9). Never `type="hidden"`, which a bot's form-filler skips over; `tabindex="-1"`/`aria-hidden="true"` keep it unreachable by keyboard or screen reader.
- The IP address used for the rate-limit counter is personal data: it is hashed (HMAC, the same blind-index technique as an encrypted field's exact-match lookup) before being stored in `human_check_rate_limits`, and the raw address is never written to that table or to the journal.
- A rejection never reveals which of the three barriers triggered — same generic French message regardless of reason, so a bot can never learn what defeated it. A rejection also never loses the visitor's input: the form is re-rendered with a fresh challenge, never a dead-end error page.
- Every rejection is journaled as `human_check_failed` (`level: security`, context limited to which form was involved — no IP beyond the journal's own standard `ip_address` column, no honeypot content, nothing else that could identify the submitter).
- A magic-link request (`POST /login/magic-link`) applies only the honeypot and minimum-delay barriers — `AuthService::requestMagicLink()` already rate-limits by email blind index (§8); a second, IP-scoped counter for the same abuse pattern would produce inconsistent thresholds. See `ARCHITECTURE.md` §8.31 for the full reasoning.
