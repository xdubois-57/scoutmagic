<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Repository;

/**
 * The one thing this module reads out of a core table it does not own: when
 * the last Desk import for a scout year ran.
 *
 * Every screen here states it, for the same reason the Encadrement module's
 * footer does (`Modules\Leadership\Repository\LeadershipRepository::
 * findLastImportAt()`): nothing shown is fresher than that import, and a
 * page that did not say so would invite a chief to act on a picture weeks
 * old. Kept as this module's own tiny repository rather than borrowed from
 * another module — a module never reaches into another module's classes.
 */
class FeesImportRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function findLastImportAt(int $scoutYearId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT MAX(imported_at) FROM import_journal WHERE scout_year_id = ?');
        $stmt->execute([$scoutYearId]);
        $value = $stmt->fetchColumn();

        return ($value === false || $value === null) ? null : (string) $value;
    }
}
