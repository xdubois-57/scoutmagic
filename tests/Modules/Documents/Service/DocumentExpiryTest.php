<?php

declare(strict_types=1);

namespace Tests\Modules\Documents\Service;

use Core\Config\AppClock;
use Modules\Documents\Service\DocumentException;
use Modules\Documents\Service\DocumentService;
use Modules\Documents\Service\DocumentsAttentionProvider;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Documents\DocumentsTestHelper;

/**
 * #731: the date the list shows is the FILE's, and the validity of a
 * document — two years by default, moved only when somebody moves it or
 * replaces the file — with the attention point it raises once past.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class DocumentExpiryTest extends TestCase
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

    private static function inTwoYears(): string
    {
        return AppClock::now()->modify('+2 years')->format('Y-m-d');
    }

    private function backdateFile(int $fileId): void
    {
        $this->pdo->exec("UPDATE files SET created_at = '2025-01-15 10:00:00' WHERE id = " . $fileId);
    }

    public function testANewDocumentIsValidForTwoYearsUnlessADateIsGiven(): void
    {
        $default = $this->service->create('ROI', null, 'public', DocumentsTestHelper::upload(), null);
        $chosen = $this->service->create('PV', null, 'public', DocumentsTestHelper::upload(), null, '2027-06-30');

        $this->assertSame(self::inTwoYears(), $default->expiresOn);
        $this->assertSame('2027-06-30', $chosen->expiresOn);
    }

    public function testAMetadataEditMovesNeitherTheFileDateNorTheExpiry(): void
    {
        $document = $this->service->create('ROI', null, 'public', DocumentsTestHelper::upload(), null, '2027-06-30');
        $this->backdateFile($document->fileId);

        $edited = $this->service->update($document->id, 'ROI 2026', 'Nouvelle description', 'chief', null, null);

        $this->assertSame('2025-01-15 10:00:00', $edited->fileUpdatedAt);
        $this->assertSame('2027-06-30', $edited->expiresOn);
    }

    public function testMovingTheDateIsEnoughToConfirmADocumentStillValid(): void
    {
        $document = $this->service->create('ROI', null, 'public', DocumentsTestHelper::upload(), null, '2020-01-01');
        $this->assertTrue($document->isExpired(AppClock::now()->format('Y-m-d')));

        $renewed = $this->service->update($document->id, 'ROI', null, 'public', null, null, '2030-01-01');

        $this->assertSame('2030-01-01', $renewed->expiresOn);
        $this->assertFalse($renewed->isExpired(AppClock::now()->format('Y-m-d')));
    }

    public function testReplacingTheFileMovesItsDateAndStartsANewPeriod(): void
    {
        $document = $this->service->create('ROI', null, 'public', DocumentsTestHelper::upload(), null, '2020-01-01');
        $this->backdateFile($document->fileId);

        // The form posts the date it showed, unchanged.
        $replaced = $this->service->update(
            $document->id,
            'ROI',
            null,
            'public',
            DocumentsTestHelper::upload('v2.pdf'),
            null,
            '2020-01-01'
        );

        $this->assertNotSame('2025-01-15 10:00:00', $replaced->fileUpdatedAt);
        $this->assertSame(self::inTwoYears(), $replaced->expiresOn);
    }

    public function testADateSetInTheSameSaveAsAReplacementWins(): void
    {
        $document = $this->service->create('ROI', null, 'public', DocumentsTestHelper::upload(), null, '2020-01-01');

        $replaced = $this->service->update(
            $document->id,
            'ROI',
            null,
            'public',
            DocumentsTestHelper::upload('v2.pdf'),
            null,
            '2026-12-31'
        );

        $this->assertSame('2026-12-31', $replaced->expiresOn);
    }

    public function testAMalformedDateIsRefused(): void
    {
        $this->expectException(DocumentException::class);
        $this->expectExceptionMessage('La date d\'expiration n\'est pas valide.');

        $this->service->create('ROI', null, 'public', DocumentsTestHelper::upload(), null, '31/12/2026');
    }

    /** A row written before the column existed counts two years from its creation. */
    public function testARowWithoutAnExpiryCountsTwoYearsFromItsCreation(): void
    {
        $document = $this->service->create('ROI', null, 'public', DocumentsTestHelper::upload(), null);
        $this->pdo->exec("UPDATE documents SET expires_on = NULL, created_at = '2023-05-02 08:00:00' WHERE id = "
            . $document->id);

        $this->assertSame('2025-05-02', $this->service->findById($document->id)?->expiresOn);
    }

    public function testTheAttentionPointCountsTheExpiredAndGoesWhenNoneIs(): void
    {
        $provider = new DocumentsAttentionProvider($this->service);
        $this->assertSame([], $provider->collect(1));

        $first = $this->service->create('ROI', null, 'public', DocumentsTestHelper::upload(), null, '2020-01-01');
        $points = $provider->collect(1);
        $this->assertCount(1, $points);
        $this->assertSame('1 document est expiré', $points[0]->title);
        $this->assertSame('/admin/documents', $points[0]->actionUrl);

        $this->service->create('PV', null, 'chief', DocumentsTestHelper::upload(), null, '2021-01-01');
        $this->service->create('Charte', null, 'public', DocumentsTestHelper::upload(), null, '2099-01-01');
        $points = $provider->collect(1);
        $this->assertSame('2 documents sont expirés', $points[0]->title);
        $this->assertStringContainsString('Certains documents partagés doivent être vérifiés', $points[0]->why);

        // Still online, and still listed: expiry is not a takedown.
        $this->assertNotNull($this->service->findById($first->id));

        foreach ($this->service->all() as $document) {
            $this->service->update(
                $document->id,
                $document->title,
                null,
                $document->visibility->value,
                null,
                null,
                '2099-01-01'
            );
        }
        $this->assertSame([], $provider->collect(1));
    }
}
