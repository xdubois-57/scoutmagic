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
use Core\Security\EncryptionService;
use Core\Security\HtmlSanitizer;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Modules\Rental\Booking\BookingMilestones;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\HoldOrigin;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Document\DocumentType;
use Modules\Rental\Document\RentalDocument;
use Modules\Rental\Payment\PaymentSettings;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalDocumentRepository;
use Modules\Rental\Repository\RentalMilestoneMarkRepository;
use Modules\Rental\Repository\RentalReminderRepository;
use Modules\Rental\Reminder\ReminderKind;
use Modules\Rental\Service\RentalContractValidityService;
use Modules\Rental\Service\RentalDocumentService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * A contract becomes void the moment the booking changes (#708, IT-20).
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RentalContractValidityServiceTest extends TestCase
{
    private \PDO $pdo;
    private string $storagePath;
    private RentalDocumentService $documents;
    private RentalDocumentRepository $documentRepository;
    private RentalBookingRepository $bookingRepository;
    private RentalAssetRepository $assetRepository;
    private RentalMilestoneMarkRepository $marks;
    private RentalReminderRepository $reminders;
    private RentalContractValidityService $service;
    private int $assetId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->storagePath = sys_get_temp_dir() . '/rental_validity_' . bin2hex(random_bytes(6));
        mkdir($this->storagePath, 0755, true);

        $settings = new SettingService(new SettingRepository($this->pdo));
        $settings->register('site_name', 'Unité Saint-Georges', 'text', 'Nom', 'Description de test.');
        RentalTestHelper::registerSettings($settings);

        $this->assetRepository = new RentalAssetRepository($this->pdo, $encryption);
        $this->bookingRepository = new RentalBookingRepository($this->pdo, $encryption);
        $this->documentRepository = new RentalDocumentRepository($this->pdo);
        $this->marks = new RentalMilestoneMarkRepository($this->pdo);
        $fileRepository = new FileRepository($this->pdo);
        $audit = RentalTestHelper::bookingAudit($this->pdo, $encryption);

        $this->documents = new RentalDocumentService(
            $this->documentRepository,
            $this->bookingRepository,
            $audit,
            new EditableContentService(new EditableContentRepository($this->pdo)),
            $fileRepository,
            new \Core\File\AttachedFileRemover($fileRepository, $this->storagePath),
            new DocumentPdfService(),
            new HtmlSanitizer(),
            $settings,
            new JournalService(new JournalRepository($this->pdo)),
            $this->storagePath
        );
        $this->service = new RentalContractValidityService(
            $this->documents,
            $this->documentRepository,
            $this->bookingRepository,
            $audit,
            null,
            $this->marks,
            $this->reminders = new RentalReminderRepository($this->pdo)
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
        @rmdir($this->storagePath);
    }

    private function asset(): RentalAsset
    {
        $asset = $this->assetRepository->findById($this->assetId);
        $this->assertNotNull($asset);

        return $asset;
    }

    private function fresh(int $id): RentalBooking
    {
        $booking = $this->bookingRepository->findById($id);
        $this->assertNotNull($booking);

        return $booking;
    }

    /** A booking whose contract went out, as the unit's answer. */
    private function bookingWithItsContractSent(): RentalBooking
    {
        $created = $this->bookingRepository->create(
            $this->assetId,
            'LOC-K7Q2M4',
            '2027-07-01',
            '2027-07-04',
            1,
            20,
            null,
            ['name' => 'Jeanne Martin', 'email' => 'jeanne@example.be', 'phone' => null, 'organisation' => null, 'purpose' => null, 'comment' => null],
            null,
            null,
            null,
            'v1',
            str_repeat('0', 64),
            'v1',
            str_repeat('0', 64),
            new \DateTimeImmutable('2027-01-01 10:00:00')
        );
        $booking = $this->fresh($created['id']);

        $contract = $this->documents->generate($booking, $this->asset(), DocumentType::CONTRACT, new PaymentSettings());
        $this->documents->markSent($contract->id, new \DateTimeImmutable('2027-01-02'));
        $this->bookingRepository->setStatus($booking->id, BookingStatus::CONTRACT_SENT, new \DateTimeImmutable('2027-01-02'));

        return $this->fresh($booking->id);
    }

    private function contract(int $bookingId): RentalDocument
    {
        foreach ($this->documentRepository->findForBooking($bookingId) as $document) {
            if ($document->type === DocumentType::CONTRACT) {
                return $document;
            }
        }
        $this->fail('no contract');
    }

    public function testAnUnchangedBookingKeepsItsContract(): void
    {
        $booking = $this->bookingWithItsContractSent();

        $this->assertNotNull($this->contract($booking->id)->fingerprint);
        $this->assertFalse($this->service->recheck($booking, $this->asset(), null, new \DateTimeImmutable()));
        $this->assertFalse($this->contract($booking->id)->isSuperseded());
    }

    /**
     * Another head count: the contract and the copy signed from it are
     * kept and marked, the text opens again, the steps reopen, and a
     * booking waiting on its signature goes back to « Demande reçue ».
     */
    public function testAChangeTheContractStatesVoidsItAndEverythingSignedFromIt(): void
    {
        $booking = $this->bookingWithItsContractSent();
        $copyId = $this->documentRepository->create($booking->id, 0, DocumentType::SIGNED_COPY, 1, false, null, null);
        $this->marks->mark($booking->id, BookingMilestones::CONTRACT_COUNTERSIGNED, null, new \DateTimeImmutable());
        // The retired agreement mark, which ticks every contract step.
        $this->marks->mark($booking->id, 'contract_accepted', null, new \DateTimeImmutable());
        $this->assertTrue($this->documents->textIsLocked($booking, DocumentType::CONTRACT));

        $this->bookingRepository->setStay($booking->id, '2027-07-01', '2027-07-04', 1, 25);
        $voided = $this->service->recheck($this->fresh($booking->id), $this->asset(), null, new \DateTimeImmutable('2027-01-10 09:00'));

        $this->assertTrue($voided);
        $contract = $this->contract($booking->id);
        $this->assertTrue($contract->isSuperseded());
        $this->assertSame('10/01/2027', $contract->supersededAt?->format('d/m/Y'));
        $this->assertTrue($this->documentRepository->findById($copyId)?->isSuperseded());
        $this->assertSame(BookingStatus::RECEIVED, $this->fresh($booking->id)->status);
        $this->assertFalse($this->documents->textIsLocked($booking, DocumentType::CONTRACT), 'a new contract must be writable');
        $this->assertSame([], $this->marks->findForBooking($booking->id));
    }

    /**
     * The text a void contract was made from opens again — and the write
     * itself agrees, not only the check before it: its UPDATE still read
     * the void contract's `sent_at` as a lock.
     */
    public function testTheTextOfAVoidContractCanBeSavedAgain(): void
    {
        $booking = $this->bookingWithItsContractSent();
        // A text of its own, so the save goes through the guarded UPDATE.
        $this->documentRepository->saveText($booking->id, DocumentType::CONTRACT, '<p>Avant</p>');

        $this->bookingRepository->setStay($booking->id, '2027-07-01', '2027-07-04', 1, 25);
        $this->service->recheck($this->fresh($booking->id), $this->asset(), null, new \DateTimeImmutable('2027-01-10 09:00'));

        $this->documents->saveBookingText($this->fresh($booking->id), DocumentType::CONTRACT, '<p>Après</p>');
        $this->assertStringContainsString('Après', (string) $this->documentRepository->findText($booking->id, DocumentType::CONTRACT));
    }

    /**
     * « Copie signée attendue » is said once per booking: the new
     * contract's deadline must be said too.
     */
    public function testAVoidContractLetsTheSignedCopyReminderBeSaidAgain(): void
    {
        $booking = $this->bookingWithItsContractSent();
        $this->assertTrue($this->reminders->claim('booking', $booking->id, ReminderKind::SIGNED_COPY_DUE, new \DateTimeImmutable('2027-01-05')));

        $this->bookingRepository->setStay($booking->id, '2027-07-01', '2027-07-04', 1, 25);
        $this->service->recheck($this->fresh($booking->id), $this->asset(), null, new \DateTimeImmutable('2027-01-10 09:00'));

        $this->assertTrue(
            $this->reminders->claim('booking', $booking->id, ReminderKind::SIGNED_COPY_DUE, new \DateTimeImmutable('2027-01-20')),
            'claimable again for the new contract'
        );
    }

    /**
     * An option the manager placed outlived the contract's sending. Back on
     * « Demande reçue », its lapse must still only free the dates — as it
     * would have under « Contrat envoyé » — never expire the booking.
     */
    public function testAManagersOptionNoLongerEndsTheBookingItsVoidContractSentBack(): void
    {
        $booking = $this->bookingWithItsContractSent();
        $until = new \DateTimeImmutable('2027-02-15 12:00:00');
        $this->bookingRepository->setHold($booking->id, $until, HoldOrigin::MANAGER);

        $this->bookingRepository->setStay($booking->id, '2027-07-01', '2027-07-04', 1, 25);
        $this->service->recheck($this->fresh($booking->id), $this->asset(), null, new \DateTimeImmutable('2027-01-10 09:00'));

        $after = $this->fresh($booking->id);
        $this->assertSame(BookingStatus::RECEIVED, $after->status);
        $this->assertEquals($until, $after->holdUntil, 'the hold is not shortened');
        $this->assertFalse($after->lapseEndsTheBooking());
    }

    /** Going back would free its dates: a confirmed booking stays confirmed. */
    public function testAConfirmedBookingStaysConfirmed(): void
    {
        $booking = $this->bookingWithItsContractSent();
        $this->bookingRepository->setStatus($booking->id, BookingStatus::CONFIRMED, new \DateTimeImmutable());

        $this->bookingRepository->setStay($booking->id, '2027-07-02', '2027-07-05', 1, 20);

        $this->assertTrue($this->service->recheck($this->fresh($booking->id), $this->asset(), null, new \DateTimeImmutable()));
        $this->assertSame(BookingStatus::CONFIRMED, $this->fresh($booking->id)->status);
        $this->assertTrue($this->contract($booking->id)->isSuperseded());
    }

    /** A booking that stopped voids nothing. */
    public function testACancellationVoidsNothing(): void
    {
        $booking = $this->bookingWithItsContractSent();
        $this->bookingRepository->setStatus($booking->id, BookingStatus::CANCELLED, new \DateTimeImmutable());
        $this->bookingRepository->setStay($booking->id, '2027-07-02', '2027-07-05', 1, 20);

        $this->assertFalse($this->service->recheck($this->fresh($booking->id), $this->asset(), null, new \DateTimeImmutable()));
        $this->assertFalse($this->contract($booking->id)->isSuperseded());
    }

    /**
     * A closed stay keeps its contract: a renter correcting their billing
     * address for the invoice must not void the copy signed by both parties.
     */
    public function testAClosedBookingVoidsNothing(): void
    {
        $booking = $this->bookingWithItsContractSent();
        $countersignedId = $this->documentRepository->create($booking->id, 0, DocumentType::SIGNED_CONTRACT, 1, true, null, null);
        $this->bookingRepository->setStatus($booking->id, BookingStatus::CLOSED, new \DateTimeImmutable());
        $this->bookingRepository->setStay($booking->id, '2027-07-01', '2027-07-04', 1, 25);

        $this->assertFalse($this->service->recheck($this->fresh($booking->id), $this->asset(), null, new \DateTimeImmutable()));
        $this->assertFalse($this->contract($booking->id)->isSuperseded());
        $this->assertFalse($this->documentRepository->findById($countersignedId)?->isSuperseded());
    }

    /**
     * A copy answers the contract filed last before it. Signed from a
     * contract older than fingerprints — which nothing voids — it stays,
     * even when a newer, fingerprinted version is voided around it.
     */
    public function testACopyOfAContractOlderThanFingerprintsOutlivesANewerVoidVersion(): void
    {
        $booking = $this->bookingWithItsContractSent();
        $legacy = $this->contract($booking->id);
        $this->pdo->prepare('UPDATE rental_documents SET fingerprint = NULL WHERE id = ?')->execute([$legacy->id]);
        $legacyCopyId = $this->documentRepository->create($booking->id, 0, DocumentType::SIGNED_CONTRACT, 1, true, null, null);

        $newer = $this->documents->generate($this->fresh($booking->id), $this->asset(), DocumentType::CONTRACT, new PaymentSettings());
        $this->bookingRepository->setStay($booking->id, '2027-07-01', '2027-07-04', 1, 25);

        $this->assertTrue($this->service->recheck($this->fresh($booking->id), $this->asset(), null, new \DateTimeImmutable()));
        $this->assertTrue($this->documentRepository->findById($newer->id)?->isSuperseded());
        $this->assertFalse($this->documentRepository->findById($legacy->id)?->isSuperseded());
        $this->assertFalse($this->documentRepository->findById($legacyCopyId)?->isSuperseded());
    }

    /** A contract made before fingerprints existed is left alone, not guessed about. */
    public function testAContractWithoutAFingerprintIsNeverVoided(): void
    {
        $booking = $this->bookingWithItsContractSent();
        $this->pdo->exec('UPDATE rental_documents SET fingerprint = NULL');
        $this->bookingRepository->setStay($booking->id, '2027-07-02', '2027-07-05', 1, 20);

        $this->assertFalse($this->service->recheck($this->fresh($booking->id), $this->asset(), null, new \DateTimeImmutable()));
    }

    /** The new contract holds, and so does what is signed from it. */
    public function testANewContractAndItsCopyHold(): void
    {
        $booking = $this->bookingWithItsContractSent();
        $this->bookingRepository->setStay($booking->id, '2027-07-01', '2027-07-04', 1, 25);
        $this->service->recheck($this->fresh($booking->id), $this->asset(), null, new \DateTimeImmutable());

        $this->documents->generate($this->fresh($booking->id), $this->asset(), DocumentType::CONTRACT, new PaymentSettings());
        $copyId = $this->documentRepository->create($booking->id, 0, DocumentType::SIGNED_COPY, 1, false, null, null);

        $this->assertFalse($this->service->recheck($this->fresh($booking->id), $this->asset(), null, new \DateTimeImmutable()));
        $this->assertFalse($this->documentRepository->findById($copyId)?->isSuperseded());
    }
}
