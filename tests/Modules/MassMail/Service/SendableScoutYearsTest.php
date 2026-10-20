<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Service;

use Core\Config\ScoutYearService;
use Core\Import\ImportJournalRepository;
use Modules\MassMail\Service\MailingListService;
use Modules\MassMail\Service\SendableScoutYears;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\MassMail\MassMailTestHelper;

/**
 * The years an email can target — and therefore the years « Voir les
 * destinataires » may preview. The previous and the current year always;
 * the next one only once there is somebody to send to.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class SendableScoutYearsTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($this->pdo);
    }

    public function testTheNextYearIsOfferedOnlyOnceSomebodyIsThereToReceive(): void
    {
        $years = $this->years();
        $options = $years->options();

        $this->assertTrue($options['previous']['available']);
        $this->assertTrue($options['current']['available']);
        $this->assertFalse($options['next']['available']);
        $this->assertSame(
            [
                ['id' => $options['previous']['id'], 'label' => $options['previous']['label']],
                ['id' => $options['current']['id'], 'label' => $options['current']['label']],
            ],
            $years->available()
        );
    }

    public function testAnImportForTheNextYearMakesItAvailable(): void
    {
        $nextId = $this->years()->options()['next']['id'];
        $this->pdo->exec(
            "INSERT INTO import_journal (scout_year_id, line_count, member_count, new_functions_count, imported_at)
             VALUES ({$nextId}, 10, 8, 0, '2026-08-01 10:00:00')"
        );

        $available = $this->years()->available();

        $this->assertCount(3, $available);
        $this->assertSame($nextId, $available[2]['id']);
    }

    private function years(): SendableScoutYears
    {
        return new SendableScoutYears(
            new ScoutYearService($this->pdo),
            new ImportJournalRepository($this->pdo),
            $this->createStub(MailingListService::class)
        );
    }
}
