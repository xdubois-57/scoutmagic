<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Service;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * The conversion `withhold` → `charge` on the engine production runs, with
 * the schema as the real migration builds it.
 *
 * `RentalStayRepository::convertWithheldIncidentsToCharges()` answers with
 * `rowCount()` after an UPDATE, which MySQL and MariaDB count as rows
 * CHANGED and SQLite as rows MATCHED. Every matched row changes its
 * decision, so the two agree — this class holds that, after checking its
 * own premise.
 */
#[Group('database')]
final class IncidentDecisionMigrationOnMysqlTest extends TestCase
{
    use UsesProductionEngine;
    use IncidentDecisionMigrationScenario;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->setUpScenario();
    }

    /**
     * The premise: this engine counts changed rows. Were it to count
     * matched rows, the idempotence the scenario checks would hold here for
     * free.
     */
    public function testThisEngineReportsChangedRowsRatherThanMatchedOnes(): void
    {
        $id = $this->incident('Vitre cassée', 4500, 'charge', null);

        $update = $this->pdo->prepare('UPDATE rental_incidents SET decision = ? WHERE id = ?');
        $update->execute(['charge', $id]);

        $this->assertSame(0, $update->rowCount(), 'an unchanged row would read as converted');
    }
}
