<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Security\EncryptionService;
use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\StorageLocationRepository;
use Core\Storage\Location\StorageLocationType;
use Modules\Gallery\Repository\AlbumRepository;
use Modules\Gallery\Service\GalleryLocationException;
use Modules\Gallery\Service\GalleryLocationService;
use Modules\Gallery\Service\GalleryStorageWiring;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * « Emplacement des nouveaux albums » — the one storage decision that is
 * the gallery's own (D4, ARCHITECTURE.md §8.107).
 *
 * The four steps this covers used to live in `GalleryConfigController`,
 * which is the mandatory Controller → Service boundary being crossed and
 * also a rule nothing could test without an HTTP request: read what was
 * chosen before, check the identifier still names a location, write it,
 * and say whether it moved. The journal entry that reads « les nouveaux
 * albums iront désormais sur « … » » is built from the last two, so
 * getting either wrong files a security-level entry naming the wrong
 * destination — or none at all.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class GalleryLocationServiceTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;
    private StorageLocationRepository $locations;
    private GalleryLocationService $service;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        $this->settings->register(
            GalleryLocationService::NEW_ALBUM_LOCATION_SETTING,
            '0',
            'number',
            'Emplacement',
            'Emplacement',
            'gallery'
        );

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $wiring = GalleryStorageWiring::build(
            $this->pdo,
            $encryption,
            $this->settings,
            sys_get_temp_dir(),
            new AlbumRepository($this->pdo)
        );
        $this->locations = $wiring->locations;
        $this->service = $wiring->galleryLocations;
    }

    public function testChoosingALocationWritesTheSettingAndReportsTheChange(): void
    {
        $id = $this->declareLocation('Disque réseau');

        $choice = $this->service->chooseForNewAlbums($id);

        $this->assertSame(0, $choice->previousId, 'A fresh installation has chosen nothing.');
        $this->assertSame($id, $choice->selectedId);
        $this->assertTrue($choice->changed());
        $this->assertSame('Disque réseau', $choice->frenchName(), 'What the journal entry names.');
        $this->assertSame(
            $id,
            (int) $this->settings->get(GalleryLocationService::NEW_ALBUM_LOCATION_SETTING, 'gallery', 0)
        );
    }

    /**
     * The previous value is read BEFORE the write, or « a changé » would
     * compare the new value against itself and the journal would never
     * record a move.
     */
    public function testTheAnswerComparesAgainstWhatWasThereBeforeTheWrite(): void
    {
        $first = $this->declareLocation('Premier');
        $second = $this->declareLocation('Second');
        $this->service->chooseForNewAlbums($first);

        $choice = $this->service->chooseForNewAlbums($second);

        $this->assertSame($first, $choice->previousId);
        $this->assertSame($second, $choice->selectedId);
        $this->assertTrue($choice->changed());
    }

    public function testRechoosingTheSameLocationIsNotAChange(): void
    {
        $id = $this->declareLocation('Disque réseau');
        $this->service->chooseForNewAlbums($id);

        $this->assertFalse($this->service->chooseForNewAlbums($id)->changed());
    }

    /**
     * 0 is « the site's default », which this module deliberately does not
     * name: it is resolved at album creation, and naming it here would
     * record a decision the administrator did not make.
     */
    public function testZeroMeansTheSiteDefaultAndIsAlwaysAccepted(): void
    {
        $id = $this->declareLocation('Disque réseau');
        $this->service->chooseForNewAlbums($id);

        $choice = $this->service->chooseForNewAlbums(0);

        $this->assertSame(0, $choice->selectedId);
        $this->assertNull($choice->location);
        $this->assertTrue($choice->changed());
        $this->assertSame('l\'emplacement par défaut du site', $choice->frenchName());
    }

    /**
     * Deleted between the page being rendered and the form being sent. A
     * silent fallback onto the default would write « default » where the
     * administrator asked for a named destination, and say nothing.
     */
    public function testAnIdentifierNamingNoLocationIsRefusedRatherThanSilentlyIgnored(): void
    {
        $this->expectException(GalleryLocationException::class);
        $this->expectExceptionMessage("L'emplacement choisi pour les nouveaux albums n'existe plus.");

        $this->service->chooseForNewAlbums(4242);
    }

    public function testARefusedChoiceLeavesTheSettingUntouched(): void
    {
        $id = $this->declareLocation('Disque réseau');
        $this->service->chooseForNewAlbums($id);

        try {
            $this->service->chooseForNewAlbums(4242);
            $this->fail('The service must refuse an identifier naming nothing.');
        } catch (GalleryLocationException) {
            // Expected.
        }

        $this->assertSame(
            $id,
            (int) $this->settings->get(GalleryLocationService::NEW_ALBUM_LOCATION_SETTING, 'gallery', 0),
            'A refusal must not half-apply the choice.'
        );
    }

    /**
     * What the whole setting is for, read back the way album creation
     * reads it.
     */
    public function testTheChosenLocationIsWhatNewAlbumsAreCreatedOn(): void
    {
        $id = $this->declareLocation('Disque réseau');
        $this->service->chooseForNewAlbums($id);

        $this->assertSame($id, $this->service->locationForNewAlbums()?->id);
    }

    private function declareLocation(string $label): int
    {
        return $this->locations->create(
            StorageLocationType::Local,
            $label,
            new LocalLocationConfig('gallery/' . bin2hex(random_bytes(4))),
            null
        );
    }
}
