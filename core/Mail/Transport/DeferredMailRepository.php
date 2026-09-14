<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Transport;

use Core\Mail\MailPurpose;
use Core\Security\EncryptionService;
use PDO;

/**
 * The deferral queue's one door to the database (D9, D18).
 *
 * **Every message here carries a recipient address and a body**, so the
 * payload is a BLOB through {@see EncryptionService} and is decrypted
 * nowhere but in this class — the rule `AGENTS.md` states as point 3 of
 * its checklist, and the reason this is a repository rather than a few
 * queries spread over a service.
 *
 * **What is stored is the CALL, not the message.** The payload holds the
 * arguments `MailService::send()` was given; draining the queue replays
 * that call. The alternative — keeping the assembled MIME — would have
 * meant a second way of putting mail on the wire, one that signs
 * nothing, routes through no lane, counts against no quota and is
 * invisible to the sandbox. Everything downstream of `send()` stays the
 * single path precisely because this queue stops short of it.
 *
 * Attachments are stored by CONTENT, not by path. `send()` takes
 * filesystem paths, and the ones it is given are routinely temporary
 * files that will not exist when the message finally leaves; storing the
 * path would have produced a message that drains successfully and
 * silently arrives without its receipt.
 */
final class DeferredMailRepository
{
    private const CONTEXT = 'mail_deferred_messages.payload';

    public function __construct(
        private PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * @param array{
     *     to: string, subject: string, bodyHtml: string, bodyText: string,
     *     replyTo: ?string, fromAddressOverride: ?string, fromNameOverride: ?string,
     *     extraHeaders: array<string, string>,
     *     attachments: array<int, array{name: string, content: string}>
     * } $payload
     */
    public function add(
        MailLane $lane,
        MailPurpose $purpose,
        array $payload,
        string $reason,
        string $nextAttemptAt,
        string $expiresAt
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO mail_deferred_messages
                 (lane, purpose, payload_encrypted, status, attempts, last_reason, next_attempt_at, expires_at)
             VALUES (?, ?, ?, ?, 0, ?, ?, ?)'
        );
        $statement->execute([
            $lane->value,
            $purpose->value,
            $this->encryption->encrypt((string) json_encode($payload), self::CONTEXT),
            DeferredMessage::STATUS_PENDING,
            mb_substr($reason, 0, 255),
            $nextAttemptAt,
            $expiresAt,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * The messages whose next attempt is due, oldest first.
     *
     * @return array<int, DeferredMessage>
     */
    public function due(int $limit, ?string $now = null): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM mail_deferred_messages
             WHERE status = ? AND next_attempt_at <= ?
             ORDER BY next_attempt_at, id
             LIMIT ' . max(1, $limit)
        );
        $statement->execute([DeferredMessage::STATUS_PENDING, $now ?? date('Y-m-d H:i:s')]);

        return array_map(
            fn(array $row): DeferredMessage => $this->hydrate($row),
            $statement->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * Messages given up on, newest first — what the Relance dialog offers
     * (D17).
     *
     * @return array<int, DeferredMessage>
     */
    public function abandoned(?MailLane $lane = null, ?string $since = null): array
    {
        $sql = 'SELECT * FROM mail_deferred_messages WHERE status = ?';
        $parameters = [DeferredMessage::STATUS_ABANDONED];

        if ($lane !== null) {
            $sql .= ' AND lane = ?';
            $parameters[] = $lane->value;
        }

        if ($since !== null) {
            $sql .= ' AND created_at >= ?';
            $parameters[] = $since;
        }

        $statement = $this->pdo->prepare($sql . ' ORDER BY created_at DESC, id DESC');
        $statement->execute($parameters);

        return array_map(
            fn(array $row): DeferredMessage => $this->hydrate($row),
            $statement->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * How many messages are waiting, per lane — the counter the page
     * shows, because « un report n'est pas un silence » (D9).
     *
     * @return array<string, int> lane value => messages
     */
    public function pendingCountByLane(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT lane, COUNT(*) AS waiting FROM mail_deferred_messages WHERE status = ? GROUP BY lane'
        );
        $statement->execute([DeferredMessage::STATUS_PENDING]);

        $counts = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[(string) $row['lane']] = (int) $row['waiting'];
        }

        return $counts;
    }

    public function countAbandoned(): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM mail_deferred_messages WHERE status = ?');
        $statement->execute([DeferredMessage::STATUS_ABANDONED]);

        return (int) $statement->fetchColumn();
    }

    /** The message left; nothing needs to be kept, body included. */
    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM mail_deferred_messages WHERE id = ?')->execute([$id]);
    }

    /** It failed again, and is due once more later (D16). */
    public function reschedule(int $id, int $attempts, string $reason, string $nextAttemptAt): void
    {
        $this->pdo->prepare(
            'UPDATE mail_deferred_messages SET attempts = ?, last_reason = ?, next_attempt_at = ? WHERE id = ?'
        )->execute([$attempts, mb_substr($reason, 0, 255), $nextAttemptAt, $id]);
    }

    /**
     * Its deadline passed. The row stays — with its body — so the Relance
     * screen can offer it, until the retention purge takes it (D17).
     */
    public function abandon(int $id, int $attempts, string $reason, ?string $now = null): void
    {
        $this->pdo->prepare(
            'UPDATE mail_deferred_messages
             SET status = ?, attempts = ?, last_reason = ?, settled_at = ? WHERE id = ?'
        )->execute([
            DeferredMessage::STATUS_ABANDONED,
            $attempts,
            mb_substr($reason, 0, 255),
            $now ?? date('Y-m-d H:i:s'),
            $id,
        ]);
    }

    /**
     * Put an abandoned message back in the queue with a fresh deadline —
     * the Relance dialog's one write (D17).
     */
    public function revive(int $id, string $nextAttemptAt, string $expiresAt): void
    {
        $this->pdo->prepare(
            'UPDATE mail_deferred_messages
             SET status = ?, attempts = 0, last_reason = \'\', next_attempt_at = ?, expires_at = ?, settled_at = NULL
             WHERE id = ? AND status = ?'
        )->execute([
            DeferredMessage::STATUS_PENDING,
            $nextAttemptAt,
            $expiresAt,
            $id,
            DeferredMessage::STATUS_ABANDONED,
        ]);
    }

    /**
     * Drop abandoned messages older than the retention — with their
     * bodies, which is the point (D18).
     */
    public function purgeAbandonedBefore(string $day): int
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM mail_deferred_messages WHERE status = ? AND settled_at < ?'
        );
        $statement->execute([DeferredMessage::STATUS_ABANDONED, $day]);

        return $statement->rowCount();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): DeferredMessage
    {
        $decoded = json_decode(
            $this->encryption->decrypt((string) $row['payload_encrypted'], self::CONTEXT),
            true
        );

        /**
         * @var array{
         *     to: string, subject: string, bodyHtml: string, bodyText: string,
         *     replyTo: ?string, fromAddressOverride: ?string, fromNameOverride: ?string,
         *     extraHeaders: array<string, string>,
         *     attachments: array<int, array{name: string, content: string}>
         * } $payload
         */
        $payload = is_array($decoded) ? $decoded : [];

        return new DeferredMessage(
            (int) $row['id'],
            MailLane::from((string) $row['lane']),
            MailPurpose::from((string) $row['purpose']),
            $payload,
            (string) $row['status'],
            (int) $row['attempts'],
            (string) $row['last_reason'],
            (string) $row['next_attempt_at'],
            (string) $row['expires_at'],
            (string) $row['created_at'],
            $row['settled_at'] === null ? null : (string) $row['settled_at']
        );
    }
}
