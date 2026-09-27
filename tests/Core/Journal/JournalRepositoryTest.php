<?php

declare(strict_types=1);

namespace Tests\Core\Journal;

use Core\Journal\JournalRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\UsesProductionEngine;

/**
 * Most of this runs on the SQLite helper. The two tests about what the
 * declared schema refuses — a foreign key, an ENUM — run on the real engine
 * instead, against `event_log` as the migration builds it
 * (`Tests\UsesProductionEngine`): SQLite's copy enforces neither.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class JournalRepositoryTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private JournalRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repo = new JournalRepository($this->pdo);
    }

    public function testInsertCreatesEntry(): void
    {
        $this->repo->insert('core', 'test_event', 'info', 'Test description', null, null);

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM event_log');
        $this->assertNotFalse($stmt);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testInsertWithContext(): void
    {
        $context = json_encode(['key' => 'value']);
        $this->repo->insert('core', 'test_event', 'security', 'With context', $context, 1);

        $stmt = $this->pdo->query('SELECT * FROM event_log LIMIT 1');
        $this->assertNotFalse($stmt);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('security', $row['level']);
        $this->assertSame('{"key":"value"}', $row['context']);
    }

    public function testDeleteOlderThan(): void
    {
        // Seeded from PHP, not SQLite's datetime('now'): deleteOlderThan()
        // computes its cutoff on the application clock (Core\Config\AppClock)
        // and SQLite's own clock is UTC whatever that is.
        $stmt = $this->pdo->prepare(
            "INSERT INTO event_log (logged_at, category, event_type, level, description)
             VALUES (?, 'core', 'old', 'info', 'Old entry')"
        );
        $stmt->execute([(new \DateTimeImmutable('-100 days'))->format('Y-m-d H:i:s')]);

        // Insert recent entry
        $this->repo->insert('core', 'recent', 'info', 'Recent entry', null, null);

        $deleted = $this->repo->deleteOlderThan(90);
        $this->assertSame(1, $deleted);

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM event_log');
        $this->assertNotFalse($stmt);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    /**
     * Regression: a session can outlive the account it points to — e.g. a
     * database wipe-and-reinstall (SetupController::backupAndEmptyDatabase())
     * leaves old browser sessions holding a user_account_id that no longer
     * exists once the schema is recreated. This fataled every single page
     * with an uncaught FK-violation PDOException, since journal logging
     * runs on essentially every request. Needs a real MySQL connection —
     * the SQLite helper used by every other test in this file doesn't
     * enforce foreign keys, so it can't reproduce SQLSTATE 23000 at all.
     */
    #[\PHPUnit\Framework\Attributes\Group('database')]
    public function testInsertSurvivesAForeignKeyViolationOnAStaleUserAccountId(): void
    {
        $pdo = $this->productionEngine();

        $repo = new JournalRepository($pdo);
        $repo->insert('core', 'stale_session_test', 'info', 'Description', null, 999999);

        $row = $pdo->query('SELECT * FROM event_log ORDER BY id DESC LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertNull($row['user_account_id']);
        $this->assertSame('stale_session_test', $row['event_type']);
    }

    /**
     * `event_log.level` is an ENUM, so an unlisted value is not "stored
     * oddly" — MySQL refuses the row outright in strict mode. The level
     * Core\Http\ErrorHandler writes for an uncaught throwable therefore
     * has to be checked against the REAL declared schema, not against the
     * SQLite helper every other test in this file uses (its `level` is a
     * TEXT column that would accept anything, including a typo).
     *
     * The filter is asserted in the same test on purpose: the journal
     * page's new « Erreur » choice is worth nothing if the rows it selects
     * are not exactly the rows written at that level.
     */
    #[\PHPUnit\Framework\Attributes\Group('database')]
    public function testTheDeclaredSchemaStoresAndFiltersTheErrorLevel(): void
    {
        $pdo = $this->productionEngine();

        $repo = new JournalRepository($pdo);
        $repo->insert('core', 'uncaught_error', 'error', 'Erreur non interceptée : RuntimeException', null, null);
        $repo->insert('core', 'login_success', 'info', 'Connexion réussie', null, null);

        $row = $pdo->query("SELECT * FROM event_log WHERE event_type = 'uncaught_error'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertSame('error', $row['level']);

        $errors = $repo->search(null, 'error');
        $this->assertCount(1, $errors);
        $this->assertSame('uncaught_error', $errors[0]['event_type']);
        $this->assertSame(1, $repo->count(null, 'error'));

        // And the level filter still excludes it from the other levels.
        $this->assertSame(1, $repo->count(null, 'info'));
        $this->assertSame(0, $repo->count(null, 'security'));
    }
}
