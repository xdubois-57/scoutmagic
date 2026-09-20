<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Contact\Device;

use Core\Journal\JournalService;
use Core\ScoutYear\AuthorizationYearService;
use Core\Security\EncryptionService;
use Core\Security\HumanCheck\HumanCheckRateLimitRepository;
use Core\Security\Role;
use Core\Security\RoleResolver;
use Core\Security\UserAccountRepository;

/**
 * Turns an HTTP Basic header into an {@see AuthenticatedDevice}, or into
 * nothing.
 *
 * **The rule that does not bend: the role is re-resolved on EVERY
 * request.** A credential never authorises anything by itself — it says
 * which account is asking, and the account's role is then computed from
 * the unit's current state exactly as {@see RoleResolver} computes it for
 * a browser session, over the same set of years the login gate judges on
 * ({@see AuthorizationYearService}). A chief who leaves the staff loses
 * the site at the next Desk import; a device of theirs has to die in
 * exactly the same way, at the next poll, without waiting for anybody to
 * remember to revoke it.
 *
 * Four things are therefore checked in order, and each one alone is
 * enough to refuse:
 *
 * 1. the site-wide switch ({@see DeviceCredentialService::isSyncEnabled()});
 * 2. the credential exists, belongs to that account and is not revoked;
 * 3. the account may still sign in at all (deactivated, or no longer a
 *    member of the unit);
 * 4. the account's role, resolved now, is `admin` or `superadmin`.
 *
 * **Every refusal is the same refusal.** The caller gets null and answers
 * one bare 401 whatever the reason, so a client — or anybody holding the
 * URL — cannot learn from the answer whether an address has an account,
 * whether a credential exists, or whether a role changed.
 */
class DeviceAuthenticator
{
    /** The floor a synchronised address book requires. */
    public const REQUIRED_ROLE = Role::ADMIN;

    /**
     * How many authentication failures from one source address are
     * journaled per window, and how long that window is.
     *
     * A misconfigured client retries every few minutes forever, so
     * journaling every refusal would bury the journal under one device's
     * bad password — the same reasoning SECURITY.md already applies to the
     * triage extract's refusals, counted in the same table under a
     * purpose of their own. Past the ceiling the answer is the identical
     * 401 and nothing is written, so the journal can neither be filled
     * nor used to bury a real attempt.
     */
    private const FAILURES_JOURNALED_PER_WINDOW = 5;
    private const FAILURE_WINDOW_MINUTES = 60;
    private const FAILURE_LIMIT_PURPOSE = 'device_auth_failure';

    public function __construct(
        private DeviceCredentialRepository $repository,
        private DeviceCredentialService $service,
        private UserAccountRepository $userAccountRepository,
        private RoleResolver $roleResolver,
        private AuthorizationYearService $authorizationYearService,
        private EncryptionService $encryption,
        private JournalService $journalService,
        private ?HumanCheckRateLimitRepository $failureLimiter = null
    ) {
    }

    /**
     * @param ?string $authorizationHeader The raw `Authorization` header.
     * @param ?string $sourceIp            Only ever hashed, never stored or journaled.
     */
    public function authenticate(?string $authorizationHeader, ?string $sourceIp = null): ?AuthenticatedDevice
    {
        $credentials = self::parseBasic($authorizationHeader);
        if ($credentials === null) {
            // No credentials offered at all: the ordinary first request of
            // every client, which answers 401 and comes back with them.
            // Not a failure worth a line in anybody's journal.
            return null;
        }

        [$email, $secret] = $credentials;

        if (!$this->service->isSyncEnabled()) {
            // The site-wide cut-out. Not journaled per request either: a
            // superadmin who flipped it already has that act in the
            // journal, and every client on the site retrying every few
            // minutes would write the same line forever.
            return null;
        }

        $account = $this->userAccountRepository->findByEmail($email);
        if ($account === null) {
            $this->journalFailure('unknown_account', null, $sourceIp);
            return null;
        }

        $credential = $this->repository->findLiveMatching($account->id, $secret);
        if ($credential === null) {
            $this->journalFailure('bad_secret', $account->id, $sourceIp);
            return null;
        }

        $years = $this->authorizationYearService->resolve();

        // The same gate the login door applies, for the same reason: a
        // user_accounts row is never sufficient on its own.
        if (!$this->roleResolver->isEmailAuthorizedToLoginAcrossYears($account->email, $years)) {
            $this->journalFailure('account_no_longer_authorized', $account->id, $sourceIp);
            return null;
        }

        $role = Role::fromString($this->roleResolver->resolveAcrossYears($account->email, $years));
        if (!$role->hasAccess(self::REQUIRED_ROLE)) {
            $this->journalFailure('role_too_low', $account->id, $sourceIp);
            return null;
        }

        return new AuthenticatedDevice($credential, $account->id, $account->email, $role);
    }

    /**
     * Stamp a credential as having synchronised. Called by the routes
     * that actually served something — never from here, because
     * authenticating is not synchronising and a client that authenticates
     * and then fails has not received a contact.
     */
    public function recordSync(AuthenticatedDevice $device): void
    {
        $this->repository->touchSync($device->credential->id);
    }

    /**
     * `Authorization: Basic base64(user:password)` → `[user, password]`,
     * or null for anything else.
     *
     * Only Basic. Digest is the other scheme CardDAV clients speak, and
     * it cannot be implemented over a hash the server does not keep in a
     * reversible form — which is exactly the property the stored secret
     * must have.
     *
     * @return ?array{0: string, 1: string}
     */
    public static function parseBasic(?string $header): ?array
    {
        if ($header === null || !preg_match('/^Basic\s+([A-Za-z0-9+\/=]+)$/', trim($header), $matches)) {
            return null;
        }

        $decoded = base64_decode($matches[1], true);
        if ($decoded === false) {
            return null;
        }

        $separator = strpos($decoded, ':');
        if ($separator === false || $separator === 0) {
            return null;
        }

        $user = substr($decoded, 0, $separator);
        $secret = substr($decoded, $separator + 1);

        return $secret === '' ? null : [$user, $secret];
    }

    /**
     * An authentication that failed, with a machine reason, the account
     * id when there is one, and **nothing else** — never the address that
     * was offered, never the secret, never a member's name. The source
     * address is not copied into the context either: the journal's own
     * `ip_address` column already holds it for every entry, and a second
     * copy in the JSON would be the same personal datum stored twice in
     * one row (SECURITY.md §16's line, applied here too).
     */
    private function journalFailure(string $reason, ?int $userAccountId, ?string $sourceIp): void
    {
        if (!$this->mayJournalFailure($sourceIp)) {
            return;
        }

        $context = ['reason' => $reason];
        if ($userAccountId !== null) {
            $context['user_account_id'] = $userAccountId;
        }

        $this->journalService->log(
            'core',
            'device_credential_auth_failed',
            'security',
            'Échec d\'authentification d\'un appareil de synchronisation',
            $context,
            null
        );
    }

    private function mayJournalFailure(?string $sourceIp): bool
    {
        if ($this->failureLimiter === null || $sourceIp === null || $sourceIp === '') {
            return true;
        }

        $ipHash = $this->encryption->blindIndex($sourceIp, 'ip');
        $since = (new \DateTimeImmutable('-' . self::FAILURE_WINDOW_MINUTES . ' minutes'))->format('Y-m-d H:i:s');

        if ($this->failureLimiter->countSince($ipHash, self::FAILURE_LIMIT_PURPOSE, $since)
            >= self::FAILURES_JOURNALED_PER_WINDOW) {
            return false;
        }

        $this->failureLimiter->record($ipHash, self::FAILURE_LIMIT_PURPOSE);

        return true;
    }
}
