<?php

declare(strict_types=1);

namespace Modules\SosStaff\Repository;

/**
 * Sections hidden from the duty calendar's left "section activity"
 * columns (module spec §1.4). STAFFDU is never stored here — it's
 * excluded unconditionally in Service\SosSettingsService, so there is no
 * row to accidentally delete.
 */
class ExcludedSectionRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return int[]
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT section_id FROM sos_excluded_sections');
        $ids = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_COLUMN) : [];
        return array_map('intval', $ids);
    }

    /**
     * Replace the whole excluded-sections list in one go — matches the
     * config page's multi-select checkbox UI (module spec §1.4), which
     * submits the complete current selection on every change.
     *
     * @param int[] $sectionIds
     */
    public function replaceAll(array $sectionIds): void
    {
        $this->pdo->exec('DELETE FROM sos_excluded_sections');
        if (count($sectionIds) === 0) {
            return;
        }

        $stmt = $this->pdo->prepare('INSERT INTO sos_excluded_sections (section_id) VALUES (?)');
        foreach (array_unique($sectionIds) as $sectionId) {
            $stmt->execute([$sectionId]);
        }
    }
}
