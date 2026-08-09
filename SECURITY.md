# Security

This document defines the non-negotiable security requirements for the project. Every contribution must comply. The RBAC guard, encryption, and file access guard are the three pillars — none may be bypassed.

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

## 4. CSRF

- CSRF token on every form, verified on every POST/PUT/DELETE.
- Token bound to session, regenerated per session.
- One deliberate exception: `POST /api/webhook/github` (`Core\Http\Controller\WebhookController`) — a machine-to-machine call from GitHub with no session to bind a token to. Authenticated instead by an HMAC-SHA256 signature (`X-Hub-Signature-256`, constant-time `hash_equals()` comparison) against a secret stored only in `secrets.enc`.

## 5. Encryption at rest

### Personal data

All fields identifying a natural person are encrypted (AES-256-GCM) as BLOB:

**Encrypted**: name, surname, totem, quali, date of birth, gender, street, number, box, complement, postal code, city, country, phone, mobile, email.

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
- Every download through `FileAccessGuard` via `/files/{id}` — no exceptions.
- **The one deliberate, narrow exception** (Lot 3, offline mode): `GET /api/offline/photo/{member_id}` (`Core\Http\Controller\OfflineController`) serves a square ~160px WebP derivative of a staff member's current photo, generated on demand, never the original bytes. This is what makes the offline trombinoscope pre-download possible — `/files/{id}` responses are never cached by the service worker (`public/sw.js` keeps that path strictly network-only) and staff photos would otherwise be unavailable offline. It still calls `FileAccessGuard::check()` on the underlying file, *and* additionally gates on `Core\Module\StaffDirectoryProvider` (is this member id currently eligible staff at all, not just "does the viewer's role clear the file's floor") — a member id that isn't currently staff can never be used to fetch an arbitrary member's photo through this route. Confined to one identified, low-sensitivity resource (staff faces already visible to any identified member via `/trombinoscope`). This is not a precedent — no other file type or context may bypass `/files/{id}` this way.
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
