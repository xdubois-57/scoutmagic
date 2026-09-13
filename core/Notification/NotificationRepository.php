<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Notification;

use Core\Security\EncryptionService;

class NotificationRepository
{
    /**
     * How many urls deleteOfTypeWithUrls() binds per statement — see the
     * reason there.
     */
    private const URL_BATCH_SIZE = 500;

    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    public function create(
        int $userAccountId,
        ?int $memberId,
        string $typeId,
        string $title,
        string $body,
        ?string $url
    ): int
    {
        // created_at from PHP rather than the column's DEFAULT
        // CURRENT_TIMESTAMP: the notification list groups rows into
        // "Aujourd'hui" / "Hier" / a date by comparing this value against
        // PHP's own idea of today (Http\Controller\NotificationController),
        // and the retention purge cuts on it the same way — a notification
        // written on a different clock lands under the wrong heading for
        // the first hours of every day.
        $stmt = $this->pdo->prepare(
            'INSERT INTO notifications (user_account_id, member_id, type_id, title, body, url, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userAccountId,
            $memberId,
            $typeId,
            $this->encryption->encrypt($title, 'notifications.title'),
            $this->encryption->encrypt($body, 'notifications.body'),
            $url,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?NotificationRecord
    {
        $stmt = $this->pdo->prepare('SELECT * FROM notifications WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * Most recent notifications for an account, newest first — the
     * notification centre.
     *
     * @return NotificationRecord[]
     */
    public function findByUserAccountId(int $userAccountId, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM notifications WHERE user_account_id = ?
              ORDER BY created_at DESC, id DESC
              LIMIT ? OFFSET ?'
        );
        $stmt->bindValue(1, $userAccountId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->bindValue(3, max(0, $offset), \PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * How many notifications this account has in all — what a page count
     * is made of.
     *
     * The centre used to render one hard-capped screenful of a hundred and
     * say nothing about the rest: an account with more had them silently
     * out of reach, and nothing on the page even hinted they existed. A
     * count is what turns that cap into a page.
     */
    public function countByUserAccountId(int $userAccountId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_account_id = ?');
        $stmt->execute([$userAccountId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * The unread notifications of one type, as just their routing facts —
     * url and creation time, never title/body (both encrypted; a caller
     * counting mentions per group has no business decrypting anything).
     * The groups module's homepage summary filters these by the group id
     * embedded in the url and by each group's own last-read time, so a
     * mention stops counting once its group has actually been opened.
     *
     * @return array<int, array{url: ?string, created_at: string}>
     */
    public function findUnreadOfType(int $userAccountId, string $typeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT url, created_at FROM notifications
             WHERE user_account_id = ? AND type_id = ? AND read_at IS NULL'
        );
        $stmt->execute([$userAccountId, $typeId]);

        return array_map(
            static fn(array $row) => [
                'url' => $row['url'] !== null ? (string) $row['url'] : null,
                'created_at' => (string) $row['created_at'],
            ],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    public function countUnread(int $userAccountId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_account_id = ? AND read_at IS NULL');
        $stmt->execute([$userAccountId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Most recent unread notification for an account — feeds the nav
     * notification indicator's "last one" preview (partials/
     * notification_dropdown.html.twig). Deliberately unread-only, not just
     * "most recent overall": the most recent notification can already be
     * read while an older one is still unread (e.g. the member followed a
     * direct link to a newer notification without visiting the centre),
     * and showing an already-read notification in a "pending" indicator
     * would misrepresent what's actually waiting for attention.
     */
    /**
     * The most recent unread notifications, newest first — what the
     * bell's own dropdown shows.
     *
     * Several rather than one: the panel already announces the count
     * ("3 notifications non lues") and used to list a single row under
     * it, which reads as the other two having gone missing. Bounded
     * because a dropdown is a preview and /notifications is the list.
     *
     * @return NotificationRecord[]
     */
    public function findRecentUnread(int $userAccountId, int $limit = 5): array
    {
        // Interpolated, never bound: an int this class computes, and
        // MySQL refuses a bound parameter in LIMIT with emulation off —
        // the same rule the module repositories follow.
        $limit = max(1, min(50, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT * FROM notifications WHERE user_account_id = ? AND read_at IS NULL
             ORDER BY created_at DESC, id DESC LIMIT ' . $limit
        );
        $stmt->execute([$userAccountId]);

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Claims a notification for its email copy: stamps `email_sent_at`
     * only if it is still NULL, and answers whether THIS caller is the one
     * that won the claim.
     *
     * One conditional UPDATE rather than a read-then-write, so two
     * scheduler runs racing over the same batch cannot both conclude "not
     * sent yet" and both send. The stamp goes on before the mail transport
     * is called: a send that then fails is a notification the recipient
     * misses by email, which is recoverable and visible in their centre —
     * whereas stamping afterwards would send the same message twice every
     * time the process died mid-flush, which is not.
     */
    public function claimForEmail(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE notifications SET email_sent_at = ? WHERE id = ? AND email_sent_at IS NULL'
        );
        $stmt->execute([(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $id]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Releases a claim taken by claimForEmail() — used when the send was
     * never actually attempted (no address, no mail transport), so the
     * row does not stay marked as if an email had gone out.
     */
    public function releaseEmailClaim(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE notifications SET email_sent_at = NULL WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function markRead(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE notifications SET read_at = ? WHERE id = ? AND read_at IS NULL');
        $stmt->execute([(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $id]);
    }

    public function markAllReadForUser(int $userAccountId): void
    {
        $stmt = $this->pdo->prepare('UPDATE notifications SET read_at = ? WHERE user_account_id = ? AND read_at IS '
            . 'NULL');
        $stmt->execute([(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $userAccountId]);
    }

    /**
     * Retention purge (Configuration > Notifications, default 90 days,
     * Core\Notification\Task\PurgeNotificationsHandler) — only ever
     * touches READ notifications; an unread one is never purged
     * regardless of age, per module spec.
     */
    public function deleteReadOlderThan(\DateTimeInterface $cutoff): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM notifications WHERE read_at IS NOT NULL AND read_at < ?');
        $stmt->execute([$cutoff->format('Y-m-d H:i:s')]);

        return $stmt->rowCount();
    }

    /**
     * Erasure purge for NAMED notifications — read or not, unlike the
     * retention purge above.
     *
     * The two answer different questions, and that is why this exists
     * rather than a flag on the other. `deleteReadOlderThan()` serves
     * TIDINESS: a notification somebody has seen has done its job, and
     * leaving an unread one alone is a promise that nothing vanishes
     * before it is read. This one serves ERASURE: a notification whose
     * body carries a value the site has undertaken to delete cannot wait
     * to be read, because nobody may ever read it — and `read_at IS NOT
     * NULL` is precisely what made such a row immortal (issue #292).
     *
     * `body` is written once at dispatch and never recomputed, so the only
     * way a stored value stops existing is for the row to go.
     *
     * **It takes the exact urls, not a type and an age**, and that is the
     * whole safety of it. A type is not a data class: a module can dispatch
     * one declared type from several paths, only some of which store
     * something erasable, and deleting by type would silently take the
     * others with it — unread, under a retention rule that was never about
     * them. So the caller names the rows: it knows which records it is
     * erasing, and `url` is what ties a notification to one of them.
     * Anything this method is not handed keeps the site-wide promise above.
     *
     * `type_id` narrows rather than selects — a url belongs to a route, and
     * pairing it with the type the caller owns is what stops a collision
     * with some other type pointing at the same page.
     *
     * @param string $typeId the declared notification type, e.g.
     *        `mass_mail.email_received`
     * @param string[] $urls exact `notifications.url` values; an empty list
     *        deletes nothing
     */
    public function deleteOfTypeWithUrls(string $typeId, array $urls): int
    {
        $urls = array_values(array_unique($urls));
        if ($urls === []) {
            return 0;
        }

        // Batched, because the caller's list is as long as the audiences it
        // is erasing and nothing bounds that. Connection sets
        // ATTR_EMULATE_PREPARES => false, so these are native prepares and
        // MySQL refuses more than 65 535 parameters in one statement — the
        // `type_id` binding included. Sending them all at once would throw
        // exactly when there is most to erase, and the caller has by then
        // already deleted the audience rows that are the only way to
        // rebuild these urls: the personal values would be stranded, not
        // merely unpurged. BATCH_SIZE is far below the limit because there
        // is nothing to gain from approaching it.
        $deleted = 0;
        foreach (array_chunk($urls, self::URL_BATCH_SIZE) as $batch) {
            $placeholders = implode(',', array_fill(0, count($batch), '?'));
            $stmt = $this->pdo->prepare(
                'DELETE FROM notifications WHERE type_id = ? AND url IN (' . $placeholders . ')'
            );
            $stmt->execute(array_merge([$typeId], $batch));
            $deleted += $stmt->rowCount();
        }

        return $deleted;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): NotificationRecord
    {
        return new NotificationRecord(
            id: (int) $row['id'],
            userAccountId: (int) $row['user_account_id'],
            memberId: $row['member_id'] !== null ? (int) $row['member_id'] : null,
            typeId: (string) $row['type_id'],
            title: $this->encryption->decrypt($this->readBlob($row['title']), 'notifications.title'),
            body: $this->encryption->decrypt($this->readBlob($row['body']), 'notifications.body'),
            url: $row['url'] !== null ? (string) $row['url'] : null,
            readAt: $row['read_at'] !== null ? (string) $row['read_at'] : null,
            createdAt: (string) $row['created_at'],
            emailSentAt: isset($row['email_sent_at']) ? (string) $row['email_sent_at'] : null
        );
    }

    private function readBlob(mixed $value): string
    {
        return is_resource($value) ? (string) stream_get_contents($value) : (string) $value;
    }
}
