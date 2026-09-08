<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail;

/**
 * "Have we already written to this person about this?" — the guard a
 * background e-mail handler needs so a REPLAY does not send again.
 *
 * `Core\Scheduler\SchedulerRunner` marks a task done only after
 * `handle()` returns, so an abrupt stop between an effect and that mark
 * replays the whole handler. `Core\Notification\NotificationRepository
 * ::claimForEmail()` closed that window for notifications, on the
 * notification's own row; three handlers had no such row, recomputing
 * their recipients on every run, and replayed their whole send (issue
 * #246). This is the same claim for them — see `sent_email_claims` in
 * `schema/core.sql` for why it holds ids and never an address.
 *
 * **One conditional INSERT, never a read then a write.** Two scheduler
 * passes racing over the same recipient both read "not sent yet" and
 * both send; the UNIQUE key arbitrates instead, and exactly one of them
 * comes back having won.
 */
class SentEmailClaimRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Takes the claim for one recipient, or reports that somebody else
     * already holds it. Call this BEFORE the transport: a send that then
     * fails is one message somebody misses, which is recoverable and
     * visible in the journal, whereas claiming afterwards sends twice
     * every time the process dies mid-flush.
     *
     * @return bool true when this caller now owns the send
     */
    public function claim(string $scope, string $recipientKey): bool
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sent_email_claims (scope, recipient_key, claimed_at) VALUES (?, ?, ?)'
        );

        try {
            $stmt->execute([$scope, $recipientKey, (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
        } catch (\PDOException $exception) {
            // 23000 is the SQLSTATE both MySQL/MariaDB and SQLite raise
            // for a UNIQUE violation, and it is the ONLY reason to answer
            // "already claimed": a connection loss or a missing table
            // must surface as itself rather than as a silently skipped
            // recipient (same rule as Modules\MassMail\Repository\
            // SuppressedAddressRepository::suppress()).
            if (($exception->errorInfo[0] ?? '') !== '23000') {
                throw $exception;
            }

            return false;
        }

        return true;
    }

    /**
     * Gives a claim back — for a send that was never actually attempted
     * (no address, no transport, the recipient vanished between two
     * queries), so the row does not stand as if a message had gone out.
     * Mirrors NotificationRepository::releaseEmailClaim().
     */
    public function release(string $scope, string $recipientKey): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM sent_email_claims WHERE scope = ? AND recipient_key = ?');
        $stmt->execute([$scope, $recipientKey]);
    }

    /**
     * @return int the number of claims dropped
     */
    public function deleteClaimedBefore(string $cutoff): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM sent_email_claims WHERE claimed_at < ?');
        $stmt->execute([$cutoff]);

        return $stmt->rowCount();
    }
}
