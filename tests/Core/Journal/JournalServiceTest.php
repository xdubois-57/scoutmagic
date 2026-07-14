<?php

declare(strict_types=1);

namespace Tests\Core\Journal;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

class JournalServiceTest extends TestCase
{
    private JournalService $service;
    private JournalRepository $repo;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repo = new JournalRepository($this->pdo);
        $this->service = new JournalService($this->repo);
    }

    public function testLogInsertsEntry(): void
    {
        $this->service->log('core', 'login_success', 'security', 'User logged in', ['ip' => '127.0.0.1'], 1);

        $entries = $this->repo->search();
        $this->assertCount(1, $entries);
        $this->assertSame('core', $entries[0]['category']);
        $this->assertSame('login_success', $entries[0]['event_type']);
        $this->assertSame('security', $entries[0]['level']);
        $this->assertSame('User logged in', $entries[0]['description']);
        $this->assertSame(1, (int) $entries[0]['user_account_id']);
        $this->assertNotNull($entries[0]['context']);
        $decoded = json_decode($entries[0]['context'], true);
        $this->assertSame('127.0.0.1', $decoded['ip']);
    }

    public function testLogWithNullUserAndEmptyContext(): void
    {
        $this->service->log('core', 'system_event', 'info', 'Something happened');

        $entries = $this->repo->search();
        $this->assertCount(1, $entries);
        $this->assertNull($entries[0]['user_account_id']);
        $this->assertNull($entries[0]['context']);
    }

    public function testSearchFiltersByCategory(): void
    {
        $this->service->log('core', 'a', 'info', 'Event A');
        $this->service->log('import', 'b', 'info', 'Event B');
        $this->service->log('core', 'c', 'info', 'Event C');

        $results = $this->repo->search('core');
        $this->assertCount(2, $results);
    }

    public function testSearchFiltersByLevel(): void
    {
        $this->service->log('core', 'a', 'info', 'Info event');
        $this->service->log('core', 'b', 'security', 'Security event');

        $results = $this->repo->search(null, 'security');
        $this->assertCount(1, $results);
        $this->assertSame('security', $results[0]['level']);
    }

    public function testSearchFiltersByDescription(): void
    {
        $this->service->log('core', 'a', 'info', 'Login succeeded');
        $this->service->log('core', 'b', 'info', 'Import completed');

        $results = $this->repo->search(null, null, 'Login');
        $this->assertCount(1, $results);
    }

    public function testCountReturnsTotal(): void
    {
        $this->service->log('core', 'a', 'info', 'E1');
        $this->service->log('core', 'b', 'info', 'E2');
        $this->service->log('core', 'c', 'info', 'E3');

        $count = $this->repo->count();
        $this->assertSame(3, $count);
    }

    public function testDeleteOlderThan(): void
    {
        // Insert an entry with an old date
        $oldDate = (new \DateTimeImmutable('-100 days'))->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO event_log (logged_at, category, event_type, level, description) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$oldDate, 'core', 'old', 'info', 'Old entry']);

        // Insert recent entry
        $this->service->log('core', 'recent', 'info', 'Recent entry');

        $deleted = $this->service->cleanup(30);
        $this->assertSame(1, $deleted);

        $remaining = $this->repo->search();
        $this->assertCount(1, $remaining);
        $this->assertSame('recent', $remaining[0]['event_type']);
    }

    public function testGetDistinctCategories(): void
    {
        $this->service->log('core', 'a', 'info', 'E1');
        $this->service->log('import', 'b', 'info', 'E2');
        $this->service->log('core', 'c', 'info', 'E3');
        $this->service->log('scheduler', 'd', 'info', 'E4');

        $categories = $this->repo->getDistinctCategories();
        $this->assertCount(3, $categories);
        $this->assertContains('core', $categories);
        $this->assertContains('import', $categories);
        $this->assertContains('scheduler', $categories);
    }

    public function testPagination(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->service->log('core', "event_{$i}", 'info', "Event {$i}");
        }

        $page1 = $this->repo->search(null, null, null, null, null, null, null, 2, 0);
        $this->assertCount(2, $page1);

        $page2 = $this->repo->search(null, null, null, null, null, null, null, 2, 2);
        $this->assertCount(2, $page2);

        $page3 = $this->repo->search(null, null, null, null, null, null, null, 2, 4);
        $this->assertCount(1, $page3);
    }

    public function testLogCapturesIpAddress(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $this->service->log('core', 'login_success', 'security', 'Connexion');
        unset($_SERVER['REMOTE_ADDR']);

        $entries = $this->repo->search();
        $this->assertSame('203.0.113.7', $entries[0]['ip_address']);
    }

    public function testSearchFiltersByIp(): void
    {
        $this->repo->insert('core', 'a', 'info', 'A', null, null, '10.0.0.1');
        $this->repo->insert('core', 'b', 'info', 'B', null, null, '10.0.0.2');

        $results = $this->repo->search(null, null, null, null, null, '10.0.0.2');
        $this->assertCount(1, $results);
        $this->assertSame('10.0.0.2', $results[0]['ip_address']);
    }

    public function testSearchFiltersByUserAccountId(): void
    {
        $this->repo->insert('core', 'a', 'info', 'A', null, 5, '10.0.0.1');
        $this->repo->insert('core', 'b', 'info', 'B', null, 9, '10.0.0.2');

        $results = $this->repo->search(null, null, null, null, null, null, 9);
        $this->assertCount(1, $results);
        $this->assertSame(9, (int) $results[0]['user_account_id']);
    }
}
