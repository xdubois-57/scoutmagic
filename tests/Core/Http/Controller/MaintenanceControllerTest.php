<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\AppConfig;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\File\FileRepository;
use Core\Http\Controller\MaintenanceController;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Maintenance\BackupRepository;
use Core\Maintenance\BackupService;
use Core\Maintenance\CommitInfo;
use Core\Maintenance\GitHubReleaseClientInterface;
use Core\Maintenance\ReleaseInfo;
use Core\Maintenance\UpdateHistoryRepository;
use Core\Module\ModuleManager;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Core\Security\SecretManager;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Core\View\TwigFactory;
use Twig\Environment;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MaintenanceControllerTest extends TestCase
{
    private \PDO $pdo;
    private MaintenanceController $controller;
    private BackupRepository $backupRepository;
    private UpdateHistoryRepository $updateHistoryRepository;
    private SchedulerRepository $schedulerRepository;
    private SettingService $settingService;
    private SecretManager $secretManager;
    private Environment $twig;
    private string $storagePath;

    /**
     * Configurable per-test — the "Vérifier maintenant" / dev-branch
     * install tests set ->release or ->commit before calling the
     * controller; every other test leaves both null (no release/commit
     * found), which is a safe, network-free default.
     */
    private GitHubReleaseClientInterface $fakeReleaseClient;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->backupRepository = new BackupRepository($this->pdo);
        $this->updateHistoryRepository = new UpdateHistoryRepository($this->pdo);
        $fileRepository = new FileRepository($this->pdo);
        $this->schedulerRepository = new SchedulerRepository($this->pdo);
        $schedulerService = new SchedulerService($this->schedulerRepository);
        $journalService = new JournalService(new JournalRepository($this->pdo));
        $this->settingService = new SettingService(new SettingRepository($this->pdo));
        foreach (['update_latest_version', 'update_checked_at', 'update_release_notes', 'update_release_html_url', 'update_download_url', 'installed_version_notes', 'installed_version_notes_url', 'installed_version_notes_for'] as $key) {
            $this->settingService->register($key, '', 'text', $key, $key);
        }
        $this->settingService->register('update_dependencies_changed', '0', 'boolean', 'update_dependencies_changed', 'update_dependencies_changed');
        $this->settingService->register('backup_auto_frequency', 'monthly', 'select', 'L', 'D', null, null, ['none', 'daily', 'weekly', 'biweekly', 'monthly']);
        $this->settingService->register('backup_auto_last_run', '', 'text', 'L', 'D');
        $this->settingService->register('auto_update_enabled', '0', 'boolean', 'L', 'D');
        $this->settingService->register('auto_update_level', 'patch', 'select', 'L', 'D', null, null, ['patch', 'minor', 'major', 'dev']);
        $this->settingService->register('auto_update_day', 'monday', 'select', 'L', 'D', null, null, ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']);
        $this->settingService->register('auto_update_time', '03:00', 'text', 'L', 'D');
        $this->settingService->register('dev_update_branch', 'main', 'text', 'L', 'D');
        $this->settingService->register('update_github_owner', 'owner', 'text', 'L', 'D');
        $this->settingService->register('update_github_repo', 'repo', 'text', 'L', 'D');
        $this->settingService->register('base_url', 'https://example.test', 'url', 'L', 'D');

        $connection = new Connection('127.0.0.1', 3306, 'nonexistent_db', 'nobody', '');
        $storagePath = sys_get_temp_dir() . '/maintenance_controller_test_' . uniqid();
        mkdir($storagePath, 0755, true);
        $this->storagePath = $storagePath;
        $backupService = new BackupService($connection, $storagePath, dirname($storagePath));

        $this->secretManager = new SecretManager($storagePath . '/keys/master.key', $storagePath . '/config/secrets.enc');
        $this->secretManager->generateMasterKey();
        $this->secretManager->writeSecrets([]);

        $moduleManager = $this->createMock(ModuleManager::class);
        $moduleManager->method('getEnabledModuleIds')->willReturn([]);

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        // Built through the real factory, not a bare Environment: this page
        // uses filters that only Core\View\TwigFactory registers (|markdown,
        // for the release notes). A hand-rolled environment silently lacks
        // them, so every test here passed while the live page died with
        // Twig\Error\SyntaxError: Unknown "markdown" filter — a compile-time
        // error no unit test could see. debug: true keeps the compiled-
        // template cache off, as this test needs.
        $this->twig = TwigFactory::create($templateDir, true);
        $this->twig->addGlobal('site_name', 'Test');
        $this->twig->addGlobal('is_authenticated', true);
        $this->twig->addGlobal('current_user_role', 'admin');
        $this->twig->addGlobal('config_mode', false);
        $this->twig->addGlobal('cookie_consent_given', true);
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('csp_nonce', 'test-nonce');
        // csrf_field/csrf_token/get_flash now come from the factory (their
        // real implementations work fine against this test's session).
        // param() is not a factory function — public/index.php registers it
        // over a SettingService — so it still needs a stub here.
        $this->twig->addFunction(new \Twig\TwigFunction('param', fn(...$a) => ''));

        $this->fakeReleaseClient = new class implements GitHubReleaseClientInterface {
            public ?ReleaseInfo $release = null;
            public ?CommitInfo $commit = null;
            public ?ReleaseInfo $releaseByTag = null;
            public ?CommitInfo $commitBySha = null;
            /** @var array<int, ReleaseInfo>|null every published release; null = fall back to $release alone */
            public ?array $releases = null;

            public function getLatestRelease(): ?ReleaseInfo
            {
                return $this->release;
            }

            public function getReleaseByTag(string $tag): ?ReleaseInfo
            {
                return $this->releaseByTag;
            }

            /** @return array<int, ReleaseInfo> */
            public function listReleases(): array
            {
                if ($this->releases !== null) {
                    return $this->releases;
                }

                return $this->release !== null ? [$this->release] : [];
            }

            public bool $lockCheckThrows = false;
            public bool $lockChanged = false;

            public function composerLockChanged(string $base, string $head): bool
            {
                if ($this->lockCheckThrows) {
                    throw new \RuntimeException('GitHub compare unavailable');
                }

                return $this->lockChanged;
            }

            public function getLatestCommit(string $branch): ?CommitInfo
            {
                return $this->commit;
            }

            public function getCommit(string $sha): ?CommitInfo
            {
                return $this->commitBySha;
            }
        };

        $this->controller = new MaintenanceController(
            $this->twig, $backupService, $this->backupRepository, $fileRepository, $this->updateHistoryRepository, $schedulerService,
            $moduleManager, $encryption, $journalService, $this->settingService, $storagePath, $this->secretManager, $this->fakeReleaseClient,
            null,
            // The health block's crontab line is spelled from the public
            // directory, the one anchor valid in both hosting layouts.
            dirname($storagePath) . '/public'
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'admin@test.be', 'admin');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    private function csrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        return $token;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonRequest(array $data): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/config/maintenance/backup/full', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn(json_encode($data));
        return $request;
    }

    public function testIndexRendersEmptyState(): void
    {
        $response = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Maintenance', $response->getBody());
        $this->assertStringContainsString('Aucune sauvegarde', $response->getBody());
    }

    // --- Bloc « État » : cron réel + mise à jour automatique ---------------

    private function writeCronHeartbeat(int $at): void
    {
        if (!is_dir($this->storagePath . '/temp')) {
            mkdir($this->storagePath . '/temp', 0755, true);
        }
        file_put_contents($this->storagePath . '/temp/cron-heartbeat', (string) $at);
    }

    /**
     * The failure this block exists for is the silent one: a crontab that
     * never fires produces no error anywhere, and the reference
     * installation ran that way for days. The page must say so, in red,
     * with the exact line to configure — `php` prefix included, since a
     * hosting panel handed a bare script path executes nothing at all.
     */
    public function testTheHealthBlockNamesAMissingCronAndShowsTheLineToConfigure(): void
    {
        $body = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('Tâche cron réelle', $body);
        $this->assertStringContainsString('Jamais détecté', $body);
        $this->assertStringContainsString('* * * * * php ' . dirname($this->storagePath) . '/public/cron.php', $body);
        $this->assertStringContainsString('maintenance-cron-warning', $body);
    }

    public function testTheHealthBlockReportsAnActiveCronWithItsCadence(): void
    {
        $now = time();
        $this->writeCronHeartbeat($now - 40);
        $this->settingService->register('cron_last_run', (string) ($now - 40), 'number', 'L', 'D', null, null, null, false, 999);
        \Core\Scheduler\CronRunHistory::register($this->settingService);
        $this->settingService->setInternal(
            \Core\Scheduler\CronRunHistory::SETTING,
            (string) json_encode([$now - 220, $now - 160, $now - 100, $now - 40])
        );

        $body = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('Actif', $body);
        $this->assertStringContainsString('cadence ~1 min', $body);
        // Nothing to fix, so nothing is shown to fix it with.
        $this->assertStringNotContainsString('maintenance-cron-warning', $body);
    }

    public function testTheHealthBlockReportsAStaleCronRatherThanAMissingOne(): void
    {
        $this->writeCronHeartbeat(time() - 10800);

        $body = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('Plus détecté', $body);
        $this->assertStringNotContainsString('Jamais détecté', $body);
        $this->assertStringContainsString('maintenance-cron-warning', $body);
    }

    /**
     * A channel whose last three installs all rolled back still carries a
     * perfectly good "dernière mise à jour réussie" date. Reading only
     * that one is how six consecutive rollbacks stayed invisible on this
     * very page — the MOST RECENT attempt is the other half.
     */
    public function testTheHealthBlockFlagsAFailedMostRecentAttemptBesideTheLastSuccess(): void
    {
        $succeeded = $this->updateHistoryRepository->create('1.0.0', '1.1.0', false, null);
        $this->updateHistoryRepository->markCompleted($succeeded);
        $failed = $this->updateHistoryRepository->create('1.1.0', '1.2.0', false, null);
        $this->updateHistoryRepository->markRolledBack($failed, 'migration KO');

        $body = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('maintenance-auto-update-health', $body);
        $this->assertStringContainsString('maintenance-update-last-attempt', $body);
        $this->assertStringContainsString('restauré automatiquement', $body);
    }

    public function testTheHealthBlockStaysQuietWhenTheMostRecentAttemptSucceeded(): void
    {
        $id = $this->updateHistoryRepository->create('1.0.0', '1.1.0', false, null);
        $this->updateHistoryRepository->markCompleted($id);

        $body = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('maintenance-auto-update-health', $body);
        $this->assertStringNotContainsString('maintenance-update-last-attempt', $body);
    }

    /**
     * Five rows was roughly one evening of dev-mode auto-updates, so the
     * six consecutive rollbacks that wedged production did not fit on the
     * page at once — and a run of failures all stopping at the same point
     * is exactly what this table exists to make obvious.
     */
    public function testTheMaintenancePageListsTwentyPastInstallationsNotFive(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $id = $this->updateHistoryRepository->create('dev-from' . $i, 'dev-vers' . $i, false, null);
            $this->updateHistoryRepository->markCompleted($id);
        }

        $body = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), [])->getBody();

        // Counted on the "version de départ" column, which only the
        // history table renders — the newest target version also appears
        // in the auto-update health line above it, and counting that one
        // would silently make 20 look like 21.
        $this->assertStringContainsString('dev-from25', $body);
        $this->assertStringContainsString('dev-from6', $body);
        $this->assertStringNotContainsString('dev-from5<', $body);
        $this->assertSame(20, substr_count($body, 'dev-from'));
    }

    /**
     * The auto-update health signal. It exists because everything else
     * about that channel stays green when it stops working — a push
     * webhook answers 200 whether it installed or ignored the push — so a
     * site can sit frozen for hundreds of commits and look healthy. These
     * four tests pin the only place that says otherwise.
     */
    public function testMaintenancePageReportsWhenTheLastAutomaticUpdateInstalled(): void
    {
        $id = $this->updateHistoryRepository->create('dev-aaaaaaa', 'dev-bbbbbbb', false, null);
        $this->updateHistoryRepository->markCompleted($id);

        $body = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('Dernière mise à jour automatique installée', $body);
        $this->assertStringContainsString('dev-bbbbbbb', $body);
    }

    public function testMaintenancePageSaysSoWhenNoAutomaticUpdateEverInstalled(): void
    {
        $body = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('Dernière mise à jour automatique installée', $body);
        $this->assertStringContainsString('aucune', $body);
    }

    /**
     * A dev channel that has never installed anything has never worked —
     * that is a fault on day one, not a site waiting to get going.
     */
    public function testDevChannelThatNeverInstalledAnythingRaisesTheWarning(): void
    {
        $this->settingService->set('auto_update_enabled', '1');
        $this->settingService->set('auto_update_level', 'dev');

        $body = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('auto-update-silence-warning', $body);
    }

    /**
     * The threshold itself: a dev channel that DID install, but long ago.
     * Distinct from the never-installed case above, which warns whatever
     * the threshold is and so cannot pin it.
     */
    public function testDevChannelSilentBeyondTheThresholdRaisesTheWarning(): void
    {
        $this->settingService->set('auto_update_enabled', '1');
        $this->settingService->set('auto_update_level', 'dev');
        $id = $this->updateHistoryRepository->create('dev-aaaaaaa', 'dev-bbbbbbb', false, null);
        $this->updateHistoryRepository->markCompleted($id);
        $this->ageCompletedAt($id, 30);

        $body = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('auto-update-silence-warning', $body);
        $this->assertStringContainsString('30 jours', $body);
    }

    /**
     * And the other side of that threshold: silent, but not yet long
     * enough to be worth saying anything about.
     */
    public function testDevChannelSilentWithinTheThresholdRaisesNoWarning(): void
    {
        $this->settingService->set('auto_update_enabled', '1');
        $this->settingService->set('auto_update_level', 'dev');
        $id = $this->updateHistoryRepository->create('dev-aaaaaaa', 'dev-bbbbbbb', false, null);
        $this->updateHistoryRepository->markCompleted($id);
        $this->ageCompletedAt($id, 3);

        $body = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), [])->getBody();

        $this->assertStringNotContainsString('auto-update-silence-warning', $body);
    }

    /**
     * The same silence on a stable channel is not a fault: it only means
     * nobody published a release. Warning about it would teach an
     * administrator to ignore the warning that matters.
     */
    public function testStableChannelSilenceRaisesNoWarning(): void
    {
        $this->settingService->set('auto_update_enabled', '1');
        $this->settingService->set('auto_update_level', 'minor');

        $body = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), [])->getBody();

        $this->assertStringNotContainsString('auto-update-silence-warning', $body);
    }

    /**
     * A dev channel that installed something today is working — the
     * warning must stay away, or it is noise from the first day.
     */
    public function testRecentDevInstallRaisesNoWarning(): void
    {
        $this->settingService->set('auto_update_enabled', '1');
        $this->settingService->set('auto_update_level', 'dev');
        $id = $this->updateHistoryRepository->create('dev-aaaaaaa', 'dev-bbbbbbb', false, null);
        $this->updateHistoryRepository->markCompleted($id);

        $body = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), [])->getBody();

        $this->assertStringNotContainsString('auto-update-silence-warning', $body);
    }

    public function testDatabaseBackupButtonIsLabeledGenerer(): void
    {
        $response = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), []);

        $this->assertStringContainsString('Générer', $response->getBody());
    }

    /**
     * The restore dropdown and the recent-backups table must both show the
     * human-readable type label ("Base de données seule"), never the raw
     * internal type string ("database").
     */
    public function testBackupTypeIsShownAsAHumanReadableLabelEverywhere(): void
    {
        $id = $this->backupRepository->create('database', 1);
        $this->backupRepository->markCompleted($id, 42, null);

        $response = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), []);
        $body = $response->getBody();

        $this->assertStringContainsString('Base de données seule', $body);
        $this->assertStringNotContainsString('>database<', $body);
        $this->assertStringNotContainsString('>database —', $body);
    }

    public function testCreateDatabaseBackupValidatesCsrf(): void
    {
        $request = new Request('POST', '/config/maintenance/backup/database', [], ['_csrf_token' => 'bad'], [], []);

        $response = $this->controller->createDatabaseBackup($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame([], $this->backupRepository->findRecent(5));
    }

    public function testCreateFullBackupValidatesCsrf(): void
    {
        $response = $this->controller->createFullBackup($this->jsonRequest([
            'scope' => 'full_config', 'password' => 'secret123', '_csrf_token' => 'bad',
        ]), []);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testCreateFullBackupRejectsInvalidScope(): void
    {
        $token = $this->csrfToken();

        $response = $this->controller->createFullBackup($this->jsonRequest([
            'scope' => 'not_a_scope', 'password' => 'secret123', '_csrf_token' => $token,
        ]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertSame(400, $response->getStatusCode());
    }

    public function testCreateFullBackupRejectsGalleryScopeWhenModuleDisabled(): void
    {
        $token = $this->csrfToken();

        $response = $this->controller->createFullBackup($this->jsonRequest([
            'scope' => 'full_with_gallery', 'password' => 'secret123', '_csrf_token' => $token,
        ]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
    }

    public function testCreateFullBackupRejectsEmptyPassword(): void
    {
        $token = $this->csrfToken();

        $response = $this->controller->createFullBackup($this->jsonRequest([
            'scope' => 'full_config', 'password' => '', '_csrf_token' => $token,
        ]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
    }

    public function testCreateFullBackupSchedulesTheBackgroundTaskAndReturnsBackupId(): void
    {
        $token = $this->csrfToken();

        $response = $this->controller->createFullBackup($this->jsonRequest([
            'scope' => 'full_config', 'password' => 'secret123', '_csrf_token' => $token,
        ]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertIsInt($decoded['backup_id']);

        $backup = $this->backupRepository->findById($decoded['backup_id']);
        $this->assertNotNull($backup);
        $this->assertSame('pending', $backup->status);

        $scheduled = $this->schedulerRepository->findByModuleAndTaskKey('core', 'create_backup');
        $this->assertCount(1, $scheduled);
    }

    public function testBackupStatusReturns404ForUnknownId(): void
    {
        $response = $this->controller->backupStatus(new Request('GET', '/api/maintenance/backup-status/999', [], [], [], []), ['id' => '999']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testBackupStatusReturnsCurrentState(): void
    {
        $id = $this->backupRepository->create('database', 1);

        $response = $this->controller->backupStatus(new Request('GET', '/api/maintenance/backup-status/' . $id, [], [], [], []), ['id' => (string) $id]);

        $decoded = json_decode($response->getBody(), true);
        $this->assertSame('pending', $decoded['status']);
        $this->assertNull($decoded['download_url']);
    }

    public function testBackupStatusReturnsDownloadUrlWhenCompleted(): void
    {
        $id = $this->backupRepository->create('database', 1);
        $this->backupRepository->markCompleted($id, 42, null);

        $response = $this->controller->backupStatus(new Request('GET', '/api/maintenance/backup-status/' . $id, [], [], [], []), ['id' => (string) $id]);

        $decoded = json_decode($response->getBody(), true);
        $this->assertSame('/files/42', $decoded['download_url']);
    }

    public function testIndexShowsUpdateAvailableWhenNewerVersionIsCached(): void
    {
        $this->settingService->setInternal('update_latest_version', '99.0.0');
        $this->settingService->setInternal('update_release_html_url', 'https://github.com/x/y/releases/tag/v99.0.0');

        $response = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), []);

        $this->assertStringContainsString('99.0.0', $response->getBody());
        $this->assertStringContainsString('Installer la mise à jour', $response->getBody());
    }

    public function testIndexHidesUpdateSectionWhenAlreadyUpToDate(): void
    {
        $response = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), []);

        $this->assertStringContainsString('Le site est à jour', $response->getBody());
    }

    /**
     * A dev/branch install's VersionFile content is literally
     * "dev-{7-char-sha}" — split for display into a clean "dev" label with
     * the commit shown separately in parentheses, rather than the raw
     * concatenated string.
     */
    public function testIndexShowsTheInstalledCommitInParenthesesForADevBuild(): void
    {
        $versionFile = sys_get_temp_dir() . '/VERSION';
        $original = is_file($versionFile) ? file_get_contents($versionFile) : null;
        file_put_contents($versionFile, "dev-a1b2c3d\n");

        try {
            $response = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), []);
            $body = $response->getBody();

            $this->assertStringContainsString('Version installée : <strong>dev</strong>', $body);
            $this->assertStringContainsString('(a1b2c3d)', $body);
        } finally {
            if ($original !== null) {
                file_put_contents($versionFile, $original);
            } else {
                @unlink($versionFile);
            }
        }
    }

    public function testIndexShowsNoParenthesesForANormalReleaseVersion(): void
    {
        $response = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), []);

        $this->assertStringNotContainsString('<span class="text-body-secondary">(', $response->getBody());
    }

    public function testIndexShowsTheInstalledVersionsOwnReleaseNotesForAStableRelease(): void
    {
        $this->fakeReleaseClient->releaseByTag = new ReleaseInfo(
            'v0.0.0',
            'Corrige un bug important dans le module Finances.',
            'https://github.com/x/y/releases/tag/v0.0.0',
            null
        );

        $response = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), []);

        $this->assertStringContainsString('Corrige un bug important dans le module Finances.', $response->getBody());
        $this->assertStringContainsString('https://github.com/x/y/releases/tag/v0.0.0', $response->getBody());
    }

    public function testIndexShowsTheInstalledCommitMessageForADevBuild(): void
    {
        $versionFile = sys_get_temp_dir() . '/VERSION';
        $original = is_file($versionFile) ? file_get_contents($versionFile) : null;
        file_put_contents($versionFile, "dev-a1b2c3d\n");
        $this->fakeReleaseClient->commitBySha = new CommitInfo(
            'a1b2c3d0000000000000000000000000000000',
            "Corrige la pagination du Trombinoscope\n\nDétails de la correction.",
            'https://github.com/x/y/commit/a1b2c3d'
        );

        try {
            $response = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), []);
            $body = $response->getBody();

            $this->assertStringContainsString('Corrige la pagination du Trombinoscope', $body);
            $this->assertStringContainsString('Détails de la correction.', $body);
            $this->assertStringContainsString('https://github.com/x/y/commit/a1b2c3d', $body);
        } finally {
            if ($original !== null) {
                file_put_contents($versionFile, $original);
            } else {
                @unlink($versionFile);
            }
        }
    }

    public function testIndexCachesTheInstalledVersionNotesAndDoesNotRefetchOnASecondLoad(): void
    {
        $this->fakeReleaseClient->releaseByTag = new ReleaseInfo('v0.0.0', 'Notes originales.', 'https://example.test/1', null);

        $first = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), []);
        $this->assertStringContainsString('Notes originales.', $first->getBody());

        // Same installed version, different fake response — a second load
        // must still show the CACHED notes, proving the controller reused
        // the setting instead of calling the GitHub client again.
        $this->fakeReleaseClient->releaseByTag = new ReleaseInfo('v0.0.0', 'Notes remplacees.', 'https://example.test/2', null);

        $second = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), []);
        $this->assertStringContainsString('Notes originales.', $second->getBody());
        $this->assertStringNotContainsString('Notes remplacees.', $second->getBody());
    }

    /**
     * While the configured channel is still 'dev', a dev build tracks the
     * branch's latest commit and is treated as always newer than any
     * stable release — the "Une nouvelle version est disponible" banner
     * must not appear for a cached release while a dev build is installed
     * and the channel remains 'dev' (version_compare would wrongly rank
     * "dev" below it).
     */
    public function testIndexDoesNotShowUpdateAvailableWhenADevBuildIsInstalledAndChannelStaysDev(): void
    {
        $versionFile = sys_get_temp_dir() . '/VERSION';
        $original = is_file($versionFile) ? file_get_contents($versionFile) : null;
        file_put_contents($versionFile, "dev-a1b2c3d\n");
        $this->settingService->setInternal('update_latest_version', '1.0.22');
        $this->settingService->set('auto_update_level', 'dev');
        $this->settingService->clearCache();

        try {
            $response = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), []);
            $body = $response->getBody();

            $this->assertStringNotContainsString('Une nouvelle version est disponible', $body);
            $this->assertStringNotContainsString('Installer la mise à jour', $body);
            $this->assertStringContainsString('Le site est à jour', $body);
        } finally {
            if ($original !== null) {
                file_put_contents($versionFile, $original);
            } else {
                @unlink($versionFile);
            }
        }
    }

    /**
     * Once the admin has switched the configured channel away from 'dev'
     * back to a numbered level, a leftover installed dev build must no
     * longer mask a genuinely newer stable release — the admin explicitly
     * asked to move off dev, so the update must be detected and offered.
     */
    public function testIndexShowsUpdateAvailableWhenADevBuildIsInstalledButChannelIsNoLongerDev(): void
    {
        $versionFile = sys_get_temp_dir() . '/VERSION';
        $original = is_file($versionFile) ? file_get_contents($versionFile) : null;
        file_put_contents($versionFile, "dev-a1b2c3d\n");
        $this->settingService->setInternal('update_latest_version', '1.0.22');
        $this->settingService->set('auto_update_level', 'minor');
        $this->settingService->clearCache();

        try {
            $response = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), []);
            $body = $response->getBody();

            $this->assertStringContainsString('Une nouvelle version est disponible', $body);
        } finally {
            if ($original !== null) {
                file_put_contents($versionFile, $original);
            } else {
                @unlink($versionFile);
            }
        }
    }

    /**
     * The dev-mode warning keeps its test-environment warnings but no
     * longer claims stable releases are ignored while the mode is active.
     */
    public function testIndexDevModeWarningNoLongerMentionsIgnoredStableReleases(): void
    {
        $this->settingService->set('auto_update_level', 'dev');
        $this->settingService->clearCache();

        $response = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), []);
        $body = $response->getBody();

        $this->assertStringContainsString('Réservé aux environnements de test', $body);
        $this->assertStringNotContainsString('les mises à jour de versions stables sont ignorées', $body);
    }

    public function testInstallUpdateValidatesCsrf(): void
    {
        $response = $this->controller->installUpdate($this->jsonRequest([
            '_csrf_token' => 'bad',
        ]), []);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testInstallUpdateRejectsWhenNoUpdateIsAvailable(): void
    {
        $token = $this->csrfToken();

        $response = $this->controller->installUpdate($this->jsonRequest(['_csrf_token' => $token]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->updateHistoryRepository->findRecent(5));
    }

    public function testInstallUpdateSchedulesTheBackgroundTaskAndReturnsHistoryId(): void
    {
        $this->settingService->setInternal('update_latest_version', '99.0.0');
        $this->settingService->setInternal('update_download_url', 'https://example.test/artifact.zip');
        $token = $this->csrfToken();

        $response = $this->controller->installUpdate($this->jsonRequest(['_csrf_token' => $token]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertIsInt($decoded['history_id']);

        $history = $this->updateHistoryRepository->findById($decoded['history_id']);
        $this->assertNotNull($history);
        $this->assertSame('pending', $history->status);
        $this->assertSame('99.0.0', $history->versionTo);

        $scheduled = $this->schedulerRepository->findByModuleAndTaskKey('core', 'install_update');
        $this->assertCount(1, $scheduled);
    }

    public function testUpdateStatusReturns404ForUnknownId(): void
    {
        $response = $this->controller->updateStatus(new Request('GET', '/api/maintenance/update-status/999', [], [], [], []), ['id' => '999']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testUpdateStatusReturnsCurrentState(): void
    {
        $id = $this->updateHistoryRepository->create('1.0.0', '1.1.0', false, 1);

        $response = $this->controller->updateStatus(new Request('GET', '/api/maintenance/update-status/' . $id, [], [], [], []), ['id' => (string) $id]);

        $decoded = json_decode($response->getBody(), true);
        $this->assertSame('pending', $decoded['status']);
    }

    /**
     * A controller with a runner attached, so the polling behaviour can be
     * asserted on the one thing that matters: WHEN it migrates.
     */
    private function controllerWithRunner(\Core\Database\MigrationRunner $runner): MaintenanceController
    {
        $reflection = new \ReflectionClass($this->controller);
        $clone = clone $this->controller;
        $property = $reflection->getProperty('migrationRunner');
        $property->setAccessible(true);
        $property->setValue($clone, $runner);

        return $clone;
    }

    /**
     * The administrator watching this page refetches every three seconds
     * anyway, and each of those requests used to read a status row and do
     * nothing. Now they drive the migration — the same thing the
     * migration-in-progress page's own endpoint does, and for the same
     * reason: a migration that only advances when the scheduler happens to
     * run is a migration somebody watches not advancing.
     */
    public function testPollingDrivesTheMigrationWhileAnUpdateIsMigrating(): void
    {
        $id = $this->updateHistoryRepository->create('1.0.0', '1.1.0', false, 1);
        $this->updateHistoryRepository->setStatus($id, 'migrating');

        $runner = $this->createMock(\Core\Database\MigrationRunner::class);
        $runner->expects($this->once())
            ->method('migrate')
            ->willReturn(new \Core\Database\MigrationResult([], [], false, 0.42));

        $response = $this->controllerWithRunner($runner)->updateStatus(
            new Request('GET', '/api/maintenance/update-status/' . $id, [], [], [], []),
            ['id' => (string) $id]
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertSame('migrating', $decoded['status']);
        $this->assertSame(0.42, $decoded['migration_progress']);
    }

    /**
     * And never outside that step. Any other status means either nothing
     * to migrate or a step this endpoint has no business touching —
     * running DDL on a status poll for a failed update would be
     * gratuitous, and on a completed one, work nobody asked for.
     */
    public function testPollingNeverMigratesOutsideTheMigratingStep(): void
    {
        $runner = $this->createMock(\Core\Database\MigrationRunner::class);
        $runner->expects($this->never())->method('migrate');
        $controller = $this->controllerWithRunner($runner);

        foreach (['pending', 'backing_up', 'downloading', 'installing', 'completed', 'failed', 'rolled_back'] as $status) {
            $id = $this->updateHistoryRepository->create('1.0.0', '1.1.0', false, 1);
            $this->updateHistoryRepository->setStatus($id, $status);

            $response = $controller->updateStatus(
                new Request('GET', '/api/maintenance/update-status/' . $id, [], [], [], []),
                ['id' => (string) $id]
            );

            $decoded = json_decode($response->getBody(), true);
            $this->assertSame($status, $decoded['status']);
            $this->assertNull($decoded['migration_progress'], "no slice may run in '{$status}'");
        }
    }

    /**
     * This endpoint's job is to report a status. A migration that throws
     * must not turn that into a 500 and leave the page unable to say what
     * is happening — the scheduled resume task owns the failure path,
     * including the rollback, and will meet the same error on its own pass.
     */
    public function testAFailingSliceStillReportsTheStatus(): void
    {
        $id = $this->updateHistoryRepository->create('1.0.0', '1.1.0', false, 1);
        $this->updateHistoryRepository->setStatus($id, 'migrating');

        $runner = $this->createMock(\Core\Database\MigrationRunner::class);
        $runner->method('migrate')->willThrowException(new \RuntimeException('boom'));

        $response = $this->controllerWithRunner($runner)->updateStatus(
            new Request('GET', '/api/maintenance/update-status/' . $id, [], [], [], []),
            ['id' => (string) $id]
        );

        $this->assertSame(200, $response->getStatusCode());
        $decoded = json_decode($response->getBody(), true);
        $this->assertSame('migrating', $decoded['status']);
        $this->assertNull($decoded['migration_progress']);
    }

    public function testUpdateAutoBackupFrequencyValidatesCsrf(): void
    {
        $response = $this->controller->updateAutoBackupFrequency($this->jsonRequest(['frequency' => 'weekly', '_csrf_token' => 'bad']), []);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testUpdateAutoBackupFrequencyRejectsInvalidValue(): void
    {
        $token = $this->csrfToken();

        $response = $this->controller->updateAutoBackupFrequency($this->jsonRequest(['frequency' => 'yearly', '_csrf_token' => $token]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->settingService->clearCache();
        $this->assertSame('monthly', $this->settingService->get('backup_auto_frequency'));
    }

    public function testUpdateAutoBackupFrequencySavesTheValue(): void
    {
        $token = $this->csrfToken();

        $response = $this->controller->updateAutoBackupFrequency($this->jsonRequest(['frequency' => 'weekly', '_csrf_token' => $token]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->settingService->clearCache();
        $this->assertSame('weekly', $this->settingService->get('backup_auto_frequency'));
    }

    public function testResetSettingsValidatesCsrf(): void
    {
        $response = $this->controller->resetSettings($this->jsonRequest(['confirm_keyword' => 'REINITIALISER', '_csrf_token' => 'bad']), []);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testResetSettingsRejectsWrongKeyword(): void
    {
        $token = $this->csrfToken();

        $response = $this->controller->resetSettings($this->jsonRequest(['confirm_keyword' => 'nope', '_csrf_token' => $token]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->schedulerRepository->findByModuleAndTaskKey('core', 'reset_settings'));
    }

    public function testResetSettingsSchedulesTheBackgroundTask(): void
    {
        $token = $this->csrfToken();

        $response = $this->controller->resetSettings($this->jsonRequest(['confirm_keyword' => 'REINITIALISER', '_csrf_token' => $token]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertIsInt($decoded['action_id']);
        $this->assertCount(1, $this->schedulerRepository->findByModuleAndTaskKey('core', 'reset_settings'));
    }

    public function testFullResetValidatesCsrf(): void
    {
        $response = $this->controller->fullReset($this->jsonRequest([
            'confirm_keyword' => 'EFFACER', 'confirm_checkbox' => true, '_csrf_token' => 'bad',
        ]), []);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testFullResetRejectsWrongKeyword(): void
    {
        $token = $this->csrfToken();

        $response = $this->controller->fullReset($this->jsonRequest([
            'confirm_keyword' => 'nope', 'confirm_checkbox' => true, '_csrf_token' => $token,
        ]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
    }

    public function testFullResetRejectsWithoutCheckbox(): void
    {
        $token = $this->csrfToken();

        $response = $this->controller->fullReset($this->jsonRequest([
            'confirm_keyword' => 'EFFACER', 'confirm_checkbox' => false, '_csrf_token' => $token,
        ]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertSame([], $this->schedulerRepository->findByModuleAndTaskKey('core', 'full_reset'));
    }

    public function testFullResetSchedulesTheBackgroundTask(): void
    {
        $token = $this->csrfToken();

        $response = $this->controller->fullReset($this->jsonRequest([
            'confirm_keyword' => 'EFFACER', 'confirm_checkbox' => true, '_csrf_token' => $token,
        ]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertCount(1, $this->schedulerRepository->findByModuleAndTaskKey('core', 'full_reset'));
    }

    public function testRestoreBackupValidatesCsrf(): void
    {
        $request = new Request('POST', '/config/maintenance/reset/restore', [], [
            '_csrf_token' => 'bad', 'confirm_keyword' => 'RESTAURER', 'source' => 'server', 'backup_id' => '1',
        ], [], []);

        $response = $this->controller->restoreBackup($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame([], $this->schedulerRepository->findByModuleAndTaskKey('core', 'restore_backup'));
    }

    public function testRestoreBackupRejectsWrongKeyword(): void
    {
        $token = $this->csrfToken();
        $request = new Request('POST', '/config/maintenance/reset/restore', [], [
            '_csrf_token' => $token, 'confirm_keyword' => 'nope', 'source' => 'server', 'backup_id' => '1',
        ], [], []);

        $response = $this->controller->restoreBackup($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame([], $this->schedulerRepository->findByModuleAndTaskKey('core', 'restore_backup'));
    }

    public function testRestoreBackupRejectsUnknownServerBackup(): void
    {
        $token = $this->csrfToken();
        $request = new Request('POST', '/config/maintenance/reset/restore', [], [
            '_csrf_token' => $token, 'confirm_keyword' => 'RESTAURER', 'source' => 'server', 'backup_id' => '999',
        ], [], []);

        $response = $this->controller->restoreBackup($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame([], $this->schedulerRepository->findByModuleAndTaskKey('core', 'restore_backup'));
    }

    // --- Restauration : envoi fragmenté (audit M2) ---

    private const RESTORE_UPLOAD_ID = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

    /**
     * Registers $bytes as this request's uploaded chunk and returns the
     * matching Request for POST /config/maintenance/restore-upload-chunk.
     */
    private function restoreChunkRequest(string $bytes, int $offset, bool $isLast, string $token): Request
    {
        $path = tempnam(sys_get_temp_dir(), 'restore_chunk_');
        file_put_contents($path, $bytes);
        $_FILES['file'] = ['name' => 'chunk', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes), 'type' => 'application/octet-stream'];

        return new Request('POST', '/config/maintenance/restore-upload-chunk', [], [
            '_csrf_token' => $token,
            'upload_id' => self::RESTORE_UPLOAD_ID,
            'chunk_offset' => (string) $offset,
            'last' => $isLast ? '1' : '0',
        ], [], []);
    }

    public function testRestoreUploadChunkValidatesCsrf(): void
    {
        $response = $this->controller->restoreUploadChunk($this->restoreChunkRequest('data', 0, false, 'bad'), []);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame([], glob($this->storagePath . '/temp/chunked_uploads/*.part') ?: []);

        unset($_FILES['file']);
    }

    public function testRestoreUploadChunkRejectsAnOutOfOrderChunkWith409(): void
    {
        $token = $this->csrfToken();
        $this->controller->restoreUploadChunk($this->restoreChunkRequest('abcdef', 0, false, $token), []);

        $response = $this->controller->restoreUploadChunk($this->restoreChunkRequest('ghijkl', 42, false, $token), []);

        $this->assertSame(409, $response->getStatusCode());
        $decoded = json_decode($response->getBody(), true);
        $this->assertSame(6, $decoded['received']);
        // Core\File\UploadException is marked
        // Core\Exception\UserFacingException, so its own French sentence
        // (which carries the resume offset the client needs) survives the
        // gate rather than being replaced by the controller's fallback.
        $this->assertStringContainsString('Fragment hors séquence', $decoded['error']);

        unset($_FILES['file']);
    }

    public function testRestoreBackupConsumesAChunkedUploadByItsId(): void
    {
        $token = $this->csrfToken();
        $first = $this->controller->restoreUploadChunk($this->restoreChunkRequest('PK archive ', 0, false, $token), []);
        $this->assertTrue(json_decode($first->getBody(), true)['success']);
        $last = $this->controller->restoreUploadChunk($this->restoreChunkRequest('bytes', 11, true, $token), []);
        $this->assertTrue(json_decode($last->getBody(), true)['success']);
        unset($_FILES['file']);

        $request = new Request('POST', '/config/maintenance/reset/restore', [], [
            '_csrf_token' => $token, 'confirm_keyword' => 'RESTAURER', 'source' => 'upload',
            'upload_id' => self::RESTORE_UPLOAD_ID,
        ], [], []);

        $response = $this->controller->restoreBackup($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('restore_id=', $response->getHeaders()['Location'] ?? '');
        $tasks = $this->schedulerRepository->findByModuleAndTaskKey('core', 'restore_backup');
        $this->assertCount(1, $tasks);
        $payload = json_decode((string) $tasks[0]['payload'], true);
        $tempPath = (string) $payload['uploaded_temp_path'];
        $this->assertFileExists($tempPath);
        $this->assertSame('PK archive bytes', file_get_contents($tempPath));
        // The assembled partial was moved, not copied.
        $this->assertSame([], glob($this->storagePath . '/temp/chunked_uploads/*.part') ?: []);
        @unlink($tempPath);
    }

    public function testRestoreBackupRejectsAnUnknownUploadId(): void
    {
        $token = $this->csrfToken();
        $request = new Request('POST', '/config/maintenance/reset/restore', [], [
            '_csrf_token' => $token, 'confirm_keyword' => 'RESTAURER', 'source' => 'upload',
            'upload_id' => self::RESTORE_UPLOAD_ID,
        ], [], []);

        $response = $this->controller->restoreBackup($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringNotContainsString('restore_id=', $response->getHeaders()['Location'] ?? '');
        $this->assertSame([], $this->schedulerRepository->findByModuleAndTaskKey('core', 'restore_backup'));
    }

    public function testRestoreBackupSchedulesTheBackgroundTaskForAnExistingServerBackup(): void
    {
        $backupId = $this->backupRepository->create('database', 1);
        $this->backupRepository->markCompleted($backupId, 1, 1);
        $token = $this->csrfToken();

        $request = new Request('POST', '/config/maintenance/reset/restore', [], [
            '_csrf_token' => $token, 'confirm_keyword' => 'RESTAURER', 'source' => 'server', 'backup_id' => (string) $backupId,
        ], [], []);

        $response = $this->controller->restoreBackup($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $location = $response->getHeaders()['Location'] ?? '';
        $this->assertStringContainsString('restore_id=', $location);
        $this->assertCount(1, $this->schedulerRepository->findByModuleAndTaskKey('core', 'restore_backup'));
    }

    // --- Mises à jour automatiques ---

    public function testSaveAutoUpdatePreferencesPersistsAllFourFields(): void
    {
        $token = $this->csrfToken();
        $request = $this->jsonRequest([
            'enabled' => true, 'level' => 'minor', 'day' => 'friday', 'time' => '22:30', '_csrf_token' => $token,
        ]);

        $response = $this->controller->saveAutoUpdatePreferences($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);

        $this->settingService->clearCache();
        $this->assertSame('1', $this->settingService->get('auto_update_enabled'));
        $this->assertSame('minor', $this->settingService->get('auto_update_level'));
        $this->assertSame('friday', $this->settingService->get('auto_update_day'));
        $this->assertSame('22:30', $this->settingService->get('auto_update_time'));
    }

    public function testSaveAutoUpdatePreferencesRejectsAnInvalidLevel(): void
    {
        $token = $this->csrfToken();
        $request = $this->jsonRequest(['enabled' => true, 'level' => 'bogus', 'day' => 'monday', 'time' => '03:00', '_csrf_token' => $token]);

        $response = $this->controller->saveAutoUpdatePreferences($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
    }

    public function testSaveAutoUpdatePreferencesRejectsAnInvalidTime(): void
    {
        $token = $this->csrfToken();
        $request = $this->jsonRequest(['enabled' => true, 'level' => 'patch', 'day' => 'monday', 'time' => '25:99', '_csrf_token' => $token]);

        $response = $this->controller->saveAutoUpdatePreferences($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
    }

    public function testSaveAutoUpdatePreferencesValidatesCsrf(): void
    {
        $request = $this->jsonRequest(['enabled' => true, 'level' => 'patch', 'day' => 'monday', 'time' => '03:00', '_csrf_token' => 'bad']);

        $response = $this->controller->saveAutoUpdatePreferences($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
    }

    public function testGenerateWebhookSecretReturnsTheSecretExactlyOnceAndPersistsIt(): void
    {
        $token = $this->csrfToken();
        $response = $this->controller->generateWebhookSecret($this->jsonRequest(['_csrf_token' => $token]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertSame(64, strlen($decoded['secret']));

        $secrets = $this->secretManager->readSecrets();
        $this->assertSame($decoded['secret'], $secrets['github_webhook_secret']);
    }

    public function testGenerateWebhookSecretRegeneratesAndReplacesAnExistingOne(): void
    {
        $first = json_decode(
            $this->controller->generateWebhookSecret($this->jsonRequest(['_csrf_token' => $this->csrfToken()]), [])->getBody(),
            true
        );
        $second = json_decode(
            $this->controller->generateWebhookSecret($this->jsonRequest(['_csrf_token' => $this->csrfToken()]), [])->getBody(),
            true
        );

        $this->assertNotSame($first['secret'], $second['secret']);
        $secrets = $this->secretManager->readSecrets();
        $this->assertSame($second['secret'], $secrets['github_webhook_secret']);
    }

    // --- Mode développement (folded into "Mises à jour automatiques" as
    // the 'dev' auto_update_level, no separate danger-zone flow anymore) ---

    public function testSaveAutoUpdatePreferencesPersistsDevLevelAndBranch(): void
    {
        $token = $this->csrfToken();
        $request = $this->jsonRequest([
            'enabled' => true, 'level' => 'dev', 'branch' => 'develop', '_csrf_token' => $token,
        ]);

        $response = $this->controller->saveAutoUpdatePreferences($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);

        $this->settingService->clearCache();
        $this->assertSame('1', $this->settingService->get('auto_update_enabled'));
        $this->assertSame('dev', $this->settingService->get('auto_update_level'));
        $this->assertSame('develop', $this->settingService->get('dev_update_branch'));
    }

    public function testSaveAutoUpdatePreferencesRejectsAnEmptyBranchForDevLevel(): void
    {
        $token = $this->csrfToken();
        $request = $this->jsonRequest(['enabled' => true, 'level' => 'dev', 'branch' => '  ', '_csrf_token' => $token]);

        $response = $this->controller->saveAutoUpdatePreferences($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
    }

    // --- reconciliation of the pending weekly-slot install on save ---

    /**
     * @return array{0: int, 1: int} [historyId, actionId]
     */
    private function seedPendingScheduledInstall(string $versionTo): array
    {
        $historyId = $this->updateHistoryRepository->create('0.0.0', $versionTo, false, null);
        $actionId = $this->schedulerRepository->create(
            'core',
            'install_update',
            '2099-01-04 03:00:00',
            json_encode(['history_id' => $historyId, 'download_url' => 'https://example.test/artifact.zip', 'source_type' => 'release']),
            'scheduled_install'
        );

        return [$historyId, $actionId];
    }

    public function testSaveAutoUpdatePreferencesSwitchingToDevCancelsThePendingScheduledInstall(): void
    {
        [$historyId, $actionId] = $this->seedPendingScheduledInstall('2.4.2');

        $request = $this->jsonRequest(['enabled' => true, 'level' => 'dev', 'branch' => 'main', '_csrf_token' => $this->csrfToken()]);
        $response = $this->controller->saveAutoUpdatePreferences($request, []);

        $this->assertTrue(json_decode($response->getBody(), true)['success']);
        $this->assertSame('canceled', $this->schedulerRepository->findById($actionId)['status']);
        $this->assertSame('failed', $this->updateHistoryRepository->findById($historyId)->status);
    }

    public function testSaveAutoUpdatePreferencesDisablingAutoUpdatesCancelsThePendingScheduledInstall(): void
    {
        [$historyId, $actionId] = $this->seedPendingScheduledInstall('2.4.2');

        $request = $this->jsonRequest(['enabled' => false, 'level' => 'major', 'day' => 'monday', 'time' => '03:00', '_csrf_token' => $this->csrfToken()]);
        $response = $this->controller->saveAutoUpdatePreferences($request, []);

        $this->assertTrue(json_decode($response->getBody(), true)['success']);
        $this->assertSame('canceled', $this->schedulerRepository->findById($actionId)['status']);
        $this->assertSame('failed', $this->updateHistoryRepository->findById($historyId)->status);
    }

    public function testSaveAutoUpdatePreferencesMovesThePendingScheduledInstallToTheNewSlot(): void
    {
        // No VERSION file at dirname(storagePath) → installed is 0.0.0, so
        // 0.0.0 → 2.4.2 is a major bump: allowed at level 'major'.
        [$historyId, $actionId] = $this->seedPendingScheduledInstall('2.4.2');

        $request = $this->jsonRequest(['enabled' => true, 'level' => 'major', 'day' => 'friday', 'time' => '22:30', '_csrf_token' => $this->csrfToken()]);
        $response = $this->controller->saveAutoUpdatePreferences($request, []);

        $this->assertTrue(json_decode($response->getBody(), true)['success']);
        $this->assertSame('canceled', $this->schedulerRepository->findById($actionId)['status']);
        // The target release is still wanted — its history row stays pending.
        $this->assertSame('pending', $this->updateHistoryRepository->findById($historyId)->status);

        $moved = $this->schedulerRepository->findByModuleAndKey('core', 'install_update', 'scheduled_install');
        $this->assertNotNull($moved);
        $payload = json_decode((string) $moved['payload'], true);
        $this->assertSame($historyId, $payload['history_id']);
        $this->assertSame('https://example.test/artifact.zip', $payload['download_url']);

        // run_at is stored in the server's timezone; the configured slot is
        // Brussels wall-clock time — convert back to check day + time.
        $runAtLocal = (new \DateTimeImmutable((string) $moved['run_at']))
            ->setTimezone(new \DateTimeZone('Europe/Brussels'));
        $this->assertSame('5', $runAtLocal->format('N'), 'must land on a Friday (Brussels)');
        $this->assertSame('22:30', $runAtLocal->format('H:i'));
    }

    public function testSaveAutoUpdatePreferencesCancelsAPendingInstallNoLongerAllowedByTheNarrowedLevel(): void
    {
        // 0.0.0 → 0.1.0 is a minor bump — no longer allowed once the admin
        // narrows the level to 'patch'.
        [$historyId, $actionId] = $this->seedPendingScheduledInstall('0.1.0');

        $request = $this->jsonRequest(['enabled' => true, 'level' => 'patch', 'day' => 'monday', 'time' => '03:00', '_csrf_token' => $this->csrfToken()]);
        $response = $this->controller->saveAutoUpdatePreferences($request, []);

        $this->assertTrue(json_decode($response->getBody(), true)['success']);
        $this->assertSame('canceled', $this->schedulerRepository->findById($actionId)['status']);
        $this->assertSame('failed', $this->updateHistoryRepository->findById($historyId)->status);
    }

    // --- "Vérifier maintenant" (POST /config/maintenance/update/check-now) ---

    public function testCheckForUpdatesNowValidatesCsrf(): void
    {
        $response = $this->controller->checkForUpdatesNow($this->jsonRequest(['_csrf_token' => 'bad']), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertSame(400, $response->getStatusCode());
    }

    public function testCheckForUpdatesNowReturnsUpdateAvailableForANewRelease(): void
    {
        $this->fakeReleaseClient->release = new ReleaseInfo('v99.0.0', 'Notes', 'https://github.com/x/y/releases/tag/v99.0.0', 'https://example.test/artifact.zip');
        $token = $this->csrfToken();

        $response = $this->controller->checkForUpdatesNow($this->jsonRequest(['_csrf_token' => $token]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertSame('release', $decoded['channel']);
        $this->assertTrue($decoded['update_available']);
        $this->assertSame('99.0.0', $decoded['version']);

        $this->settingService->clearCache();
        $this->assertSame('99.0.0', $this->settingService->get('update_latest_version'));
    }

    public function testCheckForUpdatesNowReturnsNoUpdateWhenNoReleaseIsPublished(): void
    {
        $token = $this->csrfToken();

        $response = $this->controller->checkForUpdatesNow($this->jsonRequest(['_csrf_token' => $token]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertFalse($decoded['update_available']);
    }

    /**
     * "Vérifier maintenant" always checks the currently configured channel
     * (this test's setUp defaults auto_update_level to 'patch', a stable
     * channel), so a leftover installed dev build must not mask a
     * genuinely newer release — the admin's configured level is stable,
     * not dev, so the check must report the release as available.
     */
    public function testCheckForUpdatesNowReportsAReleaseAsAvailableOverAnInstalledDevBuildWhenChannelIsStable(): void
    {
        $versionFile = sys_get_temp_dir() . '/VERSION';
        $original = is_file($versionFile) ? file_get_contents($versionFile) : null;
        file_put_contents($versionFile, "dev-a1b2c3d\n");
        $this->fakeReleaseClient->release = new ReleaseInfo('v1.0.22', 'Notes', 'https://github.com/x/y/releases/tag/v1.0.22', 'https://example.test/artifact.zip');
        $token = $this->csrfToken();

        try {
            $response = $this->controller->checkForUpdatesNow($this->jsonRequest(['_csrf_token' => $token]), []);

            $decoded = json_decode($response->getBody(), true);
            $this->assertTrue($decoded['success']);
            $this->assertSame('release', $decoded['channel']);
            $this->assertTrue($decoded['update_available']);
        } finally {
            if ($original !== null) {
                file_put_contents($versionFile, $original);
            } else {
                @unlink($versionFile);
            }
        }
    }

    /**
     * "Vérifier maintenant" proposes what can be installed right now, not
     * what the weekly automatic slot would have picked: a minor release is
     * offered even though auto_update_level is 'patch' (setUp's default),
     * which would have blocked the *unattended* install of that same
     * release.
     */
    public function testCheckForUpdatesNowProposesTheLatestReleaseWhateverTheConfiguredLevel(): void
    {
        $versionFile = sys_get_temp_dir() . '/VERSION';
        $original = is_file($versionFile) ? file_get_contents($versionFile) : null;
        file_put_contents($versionFile, "1.0.36\n");
        $this->fakeReleaseClient->releases = [
            new ReleaseInfo('v1.0.37', 'Patch', 'https://github.test/r/1.0.37', 'https://github.test/1.0.37.zip'),
            new ReleaseInfo('v1.2.0', 'Minor', 'https://github.test/r/1.2.0', 'https://github.test/1.2.0.zip'),
        ];
        $token = $this->csrfToken();

        try {
            $response = $this->controller->checkForUpdatesNow($this->jsonRequest(['_csrf_token' => $token]), []);

            $decoded = json_decode($response->getBody(), true);
            $this->assertTrue($decoded['success']);
            $this->assertTrue($decoded['update_available']);
            $this->assertSame('1.2.0', $decoded['version']);
            $this->assertSame('1.2.0', $decoded['latest_version']);

            // The install endpoint re-validates from this cache, so it has
            // to name the very release the dialog just offered.
            $this->settingService->clearCache();
            $this->assertSame('1.2.0', $this->settingService->get('update_latest_version'));
            $this->assertSame('https://github.test/1.2.0.zip', $this->settingService->get('update_download_url'));
        } finally {
            if ($original !== null) {
                file_put_contents($versionFile, $original);
            } else {
                @unlink($versionFile);
            }
        }
    }

    /**
     * Two majors published since the installed version: the first of them
     * is proposed, not the newest release, so each major's migrations run
     * on their own (Maintenance\UpdateTargetSelector).
     */
    public function testCheckForUpdatesNowProposesTheNextMajorReleaseRatherThanTheLatest(): void
    {
        $versionFile = sys_get_temp_dir() . '/VERSION';
        $original = is_file($versionFile) ? file_get_contents($versionFile) : null;
        file_put_contents($versionFile, "1.4.2\n");
        $this->settingService->set('auto_update_level', 'major');
        $this->settingService->clearCache();
        $this->fakeReleaseClient->releases = [
            new ReleaseInfo('v1.5.0', 'Minor', 'https://github.test/r/1.5.0', 'https://github.test/1.5.0.zip'),
            new ReleaseInfo('v3.0.0', 'Major 3', 'https://github.test/r/3.0.0', 'https://github.test/3.0.0.zip'),
            new ReleaseInfo('v2.0.0', 'Major 2', 'https://github.test/r/2.0.0', 'https://github.test/2.0.0.zip'),
        ];
        $token = $this->csrfToken();

        try {
            $response = $this->controller->checkForUpdatesNow($this->jsonRequest(['_csrf_token' => $token]), []);

            $decoded = json_decode($response->getBody(), true);
            $this->assertTrue($decoded['success']);
            $this->assertTrue($decoded['update_available']);
            $this->assertSame('2.0.0', $decoded['version']);
            // The page tells the admin this is a step, not the last word.
            $this->assertSame('3.0.0', $decoded['latest_version']);

            $this->settingService->clearCache();
            $this->assertSame('2.0.0', $this->settingService->get('update_latest_version'));
            $this->assertSame('https://github.test/2.0.0.zip', $this->settingService->get('update_download_url'));
        } finally {
            if ($original !== null) {
                file_put_contents($versionFile, $original);
            } else {
                @unlink($versionFile);
            }
        }
    }

    /**
     * Development mode is the other exception: the branch's latest commit
     * is proposed whatever it is, and the release listing is never
     * consulted at all.
     */
    public function testCheckForUpdatesNowIgnoresReleasesEntirelyInDevelopmentMode(): void
    {
        $this->settingService->set('auto_update_level', 'dev');
        $this->settingService->clearCache();
        $this->fakeReleaseClient->releases = [
            new ReleaseInfo('v99.0.0', 'Notes', 'https://github.test/r/99.0.0', 'https://github.test/99.0.0.zip'),
        ];
        $this->fakeReleaseClient->commit = new CommitInfo('a1b2c3d4e5f6', 'Fix something', 'https://github.com/x/y/commit/a1b2c3d4e5f6');
        $token = $this->csrfToken();

        $response = $this->controller->checkForUpdatesNow($this->jsonRequest(['_csrf_token' => $token]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertSame('dev', $decoded['channel']);
        $this->assertSame('dev-a1b2c3d', $decoded['version']);
    }

    public function testCheckForUpdatesNowChecksTheConfiguredBranchWhenDevLevelSelected(): void
    {
        $this->settingService->set('auto_update_level', 'dev');
        $this->settingService->set('dev_update_branch', 'develop');
        $this->settingService->clearCache();
        $this->fakeReleaseClient->commit = new CommitInfo('a1b2c3d4e5f6', 'Fix something', 'https://github.com/x/y/commit/a1b2c3d4e5f6');
        $token = $this->csrfToken();

        $response = $this->controller->checkForUpdatesNow($this->jsonRequest(['_csrf_token' => $token]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertSame('dev', $decoded['channel']);
        $this->assertTrue($decoded['update_available']);
        $this->assertSame('dev-a1b2c3d', $decoded['version']);
    }

    public function testCheckForUpdatesNowReturns404WhenTheConfiguredBranchDoesNotExist(): void
    {
        $this->settingService->set('auto_update_level', 'dev');
        $this->settingService->clearCache();
        $token = $this->csrfToken();

        $response = $this->controller->checkForUpdatesNow($this->jsonRequest(['_csrf_token' => $token]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testInstallUpdateSchedulesTheDevArtifactWhenDevLevelSelected(): void
    {
        $this->settingService->set('auto_update_level', 'dev');
        $this->settingService->set('dev_update_branch', 'develop');
        $this->settingService->clearCache();
        $this->fakeReleaseClient->commit = new CommitInfo('a1b2c3d4e5f6', 'Fix something', 'https://github.com/x/y/commit/a1b2c3d4e5f6');
        $token = $this->csrfToken();

        $response = $this->controller->installUpdate($this->jsonRequest(['_csrf_token' => $token]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);

        $history = $this->updateHistoryRepository->findById($decoded['history_id']);
        $this->assertNotNull($history);
        $this->assertSame('dev-a1b2c3d', $history->versionTo);

        $scheduled = $this->schedulerRepository->findByModuleAndTaskKey('core', 'install_update');
        $this->assertCount(1, $scheduled);
        $payload = json_decode((string) $scheduled[0]['payload'], true);

        // The manual dev install and the push webhook must point at the
        // SAME CI-built artifact — never the git zipball, which carries no
        // vendor/ and was the silent-dependency-drift channel.
        $this->assertSame('release', $payload['source_type']);
        $this->assertSame(
            'https://github.com/owner/repo/releases/download/dev-latest/scoutmagic-dev-a1b2c3d.zip',
            $payload['download_url']
        );

        // A just-pushed commit may not have its artifact yet: the handler
        // waits against this deadline instead of failing on the first 404.
        $this->assertArrayHasKey('wait_for_artifact_until', $payload);
        $this->assertGreaterThan(time(), (int) $payload['wait_for_artifact_until']);
    }

    public function testAFailedLockCompareNeverBlocksAManualDevInstall(): void
    {
        // The artifact carries vendor/ itself, so the flag is informational
        // — a GitHub API hiccup must degrade to "non", not to a refused or
        // alarming install.
        $this->settingService->set('auto_update_level', 'dev');
        $this->settingService->clearCache();
        $this->fakeReleaseClient->commit = new CommitInfo('a1b2c3d4e5f6', 'Fix something', 'https://github.com/x/y/commit/a1b2c3d4e5f6');
        $this->fakeReleaseClient->lockCheckThrows = true;
        $token = $this->csrfToken();

        $response = $this->controller->installUpdate($this->jsonRequest(['_csrf_token' => $token]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertFalse($this->updateHistoryRepository->findById($decoded['history_id'])->dependenciesChanged);
    }

    public function testAChangedLockIsRecordedOnAManualDevInstall(): void
    {
        $this->settingService->set('auto_update_level', 'dev');
        $this->settingService->clearCache();
        $this->fakeReleaseClient->commit = new CommitInfo('a1b2c3d4e5f6', 'Fix something', 'https://github.com/x/y/commit/a1b2c3d4e5f6');
        $this->fakeReleaseClient->lockChanged = true;
        $token = $this->csrfToken();

        $response = $this->controller->installUpdate($this->jsonRequest(['_csrf_token' => $token]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertTrue($this->updateHistoryRepository->findById($decoded['history_id'])->dependenciesChanged);
    }

    // --- update history: five rows, then the rest on demand ---

    private function seedUpdateHistory(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->updateHistoryRepository->markCompleted(
                $this->updateHistoryRepository->create('1.0.' . $i, '1.0.' . ($i + 1), false, null)
            );
        }
    }

    public function testTheUpdateHistoryShowsFiveRowsAndHidesTheRestBehindAButton(): void
    {
        // The page exists to install an update; twenty rows of history
        // pushed the install button below the fold.
        $this->seedUpdateHistory(8);

        $body = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), [])->getBody();

        // The extra rows ARE rendered — the controller already fetched
        // them, so opening the list must not cost a round trip — but they
        // sit in a collapsed tbody.
        $this->assertStringContainsString('id="update-history-more"', $body);
        $this->assertStringContainsString('class="collapse"', $body);
        // One label in the markup, the other in a data attribute for
        // collapse-label.js: a stale stylesheet must never be able to
        // render both at once (that is exactly what production showed).
        $this->assertStringContainsString('Afficher les 3 précédentes', $body);
        $this->assertStringContainsString('data-collapse-label-expanded="Afficher moins"', $body);
        $this->assertSame(0, substr_count($body, '>Afficher moins<'), 'the expanded label lives in the attribute, never as a second visible span');
        // Nine: the eight entries plus the table's own header row.
        $this->assertSame(9, substr_count($body, '<tr>'), 'every entry is rendered, five of them visible');
    }

    public function testAShortUpdateHistoryGrowsNoButtonAtAll(): void
    {
        $this->seedUpdateHistory(4);

        $body = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), [])->getBody();

        $this->assertStringNotContainsString('id="update-history-more"', $body);
        $this->assertStringNotContainsString('précédentes', $body);
    }

    public function testReleaseNotesAreClampedWithATogglePreparedButHidden(): void
    {
        // The button ships hidden: notes-clamp.js reveals it only for a
        // block that really overflows, so a two-line note never grows a
        // button that would reveal nothing.
        $this->settingService->set('update_latest_version', '99.0.0');
        $this->settingService->set('update_download_url', 'https://github.com/owner/repo/releases/download/v99.0.0/release.zip');
        $this->settingService->set('update_release_notes', str_repeat("Une ligne de notes.\n\n", 40));
        $this->settingService->clearCache();

        $body = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('data-notes-clamp="update-release-notes-toggle"', $body);
        $this->assertStringContainsString('Voir la description complète', $body);
        $this->assertStringContainsString('notes-clamp.js', $body);
    }

    public function testIndexShowsTheWebhookWarningWhenDevLevelEnabledButWebhookNotConfigured(): void
    {
        $this->settingService->set('auto_update_enabled', '1');
        $this->settingService->set('auto_update_level', 'dev');
        $this->settingService->clearCache();

        $response = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), []);

        $this->assertStringContainsString('webhook GitHub n\'est pas configuré', $response->getBody());
    }

    public function testIndexDoesNotShowTheWebhookWarningWhenAutoUpdateDisabled(): void
    {
        $response = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), []);

        $this->assertStringNotContainsString('webhook GitHub n\'est pas configuré', $response->getBody());
    }

    public function testIndexDoesNotShowTheWebhookWarningForTheStableChannelSinceItHasItsOwnDailyCheck(): void
    {
        // The webhook is entirely irrelevant to the stable channel now
        // (patch/minor/major) — Task\CheckStableUpdateHandler polls daily
        // instead — so the warning only makes sense when 'dev' is selected.
        $this->settingService->set('auto_update_enabled', '1');
        $this->settingService->set('auto_update_level', 'minor');
        $this->settingService->clearCache();

        $response = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), []);

        $this->assertStringNotContainsString('webhook GitHub n\'est pas configuré', $response->getBody());
    }

    public function testIndexNeverRendersTheWebhookSecretItself(): void
    {
        $this->controller->generateWebhookSecret($this->jsonRequest(['_csrf_token' => $this->csrfToken()]), []);
        $secrets = $this->secretManager->readSecrets();

        $response = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), []);

        $this->assertStringNotContainsString($secrets['github_webhook_secret'], $response->getBody());
    }

    public function testResetStatusReturns404ForUnknownId(): void
    {
        $response = $this->controller->resetStatus(new Request('GET', '/api/maintenance/reset-status/999', [], [], [], []), ['id' => '999']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testResetStatusReturnsCurrentState(): void
    {
        $actionId = $this->schedulerRepository->create('core', 'reset_settings', date('Y-m-d H:i:s'), null, null, null);

        $response = $this->controller->resetStatus(new Request('GET', '/api/maintenance/reset-status/' . $actionId, [], [], [], []), ['id' => (string) $actionId]);

        $decoded = json_decode($response->getBody(), true);
        $this->assertSame('pending', $decoded['status']);
    }

    /**
     * RBAC boundary: role_min admin — chief denied, admin allowed.
     */
    private function buildFrontController(): FrontController
    {
        $router = new Router();
        $router->addRoute('GET', '/config/maintenance', MaintenanceController::class, 'index', 'admin');

        $configFile = sys_get_temp_dir() . '/test_maintenance_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $config = new AppConfig($configFile);

        $fc = new FrontController($router, $this->twig, $config);
        $fc->registerController(MaintenanceController::class, $this->controller);

        return $fc;
    }

    public function testChiefIsDenied(): void
    {
        AuthSession::login(1, 'chief@test.be', 'chief');

        $response = $this->buildFrontController()->handle(new Request('GET', '/config/maintenance', [], [], [], []));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAdminIsAllowed(): void
    {
        $response = $this->buildFrontController()->handle(new Request('GET', '/config/maintenance', [], [], [], []));

        $this->assertSame(200, $response->getStatusCode());
    }

    private function ageCompletedAt(int $id, int $daysAgo): void
    {
        $stmt = $this->pdo->prepare('UPDATE update_history SET completed_at = ? WHERE id = ?');
        $stmt->execute([(new \DateTimeImmutable("-{$daysAgo} days"))->format('Y-m-d H:i:s'), $id]);
    }

}
