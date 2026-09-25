<?php

declare(strict_types=1);

namespace Tests\Modules\Covoiturage\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Geo\GeoPoint;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\TaskContext;
use Core\Security\UserAccountRepository;
use Modules\Covoiturage\Repository\CarpoolRepository;
use Modules\Covoiturage\Task\GeocodeCarpoolsHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Covoiturage\CovoiturageTestHelper as H;

/**
 * The geocoding task's decisions — nothing here reaches Nominatim: every
 * case below either has nothing to send or is switched off.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class GeocodeCarpoolsHandlerTest extends TestCase
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

    public function testSwitchedOffItSendsNothingAndStampsNothing(): void
    {
        $this->pdo->exec(
            "INSERT INTO settings (module_id, setting_key, setting_value, default_value, setting_type, label, description)
             VALUES ('covoiturage', 'covoiturage_geocoding_enabled', '0', '1', 'boolean', 'x', 'x')"
        );
        $id = H::carpool($this->pdo);

        (new GeocodeCarpoolsHandler())->handle([], $this->context());

        $this->assertSame($id, (new CarpoolRepository($this->pdo))->findNextToGeocode()?->id);
    }

    public function testAHandPlacedPointIsNeverQueued(): void
    {
        $carpools = new CarpoolRepository($this->pdo);
        $id = H::carpool($this->pdo);
        $carpools->points()->setManual($id, new GeoPoint(50.72, 4.64), new \DateTimeImmutable());

        (new GeocodeCarpoolsHandler())->handle([], $this->context());

        $this->assertSame(0, $carpools->countPendingGeocoding());
        $this->assertSame('50.720000, 4.640000', $carpools->findById($id)?->point?->line());
    }

    public function testAnAddressTooShortToMeanAPlaceIsStampedWithoutBeingSent(): void
    {
        // « Ici » is never sent to a gazetteer; the attempt is stamped so
        // the queue moves on, and the journal says why there is no point.
        $carpools = new CarpoolRepository($this->pdo);
        $id = H::carpool($this->pdo, 10, null, [], null, 'Ici');

        (new GeocodeCarpoolsHandler())->handle([], $this->context());

        $this->assertSame(0, $carpools->countPendingGeocoding());
        $this->assertNull($carpools->findById($id)?->point);
        $journal = (string) json_encode($this->pdo->query('SELECT * FROM event_log')->fetchAll(\PDO::FETCH_ASSOC));
        $this->assertStringContainsString('carpool_not_geocoded', $journal);
    }
}
