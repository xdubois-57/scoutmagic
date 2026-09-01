<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Camps\Repository\PlaceRepository;
use Modules\Camps\Task\GeocodePlacesHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Camps\CampsTestHelper;

/**
 * A place that never appears on the map used to have no explanation
 * anywhere.
 *
 * The refusal is stamped and deliberately never retried — otherwise an
 * address Nominatim cannot make sense of would be re-sent on every single
 * run, for ever, blocking the queue behind it. That design is right, and
 * it is exactly what made the silence permanent: one attempt, no result,
 * no trace, and « pourquoi mes lieux ne sont pas sur la carte » with
 * nothing to answer it.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class GeocodePlacesHandlerTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CampsTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
    }

    public function testAPlaceTheGeocoderCouldNotPlaceSaysSo(): void
    {
        // No city and no postcode: the service refuses to build a query at
        // all, so nothing is sent anywhere and the answer is « non » with
        // no network in the way.
        $placeId = (new PlaceRepository($this->pdo))->create('Le pré du fond', 'Chemin sans nom', null, null, null, null);

        (new GeocodePlacesHandler())->handle([], $this->taskContext());

        $entry = (new JournalRepository($this->pdo))->search()[0];
        $this->assertSame('camps_place_not_geocoded', $entry['event_type']);
        $this->assertSame($placeId, json_decode((string) $entry['context'], true)['place_id']);
    }

    public function testTheJournalNamesTheIdAndNotTheAddress(): void
    {
        // The address is what was refused; repeating it in a journal that
        // travels in a support archive adds nothing.
        (new PlaceRepository($this->pdo))->create('Le pré du fond', 'Chemin sans nom', null, null, null, null);

        (new GeocodePlacesHandler())->handle([], $this->taskContext());

        $entry = (new JournalRepository($this->pdo))->search()[0];
        $this->assertStringNotContainsString('Chemin sans nom', $entry['description'] . (string) $entry['context']);
    }

    public function testGeocodingSwitchedOffWritesNothingAtAll(): void
    {
        // A deliberate switch is not an incident, and a line on every run
        // of a disabled feature is the flood rather than the answer.
        $this->pdo->prepare(
            'INSERT INTO settings (module_id, setting_key, setting_value, default_value,
                                   setting_type, label, description)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute(['camps', 'camps_geocoding_enabled', '0', '1', 'boolean', 'Géocodage', '']);
        (new PlaceRepository($this->pdo))->create('Le pré du fond', 'Chemin sans nom', null, null, null, null);

        (new GeocodePlacesHandler())->handle([], $this->taskContext());

        $this->assertSame([], (new JournalRepository($this->pdo))->search());
    }

    public function testNothingToGeocodeWritesNothing(): void
    {
        (new GeocodePlacesHandler())->handle([], $this->taskContext());

        $this->assertSame([], (new JournalRepository($this->pdo))->search());
    }

    private function taskContext(): TaskContext
    {
        return new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $this->createMock(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $this->encryption),
            sys_get_temp_dir()
        );
    }
}
