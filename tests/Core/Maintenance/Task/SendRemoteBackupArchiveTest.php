<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Maintenance\Task;

use Core\Database\Connection;
use Core\Database\MigrationRunner;
use Core\Database\SchemaComparator;
use Core\Database\SchemaIntrospector;
use Core\Database\SqlParser;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Maintenance\BackupService;
use Core\Maintenance\Portable\PortableKeys;
use Core\Maintenance\Remote\RemoteBackupDestination;
use Core\Maintenance\Remote\RemotePassphrase;
use Core\Maintenance\Task\SendRemoteBackupHandler;
use Core\Scheduler\TaskContext;
use Core\Storage\Location\Backend\ResumableUploadBackend;
use Core\Storage\Location\Backend\StorageBackendFactory;
use Core\Storage\Location\Config\GoogleDriveLocationConfig;
use Core\Storage\Location\StorageLocationRepository;
use Core\Storage\Location\StorageLocationType;
use Core\Security\EncryptionService;
use Core\Security\SecretManager;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Core\Maintenance\Remote\InMemorySettingService;
use Tests\Core\Storage\Location\Backend\RefusingBackend;

/**
 * The archive the recurring send actually builds — built for real.
 *
 * {@see SendRemoteBackupHandlerTest} is about what a run does with an
 * archive; this is about the archive itself, and it needs a database
 * engine because a portable archive contains a real dump. It answers the
 * two questions that cannot be asked of a stand-in file: **is it opened
 * by the phrase this site would show its operator**, and **does its name
 * say which phrase that is**.
 *
 * The second is not a nicety. Regenerating the phrase makes every archive
 * already sent unreadable and nothing re-encrypts them; an operator
 * facing a folder of identically-named archives would try the current
 * phrase, fail, and conclude the backup was broken.
 *
 * @group database
 */
#[Group('database')]
final class SendRemoteBackupArchiveTest extends TestCase
{
    private string $basePath;
    private string $storagePath;
    private Connection $connection;
    private InMemorySettingService $settings;
    private SecretManager $secrets;
    private ?int $declaredLocationId = null;
    private ?int $destinationLocationId = null;
    private StorageLocationRepository $locations;
    private RemoteBackupDestination $destination;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/remote_archive_' . uniqid();
        $this->storagePath = $this->basePath . '/storage';
        foreach (['storage/keys', 'storage/config', 'storage/maintenance', 'core', 'public', 'modules'] as $dir) {
            @mkdir($this->basePath . '/' . $dir, 0755, true);
        }
        file_put_contents($this->basePath . '/core/App.php', '<?php // app');
        file_put_contents($this->basePath . '/public/index.php', '<?php // entry');
        file_put_contents($this->basePath . '/VERSION', "2.4.1\n");

        $this->secrets = new SecretManager(
            $this->storagePath . '/keys/master.key',
            $this->storagePath . '/config/secrets.enc'
        );
        $this->secrets->generateMasterKey();
        $this->secrets->writeSecrets(['smtp_password' => 'le-mot-de-passe-smtp']);

        $this->settings = new InMemorySettingService();
        $this->connection = $this->realConnection();

        $this->locations = new StorageLocationRepository(
            $this->connection->getPdo(),
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->destination = new RemoteBackupDestination(
            $this->settings,
            $this->locations,
            new StorageBackendFactory($this->locations, $this->storagePath)
        );
        // The destination is a declared location now, so the fixture has
        // to declare one — and clean it up, for the reason tearDown()
        // gives about a leftover default.
        $this->destinationLocationId = $this->locations->create(
            StorageLocationType::GoogleDrive,
            'Google Drive ' . uniqid(),
            new GoogleDriveLocationConfig('client-1', 'dossier-1', '2026-03-01T00:00:00+00:00'),
            (string) json_encode([
                'client_secret' => 's',
                'refresh_token' => 'un-jeton-de-rafraichissement',
                'account' => 'unite@example.org',
            ])
        );
        $this->destination->choose($this->destinationLocationId);
    }

    protected function tearDown(): void
    {
        // The location row goes with the fixture, and it has to: this
        // suite shares one database with every other `@group database`
        // test, and a leftover row is a leftover DEFAULT — the next test
        // to ask which location is the default gets this one's answer.
        // Deleted by id and with a plain statement rather than through
        // the repository, whose own delete() promotes a survivor.
        $statement = $this->connection->getPdo()->prepare('DELETE FROM storage_locations WHERE id = ?');
        foreach ([$this->declaredLocationId, $this->destinationLocationId] as $id) {
            if ($id !== null) {
                $statement->execute([$id]);
            }
        }
        $this->declaredLocationId = null;
        $this->destinationLocationId = null;

        $this->removeDirectory($this->basePath);
    }

    /**
     * **The archive that leaves is opened by the phrase the site shows.**
     *
     * Everything else about this feature could work — the OAuth dance,
     * the resumable upload, the retention — and an operator restoring
     * from Drive on the day their server burned down would still be
     * locked out if these two phrases were not the same one. Nothing
     * short of opening the archive proves they are.
     */
    public function testTheArchiveOpensWithThePhraseTheSiteWouldShowItsOperator(): void
    {
        $this->skipWithoutZipEncryption();
        $backend = new RecordingBackend2();

        (new SendRemoteBackupHandler($backend, $this->destination))->handle([], $this->context());

        $this->assertNotSame([], $backend->sent, 'nothing was built, so nothing was sent');
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($backend->sent[0]['path']) === true, 'the archive could not be reopened');

        $phrase = (new RemotePassphrase($this->settings, $this->secrets))->stored();
        $this->assertNotSame('', $phrase, 'the site has no phrase to show, yet it sent an encrypted archive');
        $keys = PortableKeys::derive($phrase, PortableKeys::parseComment($zip->getArchiveComment()));
        $this->assertTrue(
            $zip->setPassword($keys->archivePassword()),
            'the archive refused the password derived from the phrase this site holds'
        );
        $this->assertIsString(
            $zip->getFromName('database.sql'),
            'the archive sent off-site cannot be opened with the phrase the operator would be given'
        );
        $zip->close();
    }

    /** The name says which generation of the phrase opens it. */
    public function testTheNameCarriesThePassphraseGenerationThatOpensIt(): void
    {
        $this->skipWithoutZipEncryption();
        $backend = new RecordingBackend2();

        (new SendRemoteBackupHandler($backend, $this->destination))->handle([], $this->context());
        (new RemotePassphrase($this->settings, $this->secrets))->regenerate();
        (new SendRemoteBackupHandler($backend, $this->destination))->handle([], $this->context());

        $this->assertCount(2, $backend->sent);
        $this->assertMatchesRegularExpression('/^scoutmagic-[\d-]+-g1\.zip$/', $backend->sent[0]['name']);
        $this->assertMatchesRegularExpression(
            '/^scoutmagic-[\d-]+-g2\.zip$/',
            $backend->sent[1]['name'],
            'the second archive is encrypted with a new phrase and named as if the old one opened it'
        );
    }

    /**
     * **The photographs stay behind, and the declaration is why.**
     *
     * A gallery is measured in gibibytes; sent weekly it fills a free
     * Drive in about three weeks, after which nothing leaves the server
     * at all. There used to be a setting for it, defaulting to off. D10
     * removed the question instead of answering it: no archive carries a
     * directory declared as a storage location, and a real installation
     * declares its gallery folder on first run
     * (`StorageLocationService::ensureDefaultExists()`).
     *
     * So this runs the send TWICE against the same site and the same
     * photograph, one declaration apart — which is what makes the
     * absence an exclusion rather than an empty archive, and what would
     * catch a prefix that stopped matching the folder the gallery writes
     * to.
     */
    public function testTheArchiveSentOffSiteCarriesNoDeclaredLocation(): void
    {
        $this->skipWithoutZipEncryption();
        @mkdir($this->storagePath . '/gallery/1', 0755, true);
        @mkdir($this->storagePath . '/uploads', 0755, true);
        file_put_contents($this->storagePath . '/gallery/1/photo.jpg', 'fake-jpeg-bytes');
        file_put_contents($this->storagePath . '/uploads/doc.pdf', 'fake-pdf-bytes');
        $backend = new RecordingBackend2();

        // Nothing declared: the folder is part of the site like any other.
        (new SendRemoteBackupHandler($backend, $this->destination))->handle([], $this->context());
        $this->assertContains(
            'storage/gallery/1/photo.jpg',
            $this->entryNames($backend->sent[0]['path']),
            'an undeclared folder under storage/ is archived like the rest of it'
        );

        $this->declareTheGalleryFolderAsALocation();

        (new SendRemoteBackupHandler($backend, $this->destination))->handle([], $this->context());
        $names = $this->entryNames($backend->sent[1]['path']);

        $this->assertNotContains(
            'storage/gallery/1/photo.jpg',
            $names,
            'a unit\'s photographs left the server in the weekly archive'
        );
        $this->assertContains('storage/uploads/doc.pdf', $names, 'this is not an archive of the site at all');

        // **And the manifest agrees with the bytes.** It is what a restore
        // and an operator read to learn what an archive holds; one saying
        // `includes_gallery: true` over an archive without a photograph in
        // it is worse than no manifest at all, because it is believed.
        $this->assertFalse(
            $this->manifestOf($backend->sent[1]['path'])['includes_gallery'] ?? null,
            'the manifest claims a gallery the archive does not carry'
        );
    }

    /**
     * Declares `storage/gallery` the way a fresh installation does.
     */
    private function declareTheGalleryFolderAsALocation(): void
    {
        $this->declaredLocationId = (new \Core\Storage\Location\StorageLocationRepository(
            $this->connection->getPdo(),
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        ))->create(
            \Core\Storage\Location\StorageLocationType::Local,
            'Galerie',
            new \Core\Storage\Location\Config\LocalLocationConfig('gallery'),
            null
        );
    }

    /**
     * The archive's own manifest, decrypted with the site's phrase.
     *
     * @return array<string, mixed>
     */
    private function manifestOf(string $archivePath): array
    {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($archivePath) === true);
        $phrase = (new RemotePassphrase($this->settings, $this->secrets))->stored();
        $keys = PortableKeys::derive($phrase, PortableKeys::parseComment($zip->getArchiveComment()));
        $this->assertTrue($zip->setPassword($keys->archivePassword()));
        $json = $zip->getFromName(\Core\Maintenance\Portable\PortableManifest::MEMBER);
        $zip->close();

        $decoded = is_string($json) ? json_decode($json, true) : null;
        $this->assertIsArray($decoded, 'the archive carries no readable manifest');

        return $decoded;
    }

    /**
     * @return list<string>
     */
    private function entryNames(string $archivePath): array
    {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($archivePath) === true);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat !== false) {
                $names[] = (string) $stat['name'];
            }
        }
        $zip->close();

        return $names;
    }

    private function skipWithoutZipEncryption(): void
    {
        $service = new BackupService($this->connection, $this->storagePath, $this->basePath);
        if (!$service->supportsZipEncryption()) {
            $this->markTestSkipped('This PHP build has no AES zip encryption, which this feature refuses without.');
        }
    }

    private function realConnection(): Connection
    {
        $connection = new Connection(
            getenv('TEST_DB_HOST') ?: '127.0.0.1',
            (int) (getenv('TEST_DB_PORT') ?: '3306'),
            getenv('TEST_DB_NAME') ?: 'test_db',
            getenv('TEST_DB_USER') ?: 'root',
            getenv('TEST_DB_PASSWORD') ?: ''
        );
        $result = $connection->testConnection();
        if ($result !== true) {
            $this->markTestSkipped('Database not available: ' . (is_string($result) ? $result : 'unknown error'));
        }

        $runner = new MigrationRunner(
            $connection,
            new SchemaIntrospector($connection->getPdo()),
            new SchemaComparator(),
            new SqlParser()
        );
        $runner->migrate([dirname(__DIR__, 4) . '/schema/core.sql']);

        return $connection;
    }

    private function context(): TaskContext
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        return new TaskContext(
            $this->connection,
            $encryption,
            $this->createStub(MailService::class),
            new JournalService(new JournalRepository($this->connection->getPdo())),
            $this->settings,
            new UserAccountRepository($this->connection->getPdo(), $encryption),
            $this->storagePath
        );
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            is_dir($child) ? $this->removeDirectory($child) : @unlink($child);
        }
        @rmdir($path);
    }
}

/**
 * A destination that keeps a copy of what it was handed.
 *
 * It has to keep the BYTES rather than the path: the handler deletes the
 * local archive the instant the send completes, deliberately (it carries
 * the master key), so a test that opened the original would be asserting
 * against a file the feature is supposed to have removed.
 */
final class RecordingBackend2 extends RefusingBackend implements ResumableUploadBackend
{
    /** @var list<array{name: string, path: string}> */
    public array $sent = [];

    private string $keep;

    /** @var array<string, string> */
    private array $inFlight = [];

    public function __construct()
    {
        $this->keep = sys_get_temp_dir() . '/remote_sent_' . uniqid();
        @mkdir($this->keep, 0755, true);
    }

    public function __destruct()
    {
        foreach (glob($this->keep . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->keep);
    }

    public function beginPartial(string $key, int $totalBytes): void
    {
        $this->inFlight[$key] = '';
    }

    public function partialSize(string $key): int
    {
        return strlen($this->inFlight[$key] ?? '');
    }

    public function appendToPartial(string $key, string $chunk): void
    {
        $this->inFlight[$key] = ($this->inFlight[$key] ?? '') . $chunk;
    }

    public function promotePartial(string $key, string $mimeType): void
    {
        $path = $this->keep . '/' . count($this->sent) . '-' . $key;
        file_put_contents($path, $this->inFlight[$key] ?? '');
        unset($this->inFlight[$key]);

        $this->sent[] = ['name' => $key, 'path' => $path];
    }

    public function discardPartial(string $key): void
    {
        unset($this->inFlight[$key]);
    }

    public function list(string $prefix, ?string $cursor = null, int $limit = 1000): \Core\Storage\Location\StorageListing
    {
        return new \Core\Storage\Location\StorageListing([]);
    }

    public function delete(string $key): void
    {
    }
}
