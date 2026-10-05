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
use Modules\Rental\Mail\RentalMessageConsumer;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Document\DocumentType;
use Modules\Rental\Document\RentalDocument;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalDocumentRepository;

/**
 * A booking's « Courrier » page (§7.7, #720).
 *
 * A thin layer over `Modules\InboundMail\Api\InboundMailInterface`, and
 * thin on purpose: everything about reading a mailbox lives in that module,
 * and everything about who may see a booking lives here.
 *
 * **One booking, its own mail.** The page shows what the module's rules
 * filed under this booking and nothing else — no list of the unit's mail to
 * sort, no proposition to confirm, no message to attach by hand. What the
 * rules could not attribute is not shown anywhere in the rentals; the one
 * correction a manager makes is « Détacher », and it is final for this
 * booking (`detach()`).
 *
 * **A manager may only move a message to a booking of an asset they
 * manage.** The list of targets is derived from their own manageable
 * assets rather than filtered from a global list, so a hand-crafted POST
 * naming somebody else's booking finds no candidate rather than being
 * rejected after the fact.
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
        private ?InboundMailInterface $inboundMail = null,
        /**
         * Only ever used to hand a re-classified attachment's ownership to
         * the booking — see detach(). Optional because every other surface
         * of this class works without it.
         */
        private ?\Core\File\FileRepository $fileRepository = null,
        /**
         * How far each person has read each booking's mail — the « non
         * lus » badges (#720). Null: no badge anywhere.
         */
        private ?\Modules\Rental\Repository\RentalMailReadRepository $reads = null
    ) {
    }

    /**
     * How many messages were filed under each of these bookings since this
     * person last opened its « Courrier » page (#720) — every one of them,
     * for a booking they never opened. A booking with none is absent.
     *
     * @param RentalBooking[] $bookings
     * @return array<int, int> booking id => unread count
     */
    public function unreadCounts(int $userAccountId, array $bookings): array
    {
        if ($this->inboundMail === null || $this->reads === null || $bookings === []) {
            return [];
        }

        $readUpTo = $this->reads->readUpTo(
            $userAccountId,
            array_map(static fn(RentalBooking $booking): int => $booking->id, $bookings)
        );
        $after = [];
        $idByReference = [];
        foreach ($bookings as $booking) {
            $after[$booking->reference] = $readUpTo[$booking->id] ?? 0;
            $idByReference[$booking->reference] = $booking->id;
        }

        $counts = [];
        foreach ($this->inboundMail->countLinksAfter(RentalMessageConsumer::CONSUMER_ID, $after) as $reference => $count) {
            if (isset($idByReference[$reference]) && $count > 0) {
                $counts[$idByReference[$reference]] = $count;
            }
        }

        return $counts;
    }

    /** Opening the booking's « Courrier » page reads everything filed so far. */
    public function markRead(RentalBooking $booking, int $userAccountId): void
    {
        if ($this->inboundMail === null || $this->reads === null) {
            return;
        }

        $this->reads->markRead(
            $booking->id,
            $userAccountId,
            $this->inboundMail->latestLinkPosition(RentalMessageConsumer::CONSUMER_ID, $booking->reference)
        );
    }

    /**
     * Whether a mailbox gathers renters' replies for the rentals: an
     * enabled box whose scope lets this module analyse it.
     *
     * Not a condition on the page any more (#720) — the page is there
     * whatever the configuration — but what decides whether it says how
     * replies can reach it. Dedicated or shared does not matter here; the
     * configuration keeps that distinction for the other modules.
     */
    public function collects(): bool
    {
        foreach ($this->inboundMail?->listMailboxSummariesFor(RentalMessageConsumer::CONSUMER_ID) ?? [] as $summary) {
            if ($summary['is_enabled']) {
                return true;
            }
        }

        return false;
    }

    /**
     * The messages filed under this booking.
     *
     * @return InboundMessage[] oldest first
     */
    public function timeline(RentalBooking $booking): array
    {
        return $this->inboundMail?->findForReference(
            RentalMessageConsumer::CONSUMER_ID,
            $booking->reference
        ) ?? [];
    }

    /**
     * Detach a message from this booking (§7.7).
     *
     * The booking stops carrying it, and so do its managers' screens. The
     * message itself falls back into the unit's general mail, where only a
     * chef d'unité sees it and where `inbound_mail`'s retention eventually
     * removes it — detaching is almost always a correction, and a
     * correction that destroys the message makes re-filing it impossible.
     *
     * **The attachments nobody re-classified go with the association**: one
     * still sitting as `Non classé` was only ever part of the message. One
     * a manager already turned into a signed contract is a document of the
     * booking now, so it survives *and* changes hands — its `files` row is
     * re-owned by the booking, or the file would keep answering to a
     * message this booking's managers can no longer see and they would lose
     * access to their own contract.
     *
     * **Final for this booking** (#720). « Ce message ne concerne pas cette
     * réservation » is a decision, and a re-analysis must not undo it: the
     * module's rules never file the message under this booking again,
     * while another booking stays open to it.
     */
    public function detach(
        RentalBooking $booking,
        int $messageId,
        ?int $actorMemberId = null,
        ?int $actorUserAccountId = null
    ): bool
    {
        if ($this->inboundMail === null) {
            return false;
        }

        $message = $this->inboundMail->findOneForReference(
            RentalMessageConsumer::CONSUMER_ID,
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
            RentalMessageConsumer::CONSUMER_ID,
            $booking->reference,
            $messageId,
            $reclassifiedFileIds,
            true,
            $actorUserAccountId
        );

        if ($detached) {
            foreach (array_unique($reclassifiedFileIds) as $fileId) {
                $this->fileRepository?->updateOwner(
                    $fileId,
                    \Modules\Rental\Service\RentalDocumentService::OWNER_TYPE,
                    $booking->id
                );
            }

            // The booking's reference and the message's internal id, and
            // nothing else — never the sender, the subject or a word of the
            // content (§8.6).
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
        ?int $actorMemberId = null,
        ?int $actorUserAccountId = null
    ): bool {
        if ($this->inboundMail === null) {
            return false;
        }

        $target = $this->bookingRepository->findById($targetBookingId);
        if ($target === null || !$this->authorizationService->canManageAssetId(
            $actorEmail,
            $scoutYearId,
            $target->assetId
        )) {
            // Deliberately the same answer for "no such booking" and "not
            // yours": otherwise this is an oracle for which bookings exist.
            throw new RentalException("Cette réservation n'est pas accessible.");
        }

        $message = $this->inboundMail->findOneForReference(
            RentalMessageConsumer::CONSUMER_ID,
            $booking->reference,
            $messageId
        );
        if ($message === null) {
            return false;
        }

        // The documents move FIRST, re-classified ones included. The
        // consumer's own callbacks then find nothing left to take back on
        // the old booking, and nothing to file twice on the new one — a
        // signed contract keeps being a signed contract on the booking it
        // now belongs to, instead of being deleted and re-created as
        // « Non classé ».
        $movedDocumentIds = $this->moveAttachedDocuments($booking, $target, $message);

        $moved = $this->inboundMail->move(
            RentalMessageConsumer::CONSUMER_ID,
            $booking->reference,
            $target->reference,
            $messageId,
            $actorUserAccountId
        );

        if (!$moved) {
            foreach ($movedDocumentIds as $documentId) {
                $this->documentRepository->moveToBooking($documentId, $booking->id);
            }

            return false;
        }

        $this->journal->log(
            'rental',
            'rental_message_moved',
            'info',
            'Message déplacé de ' . $booking->reference . ' vers ' . $target->reference,
            ['booking_id' => $booking->id, 'target_booking_id' => $target->id, 'message_id' => $messageId],
            $actorMemberId
        );

        return true;
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
    /**
     * @return int[] the ids of the documents that changed booking
     */
    private function moveAttachedDocuments(
        RentalBooking $from,
        RentalBooking $to,
        \Modules\InboundMail\Api\InboundMessage $message
    ): array {
        $fileIds = array_map(static fn($attachment) => $attachment->fileId, $message->attachments);
        if ($fileIds === []) {
            return [];
        }

        $moved = [];
        foreach ($this->documentRepository->findForBooking($from->id) as $document) {
            if (in_array($document->fileId, $fileIds, true)) {
                $this->documentRepository->moveToBooking($document->id, $to->id);
                $moved[] = $document->id;
            }
        }

        return $moved;
    }

}
