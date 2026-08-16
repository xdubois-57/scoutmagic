<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\File\EncryptedFileStorageService;
use Core\File\FileAccessGuard;
use Core\File\FileRepository;
use Core\Http\Controller\FileController;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Photo\ImageVariantProcessor;
use Core\Photo\ImageVariantService;
use Core\Security\EncryptionService;
use Core\Security\Role;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class FileControllerTest extends TestCase
{
    private \PDO $pdo;
    private FileRepository $fileRepository;
    private FileController $controller;
    private string $storagePath;
    private ImageVariantService $imageVariantService;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            relative_path TEXT NOT NULL,
            original_name TEXT NOT NULL,
            mime_type TEXT NOT NULL,
            size_bytes INTEGER NOT NULL,
            module_id TEXT,
            role_min TEXT NOT NULL DEFAULT "public",
            custom_resolver TEXT,
            encrypted INTEGER NOT NULL DEFAULT 0,
            owner_member_id INTEGER,
            owner_type TEXT,
            owner_id INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by INTEGER
        )');
        $this->pdo->exec('CREATE TABLE event_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            logged_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            user_account_id INTEGER,
            ip_address TEXT,
            category TEXT NOT NULL,
            event_type TEXT NOT NULL,
            level TEXT NOT NULL DEFAULT "info",
            description TEXT NOT NULL,
            context TEXT
        )');
        $this->fileRepository = new FileRepository($this->pdo);
        $this->storagePath = sys_get_temp_dir() . '/file_controller_test_' . uniqid();

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $guard = new FileAccessGuard($this->fileRepository, Role::INTENDANT);
        $storage = new EncryptedFileStorageService($this->fileRepository, $encryption, $this->storagePath);
        $this->imageVariantService = new ImageVariantService($this->fileRepository, new ImageVariantProcessor(), $this->storagePath);

        $this->controller = new FileController(new Environment(new ArrayLoader([])), $guard, $this->storagePath, $storage, $this->imageVariantService);
    }

    public function testThumbnailReturns403WhenAccessDenied(): void
    {
        $id = $this->fileRepository->create('a.pdf', 'a.pdf', 'application/pdf', 100, 'admin', null, null);

        $response = $this->controller->thumbnail(new Request('GET', "/files/{$id}/thumbnail", [], [], [], []), ['id' => (string) $id]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testThumbnailReturns403ForUnknownFile(): void
    {
        $response = $this->controller->thumbnail(new Request('GET', '/files/999/thumbnail', [], [], [], []), ['id' => '999']);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testThumbnailReturns415ForNonPdfMimeType(): void
    {
        $id = $this->fileRepository->create('a.jpg', 'a.jpg', 'image/jpeg', 100, 'intendant', null, null);

        $response = $this->controller->thumbnail(new Request('GET', "/files/{$id}/thumbnail", [], [], [], []), ['id' => (string) $id]);

        $this->assertSame(415, $response->getStatusCode());
    }

    public function testThumbnailReturns404WhenRasterizationFails(): void
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $storage = new EncryptedFileStorageService($this->fileRepository, $encryption, $this->storagePath);
        $fileId = $storage->store('not a real pdf', 'application/pdf', 'a.pdf', 'test', 'intendant');

        $response = $this->controller->thumbnail(new Request('GET', "/files/{$fileId}/thumbnail", [], [], [], []), ['id' => (string) $fileId]);

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * Member page §5 requirement: successful access to an owner-scoped
     * file (member_documents) is journaled — member_id reference only, no
     * personal data.
     */
    public function testServeJournalsAccessToAnOwnerScopedFile(): void
    {
        $guard = new FileAccessGuard($this->fileRepository, Role::IDENTIFIED, [42]);
        $controller = new FileController(new Environment(new ArrayLoader([])), $guard, $this->storagePath, new EncryptedFileStorageService($this->fileRepository, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)), $this->storagePath), $this->imageVariantService);
        $journalRepo = new JournalRepository($this->pdo);
        $controller->setJournalService(new JournalService($journalRepo));

        mkdir($this->storagePath, 0755, true);
        file_put_contents($this->storagePath . '/doc.pdf', 'content');
        $stmt = $this->pdo->prepare('INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min, owner_member_id) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute(['doc.pdf', 'doc.pdf', 'application/pdf', 7, 'identified', 42]);
        $id = (int) $this->pdo->lastInsertId();

        $response = $controller->serve(new Request('GET', "/files/{$id}", [], [], [], []), ['id' => (string) $id]);

        $this->assertSame(200, $response->getStatusCode());
        $stmt = $this->pdo->query("SELECT * FROM event_log WHERE event_type = 'owner_scoped_file_accessed'");
        $entries = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $entries);
        $context = json_decode($entries[0]['context'], true);
        $this->assertSame(42, $context['owner_member_id']);
        $this->assertSame($id, $context['file_id']);
    }

    public function testServeDoesNotJournalAccessToAnOrdinaryFile(): void
    {
        $guard = new FileAccessGuard($this->fileRepository, Role::PUBLIC);
        $controller = new FileController(new Environment(new ArrayLoader([])), $guard, $this->storagePath, new EncryptedFileStorageService($this->fileRepository, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)), $this->storagePath), $this->imageVariantService);
        $journalRepo = new JournalRepository($this->pdo);
        $controller->setJournalService(new JournalService($journalRepo));

        mkdir($this->storagePath, 0755, true);
        file_put_contents($this->storagePath . '/pic.jpg', 'content');
        $stmt = $this->pdo->prepare('INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute(['pic.jpg', 'pic.jpg', 'image/jpeg', 7, 'public']);
        $id = (int) $this->pdo->lastInsertId();

        $response = $controller->serve(new Request('GET', "/files/{$id}", [], [], [], []), ['id' => (string) $id]);

        $this->assertSame(200, $response->getStatusCode());
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM event_log WHERE event_type = 'owner_scoped_file_accessed'");
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    // --- variant() ---

    private function jpegBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagejpeg($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);
        return $bytes;
    }

    private function storeFileWithVariant(string $roleMin, ?int $ownerMemberId = null): int
    {
        $dir = $this->storagePath . '/core/member_photos';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($dir . '/abc123.jpg', $this->jpegBytes(400, 400));

        $stmt = $this->pdo->prepare('INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min, owner_member_id) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute(['core/member_photos/abc123.jpg', 'photo.jpg', 'image/jpeg', 100, $roleMin, $ownerMemberId]);
        $id = (int) $this->pdo->lastInsertId();

        $this->imageVariantService->generate($id, 'thumb');

        return $id;
    }

    public function testVariantServesTheGeneratedDerivative(): void
    {
        $guard = new FileAccessGuard($this->fileRepository, Role::PUBLIC);
        $controller = new FileController(new Environment(new ArrayLoader([])), $guard, $this->storagePath, new EncryptedFileStorageService($this->fileRepository, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)), $this->storagePath), $this->imageVariantService);
        $id = $this->storeFileWithVariant('public');

        $response = $controller->variant(new Request('GET', "/files/{$id}/thumb", [], [], [], []), ['id' => (string) $id, 'variant' => 'thumb']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/webp', $response->getHeaders()['Content-Type']);
        $this->assertStringContainsString('immutable', $response->getHeaders()['Cache-Control']);
    }

    /**
     * DoD boundary: a role below the file's role_min is denied — same
     * 403 as serve().
     */
    public function testVariantReturns403ForARoleBelowRoleMin(): void
    {
        $guard = new FileAccessGuard($this->fileRepository, Role::IDENTIFIED);
        $controller = new FileController(new Environment(new ArrayLoader([])), $guard, $this->storagePath, new EncryptedFileStorageService($this->fileRepository, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)), $this->storagePath), $this->imageVariantService);
        $id = $this->storeFileWithVariant('admin');

        $response = $controller->variant(new Request('GET', "/files/{$id}/thumb", [], [], [], []), ['id' => (string) $id, 'variant' => 'thumb']);

        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * DoD boundary: an owner-scoped file the session isn't linked to is
     * denied even when the role floor alone would pass.
     */
    public function testVariantReturns403ForAnOwnerScopedFileTheSessionDoesNotOwn(): void
    {
        $guard = new FileAccessGuard($this->fileRepository, Role::IDENTIFIED, [999]);
        $controller = new FileController(new Environment(new ArrayLoader([])), $guard, $this->storagePath, new EncryptedFileStorageService($this->fileRepository, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)), $this->storagePath), $this->imageVariantService);
        $id = $this->storeFileWithVariant('identified', 42);

        $response = $controller->variant(new Request('GET', "/files/{$id}/thumb", [], [], [], []), ['id' => (string) $id, 'variant' => 'thumb']);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testVariantAllowsAnOwnerScopedFileTheSessionDoesOwn(): void
    {
        $guard = new FileAccessGuard($this->fileRepository, Role::IDENTIFIED, [42]);
        $controller = new FileController(new Environment(new ArrayLoader([])), $guard, $this->storagePath, new EncryptedFileStorageService($this->fileRepository, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)), $this->storagePath), $this->imageVariantService);
        $id = $this->storeFileWithVariant('identified', 42);

        $response = $controller->variant(new Request('GET', "/files/{$id}/thumb", [], [], [], []), ['id' => (string) $id, 'variant' => 'thumb']);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testVariantReturns404ForAnUnknownVariantNameAndNeverTouchesTheFilesystem(): void
    {
        $guard = new FileAccessGuard($this->fileRepository, Role::PUBLIC);
        $controller = new FileController(new Environment(new ArrayLoader([])), $guard, $this->storagePath, new EncryptedFileStorageService($this->fileRepository, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)), $this->storagePath), $this->imageVariantService);
        $id = $this->storeFileWithVariant('public');

        $response = $controller->variant(new Request('GET', "/files/{$id}/original", [], [], [], []), ['id' => (string) $id, 'variant' => 'original']);

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * A path-traversal attempt in {variant} must 404 without ever being
     * used to build a filesystem path — the fixed-vocabulary check runs
     * before FileAccessGuard is even consulted.
     */
    public function testVariantReturns404ForAPathTraversalAttempt(): void
    {
        $guard = new FileAccessGuard($this->fileRepository, Role::PUBLIC);
        $controller = new FileController(new Environment(new ArrayLoader([])), $guard, $this->storagePath, new EncryptedFileStorageService($this->fileRepository, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)), $this->storagePath), $this->imageVariantService);
        $id = $this->storeFileWithVariant('public');

        $response = $controller->variant(
            new Request('GET', "/files/{$id}/../../../etc/passwd", [], [], [], []),
            ['id' => (string) $id, 'variant' => '../../../etc/passwd']
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testVariantReturns404WhenTheDerivativeWasNeverGenerated(): void
    {
        $guard = new FileAccessGuard($this->fileRepository, Role::PUBLIC);
        $controller = new FileController(new Environment(new ArrayLoader([])), $guard, $this->storagePath, new EncryptedFileStorageService($this->fileRepository, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)), $this->storagePath), $this->imageVariantService);

        $dir = $this->storagePath . '/core/member_photos';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($dir . '/nogen.jpg', $this->jpegBytes(400, 400));
        $stmt = $this->pdo->prepare('INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute(['core/member_photos/nogen.jpg', 'photo.jpg', 'image/jpeg', 100, 'public']);
        $id = (int) $this->pdo->lastInsertId();

        $response = $controller->variant(new Request('GET', "/files/{$id}/thumb", [], [], [], []), ['id' => (string) $id, 'variant' => 'thumb']);

        $this->assertSame(404, $response->getStatusCode());
    }
}
