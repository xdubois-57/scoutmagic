<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Core\Journal\JournalService;
use Core\Notification\NotificationService;
use Core\Pdf\PdfCompressor;
use Modules\Rental\Audit\BookingAudit;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Document\CountersignedContract;
use Modules\Rental\Document\DocumentType;
use Modules\Rental\Document\RentalDocument;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Repository\RentalDocumentRepository;
use Modules\Rental\Repository\RentalManagerSignatureRepository;

/**
 * The contract's two signatures (#708, IT-16).
 *
 * 1. The contract leaves unsigned (`RentalDocumentService`, IT-13).
 * 2. The renter signs it and sends a copy from their tracking page — a PDF,
 *    a scan or a photo: signing a PDF on an Android phone is the hard part
 *    of this whole journey, and a photo of a printed, signed sheet has to
 *    be enough. The managers are notified.
 * 3. A manager checks it and, in one gesture, either countersigns it or
 *    refuses it with a short reason the renter is sent.
 * 4. Countersigning files the renter's copy with one page added — who
 *    signed for the unit, when, their signature — as the contract signed by
 *    both parties, and mails it to the renter.
 *
 * **The manager's signature is applied here and nowhere else**: never on a
 * copy the renter has not signed, never by anyone but its owner — the
 * account countersigning is the account whose signature is read.
 */
class RentalSignedContractService
{
    /** The notification a copy arriving raises. */
    public const NOTIFICATION = 'rental.signed_contract_received';

    /** What the renter is told when a copy is refused, at most. */
    public const MAX_REFUSAL_LENGTH = 300;

    /** What a renter may send: a PDF, or a photo or scan of the paper. */
    public const COPY_MIMES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    /** 15 MB — a phone photo of a signed contract, with room to spare. */
    public const MAX_COPY_BYTES = 15 * 1024 * 1024;

    public function __construct(
        private RentalDocumentService $documents,
        private RentalDocumentRepository $documentRepository,
        private RentalManagerSignatureRepository $signatures,
        private BookingAudit $bookingAudit,
        private RentalBookingMailService $mail,
        private PdfCompressor $pdfRewriter,
        private JournalService $journal,
        private ?NotificationService $notifications = null,
        private ?ManagerRecipientResolver $recipients = null,
        /**
         * Reads the renter's tracking token, so an e-mail can link back to
         * their page. Null leaves the e-mails without the link.
         *
         * @var (\Closure(int): ?string)|null
         */
        private ?\Closure $trackingToken = null
    ) {
    }

    // ── Where the contract stands ───────────────────────────────────────

    /** The latest contract sent to the renter, or null while none has gone. */
    public function sentContract(int $bookingId): ?RentalDocument
    {
        $found = null;
        foreach ($this->documentRepository->findForBooking($bookingId) as $document) {
            if ($document->type === DocumentType::CONTRACT && $document->sentAt !== null
                && ($found === null || $document->version > $found->version)
            ) {
                $found = $document;
            }
        }

        return $found;
    }

    /** The copy waiting for a manager's answer, or null. */
    public function pendingCopy(int $bookingId): ?RentalDocument
    {
        if ($this->finalContract($bookingId) !== null) {
            return null;
        }

        return $this->latest($bookingId, DocumentType::SIGNED_COPY, static fn(RentalDocument $d): bool => !$d->isRefused());
    }

    /**
     * The last copy refused, for the renter's page to say why — while a
     * copy can still be sent: once the contract is signed by both parties,
     * « send another » is no longer true, on either side.
     */
    public function lastRefusedCopy(int $bookingId): ?RentalDocument
    {
        if ($this->finalContract($bookingId) !== null) {
            return null;
        }

        return $this->latest($bookingId, DocumentType::SIGNED_COPY, static fn(RentalDocument $d): bool => $d->isRefused());
    }

    /**
     * The contract signed by both parties — the one document the renter
     * may download from their page.
     */
    public function finalContract(int $bookingId): ?RentalDocument
    {
        return $this->latest($bookingId, DocumentType::SIGNED_CONTRACT, static fn(): bool => true);
    }

    /**
     * Whether the renter may send a copy now: a contract has gone, none is
     * signed by both parties yet, none is waiting for an answer, and the
     * booking is still alive.
     */
    public function acceptsCopy(RentalBooking $booking): bool
    {
        return $this->whyNoCopy($booking) === null;
    }

    /**
     * Why the renter may not send a copy now, in words for them — null
     * when they may.
     */
    public function whyNoCopy(RentalBooking $booking): ?string
    {
        if ($this->finalContract($booking->id) !== null) {
            return 'Votre contrat est déjà signé par les deux parties.';
        }
        if ($this->pendingCopy($booking->id) !== null) {
            return 'Votre copie signée a déjà été reçue : elle est en cours de vérification.';
        }
        if ($booking->status->isAbandoned() || $this->sentContract($booking->id) === null) {
            return "Aucun contrat n'attend votre signature pour le moment.";
        }

        return null;
    }

    // ── 2. The renter's copy ────────────────────────────────────────────

    /**
     * Files the copy a renter sent from their page, and tells the managers.
     *
     * @throws RentalException when no copy is expected now
     */
    public function receiveCopy(RentalBooking $booking, RentalAsset $asset, int $fileId): RentalDocument
    {
        $refusal = $this->whyNoCopy($booking);
        if ($refusal !== null) {
            throw new RentalException($refusal);
        }

        $documentId = $this->documentRepository->create(
            $booking->id,
            $fileId,
            DocumentType::SIGNED_COPY,
            1,
            false,
            null,
            null
        );

        $this->bookingAudit->record(
            $booking->id,
            BookingAudit::STATUS_CHANGED,
            null,
            DocumentType::SIGNED_COPY->label(),
            'Copie signée déposée par le locataire',
            null
        );

        $this->notifyManagers($booking, $asset);

        return $this->documentRepository->findById($documentId)
            ?? throw new RentalException("Votre copie n'a pas pu être enregistrée.");
    }

    // ── 3. The manager's answer ─────────────────────────────────────────

    /**
     * Refuses a copy with the reason the renter is sent; they may send
     * another. The copy stays on file.
     *
     * @throws RentalException
     */
    public function refuseCopy(
        RentalBooking $booking,
        RentalAsset $asset,
        int $documentId,
        string $reason,
        ?int $actorMemberId,
        \DateTimeImmutable $now
    ): void {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RentalException('Dites au locataire pourquoi sa copie est refusée.');
        }
        if (mb_strlen($reason) > self::MAX_REFUSAL_LENGTH) {
            throw new RentalException('Le motif tient en ' . self::MAX_REFUSAL_LENGTH . ' caractères au plus.');
        }

        $copy = $this->pendingCopyOf($booking, $documentId);
        if (!$this->documentRepository->markRefused($copy->id, $reason, $now)) {
            // Another manager answered first: one refusal, one e-mail.
            throw new RentalException('Cette copie a déjà reçu une réponse.');
        }

        $this->bookingAudit->record(
            $booking->id,
            BookingAudit::STATUS_CHANGED,
            DocumentType::SIGNED_COPY->label(),
            'Refusée',
            'Copie signée refusée',
            $actorMemberId
        );

        $this->mail->sendCopyRefused($booking, $asset, $reason, $this->tokenOf($booking));
    }

    /**
     * Countersigns a copy with this account's own signature, files the
     * contract signed by both parties and mails it to the renter.
     *
     * @throws RentalException
     */
    public function countersign(
        RentalBooking $booking,
        RentalAsset $asset,
        int $documentId,
        int $userAccountId,
        ?int $actorMemberId,
        string $actorName,
        \DateTimeImmutable $now
    ): RentalDocument {
        $signature = $this->signatures->findPng($userAccountId);
        if ($signature === null) {
            throw new RentalException(
                "Enregistrez d'abord votre signature : elle est apposée sur la page ajoutée au contrat."
            );
        }

        $copy = $this->pendingCopyOf($booking, $documentId);
        $path = $this->documents->absolutePath($copy);
        if ($path === null) {
            throw new RentalException('Le fichier de cette copie est introuvable. Demandez-en une autre au locataire.');
        }

        $pdf = $this->assemble($path, $booking, $asset, $copy, $signature, $actorName, $now);
        $fileName = 'contrat-signe-' . $booking->reference . '.pdf';
        $final = $this->documents->attachPdf(
            $booking,
            $pdf,
            DocumentType::SIGNED_CONTRACT,
            $fileName,
            true,
            $actorMemberId
        );

        $this->bookingAudit->record(
            $booking->id,
            BookingAudit::STATUS_CHANGED,
            DocumentType::SIGNED_COPY->label(),
            DocumentType::SIGNED_CONTRACT->label(),
            'Contrat contresigné',
            $actorMemberId
        );

        $finalPath = $this->documents->absolutePath($final);
        if ($finalPath !== null) {
            try {
                $this->mail->sendSignedContract($booking, $asset, $finalPath, $fileName, $this->tokenOf($booking));
                $this->documents->markSent($final->id, $now);
            } catch (\Throwable) {
                // Countersigned and filed either way: the renter can
                // download it from their page, and a manager can resend it
                // from Documents. The journal says it did not go.
                $this->journal->log(
                    'rental',
                    'rental_signed_contract_send_failed',
                    'warning',
                    "Le contrat signé n'a pas pu être envoyé pour " . $booking->reference,
                    ['booking_id' => $booking->id]
                );
            }
        }

        return $this->documentRepository->findById($final->id) ?? $final;
    }

    // ── Internals ───────────────────────────────────────────────────────

    /**
     * @throws RentalException
     */
    private function pendingCopyOf(RentalBooking $booking, int $documentId): RentalDocument
    {
        $copy = $this->documentRepository->findById($documentId);
        if ($copy === null || $copy->bookingId !== $booking->id || $copy->type !== DocumentType::SIGNED_COPY) {
            throw new RentalException("Cette copie n'existe pas.");
        }
        if ($copy->isRefused() || $this->finalContract($booking->id) !== null) {
            throw new RentalException('Cette copie a déjà reçu une réponse.');
        }

        return $copy;
    }

    /**
     * The renter's copy and the page the unit signs on, as one PDF.
     *
     * @throws RentalException when the copy cannot be read
     */
    private function assemble(
        string $path,
        RentalBooking $booking,
        RentalAsset $asset,
        RentalDocument $copy,
        string $signature,
        string $actorName,
        \DateTimeImmutable $now
    ): string {
        $bytes = (string) file_get_contents($path);
        $document = new CountersignedContract();

        if (str_starts_with($bytes, '%PDF-')) {
            $document = $this->withPdfPages($document, $path, $bytes);
        } else {
            $document->addImagePage(self::asJpeg($bytes));
        }

        $landlord = $this->documents->landlordFor($asset);
        $document->addCountersignaturePage(
            'Contresigné pour le bailleur',
            [
                'Contresigné pour ' . $landlord->name . ' par ' . $actorName . ', le ' . $now->format('d/m/Y') . '.',
                'Cette page complète le contrat signé par le locataire, ' . $booking->renterName
                    . ', qui la précède.',
            ],
            $signature,
            [
                'Réservation ' . $booking->reference . ' — ' . $asset->name . ', du '
                    . self::frenchDate($booking->arrivalDate) . ' au ' . self::frenchDate($booking->departureDate) . '.',
                'Copie signée du locataire reçue le ' . $copy->createdAt->format('d/m/Y') . '.',
            ]
        );

        return $document->render();
    }

    /**
     * Imports a PDF's pages — rewritten once as a classic PDF when FPDI's
     * parser cannot read it as it came, which is the common case for a
     * phone's scanner.
     *
     * @throws RentalException
     */
    private function withPdfPages(CountersignedContract $document, string $path, string $bytes): CountersignedContract
    {
        try {
            $document->addPdfPages($path);

            return $document;
        } catch (\Throwable) {
            // Fall through to the rewrite, on a fresh document: a failed
            // import may have left a page half-added.
        }

        $rewritten = $this->pdfRewriter->rewriteForImport($bytes);
        if ($rewritten !== null) {
            $temporary = tempnam(sys_get_temp_dir(), 'rental-copy-');
            if ($temporary !== false) {
                try {
                    file_put_contents($temporary, $rewritten);
                    $fresh = new CountersignedContract();
                    $fresh->addPdfPages($temporary);

                    return $fresh;
                } catch (\Throwable) {
                    // Reported below.
                } finally {
                    @unlink($temporary);
                }
            }
        }

        throw new RentalException(
            "Ce PDF n'a pas pu être repris pour y ajouter la contresignature. Refusez la copie en demandant "
            . 'au locataire une photo ou un scan du contrat signé.'
        );
    }

    /**
     * A photo or scan re-encoded as JPEG, the one image format every PDF
     * reader shows the same way.
     *
     * @throws RentalException
     */
    private static function asJpeg(string $bytes): string
    {
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new RentalException(
                "Cette copie n'a pas pu être lue. Refusez-la en demandant au locataire un PDF ou une photo JPEG."
            );
        }

        ob_start();
        imagejpeg($image, null, 85);

        return (string) ob_get_clean();
    }

    private function notifyManagers(RentalBooking $booking, RentalAsset $asset): void
    {
        if ($this->notifications === null || $this->recipients === null) {
            return;
        }

        try {
            $recipients = $this->recipients->recipientsFor($asset->id, 'copie signée reçue');
            if ($recipients === []) {
                return;
            }

            // The asset and the reference, never the renter (#708, IT-05).
            $this->notifications->dispatch(self::NOTIFICATION, $recipients, [
                'title' => 'Contrat signé reçu — ' . $asset->name,
                'body' => 'La copie signée de ' . $booking->reference . ' attend votre contresignature.',
                'url' => '/mes-locations/' . rawurlencode($asset->slug) . '/reservations/' . $booking->id,
            ]);
        } catch (\Throwable) {
            // The booking's page still shows the copy; a failed
            // notification must not surface to the renter as their problem.
        }
    }

    private function tokenOf(RentalBooking $booking): ?string
    {
        return $this->trackingToken !== null ? ($this->trackingToken)($booking->id) : null;
    }

    /**
     * @param \Closure(RentalDocument): bool $keep
     */
    private function latest(int $bookingId, DocumentType $type, \Closure $keep): ?RentalDocument
    {
        $found = null;
        foreach ($this->documentRepository->findForBooking($bookingId) as $document) {
            if ($document->type === $type && $keep($document)
                && ($found === null || $document->id > $found->id)
            ) {
                $found = $document;
            }
        }

        return $found;
    }

    private static function frenchDate(string $isoDate): string
    {
        return \Core\Service\DateInput::iso($isoDate)?->format('d/m/Y') ?? $isoDate;
    }
}
