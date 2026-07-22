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
- Session cookies: `HttpOnly`, `Secure`, `SameSite=Lax`.

## 3. RBAC

- RBAC guard called by Router **before** any controller code — automatically, for every route.
- Every route must declare `role_min`. A route without `role_min` is rejected at load time.
- New imported functions default to lowest role. An import never silently elevates privileges.
- Role check is always server-side. Menu visibility is a convenience, never a security boundary.

## 4. CSRF

- CSRF token on every form, verified on every POST/PUT/DELETE.
- Token bound to session, regenerated per session.

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
- `storage/config/secrets.enc`: AES-256-GCM blob with DB + SMTP credentials.
- Key and blob in separate directories.

## 6. File access

- All non-public files under `storage/` (outside webroot).
- Every download through `FileAccessGuard` via `/files/{id}` — no exceptions.
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
- `.gitignore`: `storage/keys/`, `storage/config/`, `.env`.
- CI: secret scanner on every PR.
- SMTP and DB credentials in `secrets.enc`, not in `settings`.

## 13. Desk import security

- Import page: `role_min: chief`.
- CSV header validation before processing.
- New functions never auto-assigned to elevated roles.
- Journal stores only metadata — never raw CSV content.

## 14. Dependency security

- `composer audit` in CI on every PR.
- Only three external dependencies.
- Bootstrap: compiled files, pinned version.
