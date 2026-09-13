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
use Core\Maintenance\Remote\RemoteBackupConnection;
use Core\Maintenance\Remote\RemotePassphrase;
use Core\Maintenance\Task\SendRemoteBackupHandler;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\SecretManager;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Core\Maintenance\Remote\InMemorySettingService;

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
        (new RemoteBackupConnection($this->settings, $this->secrets))
            ->saveConnection('un-jeton-de-rafraichissement', 'unite@example.org', 'dossier-distant');
    }

    protected function tearDown(): void
    {
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
        $target = new RecordingTarget2();

        (new SendRemoteBackupHandler($target))->handle([], $this->context());

        $this->assertNotSame([], $target->sent, 'nothing was built, so nothing was sent');
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($target->sent[0]['path']) === true, 'the archive could not be reopened');

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
        $target = new RecordingTarget2();

        (new SendRemoteBackupHandler($target))->handle([], $this->context());
        (new RemotePassphrase($this->settings, $this->secrets))->regenerate();
        (new SendRemoteBackupHandler($target))->handle([], $this->context());

        $this->assertCount(2, $target->sent);
        $this->assertMatchesRegularExpression('/^scoutmagic-[\d-]+-g1\.zip$/', $target->sent[0]['name']);
        $this->assertMatchesRegularExpression(
            '/^scoutmagic-[\d-]+-g2\.zip$/',
            $target->sent[1]['name'],
            'the second archive is encrypted with a new phrase and named as if the old one opened it'
        );
    }

    /**
     * **The photographs stay behind, unless a unit says otherwise.** A
     * gallery is measured in gibibytes; sent weekly it fills a free Drive
     * in about three weeks, after which nothing leaves the server at all.
     * The setting is the escape hatch for a unit with room to spare, and
     * the default is what protects the one without.
     */
    public function testTheArchiveSentOffSiteLeavesTheGalleryBehindUnlessAskedFor(): void
    {
        $this->skipWithoutZipEncryption();
        @mkdir($this->storagePath . '/gallery/1', 0755, true);
        @mkdir($this->storagePath . '/uploads', 0755, true);
        file_put_contents($this->storagePath . '/gallery/1/photo.jpg', 'fake-jpeg-bytes');
        file_put_contents($this->storagePath . '/uploads/doc.pdf', 'fake-pdf-bytes');
        $target = new RecordingTarget2();

        (new SendRemoteBackupHandler($target))->handle([], $this->context());

        $names = $this->entryNames($target->sent[0]['path']);

        $this->assertNotContains('storage/gallery/1/photo.jpg', $names);
        $this->assertContains('storage/uploads/doc.pdf', $names, 'this is not an archive of the site at all');

        // And the switch is a switch, not a label: the same site, the
        // same photograph, one setting apart.
        $this->settings->values[SendRemoteBackupHandler::INCLUDE_GALLERY_SETTING] = '1';
        (new SendRemoteBackupHandler($target))->handle([], $this->context());
        $this->assertContains(
            'storage/gallery/1/photo.jpg',
            $this->entryNames($target->sent[1]['path']),
            'a unit that asked for its photographs off-site did not get them'
        );

        // **And the manifest agrees with the bytes.** It is what a restore
        // and an operator read to learn what an archive holds; one saying
        // `includes_gallery: false` over gibibytes of photographs is worse
        // than no manifest at all, because it is believed.
        $this->assertTrue(
            $this->manifestOf($target->sent[1]['path'])['includes_gallery'] ?? null,
            'the manifest denies a gallery the archive actually carries'
        );
        $this->assertFalse(
            $this->manifestOf($target->sent[0]['path'])['includes_gallery'] ?? null,
            'the manifest claims a gallery the archive does not carry'
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
 * It has to copy rather than remember the path: the handler deletes the
 * local archive the instant the send completes, deliberately (it carries
 * the master key), so a test that opened the original would be asserting
 * against a file the feature is supposed to have removed.
 */
final class RecordingTarget2 implements \Core\Maintenance\Remote\RemoteBackupTarget
{
    /** @var list<array{name: string, path: string}> */
    public array $sent = [];

    private string $keep;

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

    public function upload(string $localPath, string $remoteName): string
    {
        return 'witness';
    }

    public function beginUpload(string $remoteName, int $size): string
    {
        $this->sent[] = ['name' => $remoteName, 'path' => ''];

        return 'https://upload.example/session-' . count($this->sent);
    }

    public function probeUpload(string $sessionUrl, int $size): \Core\Maintenance\Remote\RemoteUpload
    {
        return \Core\Maintenance\Remote\RemoteUpload::inProgress($sessionUrl, 0);
    }

    public function sendChunks(
        string $sessionUrl,
        string $localPath,
        int $size,
        int $offset,
        \Closure $hasTimeLeft
    ): \Core\Maintenance\Remote\RemoteUpload {
        $last = count($this->sent) - 1;
        $copy = $this->keep . '/' . basename($localPath);
        copy($localPath, $copy);
        $this->sent[$last]['path'] = $copy;

        return \Core\Maintenance\Remote\RemoteUpload::completed($sessionUrl, 'file-' . count($this->sent));
    }

    public function list(): array
    {
        return [];
    }

    public function delete(string $remoteId): void
    {
    }

    public function quota(): ?\Core\Maintenance\Remote\RemoteQuota
    {
        return null;
    }

    public function testConnection(): \Core\Maintenance\Remote\RemoteConnectionCheck
    {
        return \Core\Maintenance\Remote\RemoteConnectionCheck::success('unite@example.org', null);
    }
}
