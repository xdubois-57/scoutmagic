<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Photo;

/**
 * The one row per account behind `user_account_photos` — the photo of an
 * identified login, set from "Mon compte".
 *
 * Simpler than MemberPhotoRepository on purpose: no scout year, so no
 * fall-back-to-an-earlier-year resolution. A login is a person rather
 * than a membership, and a face does not become wrong in September
 * (schema/core.sql).
 */
class AccountPhotoRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function findFileId(int $userAccountId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT file_id FROM user_account_photos WHERE user_account_id = ?');
        $stmt->execute([$userAccountId]);
        $fileId = $stmt->fetchColumn();

        return $fileId !== false ? (int) $fileId : null;
    }

    /**
     * The photos of a set of accounts in one query, keyed by account id —
     * what a page showing many people at once needs (a group feed names
     * twenty authors), so an avatar never costs a query per row.
     *
     * @param int[] $userAccountIds
     * @return array<int, int> account id => file id, absent when none
     */
    public function findFileIdsByAccountIds(array $userAccountIds): array
    {
        $ids = array_values(array_unique(array_filter($userAccountIds, static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT user_account_id, file_id FROM user_account_photos WHERE user_account_id IN ({$placeholders})"
        );
        $stmt->execute($ids);

        $photos = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $photos[(int) $row['user_account_id']] = (int) $row['file_id'];
        }

        return $photos;
    }

    /**
     * Points the account at a new photo and returns the file id it was
     * pointing at before, so the caller can delete what nothing shows any
     * more — an account replacing its photo five times must not leave
     * five files behind.
     *
     * @return int|null the replaced file id, null when there was none
     */
    public function upsert(int $userAccountId, int $fileId): ?int
    {
        $previous = $this->findFileId($userAccountId);

        if ($previous !== null) {
            $stmt = $this->pdo->prepare(
                'UPDATE user_account_photos SET file_id = ?, created_at = ? WHERE user_account_id = ?'
            );
            $stmt->execute([$fileId, date('Y-m-d H:i:s'), $userAccountId]);

            return $previous === $fileId ? null : $previous;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO user_account_photos (user_account_id, file_id) VALUES (?, ?)'
        );
        $stmt->execute([$userAccountId, $fileId]);

        return null;
    }

    /**
     * Forgets the account's photo and returns the file id it held, for
     * the same cleanup reason as upsert() above.
     */
    public function delete(int $userAccountId): ?int
    {
        $previous = $this->findFileId($userAccountId);
        if ($previous === null) {
            return null;
        }

        $stmt = $this->pdo->prepare('DELETE FROM user_account_photos WHERE user_account_id = ?');
        $stmt->execute([$userAccountId]);

        return $previous;
    }
}
