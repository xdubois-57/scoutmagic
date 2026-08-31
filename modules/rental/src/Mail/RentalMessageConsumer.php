<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Mail;

use Core\Service\DateInput;
use Modules\InboundMail\Api\AnalysisResult;
use Modules\InboundMail\Api\CandidateMessage;
use Modules\InboundMail\Api\InboundAttachment;
use Modules\InboundMail\Api\InboundMailInterface;
use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Api\MessageConsumerInterface;
use Modules\InboundMail\Api\MessageLink;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Document\DocumentType;
use Modules\Rental\Document\RentalDocument;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Service\RentalAuthorizationService;
use Modules\Rental\Service\RentalDocumentService;

/**
 * Which rental booking an incoming message belongs to (§7.6).
 *
 * **The ordering is the whole design, and it goes from certain to
 * plausible**, stopping at the first level that answers:
 *
 * 1. **A reference in the subject** (`[LOC-2027-0042]`) — the module put it
 *    there itself, so a reply carrying it back is as close to certain as
 *    this gets.
 * 2. **The thread headers** — `In-Reply-To`/`References` naming a message
 *    already attached to a booking. Also certain: those ids were minted by
 *    a client answering a specific message.
 * 3. **The sender's address, bounded by time** — the same address as the
 *    renter *and* a message falling inside a window around the stay. On its
 *    own the address is not enough: a group that rents the hall every
 *    summer has five bookings under one address, and the window is what
 *    makes "which one" answerable.
 *
 * **Ambiguity is answered with silence, never with a guess.** Two bookings
 * matching the sender inside the window means no attachment at all —
 * putting a renter's email on whichever of their two stays sorted first is
 * worse than leaving it in their mailbox, because the manager reading the
 * wrong file has no way to know it is wrong.
 *
 * **A cancelled or archived booking still matches.** The correspondence
 * about why a stay fell through belongs on that stay.
 */
class RentalMessageConsumer implements MessageConsumerInterface
{
    public const CONSUMER_ID = 'rental';

    /**
     * How far either side of a stay a sender-matched message is still
     * assumed to be about it: from the request itself until some weeks
     * after the departure. Long enough to cover the settlement
     * correspondence, short enough that next year's enquiry from the same
     * group does not land on last year's booking.
     */
    public const DEFAULT_WINDOW_DAYS_AFTER = 60;

    public function __construct(
        private RentalBookingRepository $bookingRepository,
        private InboundMailInterface $inboundMail,
        private RentalDocumentService $documentService,
        /**
         * The mailboxes this module listens to, by id. **Empty means every
         * mailbox**, which is the right default for a unit with one box:
         * asking them to tick it before anything works would be a
         * configuration step whose only possible answer is "yes".
         *
         * @var int[]
         */
        private array $mailboxIds = [],
        private int $windowDaysAfter = self::DEFAULT_WINDOW_DAYS_AFTER,
        private BookingReferenceMatcher $referenceMatcher = new BookingReferenceMatcher(),
        /**
         * Everything `canRead()` needs, and nothing else does.
         *
         * Null on the scheduled path, where there is no session to answer
         * about — a synchronisation downloads nothing. The web path
         * supplies all three, which is why the consumer is registered
         * there as a factory rather than built on every page view.
         */
        private ?RentalAuthorizationService $authorizationService = null,
        private ?int $scoutYearId = null,
        private ?string $requesterEmail = null
    ) {
    }

    public function consumerId(): string
    {
        return self::CONSUMER_ID;
    }

    public function analyze(CandidateMessage $message): AnalysisResult
    {
        if (!$this->listensTo($message->mailboxId)) {
            return AnalysisResult::nothing();
        }

        $reference = $this->referenceMatcher->match($message->subject, $message->bodyText);
        if ($reference !== null && $this->bookingRepository->findByReference($reference) !== null) {
            return AnalysisResult::linkedTo(self::CONSUMER_ID, $reference, LinkOrigin::REFERENCE);
        }

        $threaded = $this->inboundMail->findReferenceByThread(
            self::CONSUMER_ID,
            $message->mailboxId,
            $message->threadMessageIds()
        );
        if ($threaded !== null) {
            return AnalysisResult::linkedTo(self::CONSUMER_ID, $threaded, LinkOrigin::THREAD);
        }

        $bySender = $this->matchBySender($message);

        return $bySender !== null
            ? AnalysisResult::linkedTo(self::CONSUMER_ID, $bySender, LinkOrigin::SENDER)
            : AnalysisResult::nothing();
    }

    /**
     * Nothing to add once the message is on disk.
     *
     * Everything this module recognises is in the subject, the thread
     * headers and the sender — all available on arrival. There is nothing
     * inside a renter's attachment that would name a booking more reliably
     * than the reference this module put in the subject itself.
     */
    public function analyzeStored(InboundMessage $message): AnalysisResult
    {
        return AnalysisResult::nothing();
    }

    /**
     * @return string[]
     */
    public function describeEvidence(): array
    {
        return [
            'référence de location explicite dans l\'objet ou le corps',
            'réponse dans une conversation déjà rattachée à une location',
            'adresse du locataire, entre la demande et quelques semaines après le départ',
        ];
    }

    public function triageAudienceLabel(): string
    {
        return 'les gestionnaires de biens et le staff d\'unité';
    }

    /**
     * The people who would actually see this module's mail: whoever manages
     * an asset, plus the unit staff who manage all of them.
     *
     * Counted on the scout year in effect rather than estimated — the
     * warning that shows this figure is the only guard-rail on opening a
     * shared mailbox to a module, so it has to be exact or it is worse than
     * absent.
     */
    public function triageAudienceCount(): int
    {
        return $this->bookingRepository->countTriageAudience();
    }

    /**
     * Turn the message's attachments into documents of the booking (§7.8).
     *
     * **Always `Non classé`, always internal.** An attachment is a file a
     * stranger sent; presuming it is the signed contract would put an
     * unverified PDF where a signed contract goes, and marking it "for the
     * renter" would queue it to be emailed back to them. A manager
     * reclassifies it in one click if it is what it looks like.
     */
    public function onLinked(InboundMessage $message, MessageLink $link): void
    {
        if ($message->attachments === []) {
            return;
        }

        $booking = $this->bookingRepository->findByReference($link->businessReference);
        if ($booking === null) {
            return;
        }

        foreach ($message->attachments as $attachment) {
            // Registered as email-sourced: the row points at the message's
            // OWN file id, not at a copy, so deleting the document later
            // must leave the bytes — and the message's attachment — alone
            // (§8.59).
            $this->documentService->attachUploaded(
                $booking,
                $attachment->fileId,
                DocumentType::UNSORTED,
                false,
                null,
                RentalDocument::SOURCE_EMAIL
            );
        }
    }

    /**
     * Take back the documents `onLinked()` filed on that booking.
     *
     * **This is the bug that made the callback necessary.** Reassigning a
     * message from one booking to another left its `RentalDocument` rows
     * hanging off the first: the manager of the new booking could not see
     * them, and the manager of the old one could not explain them. The
     * bytes are never touched — a document sourced from an email points at
     * the message's own file (§8.59), and `delete()` already knows it does
     * not own them.
     */
    public function onUnlinked(InboundMessage $message, MessageLink $link): void
    {
        if ($message->attachments === []) {
            return;
        }

        $booking = $this->bookingRepository->findByReference($link->businessReference);
        if ($booking === null) {
            return;
        }

        $fileIds = array_map(
            static fn(InboundAttachment $attachment): int => $attachment->fileId,
            $message->attachments
        );

        foreach ($this->documentService->forBooking($booking->id) as $document) {
            if ($document->source === RentalDocument::SOURCE_EMAIL
                && in_array($document->fileId, $fileIds, true)
            ) {
                $this->documentService->delete($document);
            }
        }
    }

    /**
     * Who may read an attachment of a message attached to a booking:
     * exactly who may manage that booking's asset, and nobody else.
     *
     * The same answer `File\RentalDocumentOwnershipChecker` gives for the
     * booking's own documents — deliberately, because an attachment that
     * arrived by email and the same file reclassified into a document must
     * not have two different access rules. A renter is never allowed: their
     * contract reaches them by email and only by email (§6.24, §6.26).
     *
     * @param array<int, int> $linkedMemberIds
     */
    public function canRead(string $businessReference, array $linkedMemberIds, string $role): bool
    {
        if ($this->authorizationService === null
            || $this->scoutYearId === null
            || $this->requesterEmail === null
            || $this->requesterEmail === ''
        ) {
            return false;
        }

        $booking = $this->bookingRepository->findByReference($businessReference);
        if ($booking === null) {
            // The booking is gone but the association survived — a restored
            // backup, a botched delete. Refusing is the only safe answer:
            // there is nobody left to check the request against.
            return false;
        }

        return $this->authorizationService->canManageAssetId(
            $this->requesterEmail,
            $this->scoutYearId,
            $booking->assetId
        );
    }

    /**
     * @param int[] $mailboxIds
     */
    public function withMailboxes(array $mailboxIds): self
    {
        return new self(
            $this->bookingRepository,
            $this->inboundMail,
            $this->documentService,
            $mailboxIds,
            $this->windowDaysAfter,
            $this->referenceMatcher,
            $this->authorizationService,
            $this->scoutYearId,
            $this->requesterEmail
        );
    }

    private function listensTo(int $mailboxId): bool
    {
        return $this->mailboxIds === [] || in_array($mailboxId, $this->mailboxIds, true);
    }

    /**
     * §7.6 level 3: the renter's own address, and only inside the window.
     *
     * Both halves matter. Without the address this would attach anything;
     * without the window it would attach a message about this year's camp
     * to a booking from three years ago. And with several bookings in
     * range, it attaches nothing at all.
     */
    private function matchBySender(CandidateMessage $message): ?string
    {
        if ($message->fromEmail === '') {
            return null;
        }

        $inWindow = array_values(array_filter(
            $this->bookingRepository->findByRenterEmail($message->fromEmail),
            fn(RentalBooking $booking) => $this->covers($booking, $message->sentAt)
        ));

        return count($inWindow) === 1 ? $inWindow[0]->reference : null;
    }

    /**
     * Whether a message sent at $sentAt is plausibly about this booking:
     * from the moment the request was made until some weeks after the
     * departure.
     *
     * The window opens at the request rather than at the arrival on
     * purpose — most of the correspondence about a stay happens before it,
     * while dates and prices are still being agreed.
     */
    private function covers(RentalBooking $booking, \DateTimeImmutable $sentAt): bool
    {
        $opensAt = $booking->receivedAt->setTime(0, 0);
        $closesAt = DateInput::requireFromStorage($booking->departureDate, 'rental_bookings.departure_date')
            ->modify('+' . $this->windowDaysAfter . ' days')
            ->setTime(23, 59, 59);

        return $sentAt >= $opensAt && $sentAt <= $closesAt;
    }
}
