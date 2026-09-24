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
 * The Communications tab of a booking (§7.7).
 *
 * A thin layer over `Modules\InboundMail\Api\InboundMailInterface`, and
 * thin on purpose: everything about reading a mailbox lives in that module,
 * and everything about who may see a booking lives here. The two rules this
 * class exists to enforce:
 *
 * - **A manager may only file a message under a booking of an asset they
 *   manage.** The list of targets is derived from their own manageable
 *   assets rather than filtered from a global list, so a hand-crafted POST
 *   naming somebody else's booking finds no candidate rather than being
 *   rejected after the fact — and the target list is never a doorway into
 *   the rest of the unit's bookings.
 * - **A manager reads only the mail within their reach** (`withinReach()`):
 *   what is filed or proposed under their own bookings, and — for somebody
 *   who manages every asset — what nothing attributes yet. A box dedicated
 *   to rentals is readable in full by the module; its managers are not one
 *   audience, and this is where they are told apart.
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
        private ?\Core\File\FileRepository $fileRepository = null
    ) {
    }

    /**
     * One screenful of the triage list (issue #462): the same bound camps
     * uses, for the same reason — a dedicated box collecting for three years
     * holds thousands of messages.
     */
    public const TRIAGE_LIMIT = 100;

    /**
     * The bookings a person may file mail under — every booking of every
     * asset they manage — keyed by reference. With `sortsUnattributed()`,
     * the whole of the triage screen's reach.
     *
     * @return array<string, RentalBooking>
     */
    public function triageBookings(?string $email, int $scoutYearId): array
    {
        $assetIds = array_map(
            static fn($asset) => $asset->id,
            $this->authorizationService->listManageableAssets($email, $scoutYearId)
        );
        if ($assetIds === []) {
            return [];
        }

        $bookings = [];
        foreach ($this->bookingRepository->findAllForAssets($assetIds) as $booking) {
            $bookings[$booking->reference] = $booking;
        }

        return $bookings;
    }

    /**
     * Whether this person may read mail nothing attributes yet: a message
     * naming no booking may be about any asset, so only somebody who
     * manages every one of them may open it to sort it.
     */
    public function sortsUnattributed(?string $email, int $scoutYearId): bool
    {
        return $this->authorizationService->managesEveryAsset($email, $scoutYearId);
    }

    /**
     * The triage screen's list within that reach (`withinReach()`).
     *
     * @param string[] $references
     * @return list<array<string, mixed>>
     */
    public function triageRows(array $references, bool $sortsUnattributed, bool $dismissed = false): array
    {
        if ($this->inboundMail === null || $references === []) {
            return [];
        }

        // Narrowed in the query for somebody who does not sort the
        // unattributed mail: the whole box's hundred most recent messages
        // may hold none of theirs (InboundMailInterface::findForTriage()).
        return self::withinReach(
            $this->inboundMail->triageRows(
                RentalMessageConsumer::CONSUMER_ID,
                $references,
                self::TRIAGE_LIMIT,
                $dismissed,
                !$sortsUnattributed
            ),
            $references,
            $sortsUnattributed
        );
    }

    /**
     * How many set-aside messages are within that reach — counted on the
     * list itself, since the API's own count covers everything the module
     * may read. Bounded like the list, which is what the tab shows.
     *
     * @param string[] $references
     */
    public function countDismissed(array $references, bool $sortsUnattributed): int
    {
        return count($this->triageRows($references, $sortsUnattributed, true));
    }

    /**
     * Keeps the rows a manager may read, and on each only what is theirs.
     *
     * `findForTriage()` answers for the module: on a box dedicated to
     * rentals that is the whole box, the operator having said the module
     * reads everything there. A manager is narrower than the module. A row
     * stays when a link or a proposition names one of THEIR bookings — and
     * then shows those only, never the reference of a booking they do not
     * manage — or when nothing attributes it at all and they manage every
     * asset. Mail filed under somebody else's booking is not on their
     * screen, and so cannot be attached, set aside or restored from it.
     *
     * @param list<array<string, mixed>> $rows
     * @param string[] $references
     * @return list<array<string, mixed>>
     */
    private static function withinReach(array $rows, array $references, bool $sortsUnattributed): array
    {
        $mine = static fn(object $item): bool => in_array($item->businessReference, $references, true);

        $kept = [];
        foreach ($rows as $row) {
            $links = array_values(array_filter($row['links'], $mine));
            $candidates = array_values(array_filter($row['candidates'], $mine));
            $unattributed = $row['links'] === [] && $row['candidates'] === [];

            if ($links !== [] || $candidates !== [] || ($unattributed && $sortsUnattributed)) {
                $kept[] = [
                    'links' => $links,
                    'candidates' => $candidates,
                    // Something on it is another asset's: setting it aside
                    // would hide it from that asset's managers too, since a
                    // set-aside is the module's, not one person's.
                    'shared_with_others' => count($links) < count($row['links'])
                        || count($candidates) < count($row['candidates']),
                ] + $row;
            }
        }

        return $kept;
    }

    /**
     * Whether one message is on this person's list — the only authority a
     * message id posted by a form carries.
     *
     * @param string[] $references
     */
    private function isWithinReach(
        array $references,
        bool $sortsUnattributed,
        int $messageId,
        bool $dismissed = false,
        bool $wholly = false
    ): bool {
        foreach ($this->triageRows($references, $sortsUnattributed, $dismissed) as $row) {
            if ($row['message']->id === $messageId) {
                // `$wholly`: a decision taken for the whole module is this
                // person's only when nothing on the message is another's.
                return !$wholly || !$row['shared_with_others'];
            }
        }

        return false;
    }

    /**
     * File a message under one of the requester's bookings, because they
     * said so.
     *
     * **Only a message on their own list.** `InboundMailInterface::attach()`
     * leaves the requester's reach to the caller, and an id in a form is not
     * an authorisation: attaching an arbitrary message to one's own booking
     * would be reading it. So the message must be one the triage list of
     * THIS person shows. The booking's own hooks then file its attachments
     * (`RentalMessageConsumer::onLinked()`).
     *
     * @param string[] $references
     */
    public function attachToBooking(
        RentalBooking $target,
        int $messageId,
        array $references,
        bool $sortsUnattributed,
        ?int $userAccountId
    ): bool {
        if ($this->inboundMail === null
            || !in_array($target->reference, $references, true)
            || !$this->isWithinReach($references, $sortsUnattributed, $messageId)
        ) {
            return false;
        }

        return $this->inboundMail->attach(
            RentalMessageConsumer::CONSUMER_ID,
            $target->reference,
            $messageId,
            $userAccountId
        );
    }

    /**
     * « Ce courrier ne concerne pas les locations » — for a message on the
     * requester's own list only, and wholly theirs: a set-aside is the
     * module's, so one also proposed for another asset's booking would
     * vanish from that asset's managers' lists without their knowing.
     *
     * @param string[] $references
     */
    public function setAside(array $references, bool $sortsUnattributed, int $messageId, ?int $userAccountId): bool
    {
        return $this->inboundMail !== null
            && $this->isWithinReach($references, $sortsUnattributed, $messageId, false, true)
            && $this->inboundMail->dismissMessage(
                RentalMessageConsumer::CONSUMER_ID,
                $references,
                $messageId,
                $userAccountId
            );
    }

    /**
     * Put a set-aside message back — wholly theirs too, for the same reason.
     *
     * @param string[] $references
     */
    public function restore(array $references, bool $sortsUnattributed, int $messageId): bool
    {
        return $this->inboundMail !== null
            && $this->isWithinReach($references, $sortsUnattributed, $messageId, true, true)
            && $this->inboundMail->restoreMessage(
                RentalMessageConsumer::CONSUMER_ID,
                $references,
                $messageId
            );
    }

    /**
     * Answer one of this module's propositions, as a person — refused by
     * the API when its reference is not among the requester's.
     *
     * @param string[] $references
     */
    public function decideCandidate(
        array $references,
        int $messageId,
        int $candidateId,
        bool $confirm,
        ?int $userAccountId
    ): bool {
        if ($this->inboundMail === null || $references === []) {
            return false;
        }

        return $confirm
            ? $this->inboundMail->confirmCandidate(
                RentalMessageConsumer::CONSUMER_ID,
                $references,
                $messageId,
                $candidateId,
                $userAccountId
            )
            : $this->inboundMail->dismissCandidate(
                RentalMessageConsumer::CONSUMER_ID,
                $references,
                $messageId,
                $candidateId
            );
    }

    /**
     * The mailbox that is rentals' own — designated, not chosen: the one
     * enabled box the operator declared dedicated to this module, or none
     * (issue #462, D8).
     *
     * **Two is none.** Picking one of two would be an arbitrary choice made
     * silently on the unit's behalf; the Courrier page simply does not
     * exist until the configuration names one box, and the configuration
     * screen says why (`dedicatedMailboxCount()`).
     */
    public function dedicatedMailbox(): ?\Modules\InboundMail\Api\DedicatedMailbox
    {
        $boxes = $this->inboundMail?->dedicatedMailboxesFor(RentalMessageConsumer::CONSUMER_ID) ?? [];

        return count($boxes) === 1 ? $boxes[0] : null;
    }

    public function dedicatedMailboxCount(): int
    {
        return count($this->inboundMail?->dedicatedMailboxesFor(RentalMessageConsumer::CONSUMER_ID) ?? []);
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
     * What the module proposes about this booking and has not yet been
     * told: the messages carrying a standing proposition towards it.
     *
     * The other half of §7.6's contract, which the booking page did not
     * show: the consumer produced propositions on an ambiguous sender and
     * nobody but the Chef d'Unité could ever see them. A proposition only
     * exists to be confirmed or dismissed by somebody who knows, and the
     * manager of the booking is that somebody.
     *
     * @return list<array{
     *     message: \Modules\InboundMail\Api\InboundMessage,
     *     candidates: \Modules\InboundMail\Api\MessageCandidate[]
     * }>
     */
    public function propositions(RentalBooking $booking): array
    {
        if ($this->inboundMail === null) {
            return [];
        }

        $consumerId = \Modules\Rental\Mail\RentalMessageConsumer::CONSUMER_ID;
        $messages = $this->inboundMail->findForTriage($consumerId, [$booking->reference]);
        if ($messages === []) {
            return [];
        }

        $byMessage = $this->inboundMail->findCandidatesFor(
            $consumerId,
            array_map(static fn($message) => $message->id, $messages)
        );

        $rows = [];
        foreach ($messages as $message) {
            $candidates = array_values(array_filter(
                $byMessage[$message->id] ?? [],
                static fn($candidate): bool => $candidate->businessReference === $booking->reference
            ));
            if ($candidates !== []) {
                $rows[] = ['message' => $message, 'candidates' => $candidates];
            }
        }

        return $rows;
    }

    /**
     * Confirm a proposition towards this booking, as this manager.
     *
     * Scoped by the API itself: a proposition whose target is not this
     * booking is refused, whatever the screen posted.
     */
    public function confirmProposition(
        RentalBooking $booking,
        int $messageId,
        int $candidateId,
        ?int $userAccountId
    ): bool
    {
        if ($this->inboundMail === null) {
            return false;
        }

        return $this->inboundMail->confirmCandidate(
            \Modules\Rental\Mail\RentalMessageConsumer::CONSUMER_ID,
            [$booking->reference],
            $messageId,
            $candidateId,
            $userAccountId
        );
    }

    public function dismissProposition(RentalBooking $booking, int $messageId, int $candidateId): bool
    {
        if ($this->inboundMail === null) {
            return false;
        }

        return $this->inboundMail->dismissCandidate(
            \Modules\Rental\Mail\RentalMessageConsumer::CONSUMER_ID,
            [$booking->reference],
            $messageId,
            $candidateId
        );
    }

    /**
     * « Relancer l'analyse » — offer every unattributed message to this
     * module again, with what the site knows today.
     *
     * @return array{examined: int, linked: int, proposed: int}
     */
    public function reanalyze(): array
    {
        return $this->inboundMail?->reanalyzeUnlinked(\Modules\Rental\Mail\RentalMessageConsumer::CONSUMER_ID)
            ?? ['examined' => 0, 'linked' => 0, 'proposed' => 0];
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
            \Modules\Rental\Mail\RentalMessageConsumer::CONSUMER_ID,
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
            \Modules\Rental\Mail\RentalMessageConsumer::CONSUMER_ID,
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
