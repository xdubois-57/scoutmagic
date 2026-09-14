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
            $this->encryption->encrypt($this->encode($payload), self::CONTEXT),
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
        // The limit is bound rather than written into the statement.
        // `int` makes concatenation safe here today, and that is exactly
        // the argument the rule refuses: every statement is prepared, and
        // a value in the SQL text is a defect whatever its provenance
        // (AGENTS.md).
        $statement = $this->pdo->prepare(
            'SELECT * FROM mail_deferred_messages
             WHERE status = ? AND next_attempt_at <= ?
             ORDER BY next_attempt_at, id
             LIMIT ?'
        );
        $statement->bindValue(1, DeferredMessage::STATUS_PENDING, PDO::PARAM_STR);
        $statement->bindValue(2, $now ?? date('Y-m-d H:i:s'), PDO::PARAM_STR);
        $statement->bindValue(3, max(1, $limit), PDO::PARAM_INT);
        $statement->execute();

        $due = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            try {
                $due[] = $this->hydrate($row);
            } catch (\Throwable) {
                // **One unreadable row must not stop the queue.** Decryption
                // throws on a damaged payload or one written under a key that
                // has since changed, and `due()` orders by date — so a row
                // that threw out of this method would be first again on every
                // later pass, and the whole drain, purge included, would stop
                // for good.
                //
                // Abandoned rather than skipped, because skipping would leave
                // it pending for ever: a message whose contents cannot be
                // read can never be sent, and saying so is the only honest
                // thing left to do with it. It keeps its retention like any
                // other abandoned message, so somebody who notices has the
                // window to ask why.
                $this->abandon((int) $row['id'], (int) $row['attempts'], 'contenu illisible');
            }
        }

        return $due;
    }

    /**
     * The abandoned messages a relaunch would take, by id only.
     *
     * Same reasoning as {@see abandonedCreatedAt()}: reviving a message
     * is an UPDATE keyed on its id, and decrypting a body to find out
     * which ids those are would bring hundreds of people's e-mails into
     * memory to do arithmetic on a primary key (D18). Nothing on the
     * Relance path ever reads an abandoned message's contents, which is
     * why there is no method that would.
     *
     * **The window is measured from `settled_at`, not `created_at`**, and
     * the difference is the whole usefulness of the dialog. A message is
     * only abandoned once its next backoff step would land past its
     * deadline — with the default lifetime that is twenty hours after it
     * was queued at the earliest. Filtering on `created_at` would make
     * the six-hour window structurally unable to match anything, so the
     * button a volunteer is offered by default would always relaunch
     * zero messages. What they mean by « the last six hours » is « since
     * the outage », and the outage is when the site gave up, not when the
     * message was first written.
     *
     * @param string|null $since Given up on at or after this moment — the
     *        Relance dialog's window (D17).
     * @return array<int, int>
     */
    public function abandonedIds(?MailLane $lane = null, ?string $since = null): array
    {
        $sql = 'SELECT id FROM mail_deferred_messages WHERE status = ?';
        $parameters = [DeferredMessage::STATUS_ABANDONED];

        if ($lane !== null) {
            $sql .= ' AND lane = ?';
            $parameters[] = $lane->value;
        }

        if ($since !== null) {
            $sql .= ' AND settled_at >= ?';
            $parameters[] = $since;
        }

        $statement = $this->pdo->prepare($sql . ' ORDER BY settled_at DESC, id DESC');
        $statement->execute($parameters);

        return array_map(
            static fn(array $row): int => (int) $row['id'],
            $statement->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * When each abandoned message was GIVEN UP ON, and nothing else.
     *
     * `settled_at` for the same reason {@see abandonedIds()} filters on
     * it: the age that means something to a reader is the age of the
     * failure, not of the message. Reading `created_at` would put every
     * abandoned message in the « more than a day » bucket on arrival and
     * leave the two recent ones permanently empty.
     *
     * Decryption never enters into it: counting by age reads a timestamp
     * sitting in plain text one column over from a body it has no reason
     * to look at (D18).
     *
     * @return array<int, string> `settled_at`, newest first
     */
    public function abandonedSettledAt(?MailLane $lane = null): array
    {
        $sql = 'SELECT settled_at FROM mail_deferred_messages WHERE status = ?';
        $parameters = [DeferredMessage::STATUS_ABANDONED];

        if ($lane !== null) {
            $sql .= ' AND lane = ?';
            $parameters[] = $lane->value;
        }

        $statement = $this->pdo->prepare($sql . ' ORDER BY settled_at DESC, id DESC');
        $statement->execute($parameters);

        return array_map(
            static fn(array $row): string => (string) $row['settled_at'],
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
     * The payload as JSON, with the attachment bytes in base64.
     *
     * **`json_encode()` refuses invalid UTF-8**, and an attachment is raw
     * bytes: a real PDF, a JPEG or a ZIP is almost never valid UTF-8, so
     * encoding one straight returns `false`. Cast to string that is `''`,
     * which encrypts and stores perfectly well — leaving a row that says
     * a message is waiting and whose contents are gone, while the sender
     * was told it was on its way. Base64 is what makes the bytes
     * expressible; the guard below is what makes anything else loud.
     *
     * @param array{attachments: array<int, array{name: string, content: string}>, ...} $payload
     */
    private function encode(array $payload): string
    {
        foreach ($payload['attachments'] as $index => $attachment) {
            $payload['attachments'][$index]['content'] = base64_encode($attachment['content']);
        }

        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        return $json;
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

        if (!is_array($decoded) || !isset($decoded['to'], $decoded['attachments'])) {
            // Decrypted, but not a call this class can replay — a payload
            // from a version that shaped it differently, or one damaged
            // in a way the cipher's own seal did not catch. Thrown rather
            // than patched up with defaults, because `due()` is what turns
            // an unreadable row into an abandoned one, and a message
            // fabricated here would be sent to nobody with no subject.
            throw new \RuntimeException('Deferred payload is not a replayable call.');
        }

        /**
         * @var array{
         *     to: string, subject: string, bodyHtml: string, bodyText: string,
         *     replyTo: ?string, fromAddressOverride: ?string, fromNameOverride: ?string,
         *     extraHeaders: array<string, string>,
         *     attachments: array<int, array{name: string, content: string}>
         * } $payload
         */
        $payload = $decoded;

        foreach ($payload['attachments'] as $index => $attachment) {
            $content = base64_decode((string) $attachment['content'], true);
            if ($content === false) {
                // Damaged, or written by a version that stored raw bytes.
                // **Thrown, not skipped.** Leaving the entry as it stands
                // would hand the drain the base64 text itself, which it
                // writes to disk without looking and delivers under the
                // original file name — a recipient opening a « recu.pdf »
                // full of ASCII, and the row deleted as a clean success.
                // `due()` catches this and abandons the row, which is the
                // one place that can write the decision down.
                throw new \RuntimeException('Deferred attachment is not readable.');
            }

            $payload['attachments'][$index]['content'] = $content;
        }

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
