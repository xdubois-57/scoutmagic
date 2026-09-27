<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Mail\Feedback;

use Core\Mail\Feedback\ReturnProbeRepository;
use Core\Security\EncryptionService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * The round-trip store's replace-by-address, against the engine an
 * installation runs.
 *
 * `issue()` is an insert-or-replace written by hand: a DELETE by blind
 * index and an INSERT, in one transaction, over a `UNIQUE` index on
 * `address_blind_index`. Its docblock rests the safety of a double press
 * of « Lancer la vérification » on InnoDB's row locks — the second
 * request's DELETE waits on the first request's row. SQLite has one
 * writer for the whole file, so `ReturnPathVerifierTest` cannot see that
 * claim either hold or fail; only this engine can.
 *
 * The second connection that claim needs comes from the fixture
 * (`productionEngineConnection()`, onto this class's own database), not
 * from a `\PDO` opened here, so it is opened the way the site opens one.
 */
#[Group('database')]
final class ReturnProbeRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private ReturnProbeRepository $probes;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->probes = $this->repository($this->pdo);
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testASecondRunForTheSameAddressReplacesTheFirst(): void
    {
        $first = $this->probes->issue('Contact@Unite.be', 'key-one', $this->at('10:00'), $this->at('12:00'));
        // Another spelling of the same mailbox: the blind index is taken on
        // the normalised address, so this is the same key under UNIQUE.
        $second = $this->probes->issue('contact@unite.be ', 'key-two', $this->at('11:00'), $this->at('13:00'));

        $this->assertNotSame($first, $second);
        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM mail_return_probes'));
        $probe = $this->probes->findByAddress('contact@unite.be');
        $this->assertNotNull($probe);
        $this->assertSame($second, $probe->id);
        $this->assertSame('key-two', $probe->correlationKey);
    }

    /**
     * The premise under the docblock's « the second request's DELETE waits
     * on the first request's row »: while one run is issued and not yet
     * committed, a second session's DELETE for the same address blocks
     * rather than going through — here until a one-second lock timeout,
     * which is what proves it waited.
     *
     * Checked on an address that already had a run, and on one that had
     * none: in the second case the row being waited on is the one the
     * first run has just inserted.
     */
    public function testAnUncommittedRunMakesAConcurrentReplacementWait(): void
    {
        $this->probes->issue('contact@unite.be', 'key-one', $this->at('10:00'), $this->at('12:00'));

        foreach (['contact@unite.be', 'nouvelle@unite.be'] as $address) {
            $this->pdo->beginTransaction();
            $this->probes->issue($address, 'key-held', $this->at('11:00'), $this->at('13:00'));

            $other = $this->secondSession();
            try {
                $other->prepare('DELETE FROM mail_return_probes WHERE address_blind_index = ?')
                    ->execute([(string) $this->scalar(
                        "SELECT address_blind_index FROM mail_return_probes WHERE correlation_key = 'key-held'"
                    )]);
                $this->fail('the second session deleted a row another transaction was replacing: ' . $address);
            } catch (\PDOException $e) {
                // 1205: Lock wait timeout exceeded.
                $this->assertSame(1205, $e->errorInfo[1] ?? null, $e->getMessage());
            } finally {
                if ($other->inTransaction()) {
                    $other->rollBack();
                }
                $this->pdo->rollBack();
            }
        }
    }

    /**
     * Issued inside a caller's transaction, the pair belongs to that
     * transaction: rolled back with it, rather than committed on the way.
     */
    public function testACallersTransactionStaysTheCallers(): void
    {
        $this->pdo->beginTransaction();
        $this->probes->issue('contact@unite.be', 'key-one', $this->at('10:00'), $this->at('12:00'));
        $this->assertTrue($this->pdo->inTransaction());
        $this->pdo->rollBack();

        $this->assertNull($this->probes->findByAddress('contact@unite.be'));
    }

    public function testAPendingRunIsFoundUntilItExpiresOrArrives(): void
    {
        $id = $this->probes->issue('contact@unite.be', 'key-one', $this->at('10:00'), $this->at('12:00'));

        $this->assertSame($id, $this->probes->findPending('key-one', $this->at('11:59'))?->id);
        $this->assertNull($this->probes->findPending('key-one', $this->at('12:00')), 'expired at its instant');

        $this->probes->markReceived($id, $this->at('11:00'), null);
        $this->probes->markReceived($id, $this->at('11:30'), null);

        $this->assertNull($this->probes->findPending('key-one', $this->at('11:59')));
        $this->assertSame(
            '2026-09-19 11:00:00',
            $this->probes->findByAddress('contact@unite.be')?->receivedAt?->format('Y-m-d H:i:s'),
            'the first arrival is the one kept'
        );
    }

    public function testForgettingKeepsOnlyTheAddressesStillInUse(): void
    {
        $this->probes->issue('contact@unite.be', 'k1', $this->at('10:00'), $this->at('12:00'));
        $this->probes->issue('ancienne@unite.be', 'k2', $this->at('10:00'), $this->at('12:00'));

        $this->assertSame(1, $this->probes->forgetAllExcept(['Contact@Unite.be']));
        $this->assertNotNull($this->probes->findByAddress('contact@unite.be'));
        $this->assertSame(1, $this->probes->forgetAllExcept([]));
    }

    private function secondSession(): \PDO
    {
        $database = (string) $this->scalar('SELECT DATABASE()');
        $other = self::productionEngineConnection($database)->getPdo();
        $other->prepare('SET SESSION innodb_lock_wait_timeout = 1')->execute();
        $other->beginTransaction();

        return $other;
    }

    private function repository(\PDO $pdo): ReturnProbeRepository
    {
        return new ReturnProbeRepository($pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));
    }

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-19 ' . $time . ':00');
    }

    /** A single value from a prepared statement: every statement here is prepared, even a fixed one. */
    private function scalar(string $sql): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute();

        return $statement->fetchColumn();
    }
}
