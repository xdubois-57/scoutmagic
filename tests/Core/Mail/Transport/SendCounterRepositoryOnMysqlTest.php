<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Mail\Transport;

use Core\Mail\Transport\MailLane;
use Core\Mail\Transport\SendCounterRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * `increment()` against the engine an installation actually runs.
 *
 * The method writes one upsert in two spellings — SQLite's
 * `ON CONFLICT … DO UPDATE` for the in-memory test database, MySQL's
 * `ON DUPLICATE KEY UPDATE` for MySQL and MariaDB — and
 * `SendCounterRepositoryTest` only ever runs the first. The second is the
 * one every message leaving a real installation goes through, and the one
 * the daily quota and the reserve (IT-02) are then read from: a clause the
 * server refused would stop every send, and one that inserted instead of
 * folding would count a relay's day as one message per row.
 *
 * Against the tables the migration builds (`Tests\UsesProductionEngine`),
 * so the unique index the upsert leans on — `(provider_id, count_date,
 * lane)` — is the declared one, not a copy written out here.
 */
#[Group('database')]
final class SendCounterRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private SendCounterRepository $counters;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->counters = new SendCounterRepository($this->pdo);
    }

    /**
     * First: this connection takes the MySQL branch at all. The repository
     * chooses its spelling from the driver name, so a fixture that handed
     * back anything else would have this whole class re-test the SQLite
     * clause and pass for free.
     */
    public function testThisConnectionTakesTheOnDuplicateKeyBranch(): void
    {
        $this->assertSame('mysql', $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
    }

    public function testRepeatedSendsFoldIntoOneRowPerProviderDayAndLane(): void
    {
        $this->counters->increment(7, MailLane::Transactional, '2026-09-19');
        $this->counters->increment(7, MailLane::Transactional, '2026-09-19');
        $this->counters->increment(7, MailLane::Transactional, '2026-09-19');

        $this->assertSame(
            1,
            (int) $this->scalar('SELECT COUNT(*) FROM mail_send_counters'),
            'the unique index must fold repeated sends into the existing row, not refuse them'
        );
        $this->assertSame(3, $this->counters->totalForProvider(7, '2026-09-19'));
    }

    /**
     * The key is the triple, so a second lane, a second day or a second
     * provider is a row of its own — and the quota still reads the whole
     * day across lanes.
     */
    public function testEachPartOfTheKeyStartsItsOwnCounter(): void
    {
        $this->counters->increment(7, MailLane::Transactional, '2026-09-19');
        $this->counters->increment(7, MailLane::Bulk, '2026-09-19');
        $this->counters->increment(7, MailLane::Bulk, '2026-09-19');
        $this->counters->increment(7, MailLane::Bulk, '2026-09-20');
        $this->counters->increment(8, MailLane::Bulk, '2026-09-19');

        $this->assertSame(4, (int) $this->scalar('SELECT COUNT(*) FROM mail_send_counters'));
        $this->assertSame(3, $this->counters->totalForProvider(7, '2026-09-19'));
        $this->assertSame([7 => 3, 8 => 1], $this->counters->totalsForDay('2026-09-19'));
    }

    /**
     * `count_date` is a DATE here and TEXT on SQLite: the reserve's history
     * is keyed on the value the engine hands back, and a driver returning
     * anything but `Y-m-d` would leave every day of the window unmatched.
     */
    public function testTheNonBulkHistoryIsKeyedByPlainDates(): void
    {
        $this->counters->increment(7, MailLane::Transactional, '2026-09-18');
        $this->counters->increment(8, MailLane::Transactional, '2026-09-18');
        $this->counters->increment(7, MailLane::Bulk, '2026-09-18');
        $this->counters->increment(7, MailLane::Transactional, '2026-09-20');
        // Outside a three-day window ending on the 20th.
        $this->counters->increment(7, MailLane::Transactional, '2026-09-17');

        $this->assertSame(
            ['2026-09-18' => 2, '2026-09-20' => 1],
            $this->counters->dailyNonBulkTotals(3, '2026-09-20')
        );
    }

    /** A single value from a prepared statement: every statement here is prepared, even a fixed one. */
    private function scalar(string $sql): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute();

        return $statement->fetchColumn();
    }
}
