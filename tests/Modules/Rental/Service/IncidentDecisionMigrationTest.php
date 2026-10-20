<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Service;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * The conversion `withhold` → `charge` on in-memory SQLite; the same
 * scenario runs on the production engine in
 * `IncidentDecisionMigrationOnMysqlTest`.
 */
#[Group('database')]
final class IncidentDecisionMigrationTest extends TestCase
{
    use IncidentDecisionMigrationScenario;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($this->pdo);
        $this->setUpScenario();
    }
}
