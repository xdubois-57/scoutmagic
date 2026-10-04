<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Core\Config\SettingService;
use Core\Pdf\DocumentPdfService;
use Core\Service\DateInput;
use Modules\Rental\Audit\BookingAudit;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Document\DocumentType;
use Modules\Rental\Document\RentalDocument;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Stay\InventoryReport;
use Modules\Rental\Stay\ReadingPhase;

/**
 * « Valider l'état des lieux » (#708, IT-17): the phase frozen, its PDF
 * filed with the booking's documents and sent to the renter.
 *
 * **Frozen first, then produced.** The validation row is written before
 * anything else — its unique key is what stops two managers producing two
 * PDFs — and taken back if the PDF cannot be made, so a failure leaves the
 * inventory open rather than validated with nothing to show for it.
 *
 * **A missing reading blocks; an unchecked line does not.** An item nobody
 * looked at is printed « non vérifié », which is an honest record; a meter
 * with no reading would leave the settlement with no consumption, and a
 * validated inventory can no longer be completed.
 */
class RentalInventoryValidationService
{
    public function __construct(
        private RentalStayService $stay,
        private RentalDocumentService $documents,
        private RentalBookingMailService $mail,
        private DocumentPdfService $pdf,
        private SettingService $settings,
        private BookingAudit $bookingAudit
    ) {
    }

    /**
     * The meters still lacking their reading for this phase.
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
     * @return array{document: RentalDocument, sent: bool}
     * @throws RentalException
     */
    public function validate(
        RentalBooking $booking,
        RentalAsset $asset,
        ReadingPhase $phase,
        ?int $actorMemberId,
        string $actorName,
        \DateTimeImmutable $now,
        bool $arrivalTickedByHand = false
    ): array {
        $this->stay->assertPhaseOpen($booking->id, $phase, $arrivalTickedByHand);

        $lines = $this->stay->inventoryFor($booking->id);
        $meters = $this->stay->consumptionsFor($booking, $asset->id);
        if ($lines === [] && $meters === []) {
            throw new RentalException(
                "Ce bien n'a ni éléments d'état des lieux ni compteurs : l'état des lieux se coche à la main."
            );
        }

        $missing = $this->missingReadings($booking, $asset->id, $phase);
        if ($missing !== []) {
            throw new RentalException(
                'Il manque le relevé de : ' . implode(', ', $missing)
                    . '. Un état des lieux validé ne se complète plus.'
            );
        }

        if (!$this->stay->recordInventoryValidation($booking, $phase, $now, $actorMemberId)) {
            throw new RentalException('Cet état des lieux est déjà validé.');
        }
        // Read again now that the phase is frozen: a line saved by another
        // manager between the reads above and the validation is in, and
        // none can land after this read (the writes refuse a validated
        // phase), so the PDF holds exactly what stays stored.
        $lines = $this->stay->inventoryFor($booking->id);
        $meters = $this->stay->consumptionsFor($booking, $asset->id);

        $label = $phase === ReadingPhase::ARRIVAL ? "État des lieux d'entrée" : 'État des lieux de sortie';
        $document = null;
        try {
            $pdf = $this->pdf->generate(
                $label . ' — ' . $booking->reference,
                InventoryReport::html(
                    $phase,
                    $lines,
                    $meters,
                    $phase === ReadingPhase::DEPARTURE ? $this->stay->incidentsFor($booking->id) : [],
                    $actorName,
                    $now
                ),
                (string) ($this->settings->get('site_name') ?: 'Unité scoute'),
                [
                    'Bien : ' . $asset->name,
                    'Séjour : du ' . self::frenchDate($booking->arrivalDate) . ' au '
                        . self::frenchDate($booking->departureDate),
                    'Locataire : ' . $booking->renterName,
                ]
            );
            $document = $this->documents->attachPdf(
                $booking,
                $pdf,
                DocumentType::INVENTORY,
                ($phase === ReadingPhase::ARRIVAL ? 'etat-des-lieux-entree-' : 'etat-des-lieux-sortie-')
                    . $booking->reference . '.pdf',
                true,
                $actorMemberId
            );
            $this->stay->attachInventoryDocument($booking, $phase, $document->id);
        } catch (\Throwable $e) {
            // Taken back: an inventory validated with no PDF would be
            // frozen with nothing to show for it. And a PDF filed for a
            // validation that is taken back goes with it: left in
            // Documents, it could still be sent to the renter as if the
            // inventory had been validated.
            if ($document !== null) {
                try {
                    $this->documents->delete($document, $actorMemberId);
                } catch (\Throwable) {
                    // The validation is taken back regardless; a stray
                    // unsent PDF is the lesser fault.
                }
            }
            $this->stay->forgetInventoryValidation($booking, $phase);
            throw $e instanceof RentalException
                ? $e
                : new RentalException(
                    "Le PDF de l'état des lieux n'a pas pu être produit. Rien n'a été validé.",
                    0,
                    $e
                );
        }

        $this->bookingAudit->record(
            $booking->id,
            BookingAudit::STATUS_CHANGED,
            null,
            $label,
            'État des lieux validé',
            $actorMemberId
        );

        // Sent like any document (§6.24). Validated either way: the PDF is
        // filed, and Documents resends it.
        $sent = false;
        $path = $this->documents->absolutePath($document);
        if ($path !== null) {
            try {
                $this->mail->sendDocument(
                    $booking,
                    $asset,
                    $label,
                    $path,
                    $document->originalName ?? 'etat-des-lieux.pdf'
                );
                $this->documents->markSent($document->id, $now);
                $sent = true;
            } catch (\Throwable) {
                $sent = false;
            }
        }

        return ['document' => $document, 'sent' => $sent];
    }

    private static function frenchDate(string $isoDate): string
    {
        return DateInput::iso($isoDate)?->format('d/m/Y') ?? $isoDate;
    }
}
