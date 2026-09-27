<?php

declare(strict_types=1);

namespace Tests\Core\File\Held;

use Core\File\Held\HeldDocumentRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * The browser key's single use, against the REAL engine.
 *
 * `HeldDocumentRepository::claimForBrowser()` decides from `rowCount()`
 * after an `UPDATE … WHERE browser_opened_at IS NULL` whether this request
 * won the key. SQLite reports the rows the UPDATE MATCHED; MySQL and
 * MariaDB report the rows it CHANGED (this application does not set
 * `PDO::MYSQL_ATTR_FOUND_ROWS`). The claim writes a NULL column to a value,
 * so the two agree here — but that is exactly the kind of agreement that
 * has to be checked on the engine production runs rather than believed
 * (AGENTS.md § Database), and `HeldDocumentServiceTest` only ever sees
 * SQLite.
 *
 * The table is the one the migration builds from `schema/core.sql`,
 * through `Tests\UsesProductionEngine` — including its CHAR(64) keys under
 * the schema's case-insensitive collation.
 */
#[Group('database')]
final class HeldDocumentRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private HeldDocumentRepository $documents;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->documents = new HeldDocumentRepository($this->pdo);
        $this->now = new \DateTimeImmutable('2026-09-27 10:00:00');
    }

    /**
     * The premise first: this engine reports changed rows, not matched
     * ones. If it ever reports 1 here, FOUND_ROWS was turned on and the
     * reasoning in the class docblock must be read again.
     */
    public function testThisEngineReportsChangedRowsRatherThanMatchedOnes(): void
    {
        $id = $this->hold('browser', 'app');
        $opened = $this->now->format('Y-m-d H:i:s');
        $this->pdo->prepare('UPDATE held_documents SET browser_opened_at = ? WHERE id = ?')->execute([$opened, $id]);

        $again = $this->pdo->prepare('UPDATE held_documents SET browser_opened_at = ? WHERE id = ?');
        $again->execute([$opened, $id]);

        $this->assertSame(0, $again->rowCount());
    }

    public function testTheBrowserKeyIsClaimedOnceAndOnlyOnce(): void
    {
        $id = $this->hold('browser', 'app');

        $this->assertSame(['id' => $id, 'file_id' => 42], $this->documents->claimForBrowser(self::hash('browser'), $this->now));
        $this->assertNull($this->documents->claimForBrowser(self::hash('browser'), $this->now));
    }

    public function testAnExpiredBrowserKeyIsNeverClaimed(): void
    {
        $this->hold('browser', 'app');

        $this->assertNull($this->documents->claimForBrowser(self::hash('browser'), $this->now->modify('+5 minutes')));
    }

    public function testTheApplicationKeyOpensForItsSessionUntilItExpires(): void
    {
        $id = $this->hold('browser', 'app');

        $this->assertSame(['id' => $id, 'file_id' => 42], $this->documents->findForApp(self::hash('app'), self::hash('session'), $this->now));
        $this->assertNull($this->documents->findForApp(self::hash('app'), self::hash('other'), $this->now));
        $this->assertNull($this->documents->findForApp(self::hash('app'), self::hash('session'), $this->now->modify('+30 minutes')));
    }

    public function testThePurgeFindsWhatExpiredWhetherOpenedOrNot(): void
    {
        $opened = $this->hold('b1', 'a1');
        $this->documents->claimForBrowser(self::hash('b1'), $this->now);
        $unopened = $this->hold('b2', 'a2');
        $this->hold('b3', 'a3', $this->now->modify('+20 minutes'));

        $expired = $this->documents->findExpired($this->now->modify('+30 minutes'));

        $this->assertSame([$opened, $unopened], array_column($expired, 'id'));
    }

    public function testTheLiveCountIsPerSessionAndIgnoresTheExpired(): void
    {
        $this->hold('b1', 'a1');
        $this->hold('b2', 'a2', $this->now->modify('-40 minutes'));

        $this->assertSame(1, $this->documents->countLiveForSession(self::hash('session'), $this->now));
        $this->assertSame(0, $this->documents->countLiveForSession(self::hash('other'), $this->now));
    }

    private function hold(string $browser, string $app, ?\DateTimeImmutable $at = null): int
    {
        $at ??= $this->now;

        return $this->documents->create(
            42,
            self::hash($browser),
            self::hash($app),
            self::hash('session'),
            $at,
            $at->modify('+5 minutes'),
            $at->modify('+30 minutes')
        );
    }

    private static function hash(string $value): string
    {
        return hash('sha256', $value);
    }
}
