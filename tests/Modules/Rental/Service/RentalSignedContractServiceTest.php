<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\File\FileRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Pdf\DocumentPdfService;
use Core\Pdf\PdfCompressor;
use Core\Security\EncryptionService;
use Core\Security\HtmlSanitizer;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Document\DocumentType;
use Modules\Rental\Payment\PaymentSettings;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalDocumentRepository;
use Modules\Rental\Repository\RentalManagerSignatureRepository;
use Modules\Rental\Service\RentalBookingMailService;
use Modules\Rental\Service\RentalDocumentService;
use Modules\Rental\Service\RentalException;
use Modules\Rental\Service\RentalSignedContractService;
use PHPUnit\Framework\TestCase;
use setasign\Fpdi\Fpdi;
use Tests\DatabaseTestHelper;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * The contract's two signatures (#708, IT-16): the renter's copy, the
 * manager's answer, and the contract signed by both parties.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RentalSignedContractServiceTest extends TestCase
{
    private \PDO $pdo;
    private string $storagePath;
    private RentalDocumentService $documents;
    private RentalDocumentRepository $documentRepository;
    private RentalManagerSignatureRepository $signatures;
    private RentalBookingRepository $bookingRepository;
    private RentalAssetRepository $assetRepository;
    private FileRepository $fileRepository;
    private RentalSignedContractService $service;
    private int $assetId;

    /** @var list<array{kind: string, booking_id: int, reason?: string, path?: string}> */
    private array $mails = [];

    /** Whether the refusal e-mail goes out; a test turns it off. */
    private bool $refusalMailSucceeds = true;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->storagePath = sys_get_temp_dir() . '/rental_signed_' . bin2hex(random_bytes(6));
        mkdir($this->storagePath . '/rental/documents', 0755, true);

        $settings = new SettingService(new SettingRepository($this->pdo));
        $settings->register('site_name', 'Unité Saint-Georges', 'text', 'Nom', 'Description de test.');
        RentalTestHelper::registerSettings($settings);

        $journal = new JournalService(new JournalRepository($this->pdo));
        $this->assetRepository = new RentalAssetRepository($this->pdo, $encryption);
        $this->bookingRepository = new RentalBookingRepository($this->pdo, $encryption);
        $this->documentRepository = new RentalDocumentRepository($this->pdo);
        $this->fileRepository = new FileRepository($this->pdo);
        $this->signatures = new RentalManagerSignatureRepository($this->pdo, $encryption);
        $editable = new EditableContentService(new EditableContentRepository($this->pdo));

        $this->documents = new RentalDocumentService(
            $this->documentRepository,
            $this->bookingRepository,
            RentalTestHelper::bookingAudit($this->pdo, $encryption),
            $editable,
            $this->fileRepository,
            new \Core\File\AttachedFileRemover($this->fileRepository, $this->storagePath),
            new DocumentPdfService(),
            new HtmlSanitizer(),
            $settings,
            $journal,
            $this->storagePath
        );

        $mail = $this->createStub(RentalBookingMailService::class);
        $mail->method('sendCopyRefused')->willReturnCallback(
            function (RentalBooking $booking, RentalAsset $asset, string $reason): bool {
                $this->mails[] = ['kind' => 'refused', 'booking_id' => $booking->id, 'reason' => $reason];

                return $this->refusalMailSucceeds;
            }
        );
        $mail->method('sendSignedContract')->willReturnCallback(
            function (RentalBooking $booking, RentalAsset $asset, string $path): string {
                $this->mails[] = ['kind' => 'signed', 'booking_id' => $booking->id, 'path' => $path];

                return '<id@test>';
            }
        );

        $this->service = new RentalSignedContractService(
            $this->documents,
            $this->documentRepository,
            $this->signatures,
            RentalTestHelper::bookingAudit($this->pdo, $encryption),
            $mail,
            new PdfCompressor($this->storagePath . '/temp'),
            $journal
        );

        $this->assetId = $this->assetRepository->create(
            'Local',
            'Local Saint-Georges',
            'local-saint-georges',
            60,
            1,
            '18:00',
            '11:00',
            null,
            true
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storagePath . '/rental/documents/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storagePath . '/rental/documents');
        @rmdir($this->storagePath . '/rental');
        @rmdir($this->storagePath . '/temp');
        @rmdir($this->storagePath);
    }

    // ── Fixtures ────────────────────────────────────────────────────────

    private function asset(): RentalAsset
    {
        $asset = $this->assetRepository->findById($this->assetId);
        $this->assertNotNull($asset);

        return $asset;
    }

    private function booking(bool $contractSent = true): RentalBooking
    {
        $created = $this->bookingRepository->create(
            $this->assetId,
            'LOC-K7Q2M4',
            '2027-07-01',
            '2027-07-04',
            1,
            20,
            null,
            [
                'name' => 'Jeanne Martin',
                'email' => 'jeanne@example.be',
                'phone' => null,
                'organisation' => null,
                'purpose' => null,
                'comment' => null,
            ],
            null,
            null,
            null,
            'v1',
            str_repeat('0', 64),
            'v1',
            str_repeat('0', 64),
            new \DateTimeImmutable('2027-01-01 10:00:00')
        );
        $booking = $this->bookingRepository->findById($created['id']);
        $this->assertNotNull($booking);

        $contract = $this->documents->generate($booking, $this->asset(), DocumentType::CONTRACT, new PaymentSettings());
        if ($contractSent) {
            $this->documents->markSent($contract->id, new \DateTimeImmutable('2027-01-02 10:00:00'));
        }

        return $booking;
    }

    /** A file the renter sent, stored as the upload handler would. */
    private function storedFile(string $bytes, string $extension, string $mime): int
    {
        $relative = 'rental/documents/' . bin2hex(random_bytes(8)) . '.' . $extension;
        file_put_contents($this->storagePath . '/' . $relative, $bytes);

        return $this->fileRepository->create($relative, 'copie.' . $extension, $mime, strlen($bytes), 'identified', 'rental', null);
    }

    /** A two-page PDF, as a renter's scan might be. */
    private static function twoPagePdf(): string
    {
        $pdf = new \FPDF();
        $pdf->SetFont('Helvetica', '', 12);
        foreach (['Page 1 signée', 'Page 2 signée'] as $text) {
            $pdf->AddPage();
            $pdf->Text(20, 20, $text);
        }

        return (string) $pdf->Output('S');
    }

    private static function photo(int $width = 800, int $height = 600): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    private static function signaturePng(): string
    {
        $image = imagecreatetruecolor(300, 100);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private static function pageCount(string $path): int
    {
        return (new Fpdi())->setSourceFile($path);
    }

    private function account(): int
    {
        $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)')
            ->execute(['x', bin2hex(random_bytes(8))]);

        return (int) $this->pdo->lastInsertId();
    }

    // ── 2. The renter's copy ────────────────────────────────────────────

    public function testNoCopyIsExpectedBeforeTheContractHasGone(): void
    {
        $booking = $this->booking(false);

        $this->assertFalse($this->service->acceptsCopy($booking));
        $this->expectException(RentalException::class);
        $this->service->receiveCopy($booking, $this->asset(), $this->storedFile(self::photo(), 'jpg', 'image/jpeg'));
    }

    public function testACopyIsFiledAndWaitsForAnAnswer(): void
    {
        $booking = $this->booking();

        $copy = $this->service->receiveCopy($booking, $this->asset(), $this->storedFile(self::photo(), 'jpg', 'image/jpeg'));

        $this->assertSame(DocumentType::SIGNED_COPY, $copy->type);
        $this->assertSame($copy->id, $this->service->pendingCopy($booking->id)?->id);
        // A second one waits for the first to be answered.
        $this->assertFalse($this->service->acceptsCopy($booking));
        $this->assertStringContainsString('en cours de vérification', (string) $this->service->whyNoCopy($booking));
    }

    // ── 3. The manager's answer ─────────────────────────────────────────

    public function testARefusalNeedsAReasonOfAtMostThreeHundredCharacters(): void
    {
        $booking = $this->booking();
        $copy = $this->service->receiveCopy($booking, $this->asset(), $this->storedFile(self::photo(), 'jpg', 'image/jpeg'));

        foreach (['   ', str_repeat('a', 301)] as $reason) {
            try {
                $this->service->refuseCopy($booking, $this->asset(), $copy->id, $reason, null, new \DateTimeImmutable());
                $this->fail('a refusal without a usable reason went through');
            } catch (RentalException) {
            }
        }

        $this->assertNotNull($this->service->pendingCopy($booking->id));
        $this->assertSame([], $this->mails);
    }

    public function testARefusedCopyStaysOnFileTheRenterIsToldWhyAndMaySendAnother(): void
    {
        $booking = $this->booking();
        $copy = $this->service->receiveCopy($booking, $this->asset(), $this->storedFile(self::photo(), 'jpg', 'image/jpeg'));

        $this->service->refuseCopy($booking, $this->asset(), $copy->id, 'La deuxième page n\'est pas signée.', null, new \DateTimeImmutable());

        $refused = $this->documentRepository->findById($copy->id);
        $this->assertTrue($refused?->isRefused());
        $this->assertSame('La deuxième page n\'est pas signée.', $refused?->refusalReason);
        $this->assertSame([['kind' => 'refused', 'booking_id' => $booking->id, 'reason' => 'La deuxième page n\'est pas signée.']], $this->mails);
        $this->assertNull($this->service->pendingCopy($booking->id));
        $this->assertTrue($this->service->acceptsCopy($booking));

        // Refused once: a second refusal of the same copy is not a second e-mail.
        $this->expectException(RentalException::class);
        $this->service->refuseCopy($booking, $this->asset(), $copy->id, 'Encore.', null, new \DateTimeImmutable());
    }

    /**
     * A copy refused, a second one countersigned: the refusal is no longer
     * news. Neither side is told « send another » once both have signed.
     */
    public function testARefusalIsNoLongerSaidOnceTheContractIsSigned(): void
    {
        $booking = $this->booking();
        $first = $this->service->receiveCopy($booking, $this->asset(), $this->storedFile(self::photo(), 'jpg', 'image/jpeg'));
        $this->service->refuseCopy($booking, $this->asset(), $first->id, 'Illisible.', null, new \DateTimeImmutable());
        $this->assertSame($first->id, $this->service->lastRefusedCopy($booking->id)?->id);

        $second = $this->service->receiveCopy($booking, $this->asset(), $this->storedFile(self::photo(), 'jpg', 'image/jpeg'));
        $account = $this->account();
        $this->signatures->save($account, self::signaturePng(), new \DateTimeImmutable());
        $now = new \DateTimeImmutable();
        $this->service->countersign($booking, $this->asset(), $second->id, $account, null, 'Xavier Dubois', $now);

        $this->assertNull($this->service->lastRefusedCopy($booking->id));
    }

    /**
     * The refusal stands when its e-mail does not leave — the renter's page
     * says it — and the caller is told, so the manager can be warned.
     */
    public function testARefusalWhoseEmailFailsStandsAndSaysSo(): void
    {
        $booking = $this->booking();
        $photo = $this->storedFile(self::photo(), 'jpg', 'image/jpeg');
        $copy = $this->service->receiveCopy($booking, $this->asset(), $photo);
        $this->refusalMailSucceeds = false;

        $now = new \DateTimeImmutable();
        $mailed = $this->service->refuseCopy($booking, $this->asset(), $copy->id, 'Illisible.', null, $now);

        $this->assertFalse($mailed);
        $this->assertTrue($this->documentRepository->findById($copy->id)?->isRefused());
    }

    /**
     * A copy that cannot be assembled is left exactly as it was: nothing is
     * filed, and it can still be answered — no half-answered copy.
     */
    public function testACountersignatureThatFailsLeavesTheCopyWaiting(): void
    {
        $booking = $this->booking();
        $photo = $this->storedFile(self::photo(), 'jpg', 'image/jpeg');
        $copy = $this->service->receiveCopy($booking, $this->asset(), $photo);
        $account = $this->account();
        $this->signatures->save($account, self::signaturePng(), new \DateTimeImmutable());
        $path = $this->documents->absolutePath($copy);
        $this->assertIsString($path);
        unlink($path);

        try {
            $now = new \DateTimeImmutable();
            $this->service->countersign($booking, $this->asset(), $copy->id, $account, null, 'Xavier Dubois', $now);
            $this->fail('a copy whose file is gone cannot be countersigned');
        } catch (RentalException) {
        }

        $this->assertNull($this->service->finalContract($booking->id));
        $this->assertSame($copy->id, $this->service->pendingCopy($booking->id)?->id);
    }

    public function testCountersigningNeedsTheManagersOwnSignature(): void
    {
        $booking = $this->booking();
        $copy = $this->service->receiveCopy($booking, $this->asset(), $this->storedFile(self::photo(), 'jpg', 'image/jpeg'));
        $mine = $this->account();
        $other = $this->account();
        // Another manager's signature is no use to this one.
        $this->signatures->save($other, self::signaturePng(), new \DateTimeImmutable());

        $this->expectException(RentalException::class);
        $this->expectExceptionMessage('Enregistrez d\'abord votre signature');
        $this->service->countersign($booking, $this->asset(), $copy->id, $mine, null, 'Xavier Dubois', new \DateTimeImmutable());
    }

    /**
     * The renter's pages, kept, and ONE page added at the end — never a
     * signature placed on a page whose layout nobody here can know.
     */
    /**
     * A copy signed from a contract the booking has outgrown (#708, IT-20)
     * is answered by nobody — a page opened before the change still posts
     * its id, and a countersignature would file a contract on void terms.
     */
    public function testAReplacedCopyCanNeitherBeCountersignedNorRefused(): void
    {
        $booking = $this->booking();
        $copy = $this->service->receiveCopy($booking, $this->asset(), $this->storedFile(self::twoPagePdf(), 'pdf', 'application/pdf'));
        $account = $this->account();
        $this->signatures->save($account, self::signaturePng(), new \DateTimeImmutable());
        $this->documentRepository->markSuperseded([$copy->id], new \DateTimeImmutable());

        try {
            $this->service->countersign($booking, $this->asset(), $copy->id, $account, null, 'Xavier Dubois', new \DateTimeImmutable());
            $this->fail('a replaced copy was countersigned');
        } catch (RentalException $e) {
            $this->assertStringContainsString('remplacée', $e->getMessage());
        }
        try {
            $this->service->refuseCopy($booking, $this->asset(), $copy->id, 'Illisible.', null, new \DateTimeImmutable());
            $this->fail('a replaced copy was refused');
        } catch (RentalException $e) {
            $this->assertStringContainsString('remplacée', $e->getMessage());
        }

        $this->assertNull($this->service->finalContract($booking->id));
        $this->assertSame([], $this->mails);
    }

    public function testCountersigningAPdfKeepsItsPagesAndAddsOne(): void
    {
        $booking = $this->booking();
        $copy = $this->service->receiveCopy($booking, $this->asset(), $this->storedFile(self::twoPagePdf(), 'pdf', 'application/pdf'));
        $account = $this->account();
        $this->signatures->save($account, self::signaturePng(), new \DateTimeImmutable());

        $final = $this->service->countersign($booking, $this->asset(), $copy->id, $account, null, 'Xavier Dubois', new \DateTimeImmutable('2027-01-05 12:00:00'));

        $this->assertSame(DocumentType::SIGNED_CONTRACT, $final->type);
        $path = $this->documents->absolutePath($final);
        $this->assertIsString($path);
        $this->assertSame(3, self::pageCount($path));
        $this->assertSame($final->id, $this->service->finalContract($booking->id)?->id);
        $this->assertNull($this->service->pendingCopy($booking->id));

        // Mailed to the renter, and marked as gone.
        $this->assertSame('signed', $this->mails[0]['kind']);
        $this->assertNotNull($this->documentRepository->findById($final->id)?->sentAt);
    }

    public function testCountersigningAPhotoTurnsItIntoAPageAndAddsOne(): void
    {
        $booking = $this->booking();
        $copy = $this->service->receiveCopy($booking, $this->asset(), $this->storedFile(self::photo(), 'jpg', 'image/jpeg'));
        $account = $this->account();
        $this->signatures->save($account, self::signaturePng(), new \DateTimeImmutable());

        $final = $this->service->countersign($booking, $this->asset(), $copy->id, $account, null, 'Xavier Dubois', new \DateTimeImmutable());

        $path = $this->documents->absolutePath($final);
        $this->assertIsString($path);
        $this->assertSame(2, self::pageCount($path));
        // Signed by both parties: nothing left to send.
        $this->assertFalse($this->service->acceptsCopy($booking));
    }

    public function testACopyOfAnotherBookingCannotBeAnsweredThroughThisOne(): void
    {
        $mine = $this->booking();
        $copy = $this->service->receiveCopy($mine, $this->asset(), $this->storedFile(self::photo(), 'jpg', 'image/jpeg'));
        $created = $this->bookingRepository->create(
            $this->assetId,
            'LOC-K7Q2M5',
            '2027-08-01',
            '2027-08-04',
            1,
            20,
            null,
            ['name' => 'Autre', 'email' => 'autre@example.be', 'phone' => null, 'organisation' => null, 'purpose' => null, 'comment' => null],
            null,
            null,
            null,
            'v1',
            str_repeat('0', 64),
            'v1',
            str_repeat('0', 64),
            new \DateTimeImmutable('2027-01-01 10:00:00')
        );
        $other = $this->bookingRepository->findById($created['id']);
        $this->assertNotNull($other);

        $this->expectException(RentalException::class);
        $this->service->refuseCopy($other, $this->asset(), $copy->id, 'Non.', null, new \DateTimeImmutable());
    }

    // ── The signature itself ────────────────────────────────────────────

    /** Encrypted at rest, readable by its owner, deleted on request. */
    public function testASignatureIsEncryptedReadByItsOwnerAloneAndDeletable(): void
    {
        $owner = $this->account();
        $other = $this->account();
        $png = self::signaturePng();

        $this->signatures->save($owner, $png, new \DateTimeImmutable());

        $stored = $this->pdo->query('SELECT image_encrypted FROM rental_manager_signatures')->fetchColumn();
        $this->assertIsString($stored);
        $this->assertStringNotContainsString("\x89PNG", $stored);
        $this->assertSame($png, $this->signatures->findPng($owner));
        $this->assertNull($this->signatures->findPng($other));

        $this->assertTrue($this->signatures->delete($owner));
        $this->assertNull($this->signatures->findPng($owner));
        $this->assertFalse($this->signatures->has($owner));
    }
}
