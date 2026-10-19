<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance;

use Core\Attention\AttentionPoint;
use Core\Maintenance\MaintenanceAttentionProvider;
use Core\Maintenance\Remote\RemoteBackupDestination;
use Core\Maintenance\VersionFile;
use Core\Security\EncryptionService;
use Core\Storage\Location\Backend\StorageBackendFactory;
use Core\Storage\Location\Config\GoogleDriveLocationConfig;
use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\StorageLocationRepository;
use Core\Storage\Location\StorageLocationType;
use PHPUnit\Framework\TestCase;
use Tests\Core\Maintenance\Remote\InMemorySettingService;
use Tests\DatabaseTestHelper;

/**
 * Issue #718 § C points 11, 12 and 14: what the maintenance settings leave
 * undone, read from stored facts only — one test per point and state.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MaintenanceAttentionProviderTest extends TestCase
{
    private InMemorySettingService $settings;
    private StorageLocationRepository $locations;
    private RemoteBackupDestination $destination;
    private string $basePath;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $this->locations = new StorageLocationRepository($pdo, new EncryptionService(str_repeat('k', 32), str_repeat('b', 32)));
        // A site doing everything right: weekly backups, off-site
        // destination, automatic updates, no newer version seen.
        $this->settings = new InMemorySettingService([
            'backup_auto_frequency' => 'weekly',
            'auto_update_enabled' => '1',
            'auto_update_level' => 'minor',
            'update_latest_version' => '',
        ]);
        $this->destination = new RemoteBackupDestination(
            $this->settings,
            $this->locations,
            new StorageBackendFactory($this->locations, sys_get_temp_dir())
        );
        $this->basePath = sys_get_temp_dir() . '/sm-maint-attention-' . bin2hex(random_bytes(4));
        mkdir($this->basePath);
        VersionFile::write($this->basePath, '2.4.1');
        $this->connectOffsite();
    }

    protected function tearDown(): void
    {
        @unlink($this->basePath . '/VERSION');
        @rmdir($this->basePath . '/storage');
        @rmdir($this->basePath . '/ailleurs');
        @rmdir($this->basePath);
    }

    private function connectOffsite(): void
    {
        $id = $this->locations->create(
            StorageLocationType::GoogleDrive,
            'Google Drive',
            new GoogleDriveLocationConfig('client-1', 'dossier-1', '2026-09-10 12:00:00'),
            (string) json_encode(['client_secret' => 's', 'refresh_token' => 'r', 'account' => 'unite@example.org'])
        );
        $this->destination->choose($id);
    }

    /** @return AttentionPoint[] */
    private function points(): array
    {
        return (new MaintenanceAttentionProvider($this->settings, $this->destination, $this->basePath))->collect(1);
    }

    /** @return list<string> */
    private function titles(): array
    {
        return array_map(static fn(AttentionPoint $p): string => $p->title, $this->points());
    }

    public function testASiteBackedUpOffSiteAndUpdatingItselfRaisesNothing(): void
    {
        $this->assertSame([], $this->points());
    }

    public function testAutomaticBackupSwitchedOffIsAnAttentionPoint(): void
    {
        $this->settings->values['backup_auto_frequency'] = 'none';

        $points = $this->points();

        $this->assertCount(1, $points);
        $this->assertSame('La sauvegarde automatique est désactivée', $points[0]->title);
        $this->assertSame(AttentionPoint::SEVERITY_ATTENTION, $points[0]->severity);
        $this->assertSame('/config/maintenance/sauvegarde-automatique', $points[0]->actionUrl);
    }

    public function testNoOffsiteDestinationShowsAtOnce(): void
    {
        $this->destination->choose(0);

        $this->assertSame(["Aucune sauvegarde hors site n'est configurée"], $this->titles());
    }

    public function testADestinationWhoseLocationWasDeletedCountsAsNone(): void
    {
        $this->destination->choose(999);

        $this->assertSame(["Aucune sauvegarde hors site n'est configurée"], $this->titles());
    }

    /**
     * A « Disque du serveur » location chosen before it stopped being
     * offered is never off-site, whatever its path (#718 § C point 11):
     * losing the server loses the backups with it.
     */
    public function testADestinationOnTheSitesOwnServerIsNotOffSite(): void
    {
        $this->destination->choose($this->localLocation('Dossier du site', 'backups-copie'));

        $points = $this->points();

        $this->assertCount(1, $points);
        $this->assertSame('Les sauvegardes « hors site » restent sur le serveur du site', $points[0]->title);
        $this->assertStringContainsString('« Dossier du site »', $points[0]->why);
    }

    /** Whatever the path: a mount cannot be told from a disk, so none is guessed at. */
    public function testAnAbsolutePathIsNotOffSiteEither(): void
    {
        $this->destination->choose($this->localLocation('Disque monté', '/proc'));

        $this->assertSame(['Les sauvegardes « hors site » restent sur le serveur du site'], $this->titles());
    }

    private function localLocation(string $label, string $path): int
    {
        return $this->locations->create(StorageLocationType::Local, $label, new LocalLocationConfig($path), null);
    }

    public function testAutomaticUpdatesSwitchedOffIsAnAttentionPointNamingAWaitingVersion(): void
    {
        $this->settings->values['auto_update_enabled'] = '0';
        $this->assertSame(['Les mises à jour automatiques sont désactivées'], $this->titles());
        $this->assertStringNotContainsString('disponible', $this->points()[0]->why);

        $this->settings->values['update_latest_version'] = '2.4.2';
        $this->assertStringContainsString('La version 2.4.2 est disponible.', $this->points()[0]->why);
    }

    public function testAVersionTheLevelInstallsOnItsOwnRaisesNothing(): void
    {
        $this->settings->values['update_latest_version'] = '2.5.0';

        $this->assertSame([], $this->points(), 'a minor bump under the « minor » level installs itself');
    }

    public function testAMajorVersionUnderAMinorLevelWaitsForAManualInstall(): void
    {
        $this->settings->values['update_latest_version'] = '3.0.0';

        $points = $this->points();

        $this->assertCount(1, $points);
        $this->assertSame('La version 3.0.0 attend une installation à la main', $points[0]->title);
        $this->assertSame('/config/maintenance/mise-a-jour', $points[0]->actionUrl);
    }

    public function testTheMajorLevelInstallsItAndNothingIsRaised(): void
    {
        $this->settings->values['update_latest_version'] = '3.0.0';
        $this->settings->values['auto_update_level'] = 'major';

        $this->assertSame([], $this->points());
    }

    public function testAnOlderOrEqualLatestVersionIsNotPending(): void
    {
        $this->settings->values['auto_update_enabled'] = '0';
        $this->settings->values['update_latest_version'] = '2.4.1';

        $this->assertStringNotContainsString('disponible', $this->points()[0]->why);
    }
}
