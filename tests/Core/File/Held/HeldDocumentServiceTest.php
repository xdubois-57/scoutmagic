<?php

declare(strict_types=1);

namespace Tests\Core\File\Held;

use Core\File\EncryptedFileStorageService;
use Core\File\FileAccessGuard;
use Core\File\FileRepository;
use Core\File\Held\HeldDocumentRepository;
use Core\File\Held\HeldDocumentService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Core\Security\Role;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The two keys of a document the installed application put aside
 * (issue #502): the browser's works once, without a session, for five
 * minutes; the application's works for the session that caused the hold
 * only; and nothing else opens it — not an invented key, not /files/{id}.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class HeldDocumentServiceTest extends TestCase
{
    private const PDF = "%PDF-1.4\nautorisation parentale\n%%EOF";
    private const SESSION = 'session-of-the-installed-app';

    private \PDO $pdo;
    private string $storagePath;
    private FileRepository $files;
    private HeldDocumentService $service;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->storagePath = sys_get_temp_dir() . '/held_documents_test_' . bin2hex(random_bytes(4));
        $this->files = new FileRepository($this->pdo);
        $this->service = new HeldDocumentService(
            new HeldDocumentRepository($this->pdo),
            new EncryptedFileStorageService(
                $this->files,
                new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)),
                $this->storagePath
            ),
            $this->files,
            new JournalService(new JournalRepository($this->pdo))
        );
        $this->now = new \DateTimeImmutable('2026-09-27 10:00:00');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storagePath)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->storagePath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->storagePath);
        }
    }

    public function testTheFileIsStoredEncryptedAndNeverInClear(): void
    {
        $held = $this->hold();

        $this->assertSame('autorisation.pdf', $held->name);
        $this->assertSame(strlen(self::PDF), $held->sizeBytes);
        $stored = (string) $this->pdo->query('SELECT relative_path FROM files')->fetchColumn();
        $onDisk = (string) file_get_contents($this->storagePath . '/' . $stored);
        $this->assertStringNotContainsString('autorisation parentale', $onDisk, 'The file reached the disk in clear.');
    }

    public function testOnlyTheHashesOfTheKeysAreStored(): void
    {
        $held = $this->hold();

        $row = $this->pdo->query('SELECT * FROM held_documents')->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $dump = implode('|', array_map('strval', $row));
        $this->assertStringNotContainsString($held->browserToken, $dump);
        $this->assertStringNotContainsString($held->appToken, $dump);
        $this->assertStringNotContainsString(self::SESSION, $dump);
        $this->assertSame(hash('sha256', $held->browserToken), $row['browser_token_hash']);
    }

    public function testTheBrowserKeyOpensTheDocumentOnceWithoutASession(): void
    {
        $held = $this->hold();

        $opened = $this->service->openInBrowser($held->browserToken, $this->now->modify('+1 minute'));
        $this->assertNotNull($opened);
        $this->assertSame(self::PDF, $opened->content);
        $this->assertSame('application/pdf', $opened->mimeType);
        $this->assertSame('autorisation.pdf', $opened->name);

        $this->assertNull(
            $this->service->openInBrowser($held->browserToken, $this->now->modify('+2 minutes')),
            'The browser key opened the document a second time.'
        );
    }

    public function testTheBrowserKeyExpiresAfterFiveMinutes(): void
    {
        $held = $this->hold();

        $this->assertNull($this->service->openInBrowser(
            $held->browserToken,
            $this->now->modify('+' . HeldDocumentService::BROWSER_LIFETIME_MINUTES . ' minutes')
        ));
    }

    public function testAnInventedOrMalformedKeyOpensNothing(): void
    {
        $held = $this->hold();
        $at = $this->now->modify('+1 minute');

        $this->assertNull($this->service->openInBrowser(str_repeat('0', 64), $at));
        $this->assertNull($this->service->openInBrowser('../../etc/passwd', $at));
        $this->assertNull($this->service->openInBrowser(strtoupper($held->browserToken), $at));
        $this->assertNull($this->service->openInApp(str_repeat('f', 64), self::SESSION, $at));
        // The application key is not a browser key, and the other way round.
        $this->assertNull($this->service->openInBrowser($held->appToken, $at));
        $this->assertNull($this->service->openInApp($held->browserToken, self::SESSION, $at));
    }

    public function testTheApplicationKeyOpensOnlyForTheSessionThatCausedTheHold(): void
    {
        $held = $this->hold();
        $at = $this->now->modify('+10 minutes');

        $this->assertNull($this->service->openInApp($held->appToken, 'another-session', $at));
        $this->assertNull($this->service->openInApp($held->appToken, '', $at));
        $this->assertSame(self::PDF, $this->service->openInApp($held->appToken, self::SESSION, $at)?->content);
    }

    public function testTheApplicationKeyNeverSpendsTheBrowserKey(): void
    {
        $held = $this->hold();
        $at = $this->now->modify('+1 minute');

        // The preview and the download: as many times as the page needs.
        $this->assertNotNull($this->service->openInApp($held->appToken, self::SESSION, $at));
        $this->assertNotNull($this->service->openInApp($held->appToken, self::SESSION, $at));

        $this->assertNotNull($this->service->openInBrowser($held->browserToken, $at));
    }

    public function testTheApplicationKeyExpiresWithTheDocument(): void
    {
        $held = $this->hold();

        $this->assertNull($this->service->openInApp(
            $held->appToken,
            self::SESSION,
            $this->now->modify('+' . HeldDocumentService::LIFETIME_MINUTES . ' minutes')
        ));
    }

    public function testFilesRouteRefusesAHeldDocumentEvenToASuperAdministrator(): void
    {
        $this->hold();
        $fileId = (int) $this->pdo->query('SELECT file_id FROM held_documents')->fetchColumn();

        $guard = new FileAccessGuard($this->files, Role::SUPERADMIN, [], []);

        $this->assertNull($guard->check($fileId), '/files/{id} would serve a held document.');
    }

    public function testThePurgeDeletesExpiredDocumentsOpenedOrNotAndKeepsTheOthers(): void
    {
        $opened = $this->hold();
        $this->service->openInBrowser($opened->browserToken, $this->now->modify('+1 minute'));
        $this->hold();
        $later = $this->hold($this->now->modify('+20 minutes'));

        $deleted = $this->service->purgeExpired($this->now->modify('+' . HeldDocumentService::LIFETIME_MINUTES . ' minutes'));

        $this->assertSame(2, $deleted);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM held_documents')->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());
        $this->assertCount(1, glob($this->storagePath . '/' . HeldDocumentService::DIRECTORY . '/*') ?: []);
        $this->assertNotNull($this->service->openInApp($later->appToken, self::SESSION, $this->now->modify('+31 minutes')));
    }

    public function testCreationAndOpeningAreJournalledWithoutTheKeysOrTheContent(): void
    {
        $held = $this->hold();
        $this->service->openInBrowser($held->browserToken, $this->now->modify('+1 minute'));

        $rows = $this->pdo->query('SELECT event_type, description, context FROM event_log ORDER BY id')
            ->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertSame(['held_document_created', 'held_document_opened'], array_column($rows, 'event_type'));

        $journal = json_encode($rows, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($held->browserToken, $journal);
        $this->assertStringNotContainsString($held->appToken, $journal);
        $this->assertStringNotContainsString('autorisation parentale', $journal);
    }

    private function hold(?\DateTimeImmutable $at = null): \Core\File\Held\HeldDocument
    {
        return $this->service->hold(self::PDF, 'application/pdf', 'autorisation.pdf', self::SESSION, null, $at ?? $this->now);
    }
}
