<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Service;

use Core\Database\AdvisoryLock;
use Core\Security\EncryptionService;
use Modules\SupportDashboard\Repository\SupportInstallationRepository;
use Modules\SupportDashboard\Repository\SupportReportRateLimitRepository;
use Modules\SupportDashboard\Repository\SupportTicketRepository;

/**
 * The limits the three machine routes of the support receiver share —
 * `POST /api/support/tickets`, `…/{reference}/archive` and
 * `/api/support/mail-probes` (issue #1113, SECURITY.md §4).
 *
 * **Why they exist.** Those routes trust an installation on its first
 * call: an unknown id is registered with the secret it presents. The only
 * limit used to be per installation, and a script inventing a new id on
 * every call started each time with a fresh quota — a bcrypt hash, a row,
 * a push notification to every superadmin, a 60 MB archive kept for up to
 * a year, and a `security` journal entry per refusal, without end.
 *
 * What this adds, each one cheap enough to run before what it protects:
 *
 * - **a limit per source address**, counted before any hash, any lookup
 *   and any write — the shape the statistics intake has always had;
 * - **a cap on the journal entries** a refusal writes per source address,
 *   the triage route's rule, so a flood cannot bury a real incident;
 * - **a global quota on new registrations**, every address together, so
 *   a flood spread over many addresses still cannot mint installations;
 * - **a storage quota on the archives**, global and per installation.
 *
 * The last two leave a marker when they refuse, which
 * {@see SupportIntakeAttentionProvider} turns into an attention point: a
 * quota that refuses in silence would look, from the receiver, exactly
 * like a quiet day.
 *
 * Every counter lives in `support_report_rate_limits`, under a blind index
 * whose prefix names its purpose — never the raw address, which is
 * personal data — and is purged by Task\PurgeRateLimitsHandler once
 * {@see self::RETENTION_MINUTES} old.
 */
class SupportIntakeGuard
{
    /**
     * Requests per source address per window, the three routes together.
     * A ticket costs at most three calls (the ticket, its archive, its
     * probe), and an installation is held to five tickets an hour on its
     * own; thirty leaves room for a few installations behind one address
     * — a shared host — and none for a script.
     */
    public const REQUESTS_PER_ADDRESS = 30;
    public const REQUEST_WINDOW_MINUTES = 60;

    /** Journal entries per source address per window for refusals, as the triage route. */
    public const REFUSAL_JOURNAL_LIMIT = 20;

    /**
     * New installations this receiver accepts to register on a ticket,
     * every address together. A real unit arrives once; a dozen in an
     * hour is already a campaign, and the day's cap bounds a slow one.
     */
    public const NEW_INSTALLATIONS_PER_HOUR = 10;
    public const NEW_INSTALLATIONS_PER_DAY = 30;

    /**
     * Five gigabytes of archives kept in all, and three hundred megabytes
     * for any one installation — five archives at the largest size the
     * archive route accepts, which a real unit never comes near.
     */
    public const ARCHIVE_QUOTA_BYTES = 5 * 1024 * 1024 * 1024;
    public const ARCHIVE_QUOTA_PER_INSTALLATION_BYTES = 300 * 1024 * 1024;

    /** How long a counter row is kept: the longest window anything here reads. */
    public const RETENTION_MINUTES = 24 * 60;

    /** The markers a refusing quota leaves for the attention page. */
    public const QUOTA_NEW_INSTALLATIONS = 'new_installations';
    public const QUOTA_ARCHIVE_STORAGE = 'archive_storage';

    /** The advisory locks a registration and an archive are checked and written under. */
    public const REGISTRATION_LOCK = 'support_installation_registration';
    public const ARCHIVE_LOCK = 'support_archive_storage';

    public function __construct(
        private \PDO $pdo,
        private SupportReportRateLimitRepository $rateLimits,
        private SupportInstallationRepository $installations,
        private SupportTicketRepository $tickets,
        private EncryptionService $encryption
    ) {
    }

    /**
     * Whether a request from $clientIp may go on, the attempt counted —
     * false once the address has spent its window. Called before anything
     * that costs: a refused request has hashed nothing and written nothing
     * but its own counter.
     */
    public function admitRequest(string $clientIp): bool
    {
        return $this->rateLimits->reserve(
            $this->encryption->blindIndex('support_intake_ip:' . $clientIp),
            self::since(self::REQUEST_WINDOW_MINUTES),
            self::REQUESTS_PER_ADDRESS
        );
    }

    /**
     * Whether a refusal from $clientIp may still be written to the
     * journal. Past the cap, the request is refused all the same — only
     * the entry is dropped: an entry per refused request is precisely
     * what a flood would bury a real incident under.
     */
    public function mayJournalRefusal(string $clientIp): bool
    {
        return $this->rateLimits->reserve(
            $this->encryption->blindIndex('support_intake_journal_ip:' . $clientIp),
            self::since(self::REQUEST_WINDOW_MINUTES),
            self::REFUSAL_JOURNAL_LIMIT
        );
    }

    /**
     * Run $register — the registration of a new installation — only while
     * the global quota allows it, and return its row id; null when it was
     * refused.
     *
     * Count and insert under one advisory lock, as the rate-limit
     * reservation does: two registrations arriving together must not both
     * count nine and both insert. A registration that finds the lock taken
     * is refused rather than made to wait — the caller answers « réessayez
     * plus tard », which a real unit does, and the quota is not marked as
     * reached, because it was not.
     *
     * @param callable(): int $register
     */
    public function registerWithinQuota(callable $register): ?int
    {
        if (!AdvisoryLock::acquire($this->pdo, self::REGISTRATION_LOCK)) {
            return null;
        }

        try {
            if (
                $this->installations->countFirstSeenSince(self::since(60)) >= self::NEW_INSTALLATIONS_PER_HOUR
                || $this->installations->countFirstSeenSince(self::since(24 * 60)) >= self::NEW_INSTALLATIONS_PER_DAY
            ) {
                $this->markQuotaReached(self::QUOTA_NEW_INSTALLATIONS);

                return null;
            }

            return $register();
        } finally {
            AdvisoryLock::release($this->pdo, self::REGISTRATION_LOCK);
        }
    }

    /**
     * Run $store — keeping an archive of $bytes for this installation —
     * only while both storage quotas leave room for it. Returns whether it
     * ran, and why not: {@see self::QUOTA_ARCHIVE_STORAGE} when a quota is
     * full, `busy` when another archive is being stored at this instant.
     *
     * Under one lock for the same reason as the registrations: two
     * archives checked together could both fit and together overflow.
     *
     * $alreadyKept is asked first, under the same lock: when it answers
     * true the archive is already there — another upload of the same
     * ticket won the race — and nothing is stored, counted or refused.
     * Asked after the quota, a second upload at the boundary would be
     * refused as « full » by the very archive it duplicates.
     *
     * @param callable(): void $store
     * @param (callable(): bool)|null $alreadyKept
     * @return true|string
     */
    public function storeArchiveWithinQuota(
        int $installationRowId,
        int $bytes,
        callable $store,
        ?callable $alreadyKept = null
    ): bool|string {
        if (!AdvisoryLock::acquire($this->pdo, self::ARCHIVE_LOCK)) {
            return 'busy';
        }

        try {
            if ($alreadyKept !== null && $alreadyKept()) {
                return true;
            }

            if (
                $this->tickets->archiveBytes($installationRowId) + $bytes > self::ARCHIVE_QUOTA_PER_INSTALLATION_BYTES
                || $this->tickets->archiveBytes() + $bytes > self::ARCHIVE_QUOTA_BYTES
            ) {
                $this->markQuotaReached(self::QUOTA_ARCHIVE_STORAGE);

                return self::QUOTA_ARCHIVE_STORAGE;
            }

            $store();

            return true;
        } finally {
            AdvisoryLock::release($this->pdo, self::ARCHIVE_LOCK);
        }
    }

    /**
     * How many times $quota refused in the last $minutes — what the
     * attention page reads. Bounded by {@see self::RETENTION_MINUTES}.
     */
    public function quotaRefusalsSince(string $quota, int $minutes): int
    {
        return $this->rateLimits->countSince($this->quotaMarker($quota), self::since($minutes));
    }

    private function markQuotaReached(string $quota): void
    {
        $this->rateLimits->record($this->quotaMarker($quota));
    }

    /**
     * A blind index like every other counter of the table, so a marker
     * fits its CHAR(64) column and can never collide with an address's.
     */
    private function quotaMarker(string $quota): string
    {
        return $this->encryption->blindIndex('support_quota:' . $quota);
    }

    private static function since(int $minutes): string
    {
        return (new \DateTimeImmutable('-' . $minutes . ' minutes'))->format('Y-m-d H:i:s');
    }
}
