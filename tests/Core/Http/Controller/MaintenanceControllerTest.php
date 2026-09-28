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
use Tests\RequestWithInput;
use Core\Http\Router;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Maintenance\BackupRepository;
use Core\Maintenance\BackupService;
use Core\Maintenance\CommitInfo;
use Core\Maintenance\GitHubReleaseClientInterface;
use Core\Maintenance\Health\HostHealth;
use Core\Maintenance\Remote\RemoteBackupDestination;
use Core\Maintenance\ReleaseInfo;
use Core\Maintenance\UpdateHistoryRepository;
use Core\Module\ModuleManager;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\AuthSession;
use Core\Security\Role;
use Core\Security\EncryptionService;
use Core\Security\SecretManager;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\TestTwig;
use Twig\Environment;

if (!defined('AUTHZ_SUPPORT_TEST')) {
    define('AUTHZ_SUPPORT_TEST', true);
}
require_once dirname(__DIR__, 4) . '/scripts/authz-support.php';

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
    private Connection $connection;
    /** @var callable(BackupService, ?HostHealth=): MaintenanceController */
    private $rebuildController;

    private BackupService $backupService;

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
        // Nested rather than directly under the system temp directory:
        // `DiskBudget` charges a declared quota for `storage/`'s whole
        // parent tree, so a flat temp directory would drag in everything
        // else on the machine.
        $storagePath = sys_get_temp_dir() . '/maintenance_controller_test_' . uniqid() . '/storage';
        mkdir($storagePath, 0755, true);
        $this->storagePath = $storagePath;
        $backupService = new BackupService($connection, $storagePath, dirname($storagePath));

        $this->secretManager = new SecretManager($storagePath . '/keys/master.key', $storagePath . '/config/secrets.enc');
        $this->secretManager->generateMasterKey();
        $this->secretManager->writeSecrets([]);

        $moduleManager = $this->createStub(ModuleManager::class);
        $moduleManager->method('getEnabledModuleIds')->willReturn([]);

        // Built through the real factory, not a bare Environment: this page
        // uses filters that only Core\View\TwigFactory registers (|markdown,
        // for the release notes). A hand-rolled environment silently lacks
        // them, so every test here passed while the live page died with
        // Twig\Error\SyntaxError: Unknown "markdown" filter — a compile-time
        // error no unit test could see. Tests\TestTwig is that factory, in
        // debug mode, so the compiled-template cache stays off as well.
        $this->twig = TestTwig::create();
        $this->twig->addGlobal('site_name', 'Test');
        $this->twig->addGlobal('is_authenticated', true);
        $this->twig->addGlobal('current_user_role', 'admin');
        $this->twig->addGlobal('config_mode', false);
        $this->twig->addGlobal('cookie_consent_given', true);
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('csp_nonce', 'test-nonce');

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

        // Captured so a test can rebuild the controller around a DIFFERENT
        // BackupService — the disk-budget refusal below needs one whose
        // quota is already full, and everything else about the page must
        // stay identical for that test to mean anything.
        $this->rebuildController = function (
            BackupService $service,
            ?HostHealth $hostHealth = null,
            ?RemoteBackupDestination $remoteDestination = null
        ) use (
            $fileRepository,
            $schedulerService,
            $moduleManager,
            $encryption,
            $journalService,
            $storagePath
        ): MaintenanceController {
            return new MaintenanceController(
                $this->twig, $service, $this->backupRepository, $fileRepository, $this->updateHistoryRepository,
                $schedulerService, $encryption, $journalService, $this->settingService, $storagePath,
                $this->secretManager, $this->fakeReleaseClient,
                null,
                // The health block's crontab line is spelled from the public
                // directory, the one anchor valid in both hosting layouts.
                dirname($storagePath) . '/public',
                remoteBackupDestination: $remoteDestination,
                hostHealth: $hostHealth
            );
        };

        $this->connection = $connection;
        $this->backupService = $backupService;
        $this->controller = ($this->rebuildController)($backupService);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'admin@test.be', 'admin');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    /** An off-site destination over this test's database and settings. */
    private function remoteDestination(): RemoteBackupDestination
    {
        RemoteBackupDestination::register($this->settingService);
        $locations = new \Core\Storage\Location\StorageLocationRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );

        return new RemoteBackupDestination(
            $this->settingService,
            $locations,
            new \Core\Storage\Location\Backend\StorageBackendFactory($locations, sys_get_temp_dir())
        );
    }

    /** A Google Drive folder declared as a storage location, by id. */
    private function declareRemoteLocation(): int
    {
        return (new \Core\Storage\Location\StorageLocationRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        ))->create(
            \Core\Storage\Location\StorageLocationType::GoogleDrive,
            'Drive de l\'unité',
            new \Core\Storage\Location\Config\GoogleDriveLocationConfig('client-1', 'dossier-1', ''),
            (string) json_encode(['client_secret' => 's', 'refresh_token' => 'r', 'account' => 'unite@example.org'])
        );
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
        $request = new RequestWithInput('POST', '/config/maintenance/backup/full', [], [], [], [], json_encode($data));
        return $request;
    }

    /**
     * The body of one or several Maintenance sub-pages, by controller
     * action — the blocks a test reads moved to their own page with issue
     * #619, and a test reading two of them reads both pages.
     */
    private function page(string ...$actions): string
    {
        $body = '';
        foreach ($actions as $action) {
            $body .= $this->pageResponse($action)->getBody();
        }

        return $body;
    }

    private function pageResponse(string $action, string $path = '/config/maintenance'): \Core\Http\Response
    {
        $response = $this->controller->{$action}(new Request('GET', $path, [], [], [], []), []);
        $this->assertInstanceOf(\Core\Http\Response::class, $response);

        return $response;
    }

    public function testIndexRendersEmptyState(): void
    {
        $response = $this->pageResponse('recentBackupsPage');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Maintenance', $response->getBody());
        $this->assertStringContainsString('Aucune sauvegarde', $response->getBody());
    }

    /**
     * Every box still folds, and on its own sub-page every box arrives
     * open (issue #619).
     *
     * The page used to carry eight boxes in one screen, so six arrived
     * folded. Cut in six, a box is the reason the visitor opened its page:
     * one that arrived folded would be a page showing nothing but a title.
     * The folding header itself is unchanged — the heading wraps the
     * button, so each box keeps its <h3> sections under a parent in the
     * document outline, and the h5 sizing sits on the span because `.btn`
     * fixes its own font-size.
     */
    public function testEveryBoxArrivesOpenOnItsOwnSubPage(): void
    {
        $boxes = [
            'index' => ['maintenance-health'],
            'updatePage' => ['maintenance-update', 'maintenance-auto-update'],
            'manualBackupPage' => ['maintenance-backups'],
            // One box since IT-05: « Hors site » is its second half, no
            // longer a box of its own (see the test just below).
            'automaticBackupPage' => ['maintenance-backups-automatic'],
            'recentBackupsPage' => ['maintenance-backups-list', 'maintenance-restore'],
            'resetPage' => ['maintenance-reset'],
        ];

        foreach ($boxes as $action => $ids) {
            $body = $this->page($action);
            foreach ($ids as $id) {
                $this->assertStringContainsString(
                    '<div class="collapse show mt-3" id="' . $id . '-body">',
                    $body,
                    "« {$id} » must arrive open on its sub-page."
                );
                $this->assertStringContainsString('aria-controls="' . $id . '-body">', $body);
            }
            $this->assertMatchesRegularExpression('~<h2 class="mb-0">\s*<button type="button"~', $body);
            // Each sub-page carries its own boxes and no other.
            $this->assertSame(count($ids), substr_count($body, '<h2 class="mb-0">'), "{$action} carries a foreign box.");
        }
        $this->assertStringContainsString('<span class="h5 mb-0 flex-grow-1">Ce dont le site dépend</span>', $this->page('index'));
    }

    /**
     * The six sub-pages share one rail, each selecting itself, and each
     * has its own title (issue #619).
     */
    public function testEverySubPageCarriesTheSharedRailWithItselfSelected(): void
    {
        $pages = [
            'index' => ['/config/maintenance', "Santé de l'hébergement"],
            'updatePage' => ['/config/maintenance/mise-a-jour', 'Mise à jour'],
            'manualBackupPage' => ['/config/maintenance/sauvegarde-manuelle', 'Sauvegarde manuelle'],
            'automaticBackupPage' => ['/config/maintenance/sauvegarde-automatique', 'Sauvegarde automatique'],
            'recentBackupsPage' => ['/config/maintenance/sauvegardes-recentes', 'Sauvegardes récentes'],
            'resetPage' => ['/config/maintenance/reinitialisation', 'Réinitialisation'],
        ];

        foreach ($pages as $action => [$path, $title]) {
            $this->twig->addGlobal('current_path', $path);
            $body = $this->pageResponse($action, $path)->getBody();
            $this->assertStringContainsString('id="maintenance-page-picker"', $body);
            $this->assertSame(6, preg_match_all('~<a href="/config/maintenance[a-z/-]*"[^>]*class="nav-link~', $body), $action);
            $this->assertMatchesRegularExpression(
                '~<a href="' . preg_quote($path, '~') . '"[^>]*aria-current="page"~',
                $body,
                "{$action} does not select itself in the rail."
            );
            $this->assertStringContainsString(htmlspecialchars($title, ENT_QUOTES), $body);
        }
    }

    /**
     * « Sauvegardes » names the manual triggers only, and the automatic
     * box no longer claims to configure anything distant.
     *
     * The automatic box was called « Sauvegardes automatiques et
     * distantes » in anticipation of a remote destination that ended up
     * in a box of its own. A unit read its weekly frequency as the
     * cadence of the sends to Google, which it has never been — the two
     * mechanisms share no setting at all.
     */
    public function testTheBackupBoxesSayWhichOneTheyGovern(): void
    {
        $body = $this->page('manualBackupPage', 'automaticBackupPage');

        $this->assertStringContainsString('Sauvegarde manuelle', $body);
        $this->assertStringContainsString('Sauvegarde automatique</span>', $body);
        $this->assertStringNotContainsString('Sauvegardes automatiques et distantes', $body);

        // Both sides state the independence, because an operator reads
        // whichever box they opened.
        $this->assertStringContainsString('ne gouverne <strong>que</strong> ce qui s\'écrit sur ce serveur', $body);
        $this->assertMatchesRegularExpression(
            '~la fréquence choisie dans\s+« Sur ce serveur » n\'a aucun effet dessus~',
            $body
        );
        $this->assertStringContainsString(
            'fixée à ' . \Core\Maintenance\Task\SendRemoteBackupHandler::INTERVAL_HOURS . ' heures',
            $body
        );
    }

    /**
     * **One box, two halves named by the accident they protect from**
     * (issue #619, IT-05) — and the off-site half keeps the anchor every
     * round trip through Google and every alert lands on.
     */
    public function testTheAutomaticBackupIsOneBoxWithTwoHalvesNamedByTheirAccident(): void
    {
        $body = $this->page('automaticBackupPage');

        $this->assertSame(1, substr_count($body, '<div class="card mb-4"'), 'the two boxes were not merged');
        $this->assertStringNotContainsString('Sauvegarde hors site', $body);

        $local = strpos($body, '<h3 class="h5" id="auto-backup-local-title">Sur ce serveur</h3>');
        $remote = strpos($body, '<section id="remote-backup" aria-labelledby="remote-backup-title">');
        $this->assertIsInt($local);
        $this->assertIsInt($remote);
        $this->assertLessThan($remote, $local);
        $this->assertStringContainsString('<div id="remote-backup-body">', $body);

        // The asymmetry is said, not smoothed over.
        $this->assertStringContainsString('pour revenir en arrière après une fausse', $body);
        $this->assertStringContainsString('pour le jour où le serveur n\'existe plus', $body);

        // Nothing moved out on the way: the frequency, the destination,
        // the cadence and the passphrase are all still here.
        $this->assertStringContainsString('id="auto-backup-frequency"', $body);
        $this->assertStringContainsString('action="/config/maintenance/remote/destination"', $body);
        $this->assertStringContainsString(
            'fixée à ' . \Core\Maintenance\Task\SendRemoteBackupHandler::INTERVAL_HOURS . ' heures',
            $body
        );
        $this->assertStringContainsString('Phrase de passe des sauvegardes distantes', $body);
    }

    /**
     * **The retention says its policy, then shows the real state** — a
     * policy nobody can check at a glance reassures nobody. Before the
     * first send there is no reading, and the page says so rather than
     * showing a zero it never measured.
     */
    public function testTheOffsiteRetentionShowsItsPolicyAndTheRealState(): void
    {
        $body = $this->page('automaticBackupPage');

        $this->assertStringContainsString('la dernière' . "\n", $body);
        $this->assertMatchesRegularExpression(
            '~une par semaine sur le mois écoulé, puis une par mois au-delà~',
            $body
        );
        $this->assertStringContainsString('Pas encore de relevé', $body);

        // A destination, chosen before the reading is recorded: choosing
        // one forgets whatever the previous destination held.
        $destination = $this->remoteDestination();
        $locationId = $this->declareRemoteLocation();
        $destination->choose($locationId);
        \Core\Maintenance\Remote\RemoteRetention::register($this->settingService);
        (new \Core\Maintenance\Remote\RemoteRetention($this->settingService))->recordState(
            ['count' => 14, 'bytes' => 3 * 1024 * 1024 * 1024, 'oldest' => '2025-10-31 03:00:00'],
            new \DateTimeImmutable('2026-09-28 04:12:00'),
            $locationId
        );
        $this->controller = ($this->rebuildController)($this->backupService, null, $destination);

        $body = $this->page('automaticBackupPage');
        $this->assertStringNotContainsString('Pas encore de relevé', $body);
        $this->assertMatchesRegularExpression('~<strong>14\s+archives</strong>~', $body);
        $this->assertStringContainsString('3,0 Go occupés', $body);
        $this->assertStringContainsString('la plus ancienne du 31/10/2025', $body);
        $this->assertMatchesRegularExpression('~Relevé à la fin de l\'envoi du\s+28/09/2026 à 04:12~', $body);
    }

    /**
     * **A reading is never shown without a destination** (IT-05 review):
     * the figures describe a folder, and with no destination chosen there
     * is none they could describe — the page says nothing leaves the
     * server, and must not beside it show fourteen archives there.
     */
    public function testNoReadingIsShownWhileNoDestinationIsChosen(): void
    {
        \Core\Maintenance\Remote\RemoteRetention::register($this->settingService);
        (new \Core\Maintenance\Remote\RemoteRetention($this->settingService))->recordState(
            ['count' => 14, 'bytes' => 1024, 'oldest' => '2025-10-31 03:00:00'],
            new \DateTimeImmutable('2026-09-28 04:12:00'),
            1
        );

        $body = $this->page('automaticBackupPage');

        $this->assertStringNotContainsString('<strong>14', $body);
        $this->assertStringContainsString('Pas encore de relevé', $body);
    }

    /**
     * The two actions on a backup row are icons, and each keeps a name
     * that says WHICH backup it acts on.
     *
     * The row already carries up to three badges, a date and a size;
     * « Télécharger » and « Supprimer » spelled out wrapped it onto a
     * second line as soon as an integrity badge appeared. Fifteen
     * identical « Supprimer » would be no better to a screen reader than
     * to an eye, hence the backup's own words in the accessible name —
     * the same words the confirmation uses.
     */
    public function testABackupRowOffersIconsThatStillNameTheirBackup(): void
    {
        $backups = new \Core\Maintenance\BackupRepository($this->pdo);
        $files = new \Core\File\FileRepository($this->pdo);
        $backupId = $backups->create('full_no_gallery', null);
        $fileId = $files->create('backups/sauvegarde.zip', 'sauvegarde.zip', 'application/zip', 1024, 'admin', null, null);
        $backups->markCompleted($backupId, $fileId, null);

        $body = $this->page('recentBackupsPage');

        $this->assertStringContainsString('aria-label="Télécharger la sauvegarde « Complète (sans galerie) »', $body);
        $this->assertStringContainsString('aria-label="Supprimer la sauvegarde « Complète (sans galerie) »', $body);
        $this->assertStringContainsString('<i class="bi bi-download" aria-hidden="true"></i>', $body);
        // No visible text left beside either icon.
        $this->assertStringNotContainsString('</i> Télécharger', $body);
        $this->assertStringNotContainsString('</i> Supprimer', $body);
    }

    /**
     * #245. Core\Maintenance\BackupService excludes storage/keys and
     * storage/config from the archive — rightly, "secrets never leave the
     * server in a backup" — and the screen said so nowhere. An
     * administrator who restored a full backup on another host got an
     * undecipherable database and no explanation anywhere on the page
     * that produced the archive.
     */
    public function testThePageSaysTheArchiveCarriesNoEncryptionKey(): void
    {
        $body = $this->page('manualBackupPage', 'recentBackupsPage');

        // One sentence per scope since IT-04 of issue #619: the complete
        // site says it leaves the keys behind, and what that costs.
        $this->assertStringContainsString('sans les clés du site', $body);
        $this->assertStringContainsString('reste illisible ailleurs', $body);
        // And on the restore side, where the consequence is met — beside
        // the list since IT-06.
        $this->assertStringContainsString('pas les clés de chiffrement', $body);
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

        $this->assertMatchesRegularExpression('~id="host-check-cron" data-state="missing"~', $body);
        $this->assertStringContainsString('Jamais détectée', $body);
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

        $this->assertMatchesRegularExpression('~id="host-check-cron" data-state="ok"~', $body);
        $this->assertStringContainsString('Active — dernier passage il y a 40 s, cadence ~1 min', $body);
        // Nothing to fix, so nothing is shown to fix it with.
        $this->assertStringNotContainsString('maintenance-cron-warning', $body);
    }

    /**
     * The portable block is on screen, warning first.
     *
     * The mockup is authoritative for this wording, and the wording is the
     * feature: an operator who reads « Contient les clés de chiffrement du
     * site » before clicking understands in one sentence why this archive
     * is not like the other two, and why it has to be taken off the server.
     * A block that shipped with the button and without the warning would
     * look finished and would be the dangerous half.
     */
    public function testThePortableBlockCarriesItsWarningAndNoPasswordField(): void
    {
        $body = $this->page('manualBackupPage');

        // A scope among four since IT-04 of issue #619, and its description
        // still carries the warning.
        $this->assertStringContainsString('Sauvegarde portable', $body);
        $this->assertStringContainsString('clés comprises', $body);
        $this->assertStringContainsString('Téléchargez-la, puis supprimez-la du serveur', $body);
        $this->assertStringContainsString('Une seule est conservée', $body);

        // Nothing to type any more (issue #619, IT-03): the site generates
        // each archive's password.
        $this->assertStringNotContainsString('type="password"', $body);
        // And the two sentences the chantier requires on screen.
        $this->assertStringContainsString('Notez le mot de passe affiché au téléchargement.', $body);
        $this->assertStringContainsString('illisibles les archives déjà produites', $body);
    }

    /**
     * And it still says what the OTHER archive does not carry.
     *
     * The two blocks sit side by side, and the difference between them is
     * the whole decision an operator makes on the day of a disaster. IT-04
     * put that sentence on the encrypted block; adding a neighbour that
     * carries the keys is exactly the change that could quietly drop it.
     */
    public function testTheEncryptedBlockStillSaysItLeavesTheSecretsBehind(): void
    {
        $body = $this->page('manualBackupPage');

        $this->assertStringContainsString('sans les clés du site', $body);
        $this->assertStringContainsString('elle se restaure ici', $body);
    }

    public function testTheHealthBlockReportsAStaleCronRatherThanAMissingOne(): void
    {
        $this->writeCronHeartbeat(time() - 10800);

        $body = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('Plus détectée', $body);
        $this->assertStringNotContainsString('Jamais détectée', $body);
        $this->assertStringContainsString('maintenance-cron-warning', $body);
    }

    /**
     * A channel whose last three installs all rolled back still carries a
     * perfectly good "dernière mise à jour réussie" date. Reading only
     * that one is how six consecutive rollbacks stayed invisible — the
     * MOST RECENT attempt is the other half. It is the update's state, not
     * the host's, so it moved to Mise à jour with IT-02 (issue #619).
     */
    public function testTheUpdatePageFlagsAFailedMostRecentAttempt(): void
    {
        $succeeded = $this->updateHistoryRepository->create('1.0.0', '1.1.0', false, null);
        $this->updateHistoryRepository->markCompleted($succeeded);
        $failed = $this->updateHistoryRepository->create('1.1.0', '1.2.0', false, null);
        $this->updateHistoryRepository->markRolledBack($failed, 'migration KO');

        $body = $this->page('updatePage');

        $this->assertStringContainsString('maintenance-update-last-attempt', $body);
        $this->assertStringContainsString('restauré automatiquement', $body);
        $health = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), [])->getBody();
        $this->assertStringNotContainsString('maintenance-update-last-attempt', $health);
    }

    /**
     * An install skipped before it started is not an attempt (issue #622):
     * it neither raises the alarm on its own, nor hides the failure of the
     * attempt before it.
     */
    public function testASkippedInstallIsShownAsIgnoredAndIsNotTheLastAttempt(): void
    {
        $succeeded = $this->updateHistoryRepository->create('1.0.0', '1.1.0', false, null);
        $this->updateHistoryRepository->markCompleted($succeeded);
        $skipped = $this->updateHistoryRepository->create('1.1.0', '1.2.0', false, null);
        $this->updateHistoryRepository->markSkipped($skipped, 'Installation remplacée : un push plus récent est arrivé.');

        // Since IT-02 the history and the « last attempt » flag are both on
        // the update page; the health page shows neither.
        $body = $this->page('updatePage');
        $this->assertStringContainsString('>Ignorée</span>', $body);
        $this->assertStringNotContainsString('Échouée</span>', $body, 'a skipped install shown as failed');
        $this->assertStringNotContainsString('maintenance-update-last-attempt', $body);

        $health = $this->controller->index(new Request('GET', '/config/maintenance', [], [], [], []), [])->getBody();
        $this->assertStringNotContainsString('maintenance-update-last-attempt', $health);
    }

    public function testASkippedInstallDoesNotHideTheFailedAttemptBeforeIt(): void
    {
        $failed = $this->updateHistoryRepository->create('1.0.0', '1.1.0', false, null);
        $this->updateHistoryRepository->markRolledBack($failed, 'migration KO');
        $skipped = $this->updateHistoryRepository->create('1.1.0', '1.2.0', false, null);
        $this->updateHistoryRepository->markSkipped($skipped, 'Installation remplacée.');

        $body = $this->page('updatePage');

        $this->assertStringContainsString('maintenance-update-last-attempt', $body);
        $this->assertStringContainsString('restauré automatiquement', $body);
    }

    /** More skipped rows than the history shows still leave the failure behind them visible. */
    public function testAFailureBehindMoreSkippedInstallsThanTheTableShowsIsStillFlagged(): void
    {
        $failed = $this->updateHistoryRepository->create('1.0.0', '1.1.0', false, null);
        $this->updateHistoryRepository->markRolledBack($failed, 'migration KO');
        for ($i = 0; $i < 25; $i++) {
            $skipped = $this->updateHistoryRepository->create('1.1.0', '1.2.' . $i, false, null);
            $this->updateHistoryRepository->markSkipped($skipped, 'Installation remplacée.');
        }

        $body = $this->page('updatePage');

        $this->assertStringContainsString('maintenance-update-last-attempt', $body);
        $this->assertStringContainsString('restauré automatiquement', $body);
    }

    /**
     * **And the history the warning points at actually contains it** (issue
     * #681). The warning above is found by looking through the whole table;
     * the history is the 20 newest rows. More skipped installs than that
     * between the two, and the page said « la dernière tentative a échoué —
     * voir l'historique » while the history held nothing but « Ignorée ».
     * The test next door built exactly this case and only ever checked the
     * warning, so the contradiction was pinned in place rather than caught.
     *
     * Option 1 of the ticket, as decided: the row is rendered whatever its
     * age, labelled as older, and NOT quietly mixed into a list read newest
     * first.
     */
    public function testTheFailureBehindTooManySkippedInstallsIsAlsoInTheHistoryShown(): void
    {
        $failed = $this->updateHistoryRepository->create('9.9.9', '9.9.10', false, null);
        $this->updateHistoryRepository->markRolledBack($failed, 'migration KO');
        for ($i = 0; $i < 25; $i++) {
            $skipped = $this->updateHistoryRepository->create('1.1.0', '1.2.' . $i, false, null);
            $this->updateHistoryRepository->markSkipped($skipped, 'Installation remplacée.');
        }

        $body = $this->page('updatePage');

        // The version pair of the failed row: the 20 shown are all skipped
        // ones, so without the extra row this string is nowhere on the page.
        $this->assertStringContainsString(
            '9.9.9 → 9.9.10',
            $body,
            'the failure the warning is about is absent from the history the warning points at'
        );
        $this->assertStringContainsString('Dernière tentative réelle', $body);
        // And it is the failure that is shown, with its badge — not just its
        // version echoed somewhere.
        $this->assertStringContainsString('Échouée — restaurée automatiquement', $body);
        // A failure DOES keep the warning colour: the test below proves a
        // success does not, and without this one the fix for it could drop
        // the colour everywhere unnoticed.
        $this->assertStringContainsString('table-warning', $body);
    }

    /**
     * **The extra row is not an alarm unless the attempt failed** (raised in
     * review of #687). `update_history_older_attempt` is decided by age
     * alone, so a SUCCEEDED attempt pushed out of the fetched rows reaches
     * the same branch — and `table-warning` there painted a yellow warning
     * directly above that attempt's own green « Réussie » badge, on a page
     * whose banner says nothing is wrong. The row stays (option 1 of the
     * ticket says the last attempt is always shown, not only a failed one);
     * only the colour follows the outcome.
     */
    public function testAnOlderAttemptThatSucceededIsNotPaintedAsAWarning(): void
    {
        $ok = $this->updateHistoryRepository->create('9.9.9', '9.9.10', false, null);
        $this->updateHistoryRepository->markCompleted($ok);
        for ($i = 0; $i < 25; $i++) {
            $skipped = $this->updateHistoryRepository->create('1.1.0', '1.2.' . $i, false, null);
            $this->updateHistoryRepository->markSkipped($skipped, 'Installation remplacée.');
        }

        $body = $this->page('updatePage');

        // Still shown, and still announced as older — that part is the fix.
        $this->assertStringContainsString('9.9.9 → 9.9.10', $body);
        $this->assertStringContainsString('Dernière tentative réelle', $body);
        // But not dressed as a failure.
        $this->assertStringNotContainsString(
            'table-warning',
            $body,
            'a successful older attempt is painted as a warning, contradicting its own « Réussie » badge'
        );
    }

    /**
     * The other half of the same rule: when the last attempt IS among the
     * rows shown — the normal case — nothing extra is rendered. Without
     * this, the fix above could duplicate every failure on the page and no
     * test would mind.
     */
    public function testAFailureAlreadyInTheHistoryIsNotRepeatedAboveIt(): void
    {
        $failed = $this->updateHistoryRepository->create('9.9.9', '9.9.10', false, null);
        $this->updateHistoryRepository->markRolledBack($failed, 'migration KO');

        $body = $this->page('updatePage');

        $this->assertStringNotContainsString('Dernière tentative réelle', $body);
        $this->assertSame(
            1,
            substr_count($body, '9.9.9 → 9.9.10'),
            'the last attempt is rendered twice when it is already in the list'
        );
    }

    public function testTheUpdatePageStaysQuietWhenTheMostRecentAttemptSucceeded(): void
    {
        $id = $this->updateHistoryRepository->create('1.0.0', '1.1.0', false, null);
        $this->updateHistoryRepository->markCompleted($id);

        $this->assertStringNotContainsString('maintenance-update-last-attempt', $this->page('updatePage'));
    }

    /**
     * With the host measured, every dependency is a line, and the page
     * sends to the storage page for disk space rather than measuring it
     * a second time.
     */
    public function testTheHealthPageListsEveryHostDependencyAndLinksToStorageForDiskSpace(): void
    {
        $controller = ($this->rebuildController)(
            new BackupService($this->connection, $this->storagePath, dirname($this->storagePath)),
            new HostHealth($this->storagePath, $this->settingService, new BackupService(
                $this->connection,
                $this->storagePath,
                dirname($this->storagePath)
            ), new \PDO('sqlite::memory:'))
        );

        $body = $controller->index(new Request('GET', '/config/maintenance', [], [], [], []), [])->getBody();

        foreach (['cron', 'ffmpeg', 'archive_encryption', 'sodium', 'gd', 'mail', 'php', 'database', 'storage'] as $key) {
            $this->assertStringContainsString('id="host-check-' . $key . '"', $body);
        }
        $this->assertStringContainsString('id="host-health-summary"', $body);
        // The cron of a fresh test storage never ran: at least that line counts.
        $this->assertStringContainsString("à régler chez l'hébergeur.", $body);
        $this->assertStringContainsString('<a href="/config/stockage">', $body);
        $this->assertStringNotContainsString('Espace disque :', $body);
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

        $body = $this->page('updatePage');

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

        $body = $this->page('updatePage');

        $this->assertStringContainsString('Dernière mise à jour automatique installée', $body);
        $this->assertStringContainsString('dev-bbbbbbb', $body);
    }

    public function testMaintenancePageSaysSoWhenNoAutomaticUpdateEverInstalled(): void
    {
        $body = $this->page('updatePage');

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

        $body = $this->page('updatePage');

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

        $body = $this->page('updatePage');

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

        $body = $this->page('updatePage');

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

        $body = $this->page('updatePage');

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

        $body = $this->page('updatePage');

        $this->assertStringNotContainsString('auto-update-silence-warning', $body);
    }

    /**
     * One section, four scopes, one button (issue #619, IT-04) — and no
     * form left that downloads a file on the spot.
     */
    public function testTheManualBackupIsOneFormWithFourScopesAndOneButton(): void
    {
        $body = $this->pageResponse('manualBackupPage')->getBody();

        $this->assertSame(1, substr_count($body, 'id="manual-backup-form"'));
        foreach (['full_config', 'full_no_gallery', 'database', 'portable'] as $scope) {
            $this->assertMatchesRegularExpression('/value="' . $scope . '"/', $body);
        }
        $this->assertSame(1, substr_count($body, 'type="submit"'));
        $this->assertStringContainsString('Lancer la sauvegarde', $body);
        $this->assertStringNotContainsString('/config/maintenance/backup/database', $body);
        $this->assertStringNotContainsString('manual-backup-unencrypted', $body);
    }

    /**
     * Where libzip cannot encrypt, the screen says so before the button,
     * says what to ask the host for, and the portable scope cannot be
     * chosen (issue #619, IT-04).
     */
    public function testWhereArchivesCannotBeEncryptedTheScreenSaysSoAndDisablesThePortable(): void
    {
        $controller = ($this->rebuildController)(new class (
            $this->connection,
            $this->storagePath,
            dirname($this->storagePath)
        ) extends BackupService {
            public function supportsZipEncryption(): bool
            {
                return false;
            }
        });

        $request = new Request('GET', '/config/maintenance/sauvegarde-manuelle', [], [], [], []);
        $body = $controller->manualBackupPage($request, [])->getBody();

        $this->assertStringContainsString('manual-backup-unencrypted', $body);
        $this->assertStringContainsString('en clair', $body);
        $this->assertStringContainsString('openssl', $body);
        $this->assertMatchesRegularExpression(
            '/value="portable"\s+aria-describedby="scope-portable-help"\s+disabled/',
            $body
        );
    }

    /**
     * The restore dropdown and the recent-backups table must both show the
     * human-readable type label (Backup::typeLabel(), « Base de données »),
     * never the raw internal type string ("database").
     *
     * Each page is read on its own since issue #619 split them: on the old
     * single screen, the « Base de données seule » heading of the manual
     * backup box satisfied this test whatever the list and the picker
     * printed. The picker sits under the list since IT-06, so the option
     * itself is asserted rather than the page as a whole.
     */
    public function testBackupTypeIsShownAsAHumanReadableLabelEverywhere(): void
    {
        $id = $this->backupRepository->create('database', 1);
        $this->backupRepository->markCompleted($id, 42, null);

        $body = $this->page('recentBackupsPage');

        $this->assertStringContainsString('Base de données', $body);
        $this->assertStringContainsString('<option value="' . $id . '">Base de données — ', $body);
        $this->assertStringNotContainsString('>database<', $body);
        $this->assertStringNotContainsString('>database —', $body);
    }

    // --- Suppression manuelle d'une sauvegarde (IT-04) ---

    public function testDeletingABackupRemovesBothItsFilesAndItsRow(): void
    {
        $files = new FileRepository($this->pdo);
        @mkdir($this->storagePath . '/maintenance', 0777, true);
        file_put_contents($this->storagePath . '/maintenance/x.zip', 'zip');
        file_put_contents($this->storagePath . '/maintenance/x.sql', 'sql');
        $archive = $files->create('maintenance/x.zip', 'x.zip', 'application/zip', 3, 'admin', null, null);
        $dump = $files->create('maintenance/x.sql', 'x.sql', 'application/sql', 3, 'admin', null, null);
        $id = $this->backupRepository->create('full_no_gallery', 1);
        $this->backupRepository->markCompleted($id, $archive, $dump);

        $response = $this->controller->deleteBackup($this->deleteRequest($id), ['id' => (string) $id]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/config/maintenance/sauvegardes-recentes', $response->getHeaders()['Location'] ?? '', 'Back to the list the backup was deleted from.');
        $this->assertNull($this->backupRepository->findById($id));
        $this->assertFileDoesNotExist($this->storagePath . '/maintenance/x.zip');
        $this->assertFileDoesNotExist($this->storagePath . '/maintenance/x.sql');
        $this->assertNull($files->findById($archive));
        $this->assertNull($files->findById($dump));
    }

    /** It destroys a means of recovery, so the journal records it as such. */
    public function testDeletingABackupIsJournaledAsASecurityEvent(): void
    {
        $id = $this->backupRepository->create('database', 1);

        $this->controller->deleteBackup($this->deleteRequest($id), ['id' => (string) $id]);

        $entries = array_values(array_filter(
            (new JournalRepository($this->pdo))->search(),
            static fn (array $e): bool => $e['event_type'] === 'backup_deleted'
        ));
        $this->assertNotSame([], $entries);
        $this->assertSame('security', $entries[0]['level']);
    }

    /**
     * The refusal that matters: an install still running can only roll
     * back to the backup it took, and the person reading a list of dates
     * cannot tell which line that is.
     */
    public function testTheSafetyNetOfARunningUpdateCannotBeDeleted(): void
    {
        $id = $this->backupRepository->create('auto_update', 1);
        $historyId = $this->updateHistoryRepository->create('1.0.0', '1.1.0', false, 1);
        $this->updateHistoryRepository->setBackupId($historyId, $id);
        $this->updateHistoryRepository->setStatus($historyId, 'installing');

        $response = $this->controller->deleteBackup($this->deleteRequest($id), ['id' => (string) $id]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNotNull($this->backupRepository->findById($id), 'The net has to still be there.');
        $this->assertStringContainsString('mise à jour en cours', $this->flashMessage());
    }

    public function testDeletingABackupValidatesCsrf(): void
    {
        $id = $this->backupRepository->create('database', 1);
        $request = new Request(
            'POST',
            '/config/maintenance/backup/' . $id . '/delete',
            [],
            ['_csrf_token' => 'bad'],
            [],
            []
        );

        $this->controller->deleteBackup($request, ['id' => (string) $id]);

        $this->assertNotNull($this->backupRepository->findById($id));
    }

    /** Two clicks on the same button, or a purge in between. */
    public function testDeletingABackupThatIsAlreadyGoneSaysSoInsteadOfFailing(): void
    {
        $response = $this->controller->deleteBackup($this->deleteRequest(999999), ['id' => '999999']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('n\'existe plus', $this->flashMessage());
    }

    /**
     * Five rows on screen and the rest behind « voir plus » — and the
     * count in the summary has to be the real remainder, not a guess.
     */
    public function testTheListShowsFiveAndHidesTheRestBehindADisclosure(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $this->backupRepository->create('database', 1);
        }

        $body = $this->page('recentBackupsPage');

        $this->assertStringContainsString('Voir plus (2)', $body);
    }

    /**
     * A pre-operation backup is what an automatic rollback starts from,
     * so its confirmation says so — the hard refusal only applies while a
     * task is actually holding it, and the rest of the time a sentence is
     * what stands between a leader and a lost way back.
     */
    public function testTheConfirmationWarnsAboutAPreOperationBackup(): void
    {
        $this->backupRepository->create('auto_update', 1);
        $this->backupRepository->create('database', 1);

        $body = $this->page('recentBackupsPage');

        $this->assertStringContainsString('retour en arrière automatique reste possible', $body);
        $this->assertSame(
            1,
            substr_count($body, 'retour en arrière automatique reste possible'),
            'Only the pre-operation row carries the warning; on every row it would stop being read.'
        );
    }

    private function flashMessage(): string
    {
        return (string) (\Core\Http\FlashMessage::get()['message'] ?? '');
    }

    private function deleteRequest(int $id): Request
    {
        return new Request(
            'POST',
            '/config/maintenance/backup/' . $id . '/delete',
            [],
            ['_csrf_token' => $this->csrfToken()],
            [],
            []
        );
    }

    /**
     * The database alone goes the way every scope goes since IT-04 of
     * issue #619: a background task and a kept password, never a file
     * handed straight back.
     */
    public function testTheDatabaseAloneIsScheduledLikeEveryOtherScope(): void
    {
        $response = $this->controller->createFullBackup($this->jsonRequest([
            'scope' => 'database', '_csrf_token' => $this->csrfToken(),
        ]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $backup = $this->backupRepository->findById((int) $decoded['backup_id']);
        $this->assertSame('database', $backup?->type);
        $this->assertNotNull(
            \Core\Maintenance\BackupPasswords::forStorage($this->storagePath)->passwordFor((int) $decoded['backup_id'])
        );
        $payload = json_decode(
            (string) $this->schedulerRepository->findByModuleAndTaskKey('core', 'create_backup')[0]['payload'],
            true
        );
        $this->assertSame('database', $payload['scope']);
        $this->assertArrayNotHasKey('unencrypted', $payload);
    }

    /**
     * Where libzip cannot encrypt, the configuration, the site and the
     * database go in clear, as IT-04 decided — no password issued, the task
     * told. The portable archive refuses instead.
     */
    public function testWhereArchivesCannotBeEncryptedThreeScopesGoInClearAndThePortableIsRefused(): void
    {
        $controller = ($this->rebuildController)(new class (
            $this->connection,
            $this->storagePath,
            dirname($this->storagePath)
        ) extends BackupService {
            public function supportsZipEncryption(): bool
            {
                return false;
            }
        });

        $response = $controller->createFullBackup($this->jsonRequest([
            'scope' => 'database', '_csrf_token' => $this->csrfToken(),
        ]), []);
        $portable = $controller->createPortableBackup($this->jsonRequest([
            '_csrf_token' => $this->csrfToken(),
        ]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertNull(
            \Core\Maintenance\BackupPasswords::forStorage($this->storagePath)->passwordFor((int) $decoded['backup_id'])
        );
        $scheduled = $this->schedulerRepository->findByModuleAndTaskKey('core', 'create_backup');
        $this->assertCount(1, $scheduled);
        $this->assertTrue(json_decode((string) $scheduled[0]['payload'], true)['unencrypted']);
        $this->assertSame(422, $portable->getStatusCode());
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

    /**
     * The retired scope is refused like any other unknown one.
     *
     * It used to be accepted, and refused only when the gallery module
     * was off. D10 removed it from what the button offers — no archive
     * carries a declared storage location, so the scope could not keep
     * its name's promise — and a request still naming it, from a stale
     * page or a script somebody wrote, must not quietly produce an
     * archive under a type this code no longer writes.
     */
    public function testCreateFullBackupRejectsTheRetiredGalleryScope(): void
    {
        $token = $this->csrfToken();

        $response = $this->controller->createFullBackup($this->jsonRequest([
            'scope' => 'full_with_gallery', 'password' => 'secret123', '_csrf_token' => $token,
        ]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Portée de sauvegarde invalide.', $decoded['error']);
    }

    /**
     * Nothing to type (issue #619, IT-03): the site generates the archive's
     * password, keeps it under the archive's id, and never puts it in the
     * scheduled task — the handler reads it from secrets.enc.
     */
    public function testCreateFullBackupGeneratesAndKeepsThePasswordOutsideThePayload(): void
    {
        $response = $this->controller->createFullBackup($this->jsonRequest([
            'scope' => 'full_config', '_csrf_token' => $this->csrfToken(),
        ]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);

        $password = \Core\Maintenance\BackupPasswords::forStorage($this->storagePath)
            ->passwordFor($decoded['backup_id']);
        $this->assertNotNull($password);
        $this->assertMatchesRegularExpression('/^[A-HJKMNP-Z2-9]{5}(-[A-HJKMNP-Z2-9]{5}){5}$/', $password);

        $payload = (string) $this->schedulerRepository->findByModuleAndTaskKey('core', 'create_backup')[0]['payload'];
        $this->assertStringNotContainsString($password, $payload);
        $this->assertStringNotContainsString('password', $payload);
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

    // --- La sauvegarde portable (IT-06) ---

    private const PORTABLE_PASSPHRASE = 'quatre mots parfaitement ordinaires';

    public function testCreatePortableBackupValidatesCsrf(): void
    {
        $response = $this->controller->createPortableBackup($this->jsonRequest([
            'passphrase' => self::PORTABLE_PASSPHRASE, '_csrf_token' => 'bad',
        ]), []);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->schedulerRepository->findByModuleAndTaskKey('core', 'create_backup'));
    }

    /**
     * A portable archive's password is generated too, and far above the
     * floor a typed passphrase had to meet: it is the only lock on the
     * master key inside the archive.
     */
    public function testAPortableBackupGetsAGeneratedPasswordAboveTheLengthFloor(): void
    {
        $response = $this->controller->createPortableBackup($this->jsonRequest([
            '_csrf_token' => $this->csrfToken(),
        ]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $password = (string) \Core\Maintenance\BackupPasswords::forStorage($this->storagePath)
            ->passwordFor($decoded['backup_id']);
        $this->assertNull(\Core\Maintenance\Portable\PortablePassphrase::refuse($password));
    }

    public function testCreatePortableBackupSchedulesTheBackgroundTaskUnderItsOwnType(): void
    {
        $response = $this->controller->createPortableBackup($this->jsonRequest([
            'passphrase' => self::PORTABLE_PASSPHRASE, '_csrf_token' => $this->csrfToken(),
        ]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);

        $backup = $this->backupRepository->findById($decoded['backup_id']);
        $this->assertNotNull($backup);
        $this->assertSame(\Core\Maintenance\Backup::PORTABLE_TYPE, $backup->type);
        $this->assertSame('pending', $backup->status);

        $scheduled = $this->schedulerRepository->findByModuleAndTaskKey('core', 'create_backup');
        $this->assertCount(1, $scheduled);
    }

    /**
     * The passphrase never reaches the database at all: not in clear, and
     * no longer even encrypted in the scheduler payload, the one place a
     * stolen database dump would look.
     */
    public function testNoPassphraseTravelsInTheScheduledPayload(): void
    {
        $this->controller->createPortableBackup($this->jsonRequest([
            '_csrf_token' => $this->csrfToken(),
        ]), []);

        $scheduled = $this->schedulerRepository->findByModuleAndTaskKey('core', 'create_backup');
        $payload = (string) $scheduled[0]['payload'];

        $this->assertStringNotContainsString('password', $payload);
        $this->assertStringNotContainsString('passphrase', $payload);
    }

    /**
     * The password is revealed to whoever is about to download, by a POST
     * behind the CSRF token, and the reveal is journaled without it.
     */
    public function testTheGeneratedPasswordIsRevealedOnDemandAndJournaled(): void
    {
        $created = json_decode($this->controller->createFullBackup($this->jsonRequest([
            'scope' => 'full_config', '_csrf_token' => $this->csrfToken(),
        ]), [])->getBody(), true);
        $id = (int) $created['backup_id'];
        $kept = \Core\Maintenance\BackupPasswords::forStorage($this->storagePath)->passwordFor($id);

        $response = $this->controller->revealBackupPassword(
            $this->jsonRequest(['_csrf_token' => $this->csrfToken()]),
            ['id' => (string) $id]
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($kept, $decoded['password']);
        $this->assertSame('no-store', $response->getHeaders()['Cache-Control'] ?? null);

        $rows = $this->pdo->query(
            "SELECT level, context FROM event_log WHERE event_type = 'backup_password_revealed'"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertSame('security', $rows[0]['level']);
        $this->assertStringNotContainsString((string) $kept, (string) $rows[0]['context']);
    }

    public function testRevealingRefusesWithoutTheCsrfToken(): void
    {
        $created = json_decode($this->controller->createFullBackup($this->jsonRequest([
            'scope' => 'full_config', '_csrf_token' => $this->csrfToken(),
        ]), [])->getBody(), true);

        $response = $this->controller->revealBackupPassword(
            $this->jsonRequest(['_csrf_token' => 'bad']),
            ['id' => (string) $created['backup_id']]
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringNotContainsString('"password"', $response->getBody());
    }

    /** An archive with no kept password — older, or unencrypted — says so. */
    public function testRevealingAnArchiveWithoutAKeptPasswordSaysSo(): void
    {
        $id = $this->backupRepository->create('auto_backup', null);

        $response = $this->controller->revealBackupPassword(
            $this->jsonRequest(['_csrf_token' => $this->csrfToken()]),
            ['id' => (string) $id]
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('Aucun mot de passe', $response->getBody());
    }

    /**
     * And the journal says, at `security` level, what was asked for.
     *
     * This is the moment an operator asked the site to package its master
     * key into a downloadable file. An `info` line beside the ordinary
     * backups would bury it.
     */
    public function testAPortableBackupRequestIsJournaledAsASecurityEvent(): void
    {
        $this->controller->createPortableBackup($this->jsonRequest([
            'passphrase' => self::PORTABLE_PASSPHRASE, '_csrf_token' => $this->csrfToken(),
        ]), []);

        $rows = $this->pdo->query(
            "SELECT level, event_type FROM event_log WHERE event_type = 'portable_backup_requested'"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertCount(1, $rows);
        $this->assertSame('security', $rows[0]['level']);
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

        $response = $this->pageResponse('updatePage');

        $this->assertStringContainsString('99.0.0', $response->getBody());
        $this->assertStringContainsString('Installer la mise à jour', $response->getBody());
    }

    public function testIndexHidesUpdateSectionWhenAlreadyUpToDate(): void
    {
        $response = $this->pageResponse('updatePage');

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
        $versionFile = dirname($this->storagePath) . '/VERSION';
        $original = is_file($versionFile) ? file_get_contents($versionFile) : null;
        file_put_contents($versionFile, "dev-a1b2c3d\n");

        try {
            $response = $this->pageResponse('updatePage');
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
        $response = $this->pageResponse('updatePage');

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

        $response = $this->pageResponse('updatePage');

        $this->assertStringContainsString('Corrige un bug important dans le module Finances.', $response->getBody());
        $this->assertStringContainsString('https://github.com/x/y/releases/tag/v0.0.0', $response->getBody());
    }

    public function testIndexShowsTheInstalledCommitMessageForADevBuild(): void
    {
        $versionFile = dirname($this->storagePath) . '/VERSION';
        $original = is_file($versionFile) ? file_get_contents($versionFile) : null;
        file_put_contents($versionFile, "dev-a1b2c3d\n");
        $this->fakeReleaseClient->commitBySha = new CommitInfo(
            'a1b2c3d0000000000000000000000000000000',
            "Corrige la pagination du Trombinoscope\n\nDétails de la correction.",
            'https://github.com/x/y/commit/a1b2c3d'
        );

        try {
            $response = $this->pageResponse('updatePage');
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

        $first = $this->pageResponse('updatePage');
        $this->assertStringContainsString('Notes originales.', $first->getBody());

        // Same installed version, different fake response — a second load
        // must still show the CACHED notes, proving the controller reused
        // the setting instead of calling the GitHub client again.
        $this->fakeReleaseClient->releaseByTag = new ReleaseInfo('v0.0.0', 'Notes remplacees.', 'https://example.test/2', null);

        $second = $this->pageResponse('updatePage');
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
        $versionFile = dirname($this->storagePath) . '/VERSION';
        $original = is_file($versionFile) ? file_get_contents($versionFile) : null;
        file_put_contents($versionFile, "dev-a1b2c3d\n");
        $this->settingService->setInternal('update_latest_version', '1.0.22');
        $this->settingService->set('auto_update_level', 'dev');
        $this->settingService->clearCache();

        try {
            $response = $this->pageResponse('updatePage');
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
        $versionFile = dirname($this->storagePath) . '/VERSION';
        $original = is_file($versionFile) ? file_get_contents($versionFile) : null;
        file_put_contents($versionFile, "dev-a1b2c3d\n");
        $this->settingService->setInternal('update_latest_version', '1.0.22');
        $this->settingService->set('auto_update_level', 'minor');
        $this->settingService->clearCache();

        try {
            $response = $this->pageResponse('updatePage');
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

        $response = $this->pageResponse('updatePage');
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

        foreach (['pending', 'backing_up', 'downloading', 'installing', 'completed', 'failed', 'rolled_back', 'skipped'] as $status) {
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

        $runner = $this->createStub(\Core\Database\MigrationRunner::class);
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

    public function testFullResetSchedulesTheBackgroundTaskOnceThePasswordWasShownAndNoted(): void
    {
        $this->revealFullResetPassword();

        $response = $this->controller->fullReset($this->jsonRequest([
            'confirm_keyword' => 'EFFACER', 'confirm_checkbox' => true, 'password_noted' => true,
            '_csrf_token' => $this->csrfToken(),
        ]), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertCount(1, $this->schedulerRepository->findByModuleAndTaskKey('core', 'full_reset'));
    }

    /**
     * The safety copy is encrypted and the reset erases its password with
     * secrets.enc (issue #619, IT-03b): nothing is scheduled before the
     * password was shown.
     */
    public function testFullResetIsRefusedUntilTheSafetyCopyPasswordWasShown(): void
    {
        $response = $this->controller->fullReset($this->jsonRequest([
            'confirm_keyword' => 'EFFACER', 'confirm_checkbox' => true, 'password_noted' => true,
            '_csrf_token' => $this->csrfToken(),
        ]), []);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('Affichez', $response->getBody());
        $this->assertSame([], $this->schedulerRepository->findByModuleAndTaskKey('core', 'full_reset'));
    }

    /** A secrets file that cannot be read refuses the reset in JSON, not with an error page. */
    public function testAnUnreadableSecretsFileRefusesTheResetInJson(): void
    {
        file_put_contents($this->storagePath . '/config/secrets.enc', 'not a secrets blob');

        $response = $this->controller->fullReset($this->jsonRequest([
            'confirm_keyword' => 'EFFACER', 'confirm_checkbox' => true, 'password_noted' => true,
            '_csrf_token' => $this->csrfToken(),
        ]), []);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse(json_decode($response->getBody(), true)['success']);
        $this->assertSame([], $this->schedulerRepository->findByModuleAndTaskKey('core', 'full_reset'));
    }

    /** …and until the operator confirms having noted it. */
    public function testFullResetIsRefusedUntilThePasswordIsConfirmedNoted(): void
    {
        $this->revealFullResetPassword();

        $response = $this->controller->fullReset($this->jsonRequest([
            'confirm_keyword' => 'EFFACER', 'confirm_checkbox' => true, '_csrf_token' => $this->csrfToken(),
        ]), []);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->schedulerRepository->findByModuleAndTaskKey('core', 'full_reset'));
    }

    /**
     * A host whose libzip cannot encrypt keeps the copy in clear: there is
     * no password to show, and the reset goes ahead without one.
     */
    public function testWhereArchivesCannotBeEncryptedTheResetNeedsNoPassword(): void
    {
        $controller = ($this->rebuildController)(new class (
            $this->connection,
            $this->storagePath,
            dirname($this->storagePath)
        ) extends BackupService {
            public function supportsZipEncryption(): bool
            {
                return false;
            }
        });

        $reveal = $controller->revealFullResetPassword(
            $this->jsonRequest(['_csrf_token' => $this->csrfToken()]),
            []
        );
        $reset = $controller->fullReset($this->jsonRequest([
            'confirm_keyword' => 'EFFACER', 'confirm_checkbox' => true, '_csrf_token' => $this->csrfToken(),
        ]), []);

        $this->assertSame(409, $reveal->getStatusCode());
        $this->assertArrayNotHasKey('password', json_decode($reveal->getBody(), true));
        $this->assertTrue(json_decode($reset->getBody(), true)['success']);
        $this->assertCount(1, $this->schedulerRepository->findByModuleAndTaskKey('core', 'full_reset'));
    }

    /** The same password every time it is shown, journaled without it. */
    public function testTheFullResetPasswordIsStableAndItsRevealJournaled(): void
    {
        $first = $this->revealFullResetPassword();
        $second = $this->revealFullResetPassword();

        $this->assertSame($first, $second);
        $this->assertMatchesRegularExpression('/^[A-HJKMNP-Z2-9]{5}(-[A-HJKMNP-Z2-9]{5}){5}$/', $first);
        $rows = $this->pdo->query(
            "SELECT level, context FROM event_log WHERE event_type = 'full_reset_password_revealed'"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(2, $rows);
        $this->assertSame('security', $rows[0]['level']);
        $this->assertStringNotContainsString($first, (string) $rows[0]['context']);
    }

    private function revealFullResetPassword(): string
    {
        $response = $this->controller->revealFullResetPassword(
            $this->jsonRequest(['_csrf_token' => $this->csrfToken()]),
            []
        );
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('no-store', $response->getHeaders()['Cache-Control'] ?? null);

        return (string) json_decode($response->getBody(), true)['password'];
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

    /**
     * **A portable archive is refused before anything is scheduled.**
     *
     * It passes every test the restore path applies — `completed`, with a
     * database dump — so without this check the pass would restore the
     * live database from it, then throw while extracting `secrets/`
     * (which `RESTORABLE_TOP_LEVEL` refuses), then roll the whole site
     * back from the safety copy. The site survives that, having been
     * replaced and un-replaced for an operation that could never have
     * finished. A portable archive is for starting a NEW installation;
     * restoring one onto this site is IT-07's subject, and until then the
     * honest answer is a sentence, not a destructive round trip.
     */
    public function testRestoreBackupRefusesAPortableArchiveBeforeTouchingAnything(): void
    {
        $backupId = (new \Core\Maintenance\BackupRepository($this->pdo))
            ->create(\Core\Maintenance\Backup::PORTABLE_TYPE, null);
        $stmt = $this->pdo->prepare(
            "UPDATE backups SET status = 'completed', file_id = NULL, db_dump_file_id = NULL WHERE id = ?"
        );
        $stmt->execute([$backupId]);

        $request = new Request('POST', '/config/maintenance/reset/restore', [], [
            '_csrf_token' => $this->csrfToken(),
            'confirm_keyword' => 'RESTAURER',
            'source' => 'server',
            'backup_id' => (string) $backupId,
        ], [], []);

        $response = $this->controller->restoreBackup($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            [],
            $this->schedulerRepository->findByModuleAndTaskKey('core', 'restore_backup'),
            'nothing may be queued: the refusal has to happen before the database is touched'
        );
    }

    /** And the picker does not offer one, so nobody meets that refusal. */
    public function testThePortableArchiveIsNotOfferedInTheRestorePicker(): void
    {
        $backups = new \Core\Maintenance\BackupRepository($this->pdo);
        $portableId = $backups->create(\Core\Maintenance\Backup::PORTABLE_TYPE, null);
        $ordinaryId = $backups->create('full_no_gallery', null);
        $stmt = $this->pdo->prepare("UPDATE backups SET status = 'completed' WHERE id IN (?, ?)");
        $stmt->execute([$portableId, $ordinaryId]);

        $body = $this->page('recentBackupsPage');

        // The ordinary one is offered; the portable one is listed in
        // « Sauvegardes récentes » but never as a restore option.
        $this->assertStringContainsString('<option value="' . $ordinaryId . '"', $body);
        $this->assertStringNotContainsString('<option value="' . $portableId . '"', $body);
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
        // Back to the sub-page the restore is started from — « Sauvegardes
        // récentes » since IT-06 (issue #619).
        $this->assertStringStartsWith(
            '/config/maintenance/sauvegardes-recentes?restore_id=',
            $response->getHeaders()['Location'] ?? ''
        );
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

    /**
     * **A typed password still reaches the task for a server archive**
     * (IT-06 review). The page asks for none for an archive whose password
     * the site kept — RestoreBackupHandler uses the kept one — but a full
     * backup from before IT-03 was encrypted with a password its operator
     * chose, and dropping what they type would leave it unrestorable here.
     */
    public function testAServerRestoreCarriesATypedPasswordForAnArchiveWhosePasswordWasNeverKept(): void
    {
        $backupId = $this->backupRepository->create('full_config', 1);
        $this->backupRepository->markCompleted($backupId, 1, 1);

        $request = new Request('POST', '/config/maintenance/reset/restore', [], [
            '_csrf_token' => $this->csrfToken(), 'confirm_keyword' => 'RESTAURER', 'source' => 'server',
            'backup_id' => (string) $backupId, 'password' => 'choisi-avant-it03',
        ], [], []);

        $this->controller->restoreBackup($request, []);

        $tasks = $this->schedulerRepository->findByModuleAndTaskKey('core', 'restore_backup');
        $this->assertCount(1, $tasks);
        $payload = json_decode((string) $tasks[0]['payload'], true);
        $this->assertIsString($payload['encrypted_password'], 'the typed password was dropped');
    }

    /**
     * **The restoration lives beside the list it restores from** (issue
     * #619, IT-06), with everything it had under the red card: both
     * sources, the chunked upload, the RESTAURER keyword, portable
     * archives kept out of the server list. The password field sits in the
     * upload picker only. The reset page keeps a sentence pointing there.
     */
    public function testTheRestorationMovedToTheRecentBackupsPage(): void
    {
        $portableId = $this->backupRepository->create(\Core\Maintenance\Backup::PORTABLE_TYPE, 1);
        $this->backupRepository->markCompleted($portableId, 1, 1);
        $body = $this->page('recentBackupsPage');

        $this->assertStringContainsString('id="maintenance-restore"', $body);
        $this->assertStringContainsString('action="/config/maintenance/reset/restore"', $body);
        $this->assertStringContainsString('id="restore-source-server"', $body);
        $this->assertStringContainsString('id="restore-source-upload"', $body);
        $this->assertStringContainsString('id="restore-upload-id"', $body);
        $this->assertStringContainsString('Tapez <strong>RESTAURER</strong> pour confirmer', $body);
        $this->assertStringContainsString('Une sauvegarde de sécurité est prise automatiquement avant la restauration', $body);
        $this->assertStringNotContainsString('<option value="' . $portableId . '">', $body);

        // The password field is hidden until an upload, or an archive of
        // this server whose password was never kept, asks for it.
        $this->assertStringContainsString('<div class="mb-2 d-none" id="restore-password-field">', $body);
        $this->assertStringContainsString('id="restore-backup-password"', $body);

        $reset = $this->page('resetPage');
        $this->assertStringNotContainsString('restore-backup-form', $reset);
        $this->assertStringContainsString('/config/maintenance/sauvegardes-recentes#maintenance-restore', $reset);
    }

    /**
     * **Only a full backup whose password was never kept asks for one**
     * (IT-06 review): its option carries the flag maintenance.js reads to
     * show the field; an archive whose password the site kept does not.
     */
    public function testOnlyAnArchiveWhosePasswordWasNeverKeptIsFlaggedForATypedPassword(): void
    {
        $legacy = $this->backupRepository->create('full_config', 1);
        $this->backupRepository->markCompleted($legacy, 1, 1);
        $database = $this->backupRepository->create('database', 1);
        $this->backupRepository->markCompleted($database, 1, 1);

        $body = $this->page('recentBackupsPage');

        $this->assertStringContainsString('<option value="' . $legacy . '" data-needs-password="1">', $body);
        $this->assertStringContainsString('<option value="' . $database . '">', $body);
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
        $this->assertSame('skipped', $this->updateHistoryRepository->findById($historyId)->status);
    }

    public function testSaveAutoUpdatePreferencesDisablingAutoUpdatesCancelsThePendingScheduledInstall(): void
    {
        [$historyId, $actionId] = $this->seedPendingScheduledInstall('2.4.2');

        $request = $this->jsonRequest(['enabled' => false, 'level' => 'major', 'day' => 'monday', 'time' => '03:00', '_csrf_token' => $this->csrfToken()]);
        $response = $this->controller->saveAutoUpdatePreferences($request, []);

        $this->assertTrue(json_decode($response->getBody(), true)['success']);
        $this->assertSame('canceled', $this->schedulerRepository->findById($actionId)['status']);
        $this->assertSame('skipped', $this->updateHistoryRepository->findById($historyId)->status);
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
        $this->assertSame('skipped', $this->updateHistoryRepository->findById($historyId)->status);
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
        $versionFile = dirname($this->storagePath) . '/VERSION';
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
        $versionFile = dirname($this->storagePath) . '/VERSION';
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
        $versionFile = dirname($this->storagePath) . '/VERSION';
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

        $body = $this->page('updatePage');

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

        $body = $this->page('updatePage');
        // The page under test must be the one that renders this block,
        // or an absence assertion below passes whatever the code does.
        $this->assertStringContainsString('id="maintenance-update"', $body);

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

        $body = $this->page('updatePage');

        $this->assertStringContainsString('data-notes-clamp="update-release-notes-toggle"', $body);
        $this->assertStringContainsString('Voir la description complète', $body);
        $this->assertStringContainsString('notes-clamp.js', $body);
    }

    public function testIndexShowsTheWebhookWarningWhenDevLevelEnabledButWebhookNotConfigured(): void
    {
        $this->settingService->set('auto_update_enabled', '1');
        $this->settingService->set('auto_update_level', 'dev');
        $this->settingService->clearCache();

        $response = $this->pageResponse('updatePage');

        $this->assertStringContainsString('webhook GitHub n\'est pas configuré', $response->getBody());
    }

    public function testIndexDoesNotShowTheWebhookWarningWhenAutoUpdateDisabled(): void
    {
        $response = $this->pageResponse('updatePage');
        // The page under test must be the one that renders this block,
        // or an absence assertion below passes whatever the code does.
        $this->assertStringContainsString('id="maintenance-auto-update"', $response->getBody());

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

        $response = $this->pageResponse('updatePage');
        // The page under test must be the one that renders this block,
        // or an absence assertion below passes whatever the code does.
        $this->assertStringContainsString('id="maintenance-auto-update"', $response->getBody());

        $this->assertStringNotContainsString('webhook GitHub n\'est pas configuré', $response->getBody());
    }

    public function testIndexNeverRendersTheWebhookSecretItself(): void
    {
        $this->controller->generateWebhookSecret($this->jsonRequest(['_csrf_token' => $this->csrfToken()]), []);
        $secrets = $this->secretManager->readSecrets();

        $response = $this->pageResponse('updatePage');
        // The page under test must be the one that renders this block,
        // or an absence assertion below passes whatever the code does.
        $this->assertStringContainsString('id="auto-update-webhook-section"', $response->getBody());

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
        $router->addRoute('GET', '/config/maintenance', MaintenanceController::class, 'index', 'superadmin');

        $configFile = sys_get_temp_dir() . '/test_maintenance_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $config = new AppConfig($configFile);

        $fc = new FrontController($router, $this->twig, $config);
        $fc->registerController(MaintenanceController::class, $this->controller);

        return $fc;
    }

    /** A chef d'unité, one level below the floor maintenance has since issue #619. */
    public function testAdminIsDenied(): void
    {
        $response = $this->buildFrontController()->handle(new Request('GET', '/config/maintenance', [], [], [], []));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testSuperadminIsAllowed(): void
    {
        AuthSession::login(1, 'root@test.be', 'superadmin');

        $response = $this->buildFrontController()->handle(new Request('GET', '/config/maintenance', [], [], [], []));

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * The six sub-pages (issue #619) through the real Router and this real
     * controller, each at the floor `public/index.php` declares for it —
     * read from the file rather than restated, so the day the floor moves
     * this follows it — allowed at that floor and refused one level
     * below (AGENTS.md § Tests).
     */
    public function testEverySubPageIsServedAtItsDeclaredFloorAndRefusedBelowIt(): void
    {
        $pages = [
            '/config/maintenance' => 'index',
            '/config/maintenance/mise-a-jour' => 'updatePage',
            '/config/maintenance/sauvegarde-manuelle' => 'manualBackupPage',
            '/config/maintenance/sauvegarde-automatique' => 'automaticBackupPage',
            '/config/maintenance/sauvegardes-recentes' => 'recentBackupsPage',
            '/config/maintenance/reinitialisation' => 'resetPage',
        ];
        $floors = [];
        foreach (\authzCoreRoutes() as $route) {
            if ($route['method'] === 'GET' && isset($pages[$route['path']])) {
                $floors[$route['path']] = $route['role_min'];
            }
        }
        $this->assertSame(array_keys($pages), array_keys(array_intersect_key($pages, $floors)), 'A sub-page is not registered.');

        $router = new Router();
        foreach ($pages as $path => $action) {
            $router->addRoute('GET', $path, MaintenanceController::class, $action, $floors[$path]);
        }
        $configFile = sys_get_temp_dir() . '/test_maintenance_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $frontController = new FrontController($router, $this->twig, new AppConfig($configFile));
        $frontController->registerController(MaintenanceController::class, $this->controller);

        foreach ($pages as $path => $action) {
            $floor = Role::fromString($floors[$path]);
            $below = self::oneLevelBelow($floor);

            AuthSession::login(1, 'floor@test.be', $floor->value);
            $this->assertSame(200, $frontController->handle(new Request('GET', $path, [], [], [], []))->getStatusCode(), "{$path} at {$floor->value}");

            AuthSession::login(1, 'below@test.be', $below->value);
            $this->assertSame(403, $frontController->handle(new Request('GET', $path, [], [], [], []))->getStatusCode(), "{$path} at {$below->value}");
        }
    }

    private static function oneLevelBelow(Role $role): Role
    {
        $below = null;
        foreach (Role::cases() as $candidate) {
            if ($candidate->level() < $role->level() && ($below === null || $candidate->level() > $below->level())) {
                $below = $candidate;
            }
        }
        self::assertNotNull($below);

        return $below;
    }

    private function ageCompletedAt(int $id, int $daysAgo): void
    {
        $stmt = $this->pdo->prepare('UPDATE update_history SET completed_at = ? WHERE id = ?');
        $stmt->execute([(new \DateTimeImmutable("-{$daysAgo} days"))->format('Y-m-d H:i:s'), $id]);
    }

}
