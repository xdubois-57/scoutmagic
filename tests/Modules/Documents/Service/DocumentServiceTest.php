<?php

declare(strict_types=1);

namespace Tests\Modules\Documents\Service;

use Core\File\UploadException;
use Modules\Documents\Service\DocumentException;
use Modules\Documents\Service\DocumentService;
use Modules\Documents\Service\DocumentVisibility;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Documents\DocumentsTestHelper;

final class DocumentServiceTest extends TestCase
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

    public function testCreateStoresTheFileWithTheVisibilityAsItsRole(): void
    {
        $document = $this->service->create('Règlement d\'ordre intérieur', 'À lire.', 'chief', DocumentsTestHelper::upload(), null);

        $this->assertSame('reglement-d-ordre-interieur', $document->slug);
        $this->assertSame(DocumentVisibility::CHIEF, $document->visibility);
        $this->assertSame('À lire.', $document->description);
        $this->assertSame('chief', DocumentsTestHelper::fileRoleMin($this->pdo, $document->fileId));
        $this->assertSame('application/pdf', $document->mimeType);
    }

    public function testDirectLinkFileIsReadableByAnyoneHoldingTheAddress(): void
    {
        $document = $this->service->create('PV AG', null, 'direct_link', DocumentsTestHelper::upload(), null);

        $this->assertSame('public', DocumentsTestHelper::fileRoleMin($this->pdo, $document->fileId));
    }

    public function testDirectLinkSlugCarriesTwelveRandomHexCharacters(): void
    {
        $first = $this->service->create('PV AG 2026', null, 'direct_link', DocumentsTestHelper::upload(), null);
        $second = $this->service->create('PV AG 2026', null, 'direct_link', DocumentsTestHelper::upload(), null);

        $this->assertMatchesRegularExpression('/^pv-ag-2026-[0-9a-f]{12}$/', $first->slug);
        $this->assertTrue($first->slugIsRandom);
        $this->assertNotSame($first->slug, $second->slug);
    }

    public function testListedSlugCollisionGetsANumericSuffix(): void
    {
        $first = $this->service->create('Calendrier', null, 'public', DocumentsTestHelper::upload(), null);
        $second = $this->service->create('Calendrier', null, 'public', DocumentsTestHelper::upload(), null);
        $third = $this->service->create('Calendrier', null, 'identified', DocumentsTestHelper::upload(), null);

        $this->assertSame(['calendrier', 'calendrier-2', 'calendrier-3'], [$first->slug, $second->slug, $third->slug]);
    }

    public function testTitleWithoutLettersFallsBackToAGenericSlug(): void
    {
        $document = $this->service->create('???', null, 'public', DocumentsTestHelper::upload(), null);

        $this->assertSame('document', $document->slug);
    }

    public function testCreateRequiresAFile(): void
    {
        $this->expectException(DocumentException::class);
        $this->expectExceptionMessage('Choisissez le fichier à partager.');

        $this->service->create('Sans fichier', null, 'public', DocumentsTestHelper::noUpload(), null);
    }

    public function testCreateRequiresATitle(): void
    {
        $this->expectException(DocumentException::class);

        $this->service->create('   ', null, 'public', DocumentsTestHelper::upload(), null);
    }

    public function testCreateRejectsAnUnknownVisibility(): void
    {
        $this->expectException(DocumentException::class);

        $this->service->create('Titre', null, 'superadmin', DocumentsTestHelper::upload(), null);
    }

    public function testCreateRejectsAnExecutable(): void
    {
        // UploadHandler's own refusal, user-facing as it is: never
        // re-wrapped into a DocumentException (AGENTS.md).
        $this->expectException(UploadException::class);

        $this->service->create('Script', null, 'public', DocumentsTestHelper::upload('x.sh', "#!/bin/sh\necho hi\n\x00\x01\x02"), null);
    }

    public function testCreateIsJournalledWithIdsOnly(): void
    {
        $document = $this->service->create('Secret de titre', null, 'public', DocumentsTestHelper::upload(), null);

        $row = $this->pdo->query("SELECT * FROM event_log WHERE event_type = 'document_created'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame('documents', $row['category']);
        $this->assertStringNotContainsString('Secret de titre', (string) json_encode($row));
        $this->assertStringContainsString((string) $document->id, (string) $row['context']);
    }

    public function testUpdateKeepsTheSlugWhenTheTitleChanges(): void
    {
        $document = $this->service->create('Calendrier 2025', null, 'public', DocumentsTestHelper::upload(), null);

        $updated = $this->service->update($document->id, 'Calendrier 2026', 'Nouveau', 'public', null, null);

        $this->assertSame('calendrier-2025', $updated->slug);
        $this->assertSame('Calendrier 2026', $updated->title);
        $this->assertSame('Nouveau', $updated->description);
    }

    public function testVisibilityChangeWithoutAFileFollowsOnTheFile(): void
    {
        $document = $this->service->create('Liste', null, 'public', DocumentsTestHelper::upload(), null);

        $this->service->update($document->id, 'Liste', null, 'admin', DocumentsTestHelper::noUpload(), null);

        $this->assertSame('admin', DocumentsTestHelper::fileRoleMin($this->pdo, $document->fileId));
    }

    public function testReplacingTheFileKeepsTheAddressAndRemovesTheOldFile(): void
    {
        $document = $this->service->create('ROI', null, 'public', DocumentsTestHelper::upload(), null);
        $oldPath = $this->storedPath($document->fileId);
        $this->assertFileExists($oldPath);

        $updated = $this->service->update($document->id, 'ROI', null, 'identified', DocumentsTestHelper::upload('roi-v2.pdf'), null);

        $this->assertSame($document->slug, $updated->slug);
        $this->assertNotSame($document->fileId, $updated->fileId);
        $this->assertSame('roi-v2.pdf', $updated->originalName);
        $this->assertSame('identified', DocumentsTestHelper::fileRoleMin($this->pdo, $updated->fileId));
        $this->assertNull(DocumentsTestHelper::fileRoleMin($this->pdo, $document->fileId));
        $this->assertFileDoesNotExist($oldPath);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM event_log WHERE event_type = 'document_file_replaced'")->fetchColumn());
    }

    public function testDeleteRemovesTheRowAndTheFile(): void
    {
        $document = $this->service->create('À jeter', null, 'public', DocumentsTestHelper::upload(), null);
        $path = $this->storedPath($document->fileId);

        $this->service->delete($document->id, null);

        $this->assertNull($this->service->findById($document->id));
        $this->assertNull(DocumentsTestHelper::fileRoleMin($this->pdo, $document->fileId));
        $this->assertFileDoesNotExist($path);
    }

    public function testDeleteOfAnUnknownDocumentIsRefused(): void
    {
        $this->expectException(DocumentException::class);

        $this->service->delete(999, null);
    }

    public function testReorderIsTheOrderOfThePublicPage(): void
    {
        $a = DocumentsTestHelper::create($this->service, 'A', DocumentVisibility::PUBLIC);
        $b = DocumentsTestHelper::create($this->service, 'B', DocumentVisibility::PUBLIC);
        $c = DocumentsTestHelper::create($this->service, 'C', DocumentVisibility::PUBLIC);

        $this->service->reorder([$c, $a, $b]);

        $titles = array_map(static fn ($d) => $d->title, $this->service->all());
        $this->assertSame(['C', 'A', 'B'], $titles);
    }

    public function testFindBySlugResolvesTheFrozenAddress(): void
    {
        $document = $this->service->create('Plan d\'accès', null, 'public', DocumentsTestHelper::upload(), null);

        $this->assertSame($document->id, $this->service->findBySlug('plan-d-acces')?->id);
        $this->assertNull($this->service->findBySlug('inconnu'));
    }

    private function storedPath(int $fileId): string
    {
        $stmt = $this->pdo->prepare('SELECT relative_path FROM files WHERE id = ?');
        $stmt->execute([$fileId]);
        $relative = $stmt->fetchColumn();
        return $this->storage . '/' . $relative;
    }
}
