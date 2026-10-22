<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Core\Config\AppClock;
use Core\Config\SettingService;
use Core\Journal\JournalService;
use Core\Notification\NotificationService;
use Core\Pdf\DocumentPdfService;
use Core\Service\DateInput;
use Core\Signature\AccountSignature;
use Core\Signature\PersonalSignatureService;
use Core\View\DateFilterExtension;
use Modules\Rental\Audit\BookingAudit;
use Modules\Rental\Booking\BookingPage;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Document\DocumentType;
use Modules\Rental\Document\RentalDocument;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Repository\RentalDocumentRepository;
use Modules\Rental\Stay\BlankInventorySheet;
use Modules\Rental\Stay\InventoryPhotoAnnex;
use Modules\Rental\Stay\InventoryReport;
use Modules\Rental\Stay\InventoryScope;
use Modules\Rental\Stay\InventorySignatureBlock;
use Modules\Rental\Stay\InventorySigning;
use Modules\Rental\Stay\ReadingPhase;

/**
 * « Valider, signer et envoyer » (#708, IT-17; #989, lot E): the phase
 * frozen, its PDF signed by the manager and — when they are there and
 * willing — by the renter, filed with the booking's documents and sent to
 * the renter.
 *
 * **Frozen first, then produced, in one transaction.** The validation row
 * is written first under the booking's lock — its unique key is what stops
 * two managers producing two PDFs — and everything after it commits with
 * it or not at all, so a failure leaves the inventory open rather than
 * validated with nothing to show for it, and keeps no signature
 * « remembered » for a document that does not exist.
 *
 * **The renter's signature is never stored.** Their typed name and their
 * drawing go into the PDF and nowhere else: the row says only what they
 * did (`Stay\RenterSigning`), the history the same and the fingerprint,
 * the journal the manager's act (`recordSigning()`).
 *
 * **An asset with nothing to walk validates a minimal inventory** (#989,
 * lot G): no items and no meters, so one statement — « Bien remis en bon
 * état, sans remarque », « Bien rendu… », or the manager's free remark,
 * kept encrypted on the validation row — and, at the departure, the
 * incidents noted, signed and sent exactly like a full one. It replaced
 * the dashboard's bare tick: no inventory step is ever done without a
 * PDF the renter holds.
 *
 * **A missing reading blocks; an unchecked line does not.** An item nobody
 * looked at is printed « non vérifié », which is an honest record; a meter
 * with no reading would leave the settlement with no consumption, and a
 * validated inventory can no longer be completed.
 *
 * **The photos go in an annex** (#989): the meters' and, at the departure,
 * the incidents' — 1 600 px copies `InventoryPhotoRenderer` makes in
 * memory before the phase is frozen, never stored, numbered by
 * `Stay\InventoryPhotoAnnex` so each incident line names its photos.
 *
 * **« Signé sur papier »** (#989, lot F, decision Q3): nobody signs on the
 * screen — the printed checklist (`blankSheet()`) carries both signatures
 * — and the photo of that sheet is required instead. It is filed as a
 * `DocumentType::PHOTO` document, not for the renter (it is printed in
 * the PDF they are sent), referenced by the validation, and printed last
 * in the annex. The session's account is still recorded, in the row, the
 * history and the journal; « Mémoriser » and the journal of an electronic
 * signing are not used, since there is none.
 */
class RentalInventoryValidationService
{
    /** Said when the photo of the sheet signed on paper cannot be read back. */
    public const SHEET_UNREADABLE = "La photo de la feuille signée n'a pas pu être relue : reprenez-la. "
        . "Rien n'a été validé.";

    /** Said when a minimal inventory comes with a sheet signed on paper (#989, lots F and G). */
    public const MINIMAL_NOT_ON_PAPER = "Ce bien n'a ni éléments d'état des lieux ni compteurs : il n'y a pas de liste à "
        . "signer sur papier. Faites signer l'état des lieux sur l'écran, ou indiquez que le locataire est absent.";

    public function __construct(
        private RentalStayService $stay,
        private RentalDocumentService $documents,
        private RentalDocumentRepository $documentRepository,
        private RentalBookingMailService $mail,
        private DocumentPdfService $pdf,
        private SettingService $settings,
        private BookingAudit $bookingAudit,
        private InventoryPhotoRenderer $photoRenderer,
        private PersonalSignatureService $signatures,
        private JournalService $journal,
        private ?NotificationService $notifications = null
    ) {
    }

    /**
     * The meters still lacking their reading for this phase.
     *
     * The phase's own only: the departure starts once the arrival is
     * validated, and a validated arrival already required its readings,
     * so a meter added since does not block the departure.
     *
     * @return list<string> their labels
     */
    public function missingReadings(RentalBooking $booking, int $assetId, ReadingPhase $phase): array
    {
        $missing = [];
        foreach ($this->stay->consumptionsFor($booking, $assetId) as $consumption) {
            $reading = $phase === ReadingPhase::ARRIVAL ? $consumption->arrival : $consumption->departure;
            if ($reading === null) {
                $missing[] = $consumption->meter->label;
            }
        }

        return $missing;
    }

    /**
     * The blank checklist of a phase, as a PDF to print (#989, lot F):
     * the items with what each is checked against and a box to tick, the
     * meters with an empty field, free lines for the incidents and two
     * signature frames (`Stay\BlankInventorySheet`). Nothing is stored.
     */
    public function blankSheet(RentalBooking $booking, RentalAsset $asset, ReadingPhase $phase): string
    {
        $label = $phase === ReadingPhase::ARRIVAL ? "État des lieux d'entrée" : 'État des lieux de sortie';

        return $this->pdf->generate(
            $label . ' — liste à vérifier — ' . $booking->reference,
            BlankInventorySheet::html(
                $phase,
                $this->stay->inventoryFor($booking->id),
                $this->stay->consumptionsFor($booking, $asset->id)
            ),
            (string) ($this->settings->get('site_name') ?: 'Unité scoute'),
            $this->metaLines($booking, $asset)
        );
    }

    /**
     * Validates the phase, signed on the spot: by the session's account
     * (`$manager`, from `PersonalSignatureService::forSigning()`) and by
     * the renter unless they were absent or refused (`$renter`, from
     * `witnessFromRequest()`) — both decoded by the caller before this
     * takes any lock. Signed on paper (`InventorySigning::onPaper()`),
     * `$manager` is null: the sheet's photo is the signatures, and its
     * `PaperSheet` names the session's account.
     *
     * **One transaction, under the booking's lock** (the rule of #926):
     * the freeze, the re-read, « Mémoriser », the PDF, its filing, its
     * SHA-256 and the history line commit together or not at all, and a
     * PDF already written to disk when anything fails is deleted. After
     * the commit, and never able to undo it: the journal line of the
     * signing, the signer's notification, then the e-mail to the renter
     * — sent whatever the renter did, absent and refusal included.
     *
     * `$remark` is a minimal inventory's free remark (#989, lot G),
     * `InventoryScope::REMARK_MAX` characters at most; blank, the PDF says
     * the fixed sentence. A full inventory has its lines' notes instead,
     * and ignores it.
     *
     * @return array{document: RentalDocument, sent: bool, sha256: string}
     * @throws RentalException
     * @throws \InvalidArgumentException when an electronic signature comes with
     *   an inventory signed on paper, or none with any other
     */
    public function validate(
        RentalBooking $booking,
        RentalAsset $asset,
        ReadingPhase $phase,
        ?int $actorMemberId,
        InventorySigning $renter,
        ?AccountSignature $manager,
        \DateTimeImmutable $now,
        ?string $remark = null
    ): array {
        // Before anything: the two modes never mix (decision Q3), and a
        // signature posted with « Signé sur papier » is never put on it.
        $signatures = new InventorySignatureBlock($renter, $manager, $asset->name, $asset->address, $now);
        $sheet = $renter->paperSheet;
        $accountId = $manager !== null ? $manager->accountId : $sheet?->accountId;

        $this->stay->assertPhaseOpen($booking->id, $phase);

        $lines = $this->stay->inventoryFor($booking->id);
        $meters = $this->stay->consumptionsFor($booking, $asset->id);
        // What the dashboard, the page and the reminders agree on
        // (keepsInventoryFor()): nothing to walk is a minimal inventory.
        $scope = $this->stay->keepsInventoryFor($booking) ? InventoryScope::FULL : InventoryScope::MINIMAL;
        if ($scope === InventoryScope::FULL && $lines === [] && $meters === []) {
            // A template not copied into the booking yet: that happens at
            // the confirmation.
            throw new RentalException(
                "La liste d'état des lieux de ce bien est copiée dans la réservation à sa confirmation : "
                    . 'confirmez-la avant de faire son état des lieux.'
            );
        }
        // A minimal inventory is signed on the screen (#989, lots F and G):
        // there is no checklist to print, so a sheet signed on paper would
        // carry signatures under nothing the PDF states.
        if ($scope === InventoryScope::MINIMAL && $sheet !== null) {
            throw new RentalException(self::MINIMAL_NOT_ON_PAPER);
        }
        $remark = $scope === InventoryScope::MINIMAL ? self::remark($remark) : null;

        $missing = $this->missingReadings($booking, $asset->id, $phase);
        if ($missing !== []) {
            throw new RentalException(
                'Il manque le relevé de : ' . implode(', ', $missing)
                    . '. Un état des lieux validé ne se complète plus.'
            );
        }

        // The photos' copies are made before anything is locked: ten
        // decodes and resamples are the slow part, and they must not run
        // with the booking held and another manager waiting on it.
        $isDeparture = $phase === ReadingPhase::DEPARTURE;
        $images = $this->photoRenderer->render(InventoryPhotoAnnex::fileIds(
            $phase,
            $meters,
            $isDeparture ? $this->stay->incidentsFor($booking->id) : []
        ));
        // The sheet signed on paper is the signatures: one that cannot be
        // read refuses the validation, rather than a PDF citing nothing.
        $sheetImage = $sheet !== null
            ? $this->photoRenderer->render([$sheet->fileId])[$sheet->fileId]
                ?? throw new RentalException(self::SHEET_UNREADABLE)
            : null;

        $label = $phase === ReadingPhase::ARRIVAL ? "État des lieux d'entrée" : 'État des lieux de sortie';
        // The PDF on disk, deleted if the transaction does not commit.
        $writtenPath = null;

        try {
            [$document, $sha256, $sheetDocumentId] = $this->documentRepository->withBookingLocked(
                $booking->id,
                function () use (
                    $booking,
                    $asset,
                    $phase,
                    $actorMemberId,
                    $renter,
                    $manager,
                    $now,
                    $isDeparture,
                    $label,
                    $signatures,
                    $images,
                    $sheet,
                    $sheetImage,
                    $accountId,
                    $scope,
                    $remark,
                    &$writtenPath
                ): array {
                    if (!$this->stay->recordInventoryValidation(
                        $booking,
                        $phase,
                        $now,
                        $actorMemberId,
                        $accountId,
                        $renter->choice,
                        $scope,
                        $remark
                    )) {
                        throw new RentalException('Cet état des lieux est déjà validé.');
                    }
                    // Read again now that the phase is frozen: a line saved
                    // by another manager between the reads above and the
                    // validation is in, and none can land after this read
                    // (the writes refuse a validated phase), so the PDF
                    // holds exactly what stays stored. A photo added in
                    // between gets its copy now; one removed is simply not
                    // in what is read.
                    $lines = $this->stay->inventoryFor($booking->id);
                    $meters = $this->stay->consumptionsFor($booking, $asset->id);
                    $incidents = $isDeparture ? $this->stay->incidentsFor($booking->id) : [];
                    // Read from the incidents as frozen: a minimal departure
                    // with some never says « en bon état ».
                    $statement = $scope === InventoryScope::MINIMAL
                        ? InventoryScope::statement($phase, $remark, $incidents !== [])
                        : null;

                    // « Valider, signer et mémoriser » is one act: kept here,
                    // in this transaction, so that anything failing below
                    // keeps nothing.
                    if ($manager !== null) {
                        $this->signatures->rememberIfAsked($manager, $now);
                    }

                    $fileIds = InventoryPhotoAnnex::fileIds($phase, $meters, $incidents);
                    $images += $this->photoRenderer->render(array_values(array_diff($fileIds, array_keys($images))));
                    $photos = InventoryPhotoAnnex::photos($phase, $meters, $incidents, $images);
                    unset($images);

                    // The sheet signed on paper, filed with the booking's
                    // documents — for the unit: the renter has it in the
                    // PDF — in this transaction, so a failure files nothing.
                    $sheetDocumentId = null;
                    $sheetPhoto = null;
                    if ($sheet !== null && $sheetImage !== null) {
                        $sheetDocumentId = $this->documents->attachUploaded(
                            $booking,
                            $sheet->fileId,
                            DocumentType::PHOTO,
                            false,
                            $actorMemberId
                        );
                        $sheetPhoto = InventoryPhotoAnnex::paperSheet($photos, $sheet->fileId, $sheetImage, $now);
                    }

                    $document = $this->documents->attachPdf(
                        $booking,
                        $this->pdf->generate(
                            $label . ' — ' . $booking->reference,
                            InventoryReport::html(
                                $phase,
                                $lines,
                                $meters,
                                $incidents,
                                $signatures,
                                $photos,
                                $sheetPhoto,
                                $statement
                            ),
                            (string) ($this->settings->get('site_name') ?: 'Unité scoute'),
                            $this->metaLines($booking, $asset)
                        ),
                        DocumentType::INVENTORY,
                        ($phase === ReadingPhase::ARRIVAL ? 'etat-des-lieux-entree-' : 'etat-des-lieux-sortie-')
                            . $booking->reference . '.pdf',
                        true,
                        $actorMemberId
                    );
                    $writtenPath = $this->documents->absolutePath($document);
                    // The fingerprint of the bytes as filed — the very file
                    // the renter is sent.
                    $sha256 = $writtenPath !== null ? hash_file('sha256', $writtenPath) : false;
                    if ($sha256 === false) {
                        throw new RentalException(
                            "Le PDF de l'état des lieux n'a pas pu être relu. Rien n'a été validé ni signé."
                        );
                    }
                    $this->stay->attachInventoryDocument($booking, $phase, $document->id, $sha256, $sheetDocumentId);

                    // What the renter did and the fingerprint, never their
                    // typed name: the history is the unit's, the name the
                    // renter's, and it lives in the PDF alone. Nor the
                    // remark: it is kept once, encrypted, on the row.
                    $this->bookingAudit->record(
                        $booking->id,
                        BookingAudit::STATUS_CHANGED,
                        null,
                        $label . ($scope === InventoryScope::MINIMAL ? ' minimal' : '') . ' — '
                            . $renter->choice->historyLabel() . ' — SHA-256 ' . $sha256,
                        'État des lieux signé',
                        $actorMemberId,
                        // The account that signed — or, on paper, that
                        // validated — the session's.
                        $accountId
                    );

                    return [$document, $sha256, $sheetDocumentId];
                }
            );
        } catch (\Throwable $e) {
            // Rolled back: no validation, no document row, nothing
            // remembered. The bytes on disk are not part of the
            // transaction, and they carry both signatures.
            if ($writtenPath !== null && is_file($writtenPath)) {
                @unlink($writtenPath);
            }
            throw $e instanceof RentalException
                ? $e
                : new RentalException(
                    "Le PDF de l'état des lieux n'a pas pu être produit. Rien n'a été validé ni signé.",
                    0,
                    $e
                );
        }

        if ($manager !== null) {
            // The act, in the core's journal, once the document exists:
            // which document, which bytes, by which account — never a
            // pixel, and nothing of the renter.
            $this->signatures->recordSigning(
                $manager,
                'rental',
                DocumentType::INVENTORY->value,
                $document->id,
                $booking->id,
                $now,
                $sha256
            );
            // The signer's own trace, whatever becomes of the renter's e-mail.
            $this->notifySigner($manager, $booking, $asset, $phase, $document, $now);
        } else {
            // Signed on paper: no electronic signature to record, nor a
            // « signed with your signature » to notify — but the account
            // that validated, the document, its bytes and the sheet's
            // photo, in the journal as for any sensitive act.
            $this->recordPaperSigning($booking, $document, $sha256, $sheetDocumentId, $accountId);
        }

        // Sent like any document (§6.24), whatever the renter did: absent
        // or refusing, they still receive what was recorded. Validated
        // either way: the PDF is filed, and Documents resends it.
        $sent = false;
        $path = $this->documents->absolutePath($document);
        if ($path !== null) {
            try {
                $this->mail->sendDocument(
                    $booking,
                    $asset,
                    $label,
                    $path,
                    $document->originalName ?? 'etat-des-lieux.pdf',
                    false,
                    null,
                    $document->id
                );
                $sent = true;
            } catch (\Throwable) {
                $sent = false;
            }
            // Recording the send is bookkeeping: once the e-mail has left,
            // a failure here must not tell the manager to send it again.
            if ($sent) {
                try {
                    $this->documents->markSent($document->id, $now);
                } catch (\Throwable) {
                    // The renter has it; Documents merely shows no date.
                }
            }
        }

        return ['document' => $document, 'sent' => $sent, 'sha256' => $sha256];
    }

    /**
     * The journal line of an inventory signed on paper: ids and the
     * fingerprint, never a name — the renter's is on the sheet alone. A
     * journal that cannot be written changes nothing of the committed
     * validation.
     */
    private function recordPaperSigning(
        RentalBooking $booking,
        RentalDocument $document,
        string $sha256,
        ?int $sheetDocumentId,
        ?int $accountId
    ): void {
        try {
            $this->journal->log(
                'rental',
                'rental_inventory_signed_on_paper',
                'info',
                'État des lieux signé sur papier par les deux parties',
                [
                    'booking_id' => $booking->id,
                    'document_id' => $document->id,
                    'document_sha256' => $sha256,
                    'paper_sheet_document_id' => $sheetDocumentId,
                ],
                $accountId
            );
        } catch (\Throwable $e) {
            error_log(sprintf(
                'ScoutMagic: the journal line of an inventory signed on paper failed (%s): booking #%d, document #%d',
                $e::class,
                $booking->id,
                $document->id
            ));
        }
    }

    /**
     * Tells the signing account — `AccountSignature::$accountId`, the
     * session's, and nobody else — that its signature went on this
     * inventory (decision Q1 of #989, as #926's countersignature does):
     * which one, the reference and the time on the application clock, a
     * link to the booking's Documents; no renter data, no file.
     *
     * Dispatched without marking the signer as its actor, so the push goes
     * out with the in-app row. A failure is journaled with ids only and
     * changes nothing of the committed signing.
     */
    private function notifySigner(
        AccountSignature $signature,
        RentalBooking $booking,
        RentalAsset $asset,
        ReadingPhase $phase,
        RentalDocument $document,
        \DateTimeImmutable $now
    ): void {
        if ($this->notifications === null) {
            return;
        }

        $which = $phase === ReadingPhase::ARRIVAL ? "d'entrée" : 'de sortie';
        $at = DateFilterExtension::datetimeFr($now->setTimezone(AppClock::zone()));
        $bookingUrl = '/mes-locations/' . rawurlencode($asset->slug) . '/reservations/' . $booking->id;

        try {
            $this->notifications->dispatch(
                RentalSignedContractService::SIGNED_NOTIFICATION,
                [['userAccountId' => $signature->accountId, 'memberId' => null]],
                [
                    'title' => 'État des lieux ' . $which . ' signé — ' . $asset->name,
                    'body' => "Vous avez signé l'état des lieux " . $which . ' de ' . $booking->reference
                        . ' le ' . $at . ', avec votre signature. Il est rangé dans les Documents de la réservation.',
                    'url' => BookingPage::DOCUMENTS->url($bookingUrl),
                ]
            );
        } catch (\Throwable $e) {
            $cause = (new \ReflectionClass($e))->getShortName();
            try {
                $this->journal->log(
                    'rental',
                    'rental_signer_notification_failed',
                    'warning',
                    "La notification au signataire d'un état des lieux n'a pas pu être créée (" . $cause . ').',
                    ['booking_id' => $booking->id, 'document_id' => $document->id],
                    $signature->accountId
                );
            } catch (\Throwable $journalError) {
                error_log(sprintf(
                    'ScoutMagic: a signer notification failed (%s) and so did its journal line (%s):'
                        . ' booking #%d, document #%d',
                    $cause,
                    $journalError::class,
                    $booking->id,
                    $document->id
                ));
            }
        }
    }

    /**
     * The lines under the title of both PDFs: the asset, the stay, the renter.
     *
     * @return list<string>
     */
    private function metaLines(RentalBooking $booking, RentalAsset $asset): array
    {
        return [
            'Bien : ' . $asset->name,
            'Séjour : du ' . self::frenchDate($booking->arrivalDate) . ' au '
                . self::frenchDate($booking->departureDate),
            'Locataire : ' . $booking->renterName,
        ];
    }

    /**
     * A minimal inventory's remark as stored: trimmed, null when blank.
     *
     * @throws RentalException over `InventoryScope::REMARK_MAX` characters
     */
    private static function remark(?string $remark): ?string
    {
        $remark = $remark !== null ? trim($remark) : '';
        if ($remark === '') {
            return null;
        }
        if (mb_strlen($remark) > InventoryScope::REMARK_MAX) {
            throw new RentalException(
                'La remarque ne peut pas dépasser ' . number_format(InventoryScope::REMARK_MAX, 0, ',', ' ')
                    . ' caractères.'
            );
        }

        return $remark;
    }

    private static function frenchDate(string $isoDate): string
    {
        return DateInput::iso($isoDate)?->format('d/m/Y') ?? $isoDate;
    }
}
