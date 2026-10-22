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
use Core\Security\UserAccountRepository;
use Core\Signature\AccountSignature;
use Core\Signature\PersonalSignatureRepository;
use Core\Signature\PersonalSignatureService;
use Core\Signature\SignatureException;
use Core\Signature\SignatureImage;
use Core\Signature\SignatureSubmission;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Document\DocumentType;
use Modules\Rental\Payment\PaymentSettings;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalDocumentRepository;
use Modules\Rental\Service\RentalBookingMailService;
use Modules\Rental\Service\RentalDocumentService;
use Modules\Rental\Service\RentalException;
use Modules\Rental\Service\RentalSignedContractService;
use Modules\Rental\Availability\AvailabilityCalculator;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\HoldOrigin;
use Modules\Rental\Pricing\RentalPricingEngine;
use Modules\Rental\Repository\RentalBlockRepository;
use Modules\Rental\Repository\RentalConstraintsRepository;
use Modules\Rental\Repository\RentalPricingRepository;
use Modules\Rental\Service\RentalAvailabilityService;
use Modules\Rental\Service\RentalBookingService;
use Modules\Rental\Service\RentalOccupancyGuard;
use Modules\Rental\Service\RentalPricingService;
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
    private PersonalSignatureService $signatures;
    private RentalBookingRepository $bookingRepository;
    private RentalAssetRepository $assetRepository;
    private FileRepository $fileRepository;
    private RentalSignedContractService $service;
    private RentalBookingService $bookingService;
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
        $this->signatures = new PersonalSignatureService(
            new PersonalSignatureRepository($this->pdo, $encryption),
            $journal,
            new UserAccountRepository($this->pdo, $encryption)
        );
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

        $this->bookingService = new RentalBookingService($this->bookingRepository, $journal);
        $pricing = new RentalPricingService(new RentalPricingRepository($this->pdo), new RentalPricingEngine(), $journal);
        $this->service = new RentalSignedContractService(
            $this->documents,
            $this->documentRepository,
            $this->signatures,
            RentalTestHelper::bookingAudit($this->pdo, $encryption),
            $mail,
            new PdfCompressor($this->storagePath . '/temp'),
            $journal,
            null,
            null,
            null,
            // As public/index.php wires it: a copy received or refused is a
            // write of an occupation (#927, chantier §2.1).
            new RentalOccupancyGuard(
                $this->bookingRepository,
                new RentalAvailabilityService(
                    new AvailabilityCalculator(),
                    new RentalConstraintsRepository($this->pdo),
                    [$this->bookingService, new RentalBlockRepository($this->pdo)]
                ),
                $pricing
            )
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
        // A contract is refused without the asset's address (issue #977).
        $this->assetRepository->saveContractDetails($this->assetId, 'Chemin du Camp 4, 1300 Wavre', 90);
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
        // ...nor without the renter's (issue #977).
        $this->bookingRepository->saveBillingIdentity($created['id'], ['address' => 'Rue Haute 12, 1000 Bruxelles']);
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

    /**
     * The signature this account keeps, as the dialog's stored mode hands
     * it over (`PersonalSignatureService::forSigning()`).
     */
    private function stored(int $account): AccountSignature
    {
        return $this->signatures->forSigning($account, new SignatureSubmission(true));
    }

    /** A signature traced in the dialog for this contract alone, the box ticked or not. */
    private function drawnNow(int $account, bool $remember): AccountSignature
    {
        return $this->signatures->forSigning(
            $account,
            new SignatureSubmission(false, 'data:image/png;base64,' . base64_encode(self::signaturePng()), null, $remember)
        );
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
        $this->signatures->save($account, SignatureImage::fromBytes(self::signaturePng()), new \DateTimeImmutable());
        $now = new \DateTimeImmutable();
        $this->service->countersign($booking, $this->asset(), $second->id, $this->stored($account), null, $now);

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
        $this->signatures->save($account, SignatureImage::fromBytes(self::signaturePng()), new \DateTimeImmutable());
        $path = $this->documents->absolutePath($copy);
        $this->assertIsString($path);
        unlink($path);

        try {
            $now = new \DateTimeImmutable();
            $this->service->countersign($booking, $this->asset(), $copy->id, $this->stored($account), null, $now);
            $this->fail('a copy whose file is gone cannot be countersigned');
        } catch (RentalException) {
        }

        $this->assertNull($this->service->finalContract($booking->id));
        $this->assertSame($copy->id, $this->service->pendingCopy($booking->id)?->id);
    }

    /**
     * Another account's stored signature is no use to this one (issue #926):
     * the account signing reads its own, and without one it signs with a
     * signature given now — which leaves the other's untouched.
     */
    public function testAnotherAccountsStoredSignatureIsNeverUsed(): void
    {
        $booking = $this->booking();
        $copy = $this->service->receiveCopy($booking, $this->asset(), $this->storedFile(self::photo(), 'jpg', 'image/jpeg'));
        $mine = $this->account();
        $other = $this->account();
        $this->signatures->save($other, SignatureImage::fromBytes(self::signaturePng()), new \DateTimeImmutable('2026-01-01 08:00:00'));

        try {
            $this->stored($mine);
            $this->fail("the other account's signature was read for this one");
        } catch (SignatureException) {
        }

        $this->service->countersign($booking, $this->asset(), $copy->id, $this->drawnNow($mine, false), null, new \DateTimeImmutable());

        $this->assertNotNull($this->service->finalContract($booking->id));
        $this->assertFalse($this->signatures->has($mine));
        $this->assertSame('2026-01-01 08:00:00', $this->signatures->savedAt($other)?->format('Y-m-d H:i:s'));
    }

    // ── Signed now, remembered or not (issue #926) ──────────────────────

    public function testASignatureGivenForThisContractAloneIsKeptNowhere(): void
    {
        $booking = $this->booking();
        $copy = $this->service->receiveCopy($booking, $this->asset(), $this->storedFile(self::photo(), 'jpg', 'image/jpeg'));
        $account = $this->account();

        $final = $this->service->countersign($booking, $this->asset(), $copy->id, $this->drawnNow($account, false), null, new \DateTimeImmutable());

        $this->assertSame(DocumentType::SIGNED_CONTRACT, $final->type);
        $this->assertFalse($this->signatures->has($account));
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM user_account_signatures')->fetchColumn());
    }

    public function testTickedTheSignatureIsKeptInTheAccount(): void
    {
        $booking = $this->booking();
        $copy = $this->service->receiveCopy($booking, $this->asset(), $this->storedFile(self::photo(), 'jpg', 'image/jpeg'));
        $account = $this->account();
        $signature = $this->drawnNow($account, true);

        $this->service->countersign($booking, $this->asset(), $copy->id, $signature, null, new \DateTimeImmutable());

        $this->assertSame($signature->png->bytes(), $this->signatures->ownImage($account)?->bytes());
    }

    /**
     * « Signer et mémoriser » is one act: the remembering runs inside the
     * booking's transaction, and a countersignature that fails — here, the
     * renter's file gone — takes it back with everything else.
     */
    public function testARememberingWhoseCountersignatureFailsIsRolledBack(): void
    {
        $booking = $this->booking();
        $copy = $this->service->receiveCopy($booking, $this->asset(), $this->storedFile(self::photo(), 'jpg', 'image/jpeg'));
        $account = $this->account();
        $path = $this->documents->absolutePath($copy);
        $this->assertIsString($path);
        unlink($path);

        try {
            $this->service->countersign($booking, $this->asset(), $copy->id, $this->drawnNow($account, true), null, new \DateTimeImmutable());
            $this->fail('a copy whose file is gone cannot be countersigned');
        } catch (RentalException $e) {
            $this->assertStringContainsString('introuvable', $e->getMessage());
        }

        $this->assertFalse($this->signatures->has($account), 'nothing is remembered for a signing that failed');
        $this->assertFalse($this->pdo->inTransaction());
        $saved = $this->pdo->query("SELECT COUNT(*) FROM event_log WHERE event_type = 'personal_signature_saved'")->fetchColumn();
        $this->assertSame(0, (int) $saved);
        $this->assertSame($copy->id, $this->service->pendingCopy($booking->id)?->id);
    }

    /**
     * The act is in the core's journal under the signing account, and the
     * booking's history names that account — never a pixel in either.
     */
    public function testTheActIsJournaledAndAuditedForTheSigningAccountWithoutAPixel(): void
    {
        $booking = $this->booking();
        $copy = $this->service->receiveCopy($booking, $this->asset(), $this->storedFile(self::photo(), 'jpg', 'image/jpeg'));
        $account = $this->account();
        $signature = $this->drawnNow($account, true);

        $final = $this->service->countersign($booking, $this->asset(), $copy->id, $signature, null, new \DateTimeImmutable('2027-01-05 12:00:00'));

        $stmt = $this->pdo->prepare('SELECT * FROM event_log WHERE event_type = ?');
        $stmt->execute(['personal_signature_document_signed']);
        $entries = $stmt->fetchAll();
        $this->assertCount(1, $entries);
        $this->assertSame($account, (int) $entries[0]['user_account_id']);
        $context = json_decode((string) $entries[0]['context'], true);
        $this->assertSame('rental', $context['module']);
        $this->assertSame('signed_contract', $context['document_type']);
        $this->assertSame($final->id, $context['document_id']);
        $this->assertSame($booking->id, $context['owner_entity_id']);
        $this->assertSame('drawn_now', $context['origin']);
        $this->assertTrue($context['remembered']);

        $everything = json_encode($this->pdo->query('SELECT * FROM event_log')->fetchAll(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(base64_encode($signature->png->bytes()), $everything);
        $this->assertStringNotContainsString(base64_encode(self::signaturePng()), $everything);

        $audit = $this->pdo->prepare(
            "SELECT actor_user_account_id FROM entity_changes WHERE entity_type = 'rental_booking' AND entity_id = ? AND field_key = 'status_changed'"
        );
        $audit->execute([$booking->id]);
        $this->assertContains($account, array_map('intval', $audit->fetchAll(\PDO::FETCH_COLUMN)));
    }

    /** The page added at the end names the account by its own name, « un gestionnaire » without one. */
    public function testTheAccountsOwnNameSignsTheAddedPage(): void
    {
        $booking = $this->booking();
        $copy = $this->service->receiveCopy($booking, $this->asset(), $this->storedFile(self::photo(), 'jpg', 'image/jpeg'));
        $account = $this->account();
        (new UserAccountRepository($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))))
            ->updateProfile($account, 'Marie', 'Dupont');

        $final = $this->service->countersign($booking, $this->asset(), $copy->id, $this->drawnNow($account, false), null, new \DateTimeImmutable());

        $path = $this->documents->absolutePath($final);
        $this->assertIsString($path);
        $text = (string) preg_replace('/\s+/', ' ', (string) (new \Core\File\PdfTextExtractor())->extractText((string) file_get_contents($path)));
        $this->assertStringContainsString(' par Marie Dupont, le ', $text);
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
        $this->signatures->save($account, SignatureImage::fromBytes(self::signaturePng()), new \DateTimeImmutable());
        $this->documentRepository->markSuperseded([$copy->id], new \DateTimeImmutable());

        try {
            $this->service->countersign($booking, $this->asset(), $copy->id, $this->stored($account), null, new \DateTimeImmutable());
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
        $this->signatures->save($account, SignatureImage::fromBytes(self::signaturePng()), new \DateTimeImmutable());

        $final = $this->service->countersign($booking, $this->asset(), $copy->id, $this->stored($account), null, new \DateTimeImmutable('2027-01-05 12:00:00'));

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
        $this->signatures->save($account, SignatureImage::fromBytes(self::signaturePng()), new \DateTimeImmutable());

        $final = $this->service->countersign($booking, $this->asset(), $copy->id, $this->stored($account), null, new \DateTimeImmutable());

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

    // ── Firm hold (#927, chantier §2.1) ─────────────────────────────────

    private function reload(RentalBooking $booking): RentalBooking
    {
        $fresh = $this->bookingRepository->findById($booking->id);
        $this->assertNotNull($fresh);

        return $fresh;
    }

    /** A competing request on the same week, created straight in the table. */
    private function competitor(?\DateTimeImmutable $holdUntil): RentalBooking
    {
        $created = $this->bookingRepository->create(
            $this->assetId,
            'LOC-Q8R9S2',
            '2027-07-02',
            '2027-07-05',
            1,
            10,
            null,
            [
                'name' => 'Paul Durand',
                'email' => 'paul@example.be',
                'phone' => null,
                'organisation' => null,
                'purpose' => null,
                'comment' => null,
            ],
            null,
            $holdUntil,
            $holdUntil !== null ? HoldOrigin::AUTOMATIC : null,
            'v1',
            str_repeat('0', 64),
            'v1',
            str_repeat('0', 64),
            new \DateTimeImmutable('2027-01-20 10:00:00')
        );

        return $this->reload($this->bookingRepository->findById($created['id']) ?? self::fail('no booking'));
    }

    /**
     * Copy received, before any countersignature or confirmation: the dates
     * are held firmly — past their deadline, by no task released — and the
     * status has not moved.
     */
    public function testACopyReceivedHoldsTheDatesFirmlyAndNoTaskFreesThem(): void
    {
        $booking = $this->booking();
        $this->bookingRepository->setHold($booking->id, new \DateTimeImmutable('2027-01-25 10:00:00'), HoldOrigin::AUTOMATIC);
        $this->service->receiveCopy(
            $this->reload($booking),
            $this->asset(),
            $this->storedFile(self::photo(), 'jpg', 'image/jpeg'),
            new \DateTimeImmutable('2027-01-20 10:00:00')
        );

        $after = new \DateTimeImmutable('2027-02-10 10:00:00');
        $signed = $this->reload($booking);
        $this->assertNotNull($signed->signedCopyReceivedAt);
        $this->assertTrue($signed->holdsFirmly());
        $this->assertTrue($signed->occupiesTheAsset($after), 'the lapsed deadline frees nothing');
        $this->assertSame(BookingStatus::RECEIVED, $signed->status, 'a copy confirms nothing');

        $swept = $this->bookingService->expireLapsedHolds($after);
        $this->assertSame(['released' => 0, 'expired' => 0], $swept);
        $this->assertSame('2027-01-25 10:00:00', $this->reload($booking)->holdUntil?->format('Y-m-d H:i:s'));
        $this->assertCount(1, $this->bookingService->findOccupancies(
            $this->assetId,
            new \DateTimeImmutable('2027-06-01'),
            new \DateTimeImmutable('2027-08-01'),
            $after
        ));
    }

    /**
     * Refused: the dates fall back to a provisional hold lasting at least
     * three days from the refusal.
     */
    public function testARefusedCopyHoldsTheDatesThreeDaysMore(): void
    {
        $booking = $this->booking();
        $copy = $this->service->receiveCopy($booking, $this->asset(), $this->storedFile(self::photo(), 'jpg', 'image/jpeg'));

        $refusedAt = new \DateTimeImmutable('2027-02-10 15:00:00');
        $this->service->refuseCopy($booking, $this->asset(), $copy->id, 'Illisible.', null, $refusedAt);

        $refused = $this->reload($booking);
        $this->assertFalse($refused->holdsFirmly());
        $this->assertSame('2027-02-13 15:00:00', $refused->holdUntil?->format('Y-m-d H:i:s'));
        $this->assertSame(HoldOrigin::AUTOMATIC, $refused->holdOrigin);
        $this->assertTrue($refused->occupiesTheAsset(new \DateTimeImmutable('2027-02-13 14:59:00')));
        $this->assertFalse($refused->occupiesTheAsset(new \DateTimeImmutable('2027-02-13 15:01:00')));
    }

    /** Never past the start of the stay, and never shorter than a hold still running. */
    public function testTheRefusalHoldIsCappedAtTheStayAndNeverShortened(): void
    {
        $booking = $this->booking();
        $this->bookingRepository->setHold($booking->id, new \DateTimeImmutable('2027-06-20 10:00:00'), HoldOrigin::MANAGER);
        $copy = $this->service->receiveCopy($this->reload($booking), $this->asset(), $this->storedFile(self::photo(), 'jpg', 'image/jpeg'));
        $this->service->refuseCopy($booking, $this->asset(), $copy->id, 'Illisible.', null, new \DateTimeImmutable('2027-02-10 15:00:00'));
        $kept = $this->reload($booking);
        $this->assertSame('2027-06-20 10:00:00', $kept->holdUntil?->format('Y-m-d H:i:s'), 'never shortened');
        $this->assertSame(HoldOrigin::MANAGER, $kept->holdOrigin, 'an option stays an option');

        // The stay starts on 1 July: refused on 29 June with no hold
        // running, the dates are held until the midnight it starts.
        $unheld = new RentalBooking(...array_merge(get_object_vars($kept), ['holdUntil' => null]));
        $this->assertEquals(
            new \DateTimeImmutable('2027-07-01 00:00:00'),
            RentalOccupancyGuard::holdAfterRefusal($unheld, new \DateTimeImmutable('2027-06-29 09:00:00'))
        );
        $this->assertNull(
            RentalOccupancyGuard::holdAfterRefusal($unheld, new \DateTimeImmutable('2027-07-01 09:00:00')),
            'nothing to hold once the stay has begun'
        );
        $confirmed = new RentalBooking(...array_merge(get_object_vars($unheld), ['status' => BookingStatus::CONFIRMED]));
        $this->assertNull(RentalOccupancyGuard::holdAfterRefusal($confirmed, new \DateTimeImmutable('2027-02-10')));
    }

    /**
     * A renter whose hold lapsed while somebody else took the week cannot
     * make it theirs again by sending a copy: two renters would hold the
     * same dates. Refused before the file is stored, and again under the
     * lock.
     */
    public function testACopyIsRefusedWhenItsDatesWentToSomebodyElse(): void
    {
        $booking = $this->booking();
        $this->bookingRepository->setHold($booking->id, new \DateTimeImmutable('2027-01-15 10:00:00'), HoldOrigin::AUTOMATIC);
        $this->competitor(new \DateTimeImmutable('2027-03-01 10:00:00'));
        $now = new \DateTimeImmutable('2027-01-20 10:00:00');

        $this->assertSame(
            RentalSignedContractService::DATES_NO_LONGER_HELD,
            $this->service->whyNoCopy($this->reload($booking), $this->asset(), $now)
        );

        $this->expectException(RentalException::class);
        $this->expectExceptionMessage(RentalSignedContractService::DATES_NO_LONGER_HELD);
        $this->service->receiveCopy(
            $this->reload($booking),
            $this->asset(),
            $this->storedFile(self::photo(), 'jpg', 'image/jpeg'),
            $now
        );
    }

    /** Its own hold still running, nobody can have taken the dates: the copy is welcome. */
    public function testACopyWithinItsOwnHoldIsWelcome(): void
    {
        $booking = $this->booking();
        $this->bookingRepository->setHold($booking->id, new \DateTimeImmutable('2027-03-15 10:00:00'), HoldOrigin::AUTOMATIC);

        $this->assertNull($this->service->whyNoCopy(
            $this->reload($booking),
            $this->asset(),
            new \DateTimeImmutable('2027-01-20 10:00:00')
        ));
    }
}
