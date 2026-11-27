<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Groups\Repository;

use Modules\Groups\Repository\GroupRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * The two queries that spell Service\GroupArchiveRule's « past year » in
 * SQL (issue #1350), on the engine production runs and its real schema:
 * `scout_years.end_date` is a DATE there, compared with the public year's
 * start date passed as a string — exactly where SQLite, which stores both
 * as text, could agree with itself and disagree with MariaDB.
 */
#[Group('database')]
final class GroupRepositoryArchiveOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private const PUBLIC_YEAR_START = '2025-09-01';

    public function testOnlyActiveGroupsOfAYearThatEndedBeforeThePublicYearAreYearEndCandidates(): void
    {
        $pdo = $this->productionEngine();
        $groups = new GroupRepository($pdo);
        $past = $this->year($pdo, '2024-2025', '2024-09-01', '2025-08-31');
        $current = $this->year($pdo, '2025-2026', '2025-09-01', '2026-08-31');
        $next = $this->year($pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $creator = $this->member($pdo, 'C1');

        $pastActive = $groups->create('Passé actif', $past, null, $creator);
        $pastArchived = $groups->create('Passé archivé', $past, null, $creator);
        $groups->setArchived($pastArchived, '2025-03-01 10:00:00');
        $groups->create('Courant', $current, null, $creator);
        $groups->create('Prochain', $next, null, $creator);
        $groups->create('Sans année', null, null, $creator);

        $found = $groups->findActiveOfYearsEndedBefore(self::PUBLIC_YEAR_START, 50);

        $this->assertSame([$pastActive], array_map(fn($g) => $g->id, $found));
    }

    public function testTheQuotaCountsActiveGroupsOnlyWhateverTheirYear(): void
    {
        $pdo = $this->productionEngine();
        $groups = new GroupRepository($pdo);
        $past = $this->year($pdo, '2024-2025', '2024-09-01', '2025-08-31');
        $current = $this->year($pdo, '2025-2026', '2025-09-01', '2026-08-31');
        $next = $this->year($pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $creator = $this->member($pdo, 'C2');
        $other = $this->member($pdo, 'C3');
        $groups->create('Quelqu\'un d\'autre', $current, null, $other);

        $groups->create('Passé, sans date', $past, null, $creator);
        $archived = $groups->create('Courant archivé', $current, null, $creator);
        $groups->setArchived($archived, '2026-01-01 00:00:00');
        $groups->create('Courant', $current, null, $creator);
        $groups->create('Prochain', $next, null, $creator);
        $groups->create('Sans année', null, null, $creator);

        $this->assertSame(3, $groups->countActiveCreatedBy($creator, self::PUBLIC_YEAR_START));
    }

    /**
     * The premise both tests rely on: the column really is a DATE on this
     * engine, not the text SQLite keeps — otherwise they would prove
     * nothing SQLite had not already.
     */
    public function testThePremiseScoutYearEndDateIsADateColumnHere(): void
    {
        $pdo = $this->productionEngine();
        $stmt = $pdo->prepare(
            'SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute(['scout_years', 'end_date']);

        $this->assertSame('date', strtolower((string) $stmt->fetchColumn()));
    }

    private function member(\PDO $pdo, string $deskId): int
    {
        $pdo->prepare('INSERT INTO members (desk_id) VALUES (?)')->execute([$deskId]);

        return (int) $pdo->lastInsertId();
    }

    private function year(\PDO $pdo, string $label, string $start, string $end): int
    {
        $pdo->prepare('INSERT INTO scout_years (label, start_date, end_date) VALUES (?, ?, ?)')
            ->execute([$label, $start, $end]);

        return (int) $pdo->lastInsertId();
    }
}
