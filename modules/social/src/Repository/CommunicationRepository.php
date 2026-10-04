<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Repository;

use Core\Service\DateInput;

class CommunicationRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function find(int $id): ?Communication
    {
        $stmt = $this->pdo->prepare('SELECT * FROM social_communications WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    /**
     * `$sourceKind` and `$sourceId` go together: what this communication
     * shares, or nothing at all. The caller has already asked the owning
     * module whether this viewer may share it — this layer only records
     * the answer.
     */
    public function create(
        string $title,
        string $body,
        ?int $createdBy,
        \DateTimeImmutable $now,
        ?string $sourceKind = null,
        ?int $sourceId = null
    ): int {
        $stamp = $now->format('Y-m-d H:i:s');
        $this->pdo->prepare(
            'INSERT INTO social_communications'
            . ' (title, body, created_by, created_at, updated_at, source_kind, source_id)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$title, $body, $createdBy, $stamp, $stamp, $sourceKind, $sourceId]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * The id of a communication this very POST appears to have created
     * already, or null.
     *
     * **Why this exists.** Publishing a source-backed share creates the
     * row and publishes it in one request, and publications are recorded
     * against the COMMUNICATION's own id — so a replayed POST creates a
     * second row with a second id, whose
     * `(source_kind, source_id, destination)` key differs from the
     * first's and therefore passes the unique constraint. The album went
     * out publicly twice. The retired `/partage/album/{id}` route
     * published against the album's own stable key, so a replay hit that
     * constraint and did nothing; routing through `store()` lost the
     * protection (raised in review on the pull request for IT-01).
     *
     * **Why the body is part of the match.** Sharing the same album again
     * with a DIFFERENT message is deliberate and allowed. A replay
     * carries byte-identical fields, so comparing the text tells the two
     * apart without a token. The window keeps it to a replay rather than
     * a decision taken later.
     */
    public function recentTwin(
        string $sourceKind,
        int $sourceId,
        string $body,
        ?int $createdBy,
        \DateTimeImmutable $since
    ): ?int {
        // The author is compared with an explicit IS NULL branch rather
        // than MySQL's `<=>`, which SQLite — what the suite runs on
        // outside the `database` group — does not know.
        $author = $createdBy === null ? 'created_by IS NULL' : 'created_by = ?';
        $parameters = [$sourceKind, $sourceId, $body];
        if ($createdBy !== null) {
            $parameters[] = $createdBy;
        }
        $parameters[] = $since->format('Y-m-d H:i:s');

        $statement = $this->pdo->prepare(
            'SELECT id FROM social_communications'
            . ' WHERE source_kind = ? AND source_id = ? AND body = ?'
            . ' AND ' . $author . ' AND created_at >= ?'
            . ' ORDER BY id DESC LIMIT 1'
        );
        $statement->execute($parameters);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function updateText(int $id, string $title, string $body, \DateTimeImmutable $now): void
    {
        $this->pdo->prepare('UPDATE social_communications SET title = ?, body = ?, updated_at = ? WHERE id = ?')
            ->execute([$title, $body, $now->format('Y-m-d H:i:s'), $id]);
    }

    /** One image or the other: choosing a gallery photo forgets the uploaded file, and back. */
    public function useGalleryPhoto(int $id, int $mediaId, \DateTimeImmutable $now): void
    {
        $this->pdo->prepare(
            'UPDATE social_communications SET gallery_media_id = ?, file_id = NULL, updated_at = ? WHERE id = ?'
        )->execute([$mediaId, $now->format('Y-m-d H:i:s'), $id]);
    }

    public function useUploadedFile(int $id, int $fileId, \DateTimeImmutable $now): void
    {
        $this->pdo->prepare(
            'UPDATE social_communications SET file_id = ?, gallery_media_id = NULL, updated_at = ? WHERE id = ?'
        )->execute([$fileId, $now->format('Y-m-d H:i:s'), $id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): Communication
    {
        return new Communication(
            (int) $row['id'],
            (string) $row['title'],
            (string) $row['body'],
            $row['gallery_media_id'] === null ? null : (int) $row['gallery_media_id'],
            $row['file_id'] === null ? null : (int) $row['file_id'],
            $row['created_by'] === null ? null : (int) $row['created_by'],
            DateInput::fromStorage((string) $row['created_at']) ?? new \DateTimeImmutable(),
            $row['source_kind'] === null ? null : (string) $row['source_kind'],
            $row['source_id'] === null ? null : (int) $row['source_id']
        );
    }
}
