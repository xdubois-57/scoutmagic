<?php

declare(strict_types=1);

namespace Tests\Modules\Covoiturage\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\TaskContext;
use Core\Security\UserAccountRepository;
use Modules\Covoiturage\Repository\SeatRequest;
use Modules\Covoiturage\Task\PurgeCarpoolsHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Covoiturage\CovoiturageTestHelper as H;

/**
 * D9: display and retention are one thing. A carpool goes 30 days after its
 * LAST date (a setting), with its cars, requests, names and phones.
 */
final class PurgeCarpoolsHandlerTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        H::createTables($this->pdo);
    }

    private function context(): TaskContext
    {
        return new TaskContext(
            Connection::withPdo($this->pdo),
            H::encryption(),
            $this->createStub(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            new UserAccountRepository($this->pdo, H::encryption()),
            sys_get_temp_dir()
        );
    }

    private function rows(string $table): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    public function testACarpoolGoesThirtyDaysAfterItsLastDateWithEverythingInIt(): void
    {
        $gone = H::carpool($this->pdo, -31);
        $offer = H::offer($this->pdo, $gone, 1);
        H::request($this->pdo, $offer, 2, ['Tom Leroy'], SeatRequest::ACCEPTED);
        $kept = H::carpool($this->pdo, -29);
        // The RETURN date counts, not the outbound one.
        $longTrip = H::carpool($this->pdo, -40, -20);

        (new PurgeCarpoolsHandler())->handle([], $this->context());

        $ids = array_map('intval', $this->pdo->query('SELECT id FROM carpools ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertSame([$kept, $longTrip], $ids);
        $this->assertSame(0, $this->rows('carpool_offers'), 'The cars went with their carpool.');
        $this->assertSame(0, $this->rows('carpool_requests'), 'The names and phones went too.');
    }

    public function testTheRetentionIsASetting(): void
    {
        $this->pdo->exec(
            "INSERT INTO settings (module_id, setting_key, setting_value, default_value, setting_type, label, description)
             VALUES ('covoiturage', 'covoiturage_retention_days', '7', '30', 'number', 'x', 'x')"
        );
        H::carpool($this->pdo, -8);
        $kept = H::carpool($this->pdo, -6);

        (new PurgeCarpoolsHandler())->handle([], $this->context());

        $this->assertSame([$kept], array_map('intval', $this->pdo->query('SELECT id FROM carpools')->fetchAll(\PDO::FETCH_COLUMN)));
    }

    public function testItReArmsItselfForTomorrow(): void
    {
        (new PurgeCarpoolsHandler())->handle([], $this->context());

        $row = $this->pdo->query(
            "SELECT run_at FROM scheduled_actions WHERE module_id = 'covoiturage' AND task_key = 'purge_carpools'"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertStringStartsWith((new \DateTimeImmutable('tomorrow'))->format('Y-m-d'), (string) $row['run_at']);
    }
}
