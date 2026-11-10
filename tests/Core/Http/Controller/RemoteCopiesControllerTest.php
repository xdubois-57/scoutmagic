<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Http\Controller\RemoteBackupController;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Maintenance\Remote\RemoteBackupDestination;
use Core\Maintenance\Remote\RemoteCopies;
use Core\Maintenance\Remote\RemotePassphrase;
use Core\Maintenance\Remote\RemoteRetention;
use Core\Maintenance\Task\FetchRemoteBackupHandler;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\Security\SecretManager;
use Core\Storage\Location\Backend\StorageBackendFactory;
use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\StorageLocationRepository;
use Core\Storage\Location\StorageLocationType;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\RequestWithInput;
use Tests\TestTwig;

/**
 * The « Copies hors site » card and restoring one of its copies (#847,
 * part 4): the list read on the destination with a badge per passphrase
 * generation, its states, and a restore accepted for a listed name only.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class RemoteCopiesControllerTest extends TestCase
{
    private string $base;
    private string $remote;
    private \PDO $pdo;
    private RemoteBackupSettingsDouble $settings;
    private StorageLocationRepository $locations;
    private RemoteBackupDestination $destination;
    private SchedulerService $scheduler;
    private RemotePassphrase $passphrase;

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            @session_start();
        }
        $_SESSION = [];
        $_POST = [];

        $this->base = sys_get_temp_dir() . '/remote_copies_' . bin2hex(random_bytes(6));
        $this->remote = $this->base . '/destination';
        @mkdir($this->base . '/storage/keys', 0700, true);
        @mkdir($this->base . '/storage/config', 0700, true);
        @mkdir($this->base . '/storage/temp', 0700, true);
        @mkdir($this->remote, 0700, true);
        $secrets = new SecretManager(
            $this->base . '/storage/keys/master.key',
            $this->base . '/storage/config/secrets.enc'
        );
        $secrets->generateMasterKey();
        $secrets->writeSecrets([]);

        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settings = new RemoteBackupSettingsDouble([RemotePassphrase::GENERATION_SETTING => '2']);
        $this->passphrase = new RemotePassphrase($this->settings, $secrets);
        $this->locations = new StorageLocationRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->destination = new RemoteBackupDestination(
            $this->settings,
            $this->locations,
            new StorageBackendFactory($this->locations, $this->base . '/storage')
        );
        $this->scheduler = new SchedulerService(new SchedulerRepository($this->pdo));
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    public function testTheCardListsTheCopiesOnTheDestinationWithTheirPhrase(): void
    {
        $this->chooseLocalDestination();
        $current = $this->depositCopy('2026-10-02 03:00:00', 2, 2048);
        $old = $this->depositCopy('2026-10-01 03:00:00', 1, 1024);
        file_put_contents($this->remote . '/autre-fichier.txt', 'pas une copie');

        $body = $this->archives();

        $this->assertStringContainsString('Disque du serveur — Copies', $body);
        $this->assertSame(2, substr_count($body, 'data-remote-copy='));
        $this->assertMatchesRegularExpression(
            '~data-remote-copy="' . preg_quote($current, '~') . '">.*?Lisible avec la phrase actuelle~s',
            $body
        );
        $this->assertMatchesRegularExpression(
            '~data-remote-copy="' . preg_quote($old, '~') . '">.*?Ancienne phrase de passe~s',
            $body
        );
        $this->assertLessThan(strpos($body, $old), strpos($body, $current), 'newest first');
        $this->assertStringNotContainsString('autre-fichier', $body);
    }

    public function testANameWhoseGenerationCannotBeReadAsksForAPhrase(): void
    {
        $this->assertNull(RemoteCopies::generationOf('scoutmagic-2026-10-01-030000.zip'));
        $this->assertSame(3, RemoteCopies::generationOf('scoutmagic-2026-10-01-030000-g3.zip'));
    }

    public function testWithoutADestinationTheCardSaysWhereToChooseOne(): void
    {
        $body = $this->archives();

        $this->assertStringContainsString('data-remote-state="none"', $body);
        $this->assertStringContainsString('/config/maintenance/sauvegarde-automatique#remote-backup', $body);
    }

    public function testADestinationThatCannotBeListedIsSaidWithARetry(): void
    {
        // A location whose backend cannot even be built — the same answer
        // as a destination that does not reply: nothing to list, retry.
        $id = $this->locations->create(
            StorageLocationType::Local,
            'Copies',
            new LocalLocationConfig('../hors-du-site'),
            null
        );
        $this->destination->choose($id);

        $body = $this->archives();

        $this->assertStringContainsString('data-remote-state="unreachable"', $body);
        $this->assertStringContainsString('data-remote-retry', $body);
    }

    public function testARestoreOfAListedCopyQueuesItsFetch(): void
    {
        $this->chooseLocalDestination();
        $name = $this->depositCopy('2026-10-02 03:00:00', 2, 2048);

        $response = $this->restore(['name' => $name, 'confirm_keyword' => 'RESTAURER']);

        $this->assertSame(200, $response->getStatusCode());
        $live = $this->liveFetch();
        $this->assertIsArray($live);
        $payload = json_decode((string) $live['payload'], true);
        $this->assertSame($name, $payload['name']);
        $this->assertSame(2048, $payload['size']);
        $this->assertTrue(FetchRemoteBackupHandler::isOurTempFile($this->base . '/storage', $payload['path']));
        $this->assertArrayNotHasKey('encrypted_password', $payload, 'the phrase in force is the site\'s own');

        // And the card follows it.
        $this->assertStringContainsString('data-remote-state="fetching"', $this->archives());
        // One at a time.
        $this->assertSame(409, $this->restore(['name' => $name, 'confirm_keyword' => 'RESTAURER'])->getStatusCode());
    }

    public function testOnlyANameInTheDestinationsListIsAccepted(): void
    {
        $this->chooseLocalDestination();
        $this->depositCopy('2026-10-02 03:00:00', 2, 2048);

        foreach (['scoutmagic-2030-01-01-000000-g2.zip', '../../storage/keys/master.key', 'autre.zip'] as $name) {
            $response = $this->restore(['name' => $name, 'confirm_keyword' => 'RESTAURER']);
            $this->assertSame(404, $response->getStatusCode(), $name);
        }
        $this->assertNull($this->liveFetch());
    }

    public function testACopyOfAnOlderPhraseNeedsItsPhraseAndTheKeyword(): void
    {
        $this->chooseLocalDestination();
        $old = $this->depositCopy('2026-10-01 03:00:00', 1, 1024);

        $this->assertSame(422, $this->restore(['name' => $old])->getStatusCode(), 'no keyword');
        $this->assertSame(422, $this->restore(['name' => $old, 'confirm_keyword' => 'RESTAURER'])->getStatusCode());

        $this->assertSame(200, $this->restore([
            'name' => $old,
            'confirm_keyword' => 'RESTAURER',
            'passphrase' => 'l-ancienne-phrase',
        ])->getStatusCode());
        $live = $this->liveFetch();
        $payload = json_decode((string) ($live['payload'] ?? ''), true);
        $this->assertNotSame('', (string) ($payload['encrypted_password'] ?? ''), 'it travels encrypted');
        $this->assertStringNotContainsString('l-ancienne-phrase', (string) $live['payload']);
    }

    /** Not enough room for the copy: refused at once, saying how much is needed. */
    public function testACopyTooLargeForTheDiskIsRefusedAtOnce(): void
    {
        $this->chooseLocalDestination();
        $name = $this->depositCopy('2026-10-02 03:00:00', 2, 2048);
        $this->settings->values[\Core\Storage\DiskBudget::QUOTA_SETTING] = '1';

        $response = $this->restore(['name' => $name, 'confirm_keyword' => 'RESTAURER'], true);

        $this->assertSame(507, $response->getStatusCode());
        $this->assertNull($this->liveFetch());
    }

    public function testARestoreUnderWayRefusesACopy(): void
    {
        $this->chooseLocalDestination();
        $name = $this->depositCopy('2026-10-02 03:00:00', 2, 2048);
        $this->scheduler->scheduleAfter('core', 'restore_backup', 0, []);

        $this->assertSame(409, $this->restore(['name' => $name, 'confirm_keyword' => 'RESTAURER'])->getStatusCode());
    }

    /**
     * @return array<string, mixed>|null
     */
    private function liveFetch(): ?array
    {
        return $this->scheduler->findLive(
            'core',
            FetchRemoteBackupHandler::TASK_KEY,
            FetchRemoteBackupHandler::REFERENCE
        );
    }

    private function chooseLocalDestination(): void
    {
        $id = $this->locations->create(
            StorageLocationType::Local,
            'Copies',
            new LocalLocationConfig($this->remote),
            null
        );
        $this->destination->choose($id);
    }

    private function depositCopy(string $at, int $generation, int $size): string
    {
        $name = RemoteRetention::nameFor(new \DateTimeImmutable($at, new \DateTimeZone('UTC')), $generation);
        file_put_contents($this->remote . '/' . $name, str_repeat('x', $size));

        return $name;
    }

    private function archives(): string
    {
        return $this->controller()->archives(new RequestWithInput('GET', '/', [], [], [], []), [])->getBody();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function restore(array $body, bool $withDiskBudget = false): Response
    {
        $token = CsrfGuard::generateToken();

        return $this->controller($withDiskBudget)->restoreCopy(
            new RequestWithInput('POST', '/', [], [], [], [], (string) json_encode($body + ['_csrf_token' => $token])),
            []
        );
    }

    private function controller(bool $withDiskBudget = false): RemoteBackupController
    {
        return new RemoteBackupController(
            TestTwig::create(),
            $this->destination,
            $this->locations,
            new JournalService(new RecordingJournalRepository()),
            $this->passphrase,
            new RemoteCopies($this->destination, new RemoteRetention($this->settings), $this->passphrase),
            $this->scheduler,
            new EncryptionService(str_repeat('c', 32), str_repeat('d', 32)),
            $withDiskBudget ? new \Core\Storage\DiskBudget($this->base . '/storage', $this->settings) : null,
            $this->base . '/storage'
        );
    }
}
