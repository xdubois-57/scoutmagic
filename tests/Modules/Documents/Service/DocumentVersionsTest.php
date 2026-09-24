<?php

declare(strict_types=1);

namespace Tests\Modules\Documents\Service;

use Core\File\FileAccessGuard;
use Core\File\FileRepository;
use Core\Security\Role;
use Modules\Documents\File\DirectLinkGrants;
use Modules\Documents\File\DocumentFileOwnershipChecker;
use Modules\Documents\Repository\Document;
use Modules\Documents\Repository\DocumentRepository;
use Modules\Documents\Service\DocumentService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Documents\DocumentsTestHelper;

/**
 * IT-02: replacing a document's file keeps the address, turns the
 * outgoing file into a past version only the Staff d'U can read, and
 * keeps five of them.
 */
final class DocumentVersionsTest extends TestCase
{
    private \PDO $pdo;
    private string $storage;
    private DocumentService $service;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        DocumentsTestHelper::createTables($this->pdo);
        $this->storage = DocumentsTestHelper::storage();
        $this->service = DocumentsTestHelper::service($this->pdo, $this->storage);
    }

    protected function tearDown(): void
    {
        DocumentsTestHelper::removeTree($this->storage);
    }

    public function testReplacingTheFileMakesTheOldOneAPastVersion(): void
    {
        $document = $this->service->create('ROI', null, 'public', DocumentsTestHelper::upload(), null);

        $updated = $this->replace($document, 'roi-v2.pdf');

        $this->assertSame($document->slug, $updated->slug);
        $this->assertSame('roi-v2.pdf', $updated->originalName);
        $this->assertSame(2, $updated->versionNumber);
        $this->assertSame('public', DocumentsTestHelper::fileRoleMin($this->pdo, $updated->fileId));

        $versions = $this->service->versionsByDocument()[$document->id] ?? [];
        $this->assertCount(1, $versions);
        $this->assertSame(1, $versions[0]->versionNumber);
        $this->assertSame($document->fileId, $versions[0]->fileId);
        $this->assertSame($document->sizeBytes, $versions[0]->sizeBytes);
        $this->assertFileExists($this->storedPath($document->fileId));
    }

    public function testAPastVersionIsNoLongerDownloadableBelowTheStaffEvenOnAPublicDocument(): void
    {
        $document = $this->service->create('ROI', null, 'public', DocumentsTestHelper::upload(), null);
        $this->replace($document);

        $this->assertSame('admin', DocumentsTestHelper::fileRoleMin($this->pdo, $document->fileId));
        foreach ([Role::PUBLIC, Role::IDENTIFIED, Role::INTENDANT, Role::CHIEF] as $role) {
            $this->assertNull($this->guard($role)->check($document->fileId), $role->value);
        }
        $this->assertNotNull($this->guard(Role::ADMIN)->check($document->fileId));
    }

    public function testAPastVersionOfAnUnlistedDocumentIsClosedToo(): void
    {
        $document = $this->service->create('PV', null, 'direct_link', DocumentsTestHelper::upload(), null);
        $this->replace($document);

        $this->assertSame('admin', DocumentsTestHelper::fileRoleMin($this->pdo, $document->fileId));
    }

    public function testTheStableAddressFollowsTheCurrentVersion(): void
    {
        $document = $this->service->create('Matériel', null, 'public', DocumentsTestHelper::upload(), null);
        $second = $this->replace($document);
        $third = $this->replace($second);

        $this->assertSame($third->fileId, $this->service->findBySlug($document->slug)?->fileId);
        $this->assertSame(3, $third->versionNumber);
    }

    public function testTheSixthReplacementDeletesTheFirstVersionAndItsFile(): void
    {
        $document = $this->service->create('Matériel', null, 'public', DocumentsTestHelper::upload(), null);
        $firstFile = $document->fileId;
        $firstPath = $this->storedPath($firstFile);

        $current = $document;
        for ($i = 1; $i <= 5; $i++) {
            $current = $this->replace($current);
        }
        $this->assertCount(5, $this->service->versionsByDocument()[$document->id]);
        $this->assertFileExists($firstPath);

        $current = $this->replace($current);

        $versions = $this->service->versionsByDocument()[$document->id];
        $this->assertCount(DocumentService::KEPT_VERSIONS, $versions);
        $this->assertSame([6, 5, 4, 3, 2], array_map(static fn($v) => $v->versionNumber, $versions));
        $this->assertSame(7, $current->versionNumber);
        $this->assertNull(DocumentsTestHelper::fileRoleMin($this->pdo, $firstFile));
        $this->assertFileDoesNotExist($firstPath);
        $this->assertSame(1, $this->journalEntries('document_version_deleted'));
        $this->assertSame(6, $this->journalEntries('document_file_replaced'));
    }

    public function testDeletingADocumentDeletesItsVersionsAndTheirFiles(): void
    {
        $document = $this->service->create('À jeter', null, 'public', DocumentsTestHelper::upload(), null);
        $second = $this->replace($document);
        $third = $this->replace($second);
        $paths = array_map(fn(int $id) => $this->storedPath($id), [$document->fileId, $second->fileId, $third->fileId]);

        $this->service->delete($document->id, null);

        $this->assertSame([], $this->service->versionsByDocument());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());
        foreach ($paths as $path) {
            $this->assertFileDoesNotExist($path);
        }
    }

    public function testEditingWithoutAFileMakesNoVersion(): void
    {
        $document = $this->service->create('Titre', null, 'public', DocumentsTestHelper::upload(), null);

        $updated = $this->service->update($document->id, 'Autre titre', null, 'chief', DocumentsTestHelper::noUpload(), null);

        $this->assertSame(1, $updated->versionNumber);
        $this->assertSame([], $this->service->versionsByDocument());
    }

    /** The guard as production builds it, with the module's checker. */
    private function guard(Role $role): FileAccessGuard
    {
        return new FileAccessGuard(
            new FileRepository($this->pdo),
            $role,
            [],
            [new DocumentFileOwnershipChecker(new DocumentRepository($this->pdo), new DirectLinkGrants())]
        );
    }

    private function replace(Document $document, string $name = 'nouveau.pdf'): Document
    {
        return $this->service->update(
            $document->id,
            $document->title,
            $document->description,
            $document->visibility->value,
            DocumentsTestHelper::upload($name),
            null
        );
    }

    private function journalEntries(string $eventType): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM event_log WHERE event_type = ?');
        $stmt->execute([$eventType]);
        return (int) $stmt->fetchColumn();
    }

    private function storedPath(int $fileId): string
    {
        $stmt = $this->pdo->prepare('SELECT relative_path FROM files WHERE id = ?');
        $stmt->execute([$fileId]);
        $relative = $stmt->fetchColumn();
        $this->assertIsString($relative);
        return $this->storage . '/' . $relative;
    }
}
