<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Mail\Transport;

use Core\Mail\Transport\ProviderHealth;
use Core\Mail\Transport\ProviderHealthRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * The circuit breaker's store against the engine an installation runs.
 *
 * Every write goes through one private `store()`, an upsert spelled twice:
 * `ON CONFLICT … DO UPDATE SET x = excluded.x` for SQLite and
 * `ON DUPLICATE KEY UPDATE x = VALUES(x)` for MySQL and MariaDB.
 * `ProviderHealthTest` and the transport tests only ever run the first.
 * The second decides whether a relay that keeps failing is ever stepped
 * over on a real site — a clause that inserted rather than replaced would
 * hit the primary key on the second failure, and one that silently kept
 * the old values would never open the circuit at all.
 *
 * Against the tables the migration builds (`Tests\UsesProductionEngine`):
 * `mail_provider_health` keyed on `provider_id`, with its `DATETIME`
 * columns and its `VARCHAR(255)` reason under the server's strict mode.
 */
#[Group('database')]
final class ProviderHealthRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private ProviderHealthRepository $health;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->health = new ProviderHealthRepository($this->pdo);
    }

    /**
     * First: this connection takes the `ON DUPLICATE KEY` branch. The
     * repository picks its spelling from the driver name, so anything else
     * would have this class re-test the SQLite clause.
     */
    public function testThisConnectionTakesTheOnDuplicateKeyBranch(): void
    {
        $this->assertSame('mysql', $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
    }

    /**
     * Three failures are three writes to one row — the first an INSERT,
     * the next two the UPDATE half — and the third opens the circuit for
     * the first lockout.
     */
    public function testConsecutiveFailuresAccumulateOnOneRowAndOpenTheCircuit(): void
    {
        $this->health->recordFailure(3, 'timeout', '2026-09-19 10:00:00');
        $this->health->recordFailure(3, 'timeout', '2026-09-19 10:01:00');
        $opened = $this->health->recordFailure(3, 'refused', '2026-09-19 10:02:00');

        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM mail_provider_health'));

        $stored = $this->health->forProvider(3);
        $this->assertSame(ProviderHealth::FAILURES_BEFORE_OPEN, $stored->consecutiveFailures);
        $this->assertSame('2026-09-19 10:02:00', $stored->openedAt);
        $this->assertSame('2026-09-19 10:07:00', $stored->openedUntil);
        $this->assertSame(1, $stored->openCount);
        $this->assertSame('refused', $stored->lastReason);
        $this->assertEquals($opened, $stored, 'what recordFailure() returned is what the row now holds');
        $this->assertTrue($stored->isOpen('2026-09-19 10:05:00'));
    }

    /**
     * A success overwrites every column but `open_count` — which is the
     * half of the upsert a `VALUES()` mix-up would get wrong without any
     * error — and the next opening's lockout is doubled from it.
     */
    public function testASuccessClosesTheCircuitButRemembersHowOftenItOpened(): void
    {
        foreach (['10:00:00', '10:01:00', '10:02:00'] as $time) {
            $this->health->recordFailure(3, 'timeout', '2026-09-19 ' . $time);
        }

        $this->assertTrue($this->health->recordSuccess(3, '2026-09-19 10:10:00'));

        $closed = $this->health->forProvider(3);
        $this->assertSame(0, $closed->consecutiveFailures);
        $this->assertNull($closed->openedAt);
        $this->assertNull($closed->openedUntil);
        $this->assertSame(1, $closed->openCount);
        $this->assertSame('', $closed->lastReason);
        $this->assertSame(
            '2026-09-19 10:10:00',
            $this->scalar('SELECT updated_at FROM mail_provider_health WHERE provider_id = 3')
        );

        $this->assertFalse($this->health->recordSuccess(3, '2026-09-19 10:11:00'), 'nothing left to close');

        foreach (['11:00:00', '11:01:00', '11:02:00'] as $time) {
            $reopened = $this->health->recordFailure(3, 'timeout', '2026-09-19 ' . $time);
        }
        $this->assertSame('2026-09-19 11:12:00', $reopened->openedUntil, 'second lockout: ten minutes');
        $this->assertSame(2, $this->health->forProvider(3)->openCount);
    }

    /**
     * `last_reason` is `VARCHAR(255)` in characters, and this engine runs
     * strict: a value one character too long is refused, not truncated. A
     * relay's error text is long and routinely accented, so the cut must
     * be counted in characters as the column counts them.
     */
    public function testALongAccentedReasonIsCutToTheColumnRatherThanRefused(): void
    {
        $this->health->recordFailure(4, str_repeat('é', 300), '2026-09-19 10:00:00');

        $this->assertSame(str_repeat('é', 255), $this->health->forProvider(4)->lastReason);
    }

    /** A single value from a prepared statement: every statement here is prepared, even a fixed one. */
    private function scalar(string $sql): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute();

        return $statement->fetchColumn();
    }
}
