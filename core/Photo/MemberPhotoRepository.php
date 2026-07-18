<?php

declare(strict_types=1);

namespace Core\Photo;

class MemberPhotoRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Resolve the file id to show for a member at a given scout year: the
     * photo for that exact year, or — when none exists — the most recent
     * photo from an earlier year. Null when the member has no photo at all
     * up to and including that year.
     */
    public function findFileIdForYearOrEarlier(int $memberId, int $scoutYearId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT mp.file_id
             FROM member_photos mp
             JOIN scout_years sy ON sy.id = mp.scout_year_id
             JOIN scout_years target ON target.id = ?
             WHERE mp.member_id = ? AND sy.start_date <= target.start_date
             ORDER BY sy.start_date DESC
             LIMIT 1'
        );
        $stmt->execute([$scoutYearId, $memberId]);
        $fileId = $stmt->fetchColumn();

        return $fileId !== false ? (int) $fileId : null;
    }

    /**
     * Create or replace the photo for a member at a given scout year.
     */
    public function upsert(int $memberId, int $scoutYearId, int $fileId, ?int $createdBy): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM member_photos WHERE member_id = ? AND scout_year_id = ?');
        $stmt->execute([$memberId, $scoutYearId]);
        $existingId = $stmt->fetchColumn();

        if ($existingId !== false) {
            $update = $this->pdo->prepare('UPDATE member_photos SET file_id = ?, created_by = ?, created_at = ? WHERE id = ?');
            $update->execute([$fileId, $createdBy, date('Y-m-d H:i:s'), (int) $existingId]);
            return;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO member_photos (member_id, scout_year_id, file_id, created_by) VALUES (?, ?, ?, ?)'
        );
        $insert->execute([$memberId, $scoutYearId, $fileId, $createdBy]);
    }
}
