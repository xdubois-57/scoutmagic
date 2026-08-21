<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Core\Journal\JournalService;
use Modules\InboundMail\Api\InboundMailInterface;
use Modules\InboundMail\Api\InboundMessage;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Document\DocumentType;
use Modules\Rental\Document\RentalDocument;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalDocumentRepository;

/**
 * The Communications tab of a booking (§7.7).
 *
 * A thin layer over `Modules\InboundMail\Api\InboundMailInterface`, and
 * thin on purpose: everything about reading a mailbox lives in that module,
 * and everything about who may see a booking lives here. The two rules this
 * class exists to enforce:
 *
 * - **A manager may only move a message to a booking of an asset they
 *   manage.** The list of targets is derived from their own manageable
 *   assets rather than filtered from a global list, so a hand-crafted POST
 *   naming somebody else's booking finds no candidate rather than being
 *   rejected after the fact — and the target list is never a doorway into
 *   the rest of the unit's bookings.
 * - **A manager cannot attach a new message.** There is no surface for it
 *   anywhere in this class, because there is no surface onto the mailbox at
 *   all: attaching is what the automatic rules do, and correcting them is
 *   what detach and move are for.
 *
 * The whole class degrades to nothing when `inbound_mail` is disabled —
 * `$inboundMail` is null and every method answers as if no message ever
 * arrived, which is exactly true.
 */
class RentalCommunicationService
{
    public function __construct(
        private RentalBookingRepository $bookingRepository,
        private RentalDocumentRepository $documentRepository,
        private RentalAuthorizationService $authorizationService,
        private JournalService $journal,
        private ?InboundMailInterface $inboundMail = null
    ) {
    }

    /**
     * Whether the tab is worth showing at all: the module is present and at
     * least one mailbox is enabled. A tab that can only ever be empty is
     * noise on a page that already has a lot on it.
     */
    public function isAvailable(): bool
    {
        return $this->inboundMail !== null && $this->inboundMail->isCollecting();
    }

    /**
     * @return InboundMessage[] oldest first
     */
    public function timeline(RentalBooking $booking): array
    {
        return $this->inboundMail?->findForReference(
            \Modules\Rental\Mail\RentalMessageConsumer::CONSUMER_ID,
            $booking->reference
        ) ?? [];
    }

    /**
     * Detach a message from this booking (§7.7).
     *
     * There is no unattached queue for it to fall into, so it is deleted —
     * **along with the attachments nobody re-classified**. An attachment a
     * manager already turned into a signed contract is a document of the
     * booking now and survives; one still sitting as `Non classé` was only
     * ever part of the message and goes with it.
     */
    public function detach(RentalBooking $booking, int $messageId, ?int $actorMemberId = null): bool
    {
        if ($this->inboundMail === null) {
            return false;
        }

        $message = $this->inboundMail->findOneForReference(
            \Modules\Rental\Mail\RentalMessageConsumer::CONSUMER_ID,
            $booking->reference,
            $messageId
        );
        if ($message === null) {
            return false;
        }

        $attachedFileIds = array_map(
            static fn($attachment) => $attachment->fileId,
            $message->attachments
        );

        $reclassifiedFileIds = [];
        foreach ($this->documentRepository->findForBooking($booking->id) as $document) {
            if (!in_array($document->fileId, $attachedFileIds, true)) {
                continue;
            }

            if ($document->type === DocumentType::UNSORTED) {
                // Never re-classified: it was only ever part of the
                // message, so it leaves with it.
                $this->documentRepository->delete($document->id);
                continue;
            }

            $reclassifiedFileIds[] = $document->fileId;
        }

        $detached = $this->inboundMail->detach(
            \Modules\Rental\Mail\RentalMessageConsumer::CONSUMER_ID,
            $booking->reference,
            $messageId,
            $reclassifiedFileIds
        );

        if ($detached) {
            // The booking's reference and the message's internal id, and
            // nothing else — never the sender, the subject or a word of the
            // content (§7.9).
            $this->journal->log(
                'rental',
                'rental_message_detached',
                'info',
                'Message détaché de la réservation ' . $booking->reference,
                ['booking_id' => $booking->id, 'message_id' => $messageId],
                $actorMemberId
            );
        }

        return $detached;
    }

    /**
     * Move a message to another booking — **only one of the assets this
     * manager actually manages** (§7.7).
     *
     * @throws RentalException when the target is not one of theirs
     */
    public function move(
        RentalBooking $booking,
        int $messageId,
        int $targetBookingId,
        ?string $actorEmail,
        int $scoutYearId,
        ?int $actorMemberId = null
    ): bool {
        if ($this->inboundMail === null) {
            return false;
        }

        $target = $this->bookingRepository->findById($targetBookingId);
        if ($target === null || !$this->authorizationService->canManageAssetId($actorEmail, $scoutYearId, $target->assetId)) {
            // Deliberately the same answer for "no such booking" and "not
            // yours": otherwise this is an oracle for which bookings exist.
            throw new RentalException("Cette réservation n'est pas accessible.");
        }

        $moved = $this->inboundMail->move(
            \Modules\Rental\Mail\RentalMessageConsumer::CONSUMER_ID,
            $booking->reference,
            $target->reference,
            $messageId
        );

        if ($moved) {
            $this->moveAttachedDocuments($booking, $target, $messageId);

            $this->journal->log(
                'rental',
                'rental_message_moved',
                'info',
                'Message déplacé de ' . $booking->reference . ' vers ' . $target->reference,
                ['booking_id' => $booking->id, 'target_booking_id' => $target->id, 'message_id' => $messageId],
                $actorMemberId
            );
        }

        return $moved;
    }

    /**
     * The bookings a message may be moved to: those of the assets this
     * manager manages, minus the one it is already on.
     *
     * Built from their own assets rather than filtered from every booking
     * in the unit — see the class docblock.
     *
     * @return RentalBooking[]
     */
    public function moveTargets(RentalBooking $booking, ?string $actorEmail, int $scoutYearId): array
    {
        $assetIds = array_map(
            static fn($asset) => $asset->id,
            $this->authorizationService->listManageableAssets($actorEmail, $scoutYearId)
        );

        if ($assetIds === []) {
            return [];
        }

        return array_values(array_filter(
            $this->bookingRepository->findAllForAssets($assetIds),
            static fn(RentalBooking $candidate) => $candidate->id !== $booking->id
        ));
    }

    /**
     * A moved message takes its documents with it — otherwise the PDF stays
     * filed under a booking whose correspondence no longer mentions it, and
     * the manager who moved the message has no way to move the file after
     * the fact.
     */
    private function moveAttachedDocuments(RentalBooking $from, RentalBooking $to, int $messageId): void
    {
        $message = $this->inboundMail?->findOneForReference(
            \Modules\Rental\Mail\RentalMessageConsumer::CONSUMER_ID,
            $to->reference,
            $messageId
        );
        if ($message === null) {
            return;
        }

        $fileIds = array_map(static fn($attachment) => $attachment->fileId, $message->attachments);
        if ($fileIds === []) {
            return;
        }

        foreach ($this->documentRepository->findForBooking($from->id) as $document) {
            if (in_array($document->fileId, $fileIds, true)) {
                $this->documentRepository->moveToBooking($document->id, $to->id);
            }
        }
    }

    /**
     * The documents of this booking that came from a given message, so the
     * timeline can show a manager that an attachment is already filed —
     * and under what.
     *
     * @return array<int, RentalDocument> keyed by file id
     */
    public function documentsByFileId(RentalBooking $booking): array
    {
        $byFileId = [];
        foreach ($this->documentRepository->findForBooking($booking->id) as $document) {
            $byFileId[$document->fileId] = $document;
        }

        return $byFileId;
    }
}
