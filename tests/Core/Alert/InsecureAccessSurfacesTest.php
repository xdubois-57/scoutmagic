<?php

declare(strict_types=1);

namespace Tests\Core\Alert;

use Core\Alert\AlertSurfaces;
use Core\Alert\Check\HttpsCheck;
use Core\Alert\OperationalAlertRepository;
use Core\Alert\OperationalAlertService;
use Core\Alert\OperationalAttentionProvider;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Http\InsecureBrowserAccess;
use Core\Maintenance\Health\HostHealth;
use Core\Notification\NotificationService;
use Core\Support\Collector\SecureConnectionCollector;
use Core\Support\SupportCollectorContext;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * One observation, four surfaces, one answer (#751): Points d'attention,
 * the notification, Santé de l'hébergement and the diagnostic package all
 * read {@see InsecureBrowserAccess}, so they agree on the state and the
 * date — and all go quiet together once the 24 hours have passed.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class InsecureAccessSurfacesTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;
    private string $root;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        $this->root = sys_get_temp_dir() . '/sm-insecure-surfaces-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config', 0700, true);
        mkdir($this->root . '/storage', 0700, true);
        file_put_contents($this->root . '/config/app.php', "<?php\n\nreturn ['https_required' => true];\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/config/app.php');
        foreach (glob($this->root . '/storage/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->root . '/config');
        @rmdir($this->root . '/storage');
        @rmdir($this->root);
    }

    public function testAllFourSurfacesShowTheSameActiveObservation(): void
    {
        $now = time();
        (new InsecureBrowserAccess($this->settings))->record($now - 3 * 3600 - 60);

        // Notifications: the transition notifies once.
        $dispatched = [];
        $this->service($dispatched)->run([$this->check()]);
        $this->assertCount(1, $dispatched, 'the notification goes out');

        // Points d'attention: the stored row, with the same reading.
        $points = $this->attention();
        $this->assertCount(1, $points);
        $this->assertSame('Connexion sécurisée : dernier accès non sécurisé il y a 3 h', $points[0]->title);

        // Santé de l'hébergement.
        $line = HostHealth::secureConnection(
            (new InsecureBrowserAccess($this->settings))->lastObservedAt(),
            $now
        );
        $this->assertFalse($line->isOk());
        $this->assertSame('Accès non sécurisé observé il y a 3 h', $line->status);

        // The diagnostic package.
        [$file, $notes] = $this->package($now);
        $this->assertStringContainsString('État : ACTIF', $file);
        $this->assertStringContainsString('https_required (config/app.php) : oui', $file);
        $this->assertStringContainsString('(il y a 3 h)', $file);
        $this->assertSame(
            ['ScoutMagic a récemment été consulté depuis une connexion non sécurisée (il y a 3 h).'],
            $notes
        );
    }

    public function testAllFourSurfacesAreQuietADayLater(): void
    {
        $now = time();
        (new InsecureBrowserAccess($this->settings))->record($now - 25 * 3600);

        $dispatched = [];
        $this->service($dispatched)->run([$this->check()]);
        $this->assertSame([], $dispatched);
        $this->assertSame([], $this->attention());

        $this->assertTrue(HostHealth::secureConnection(
            (new InsecureBrowserAccess($this->settings))->lastObservedAt(),
            $now
        )->isOk());

        [$file, $notes] = $this->package($now);
        $this->assertStringContainsString('État : sain', $file);
        $this->assertSame([], $notes);
    }

    private function check(): HttpsCheck
    {
        return new HttpsCheck(new InsecureBrowserAccess($this->settings));
    }

    /**
     * @param list<string> $dispatched
     */
    private function service(array &$dispatched): OperationalAlertService
    {
        $notifications = $this->createStub(NotificationService::class);
        $notifications->method('recipientsForType')->willReturn([['userAccountId' => 1, 'memberId' => null]]);
        $notifications->method('dispatch')->willReturnCallback(
            static function (string $typeId) use (&$dispatched): void {
                $dispatched[] = $typeId;
            }
        );

        return new OperationalAlertService(new OperationalAlertRepository($this->pdo), $notifications);
    }

    /** @return list<\Core\Attention\AttentionPoint> */
    private function attention(): array
    {
        return (new OperationalAttentionProvider(
            new OperationalAlertRepository($this->pdo),
            AlertSurfaces::labels(),
            AlertSurfaces::destinations()
        ))->collect(1);
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function package(int $now): array
    {
        $archivePath = $this->root . '/storage/package.zip';
        $archive = new \ZipArchive();
        $archive->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $connection = $this->createStub(Connection::class);
        $connection->method('getPdo')->willReturn($this->pdo);
        $context = new SupportCollectorContext($archive, $connection, $this->settings, $this->root, $this->root . '/storage');

        (new SecureConnectionCollector($now))->collect($context);
        $archive->close();

        $reader = new \ZipArchive();
        $reader->open($archivePath);
        $file = (string) $reader->getFromName('connexion-securisee.txt');
        $reader->close();

        return [$file, array_values($context->notes())];
    }
}
