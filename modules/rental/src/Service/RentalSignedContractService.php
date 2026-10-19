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
        private ?\Closure $trackingToken = null,
        /**
         * The asset's lock and its availability check (#927, chantier
         * §2.1): a copy received holds the dates firmly, a copy refused
         * holds them a few days more, and both are writes of an occupation.
         * Null leaves both unguarded — a fixture's shortcut, never a
         * screen's.
         */
        private ?RentalOccupancyGuard $occupancy = null,
        /**
         * A booking's modification in progress (#927, chantier §2.3): its
         * replacement contract is the one awaiting a copy while it is out,
         * and its countersignature applies the modification. Null leaves
         * every booking with its first contract only.
         */
        private ?RentalModificationService $modifications = null
    ) {
    }

    /** What a renter is told when their dates went to somebody else before their copy arrived. */
    public const DATES_NO_LONGER_HELD = "Vos dates ne sont plus bloquées pour vous et ne sont plus libres : "
        . "contactez l'unité avant d'envoyer votre copie signée.";

    // ── Where the contract stands ───────────────────────────────────────

    /**
     * The latest contract in force sent to the renter, or null while none
     * has gone. A replacement contract still waiting for its
     * countersignature is not it (`replacement()`).
     */
    public function sentContract(int $bookingId): ?RentalDocument
    {
        $found = null;
        foreach ($this->documentRepository->findForBooking($bookingId) as $document) {
            if ($document->type === DocumentType::CONTRACT && $document->sentAt !== null
                && $document->countsForTheBooking()
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

        return $this->latest(
            $bookingId,
            DocumentType::SIGNED_COPY,
            static fn(RentalDocument $d): bool => !$d->isRefused()
        );
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

        return $this->latest(
            $bookingId,
            DocumentType::SIGNED_COPY,
            static fn(RentalDocument $d): bool => $d->isRefused()
        );
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
     * The booking's modification in progress with its replacement contract
     * and the copies of it (#927, chantier §2.3), or null.
     */
    public function replacement(int $bookingId): ?\Modules\Rental\Booking\ModificationProgress
    {
        return $this->modifications?->progress($bookingId);
    }

    /** What a renter is told while a replacement contract has not reached them. */
    public const REPLACEMENT_NOT_RECEIVED = 'Un nouveau contrat vous est destiné et ne vous est pas encore parvenu : '
        . "il vous sera envoyé par e-mail. Déposez ici la copie signée de ce nouveau contrat dès sa réception.";

    /**
     * The same, once its replacement contract is generated and the project
     * frozen (`sent`) — **whether or not the e-mail left**. From then on
     * every copy answers that contract and never the one in force: a copy
     * filed against the old contract would hold the booking's dates
     * firmly, and a refusal of it would lengthen the booking's hold, for a
     * contract about to be replaced (CodeRabbit on #976). The renter, who
     * may not have received it, is kept from filing anything until they
     * have (`whyNoCopy()`).
     */
    private function replacementOut(int $bookingId): ?\Modules\Rental\Booking\ModificationProgress
    {
        $replacement = $this->replacement($bookingId);

        return $replacement !== null && $replacement->contract !== null
            && $replacement->modification->status === \Modules\Rental\Booking\ModificationStatus::SENT
            ? $replacement
            : null;
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
     *
     * Given the asset, it also asks whether the copy could hold the dates
     * (#927, chantier §2.1): a signed copy holds them firmly, so one sent
     * after the hold lapsed and somebody else took the week is refused
     * before its file is stored, rather than handing the same dates to two
     * renters.
     */
    public function whyNoCopy(
        RentalBooking $booking,
        ?RentalAsset $asset = null,
        ?\DateTimeImmutable $now = null
    ): ?string {
        // A replacement contract out for signature is the one a copy
        // answers (chantier §2.3). Its new dates are held by its project, so
        // nothing about the booking's own hold is asked.
        $replacement = $this->replacementOut($booking->id);
        if ($replacement !== null && $replacement->contract !== null) {
            if ($booking->status->isAbandoned()) {
                return "Aucun contrat n'attend votre signature pour le moment.";
            }
            // Its e-mail failed: the renter holds only the contract in
            // force, and a copy of THAT one must not land on the
            // replacement, nor on the contract being replaced.
            if ($replacement->contract->sentAt === null) {
                return self::REPLACEMENT_NOT_RECEIVED;
            }

            return $replacement->pendingCopy !== null
                ? 'Votre copie signée du nouveau contrat a déjà été reçue : elle est en cours de vérification.'
                : null;
        }

        if ($this->finalContract($booking->id) !== null) {
            return 'Votre contrat est déjà signé par les deux parties.';
        }
        if ($this->pendingCopy($booking->id) !== null) {
            return 'Votre copie signée a déjà été reçue : elle est en cours de vérification.';
        }
        if ($booking->status->isAbandoned() || $this->sentContract($booking->id) === null) {
            return "Aucun contrat n'attend votre signature pour le moment.";
        }
        if ($asset !== null && $this->occupancy !== null
            && !$this->occupancy->mayHoldItsDates($booking, $asset, $now ?? new \DateTimeImmutable())
        ) {
            return self::DATES_NO_LONGER_HELD;
        }

        return null;
    }

    // ── 2. The renter's copy ────────────────────────────────────────────

    /**
     * Files the copy a renter sent from their page, and tells the managers.
     *
     * **From this moment the dates are held firmly** (#927, chantier §2.1):
     * a signed copy not refused is the renter's commitment, and their dates
     * no longer lapse while the unit checks it. Taking them for good is a
     * write of an occupation, so it is decided under the asset's lock — the
     * booking read again, and its dates free of everybody else when its own
     * hold has lapsed. Nothing changes the status: confirming stays the
     * unit's decision.
     *
     * @throws RentalException when no copy is expected now
     */
    public function receiveCopy(
        RentalBooking $booking,
        RentalAsset $asset,
        int $fileId,
        ?\DateTimeImmutable $now = null
    ): RentalDocument {
        $now ??= new \DateTimeImmutable();
        $file = function () use ($booking, $asset, $fileId, $now): array {
            $current = $this->occupancy?->fresh($booking) ?? $booking;
            $refusal = $this->whyNoCopy($current, $asset, $now);
            if ($refusal !== null) {
                throw new RentalException($refusal);
            }

            [$contractId, $modificationId] = $this->copyTarget($current);
            $documentId = $this->documentRepository->create(
                $current->id,
                $fileId,
                DocumentType::SIGNED_COPY,
                1,
                false,
                null,
                null,
                RentalDocument::SOURCE_MANUAL,
                $contractId,
                $modificationId
            );

            $this->bookingAudit->record(
                $current->id,
                BookingAudit::STATUS_CHANGED,
                null,
                DocumentType::SIGNED_COPY->label(),
                $modificationId !== null
                    ? 'Copie signée du contrat de remplacement déposée par le locataire'
                    : 'Copie signée déposée par le locataire',
                null
            );

            return [$documentId, $modificationId !== null];
        };
        [$documentId, $ofReplacement] = $this->occupancy !== null
            ? $this->occupancy->onAsset($asset->id, $file)
            : $file();

        // After the commit: a notification that fails must not take the
        // copy back with it.
        $this->notifyManagers($booking, $asset, $ofReplacement);

        return $this->documentRepository->findById($documentId)
            ?? throw new RentalException("Votre copie n'a pas pu être enregistrée.");
    }

    /**
     * Files a signed copy a manager brought in — uploaded by hand, or an
     * e-mail's attachment reclassified (`$existingDocumentId`) — **through
     * the same guard as the renter's** (#927, chantier §2.1): under the
     * asset's lock, refused when it would hold dates somebody else took
     * since this booking's hold lapsed, and tied to the contract it
     * answers — the replacement contract out for signature, else the
     * contract in force. No notification: the manager is the one filing it.
     * Returns the document's id.
     *
     * @throws RentalException
     */
    public function fileCopyFromManager(
        RentalBooking $booking,
        RentalAsset $asset,
        ?int $fileId,
        ?int $existingDocumentId,
        bool $isForRenter,
        ?int $actorMemberId,
        ?\DateTimeImmutable $now = null
    ): int {
        $now ??= new \DateTimeImmutable();
        $file = function () use (
            $booking,
            $asset,
            $fileId,
            $existingDocumentId,
            $isForRenter,
            $actorMemberId,
            $now
        ): int {
            $current = $this->occupancy?->fresh($booking) ?? $booking;
            [$contractId, $modificationId] = $this->copyTarget($current);
            if ($modificationId === null && $this->occupancy !== null
                && !$this->occupancy->mayHoldItsDates($current, $asset, $now)
            ) {
                throw new RentalException(
                    'Les dates de cette réservation ne sont plus bloquées et sont occupées par ailleurs : '
                    . 'une copie signée ne peut plus les bloquer. Changez les dates ou refusez la demande.'
                );
            }

            $existing = $existingDocumentId !== null ? $this->documentRepository->findById($existingDocumentId) : null;
            if ($existing !== null) {
                $this->documentRepository->updateType($existing->id, DocumentType::SIGNED_COPY, $isForRenter);
                $this->documentRepository->attachToContract($existing->id, $contractId, $modificationId);
                $documentId = $existing->id;
            } else {
                $documentId = $this->documentRepository->create(
                    $current->id,
                    (int) $fileId,
                    DocumentType::SIGNED_COPY,
                    1,
                    $isForRenter,
                    null,
                    $actorMemberId,
                    RentalDocument::SOURCE_MANUAL,
                    $contractId,
                    $modificationId
                );
            }

            $this->bookingAudit->record(
                $current->id,
                BookingAudit::STATUS_CHANGED,
                $existing?->type->label(),
                DocumentType::SIGNED_COPY->label(),
                $existing !== null ? 'Document reclassé' : 'Document ajouté',
                $actorMemberId
            );

            return $documentId;
        };

        return $this->occupancy !== null ? $this->occupancy->onAsset($asset->id, $file) : $file();
    }

    // ── 3. The manager's answer ─────────────────────────────────────────

    /**
     * Refuses a copy with the reason the renter is sent; they may send
     * another. The copy stays on file. Returns whether the e-mail saying
     * so went out — the refusal stands either way, and the renter's page
     * says it.
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
    ): bool {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RentalException('Dites au locataire pourquoi sa copie est refusée.');
        }
        if (mb_strlen($reason) > self::MAX_REFUSAL_LENGTH) {
            throw new RentalException('Le motif tient en ' . self::MAX_REFUSAL_LENGTH . ' caractères au plus.');
        }

        // Decided and written under the booking's lock: a countersignature
        // of the same copy cannot slip in between the check and the write.
        // Inside the asset's lock, and never around it (Repository\AssetLock):
        // the refusal ends the firm hold the copy gave and lengthens the
        // provisional one, which is a write of an occupation (#927, §2.1).
        $refuse = function () use ($booking, $documentId, $reason, $actorMemberId, $now): void {
            $this->documentRepository->withBookingLocked($booking->id, function () use (
                $booking,
                $documentId,
                $reason,
                $actorMemberId,
                $now
            ): void {
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

                // A copy of a replacement contract held nothing of the
                // booking's: its project holds its own dates, refused copy
                // or not (chantier §2.3).
                if ($copy->modificationId === null) {
                    $this->holdAfterRefusal($booking, $actorMemberId, $now);
                }
            });
        };
        if ($this->occupancy !== null) {
            $this->occupancy->onAsset($asset->id, $refuse);
        } else {
            $refuse();
        }

        return $this->mail->sendCopyRefused($booking, $asset, $reason, $this->tokenOf($booking));
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

        $fileName = 'contrat-signe-' . $booking->reference . '.pdf';

        // Decided, assembled and filed under the booking's lock: a second
        // countersignature, or a refusal, of the same copy waits, then finds
        // it answered. Nothing is written before the signed contract itself,
        // so a copy that cannot be assembled is left exactly as it was.
        //
        // Inside the asset's lock (Repository\AssetLock): countersigning a
        // replacement contract moves the booking's dates (#927, §2.3).
        $countersign = fn(): RentalDocument => $this->documentRepository->withBookingLocked(
            $booking->id,
            function () use (
                $booking,
                $asset,
                $documentId,
                $signature,
                $actorName,
                $actorMemberId,
                $now,
                $fileName
            ): RentalDocument {
                $copy = $this->pendingCopyOf($booking, $documentId);
                $path = $this->documents->absolutePath($copy);
                if ($path === null) {
                    throw new RentalException(
                        'Le fichier de cette copie est introuvable. Demandez-en une autre au locataire.'
                    );
                }

                // A replacement contract signs the project's terms, not the
                // booking's: its countersignature page says those.
                $modifications = $this->modifications;
                $modification = $copy->modificationId !== null
                    ? $modifications?->find($booking, $copy->modificationId)
                    : null;
                $signed = $modification !== null
                    ? $booking->withTerms(
                        $modification->arrivalDate,
                        $modification->departureDate,
                        $modification->units,
                        $modification->persons,
                        $modification->price
                    )
                    : $booking;

                $pdf = $this->assemble($path, $signed, $asset, $copy, $signature, $actorName, $now);
                $final = $this->documents->attachPdf(
                    $booking,
                    $pdf,
                    DocumentType::SIGNED_CONTRACT,
                    $fileName,
                    true,
                    $actorMemberId,
                    $copy->contractDocumentId,
                    $copy->modificationId
                );

                $this->bookingAudit->record(
                    $booking->id,
                    BookingAudit::STATUS_CHANGED,
                    DocumentType::SIGNED_COPY->label(),
                    DocumentType::SIGNED_CONTRACT->label(),
                    $modification !== null ? 'Contrat de remplacement contresigné' : 'Contrat contresigné',
                    $actorMemberId
                );

                // **The countersignature finalises the modification**, in this
                // very transaction (chantier §2.3): no button of its own, and
                // nothing half-applied if anything below fails.
                if ($modification !== null) {
                    $modifications->applyOnCountersignature(
                        $modification,
                        $this->occupancy?->fresh($booking) ?? $booking,
                        $asset,
                        $actorMemberId,
                        $now
                    );
                }

                return $final;
            }
        );
        $final = $this->occupancy !== null ? $this->occupancy->onAsset($asset->id, $countersign) : $countersign();
        // The renter's e-mail says the booking as it now stands.
        $booking = $this->occupancy?->fresh($booking) ?? $booking;

        $finalPath = $this->documents->absolutePath($final);
        if ($finalPath !== null) {
            try {
                $this->mail->sendSignedContract(
                    $booking,
                    $asset,
                    $finalPath,
                    $fileName,
                    $this->tokenOf($booking),
                    $final->id
                );
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
     * The renter's copy refused, the dates fall back to a provisional hold
     * — lasting at least `RentalOccupancyGuard::REFUSED_COPY_GRACE_DAYS`
     * more, capped at the start of the stay, never shortened (chantier
     * §2.1): time to send a correct copy, which holds them firmly again.
     */
    private function holdAfterRefusal(RentalBooking $booking, ?int $actorMemberId, \DateTimeImmutable $now): void
    {
        if ($this->occupancy === null) {
            return;
        }

        $current = $this->occupancy->fresh($booking);
        $before = $current->holdIsActive($now) ? $current->holdUntil : null;
        $until = $this->occupancy->holdAfterRefusedCopy($current, $now);
        if ($until === null) {
            return;
        }

        $this->bookingAudit->record(
            $current->id,
            BookingAudit::HOLD_EXTENDED,
            $before?->format('d/m/Y H:i'),
            $until->format('d/m/Y H:i'),
            'Blocage prolongé après le refus de la copie signée',
            $actorMemberId
        );
    }

    /**
     * @throws RentalException
     */
    private function pendingCopyOf(RentalBooking $booking, int $documentId): RentalDocument
    {
        $copy = $this->documentRepository->findById($documentId);
        if ($copy === null || $copy->bookingId !== $booking->id || $copy->type !== DocumentType::SIGNED_COPY) {
            throw new RentalException("Cette copie n'existe pas.");
        }
        if ($copy->isRefused()) {
            throw new RentalException('Cette copie a déjà reçu une réponse.');
        }
        if ($copy->modificationId !== null) {
            // A copy of a replacement contract is answered while its
            // project is out for signature, and only then.
            $modification = $this->modifications?->find($booking, $copy->modificationId);
            if ($modification === null || $modification->status !== \Modules\Rental\Booking\ModificationStatus::SENT) {
                throw new RentalException(
                    $modification !== null && $modification->status->isApplied()
                        ? 'Cette copie a déjà reçu une réponse.'
                        : "Cette modification n'est plus en cours : sa copie ne se contresigne plus."
                );
            }
        } elseif ($this->finalContract($booking->id) !== null) {
            throw new RentalException('Cette copie a déjà reçu une réponse.');
        }
        // Signed from a contract the booking has outgrown (#708, IT-20) —
        // a page opened before the change still posts its id. Countersigned,
        // it would file a contract « signed by both parties » on terms that
        // no longer hold, which nothing could void afterwards.
        if ($copy->isSuperseded()) {
            throw new RentalException(
                'Cette copie a été remplacée : la réservation a changé depuis. Un nouveau contrat doit partir.'
            );
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
                    . self::frenchDate($booking->arrivalDate)
                    . ' au ' . self::frenchDate($booking->departureDate) . '.',
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

    private function notifyManagers(RentalBooking $booking, RentalAsset $asset, bool $ofReplacement = false): void
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
            // A replacement contract is countersigned on Documents, beside
            // the contract in force (#927, chantier §2.4).
            $this->notifications->dispatch(self::NOTIFICATION, $recipients, [
                'title' => 'Contrat signé reçu — ' . $asset->name,
                'body' => $ofReplacement
                    ? 'La copie signée du contrat de remplacement de ' . $booking->reference
                        . ' attend votre contresignature.'
                    : 'La copie signée de ' . $booking->reference . ' attend votre contresignature.',
                'url' => '/mes-locations/' . rawurlencode($asset->slug) . '/reservations/' . $booking->id
                    . ($ofReplacement ? '/documents' : ''),
            ]);
        } catch (\Throwable) {
            // The booking's page still shows the copy; a failed
            // notification must not surface to the renter as their problem.
        }
    }

    /**
     * The contract a copy filed now answers, and the modification it
     * belongs to: the replacement contract out for signature, else the
     * contract in force (null when none was sent — a copy of a contract
     * signed on paper).
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function copyTarget(RentalBooking $booking): array
    {
        $replacement = $this->replacementOut($booking->id);
        if ($replacement !== null && $replacement->contract !== null) {
            return [$replacement->contract->id, $replacement->modification->id];
        }

        return [$this->sentContract($booking->id)?->id, null];
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
            // A document the booking has outgrown is on file, and nothing
            // else (#708, IT-20); one of a modification still out for
            // signature is its project's, not yet the booking's (#927).
            if ($document->type === $type && $document->countsForTheBooking() && $keep($document)
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
