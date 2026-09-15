<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback\Bounce;

use Core\Security\EncryptionService;
use Core\Service\DateInput;
use PDO;

/**
 * Reads and writes `mail_bounce_states` — the only place the stored
 * address is decrypted (roadmap IT-05, AGENTS.md checklist point 3).
 */
class BounceStateRepository
{
    /** The encryption context for the stored address. */
    private const CONTEXT = 'mail_bounce_states.email';

    /**
     * The SHARED identity purpose, not one of this table's own.
     *
     * `EncryptionService::blindIndex()` states the rule: indexes
     * deliberately compared across tables must share one purpose. This one
     * is compared against `member_emails`, to answer « qui possède cette
     * adresse » when a bounce arrives naming nothing but a mailbox.
     */
    private const BLIND_INDEX_PURPOSE = 'email';

    /** How many rows the screens list before asking for a narrower question. */
    public const RECENT_LIMIT = 100;

    public function __construct(private PDO $pdo, private EncryptionService $encryption)
    {
    }

    public function find(string $email): ?BounceState
    {
        $statement = $this->pdo->prepare($this->selectClause() . ' WHERE email_blind_index = ?');
        $statement->execute([$this->blindIndex($email)]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findById(int $id): ?BounceState
    {
        $statement = $this->pdo->prepare($this->selectClause() . ' WHERE id = ?');
        $statement->execute([$id]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * Record one bounce against one address, creating the row or adding to
     * it.
     *
     * `$failures` only ever counts PERMANENT failures: a transient bounce
     * updates what is known — category, code, when — without moving the
     * address any closer to being blocked. That asymmetry is the whole of
     * « un temporaire est réessayé ».
     */
    public function record(
        string $email,
        BounceCategory $category,
        BounceSeverity $severity,
        string $statusCode,
        \DateTimeImmutable $now
    ): BounceState {
        $existing = $this->find($email);
        $stamp = $now->format('Y-m-d H:i:s');

        if ($existing === null) {
            $statement = $this->pdo->prepare(
                'INSERT INTO mail_bounce_states
                    (email_encrypted, email_blind_index, category, severity, status_code,
                     failures, first_seen_at, last_seen_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([
                $this->encryption->encrypt($email, self::CONTEXT),
                $this->blindIndex($email),
                $category->value,
                $severity->value,
                $statusCode,
                $severity === BounceSeverity::Permanent ? 1 : 0,
                $stamp,
                $stamp,
            ]);

            $state = $this->find($email);
            if ($state === null) {
                throw new \RuntimeException('The bounce state was written and could not be read back.');
            }

            return $state;
        }

        $failures = $existing->failures + ($severity === BounceSeverity::Permanent ? 1 : 0);

        $statement = $this->pdo->prepare(
            'UPDATE mail_bounce_states
                SET category = ?, severity = ?, status_code = ?, failures = ?, last_seen_at = ?
              WHERE id = ?'
        );
        $statement->execute([
            $category->value,
            $severity->value,
            $statusCode,
            $failures,
            $stamp,
            $existing->id,
        ]);

        return new BounceState(
            $existing->id,
            $email,
            $category,
            $severity,
            $statusCode,
            $failures,
            $existing->firstSeenAt,
            $now,
            $existing->blockedAt,
            $existing->notifiedCode,
            $existing->lastSendAt
        );
    }

    /** Stop writing to this address. */
    public function block(int $id, \DateTimeImmutable $now): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE mail_bounce_states SET blocked_at = ? WHERE id = ? AND blocked_at IS NULL'
        );
        $statement->execute([$now->format('Y-m-d H:i:s'), $id]);
    }

    /**
     * Lift the block and put the counter back to zero.
     *
     * Both, together, and that is the point: unblocking without resetting
     * would re-block on the very next bounce, so the member's gesture
     * would buy them one message. It takes N failures again, exactly as if
     * the address had never bounced.
     *
     * `notified_code` is cleared too — an error the member has just acted
     * on is an error they should be told about again if it comes back.
     */
    public function unblock(int $id): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE mail_bounce_states
                SET blocked_at = NULL, failures = 0, notified_code = NULL
              WHERE id = ?'
        );
        $statement->execute([$id]);
    }

    /** Remember that the member has been told about this particular error. */
    public function markNotified(int $id, string $statusCode): void
    {
        $statement = $this->pdo->prepare('UPDATE mail_bounce_states SET notified_code = ? WHERE id = ?');
        $statement->execute([$statusCode, $id]);
    }

    /**
     * Note that a message for this address has just gone to a relay, and
     * settle the previous send while we are here.
     *
     * **A send cannot clear the counter at the moment it happens**, and
     * getting that wrong would have disabled the whole mechanism in a way
     * no test of the blocking rule would have caught: the relay accepting
     * a message says nothing about delivery, and the bounce for that very
     * send arrives seconds later. Clearing on acceptance would therefore
     * wipe the count before every single bounce, and no address would ever
     * reach the threshold.
     *
     * So each send judges the one before it. If the previous send is more
     * recent than the last bounce, nothing came back from it — the address
     * works, and everything known about its failures stops being true. The
     * row is then deleted rather than zeroed: a state recording no
     * failure, no block and no pending notification says nothing, and
     * keeping one would grow a table with an entry per address the unit
     * has ever written to.
     *
     * The one-send lag is inherent, not a shortcut. A send is only known
     * to have worked once there has been time for it not to bounce, and
     * the next send is the natural moment to look.
     */
    public function recordSend(string $email, \DateTimeImmutable $now): void
    {
        $existing = $this->find($email);
        if ($existing === null) {
            // The overwhelming majority of sends are to addresses that
            // have never bounced. Nothing to settle and nothing to write.
            return;
        }

        if ($existing->lastSendWasClean()) {
            $this->forget($email);

            return;
        }

        $statement = $this->pdo->prepare('UPDATE mail_bounce_states SET last_send_at = ? WHERE id = ?');
        $statement->execute([$now->format('Y-m-d H:i:s'), $existing->id]);
    }

    /**
     * Drop everything known about an address — used when a send settles a
     * clean one, and by the tests that need a blank slate.
     */
    public function forget(string $email): void
    {
        $statement = $this->pdo->prepare('DELETE FROM mail_bounce_states WHERE email_blind_index = ?');
        $statement->execute([$this->blindIndex($email)]);
    }

    /**
     * Every address currently blocked, newest first — what the super-admin
     * screen lists.
     *
     * @return list<BounceState>
     */
    public function blocked(int $limit = self::RECENT_LIMIT): array
    {
        $statement = $this->pdo->prepare(
            $this->selectClause() . ' WHERE blocked_at IS NOT NULL ORDER BY blocked_at DESC LIMIT ?'
        );
        $statement->bindValue(1, max(1, $limit), PDO::PARAM_INT);
        $statement->execute();

        return array_map(
            fn(array $row): BounceState => $this->hydrate($row),
            $statement->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function countBlocked(): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM mail_bounce_states WHERE blocked_at IS NOT NULL');
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    private function blindIndex(string $email): string
    {
        return $this->encryption->blindIndex(
            EncryptionService::normalizeEmailForIndex($email),
            self::BLIND_INDEX_PURPOSE
        );
    }

    private function selectClause(): string
    {
        return 'SELECT id, email_encrypted, category, severity, status_code, failures,
                       first_seen_at, last_seen_at, blocked_at, notified_code, last_send_at
                  FROM mail_bounce_states';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): BounceState
    {
        return new BounceState(
            (int) $row['id'],
            $this->encryption->decrypt((string) $row['email_encrypted'], self::CONTEXT),
            BounceCategory::from((string) $row['category']),
            BounceSeverity::from((string) $row['severity']),
            (string) $row['status_code'],
            (int) $row['failures'],
            DateInput::requireFromStorage($row['first_seen_at'], 'mail_bounce_states.first_seen_at'),
            DateInput::requireFromStorage($row['last_seen_at'], 'mail_bounce_states.last_seen_at'),
            DateInput::fromStorage($row['blocked_at']),
            $row['notified_code'] === null ? null : (string) $row['notified_code'],
            DateInput::fromStorage($row['last_send_at'])
        );
    }
}
