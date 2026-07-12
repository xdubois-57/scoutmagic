<?php

declare(strict_types=1);

namespace Tests\Core\Journal;

use Core\Journal\JournalRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class JournalRepositoryTest extends TestCase
{
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
        // Insert old entry
        $stmt = $this->pdo->prepare(
            "INSERT INTO event_log (logged_at, category, event_type, level, description)
             VALUES (datetime('now', '-100 days'), 'core', 'old', 'info', 'Old entry')"
        );
        $stmt->execute();

        // Insert recent entry
        $this->repo->insert('core', 'recent', 'info', 'Recent entry', null, null);

        $deleted = $this->repo->deleteOlderThan(90);
        $this->assertSame(1, $deleted);

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM event_log');
        $this->assertNotFalse($stmt);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }
}
