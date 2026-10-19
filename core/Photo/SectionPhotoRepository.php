<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Photo;

class SectionPhotoRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Resolve the file id to show for a section at a given scout year: the
     * photo for that exact year, or — when none exists — the most recent
     * photo from an earlier year. Null when the section has no photo at
     * all up to and including that year. Mirrors MemberPhotoRepository::
     * findFileIdForYearOrEarlier() exactly, keyed by section instead of
     * member.
     */
    public function findFileIdForYearOrEarlier(int $sectionId, int $scoutYearId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT sp.file_id
             FROM section_staff_photos sp
             JOIN scout_years sy ON sy.id = sp.scout_year_id
             JOIN scout_years target ON target.id = ?
             WHERE sp.section_id = ? AND sy.start_date <= target.start_date
             ORDER BY sy.start_date DESC
             LIMIT 1'
        );
        $stmt->execute([$scoutYearId, $sectionId]);
        $fileId = $stmt->fetchColumn();

        return $fileId !== false ? (int) $fileId : null;
    }

    /**
     * How many distinct sections have a photo of their own for exactly this
     * scout year — deliberately *not* findFileIdForYearOrEarlier()'s
     * fallback semantics. The Staffs page falls back to last year's photo
     * so a section is never shown photo-less; the "Année scoute" workflow
     * asks the opposite question ("has this year's photo actually been
     * taken yet?"), which last year's photo answers with a no.
     */
    public function countSectionsWithPhotoForYear(int $scoutYearId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT section_id) FROM section_staff_photos WHERE scout_year_id = ?'
        );
        $stmt->execute([$scoutYearId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Of $sectionIds, those with no group photo of their own for exactly
     * this scout year — countSectionsWithPhotoForYear()'s question asked
     * per section, so the "Année scoute" workflow can name the sections
     * still to photograph. Last year's photo, which the Staffs page shows
     * in the meantime, does not count. In the order given.
     *
     * @param array<int, int> $sectionIds
     * @return list<int>
     */
    public function findSectionIdsWithoutPhotoForYear(array $sectionIds, int $scoutYearId): array
    {
        $sectionIds = array_values(array_unique(array_map('intval', $sectionIds)));
        if ($sectionIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($sectionIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT section_id FROM section_staff_photos
             WHERE scout_year_id = ? AND section_id IN ({$placeholders})"
        );
        $stmt->execute([$scoutYearId, ...$sectionIds]);
        $withPhoto = array_flip(array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));

        return array_values(array_filter(
            $sectionIds,
            static fn(int $sectionId): bool => !isset($withPhoto[$sectionId])
        ));
    }

    /**
     * Create or replace the photo for a section at a given scout year.
     */
    public function upsert(int $sectionId, int $scoutYearId, int $fileId, ?int $createdBy): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM section_staff_photos WHERE section_id = ? AND scout_year_id = ?');
        $stmt->execute([$sectionId, $scoutYearId]);
        $existingId = $stmt->fetchColumn();

        if ($existingId !== false) {
            $update = $this->pdo->prepare('UPDATE section_staff_photos SET file_id = ?, created_by = ?, created_at = '
                . '? WHERE id = ?');
            $update->execute([$fileId, $createdBy, date('Y-m-d H:i:s'), (int) $existingId]);
            return;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO section_staff_photos (section_id, scout_year_id, file_id, created_by) VALUES (?, ?, ?, ?)'
        );
        $insert->execute([$sectionId, $scoutYearId, $fileId, $createdBy]);
    }
}
