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
    /**
     * The communication this share is, creating it if it is new — **and
     * answering the existing row when it is not** (issue #793, #706
     * IT-05).
     *
     * The same source, the same text and the same chief are the same
     * communication, and `uq_social_communications_twin` is what decides
     * it: the INSERT either goes in or raises a duplicate key, and the
     * duplicate key IS the replay signal. No SELECT before it, so there
     * is no window for two simultaneous POSTs to both pass — which the
     * SELECT-then-INSERT over a 120-second window that stood here until
     * IT-05 could not close, `session_write_close()` running before
     * routing so that PHP's session lock does not serialise two requests
     * from one chief either.
     *
     * **Answering the row is not dropping the request.** A chief who
     * shares an album to Facebook and then, straight away, to a
     * discussion group sends the same text both times; the caller carries
     * the publication out against the row this hands back, and
     * `social_publications`' own unique key refuses only what has already
     * gone, destination by destination. Deciding it here, from the text,
     * would throw the second share away in silence — the defect a review
     * of IT-01 found and the reason that method existed at all.
     *
     * Nothing of the existing row is touched: its title, its text, its
     * blur and its kept card are what the first share settled.
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
        // Null for a communication with no source: there is nothing to be
        // a duplicate of, and the unique key then matches no row.
        $hash = $sourceKind === null ? null : hash('sha256', $body);

        try {
            $this->pdo->prepare(
                'INSERT INTO social_communications'
                . ' (title, body, created_by, created_at, updated_at, source_kind, source_id, body_hash)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$title, $body, $createdBy, $stamp, $stamp, $sourceKind, $sourceId, $hash]);

            return (int) $this->pdo->lastInsertId();
        } catch (\PDOException $e) {
            if (!self::isDuplicate($e)) {
                throw $e;
            }
        }

        $twin = $this->twin((string) $sourceKind, (int) $sourceId, (string) $hash, $createdBy);

        // The key raised and the row is not there: another request rolled
        // its own back between the two statements. Nothing to answer with,
        // so the duplicate key is re-raised rather than turned into a
        // silent zero that would publish against row 0.
        if ($twin === null) {
            throw new \RuntimeException('The twin this key named is not there any more.');
        }

        return $twin;
    }

    /**
     * The row `uq_social_communications_twin` matched, read back by the
     * same columns the key is built from.
     */
    private function twin(string $sourceKind, int $sourceId, string $hash, ?int $createdBy): ?int
    {
        // The author is compared with an explicit IS NULL branch rather
        // than MySQL's `<=>`, which SQLite — what the suite runs on
        // outside the `database` group — does not know.
        $author = $createdBy === null ? 'created_by IS NULL' : 'created_by = ?';
        $parameters = [$sourceKind, $sourceId, $hash];
        if ($createdBy !== null) {
            $parameters[] = $createdBy;
        }

        $statement = $this->pdo->prepare(
            'SELECT id FROM social_communications'
            . ' WHERE source_kind = ? AND source_id = ? AND body_hash = ?'
            . ' AND ' . $author . ' ORDER BY id DESC LIMIT 1'
        );
        $statement->execute($parameters);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * A duplicate-key error, told apart from every other database
     * failure: 1062 on MySQL, 19 with « UNIQUE » in the message on
     * SQLite. The same reading as
     * {@see PublicationRepository::isDuplicate()}, which is where this
     * pattern already earned its place.
     */
    private static function isDuplicate(\PDOException $e): bool
    {
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        return $driverCode === 1062 || ($driverCode === 19 && str_contains($e->getMessage(), 'UNIQUE'));
    }

    public function updateText(int $id, string $title, string $body, \DateTimeImmutable $now): void
    {
        $this->pdo->prepare('UPDATE social_communications SET title = ?, body = ?, updated_at = ? WHERE id = ?')
            ->execute([$title, $body, $now->format('Y-m-d H:i:s'), $id]);
    }

    /**
     * The blur the composer's slider was left on (issue #706, IT-02).
     *
     * Its own method rather than a parameter of `updateText()`: the text
     * is frozen once one destination has been tried, and so is this, but
     * they are set at different moments — the text as it is typed, this
     * at « Publier » — and a caller that had to pass both would have to
     * invent one of them.
     */
    public function updateBlurRatio(int $id, float $ratio, \DateTimeImmutable $now): void
    {
        $this->pdo->prepare('UPDATE social_communications SET blur_ratio = ?, updated_at = ? WHERE id = ?')
            ->execute([$ratio, $now->format('Y-m-d H:i:s'), $id]);
    }

    /**
     * The card the browser posted, now stored — or null to forget the one
     * stored before (issue #706, IT-02).
     *
     * Written while nothing has left yet, and frozen with the rest once
     * something has: a destination published later must receive the same
     * image the first one did. Null is how a card that no longer shows
     * what this share would publish is dropped, so the publication
     * composes a current one instead; see
     * `CommunicationController::dropStoredCard()`.
     */
    public function updateCardFile(int $id, ?int $fileId, \DateTimeImmutable $now): void
    {
        $this->pdo->prepare('UPDATE social_communications SET card_file_id = ?, updated_at = ? WHERE id = ?')
            ->execute([$fileId, $now->format('Y-m-d H:i:s'), $id]);
    }

    /**
     * The communication's public page comes into being (issue #706,
     * IT-04): the token is written once and never rewritten.
     *
     * `WHERE public_token IS NULL` is not belt and braces, it is the
     * rule: re-minting would silently orphan a page Facebook has already
     * cached and a chief has already posted, and the post is not theirs
     * to edit any more. Two requests racing to publish the same
     * communication therefore mint at most one page, whichever arrives
     * first, and the loser reads that one back.
     */
    public function assignPublicToken(int $id, string $token, \DateTimeImmutable $now): void
    {
        $this->pdo->prepare(
            'UPDATE social_communications SET public_token = ?, updated_at = ?'
            . ' WHERE id = ? AND public_token IS NULL'
        )->execute([$token, $now->format('Y-m-d H:i:s'), $id]);
    }

    /**
     * The communication a public page's address names, for a caller who
     * has no account and no session — the token IS the authorisation
     * (SECURITY.md § 6).
     *
     * An empty token is refused before the query rather than matched
     * against the column: every row written before IT-04 carries NULL
     * there, which `= ''` would never match anyway, but a caller asking
     * for nothing should get an answer that does not depend on that.
     */
    public function findByPublicToken(string $token): ?Communication
    {
        if ($token === '') {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM social_communications WHERE public_token = ?');
        $stmt->execute([$token]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
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
            $row['source_id'] === null ? null : (int) $row['source_id'],
            // Null stays null: it means « never chosen », and the site's
            // own starting position applies. A cast would turn it into a
            // deliberate 0 — « Net » — which is a different answer.
            ($row['blur_ratio'] ?? null) === null ? null : (float) $row['blur_ratio'],
            ($row['card_file_id'] ?? null) === null ? null : (int) $row['card_file_id'],
            ($row['public_token'] ?? null) === null ? null : (string) $row['public_token']
        );
    }
}
