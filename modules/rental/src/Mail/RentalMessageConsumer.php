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
use Modules\InboundMail\Api\MessageCandidate;
use Modules\InboundMail\Api\MessageConsumerInterface;
use Modules\InboundMail\Api\MessageLink;
use Modules\InboundMail\Api\ReferenceDirectory;
use Modules\InboundMail\Api\ReferenceSuggestion;
use Core\Service\TextNormalizerService;
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
class RentalMessageConsumer implements MessageConsumerInterface, ReferenceDirectory
{
    public const CONSUMER_ID = 'rental';

    /**
     * How many propositions one ambiguous message may produce. A renter
     * with a standing booking every month would otherwise turn a single
     * email into a wall nobody reads — which is a different way of saying
     * nothing at all.
     */
    public const MAX_PROPOSITIONS = 5;

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
        private ?string $requesterEmail = null,
        /**
         * The unit's assets, for « Rattacher à… » on the chief's screen
         * (`Api\ReferenceDirectory`): a booking is named by its asset and
         * reached through its asset's slug. Null on the scheduled path,
         * where nobody searches.
         */
        private ?\Modules\Rental\Repository\RentalAssetRepository $assetRepository = null
    ) {
    }

    // ── Api\ReferenceDirectory: the bookings as a person names them ────

    /**
     * @return ReferenceSuggestion[]
     */
    public function searchReferences(string $query, int $limit = 10): array
    {
        $query = trim($query);
        if ($query === '' || $this->assetRepository === null) {
            return [];
        }

        $assetNames = [];
        foreach ($this->assetRepository->findAll() as $asset) {
            $assetNames[$asset->id] = $asset->name;
        }

        $terms = array_values(array_filter(explode(' ', TextNormalizerService::fold($query))));
        $exact = strtoupper($query);
        $suggestions = [];

        foreach ($this->bookingRepository->findAllForAssets(array_keys($assetNames)) as $booking) {
            $assetName = $assetNames[$booking->assetId] ?? '';
            $haystack = TextNormalizerService::fold(implode(' ', [
                $booking->reference,
                $booking->renterName,
                (string) $booking->renterOrganisation,
                $assetName,
                $booking->arrivalDate,
                $booking->departureDate,
            ]));

            $isExact = $booking->reference === $exact;
            if (!$isExact) {
                foreach ($terms as $term) {
                    if (!str_contains($haystack, $term)) {
                        continue 2;
                    }
                }
            }

            $suggestion = new ReferenceSuggestion(
                $booking->reference,
                $booking->reference . ' — ' . $booking->renterName,
                trim($assetName . ' · du ' . $booking->arrivalDate . ' au ' . $booking->departureDate
                    . ' · ' . $booking->status->label())
            );

            // An exact reference leads, whatever else matched.
            if ($isExact) {
                array_unshift($suggestions, $suggestion);
            } else {
                $suggestions[] = $suggestion;
            }
        }

        return array_slice($suggestions, 0, max(1, $limit));
    }

    public function referenceUrl(string $businessReference): ?string
    {
        $booking = $this->bookingRepository->findByReference($businessReference);
        if ($booking === null) {
            return null;
        }

        $asset = $this->assetRepository?->findById($booking->assetId);

        return $asset === null ? null : '/mes-locations/' . $asset->slug . '/reservations/' . $booking->id;
    }

    public function consumerId(): string
    {
        return self::CONSUMER_ID;
    }

    public function displayName(): string
    {
        return 'Locations';
    }

    public function analyze(CandidateMessage $message): AnalysisResult
    {
        // Which boxes this module reads is the mailbox configuration's
        // answer (§8.58, `Service\MailboxScopeService`): a consumer is
        // only ever handed the messages of a box it was opened to. The
        // module's own list of box ids, which used to be checked here,
        // said something the operator's answer could contradict without
        // anything on either screen explaining why nothing arrived.
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

        return $this->fromSender($message);
    }

    /**
     * The sender-and-window level, which produces a link when it is sure
     * and **propositions when it is not**.
     *
     * Ambiguity used to be answered with silence: several bookings of the
     * same renter in range meant no association at all. That was right
     * about not choosing — putting a renter's email on whichever of their
     * two bookings sorted first is worse than not attaching it, because
     * the manager reading the wrong file has no way to know — and wrong
     * about stopping there. The module knows something; it just does not
     * know which. Saying so, and letting a human pick, is what a
     * proposition is for.
     */
    private function fromSender(CandidateMessage $message): AnalysisResult
    {
        $inWindow = $this->bookingsInWindow($message);

        if (count($inWindow) === 1) {
            return AnalysisResult::linkedTo(self::CONSUMER_ID, $inWindow[0]->reference, LinkOrigin::SENDER);
        }

        if ($inWindow === []) {
            return AnalysisResult::nothing();
        }

        // By arrival date, because that is the order a person compares
        // them in. The repository's own order is about listing bookings,
        // not about choosing between two of them, and inheriting it here
        // would make the list arbitrary for the one reader who has to pick.
        usort(
            $inWindow,
            static fn(RentalBooking $a, RentalBooking $b) => $a->arrivalDate <=> $b->arrivalDate
        );

        // Bounded. A renter with a standing booking every month would
        // otherwise turn one email into a wall of propositions nobody
        // reads, which is a different way of saying nothing.
        $candidates = [];
        foreach (array_slice($inWindow, 0, self::MAX_PROPOSITIONS) as $booking) {
            $candidates[] = new MessageCandidate(
                businessReference: $booking->reference,
                label: $this->labelFor($booking),
                evidenceType: 'sender_window',
                explanation: 'L\'adresse de l\'expéditeur est celle du locataire, et le message est '
                    . 'arrivé pendant la période de cette réservation. '
                    . count($inWindow) . ' réservations de ce locataire correspondent : '
                    . 'ScoutMagic n\'en choisit aucune.'
            );
        }

        return new AnalysisResult([], $candidates);
    }

    /**
     * A booking as a manager recognises it — the reference alone is an
     * identifier, not something anybody reads at a glance.
     */
    private function labelFor(RentalBooking $booking): string
    {
        // Built here rather than through the `date_fr` Twig filter: this
        // string is stored (encrypted) on the proposition row, so it has to
        // exist before any template does.
        $arrival = DateInput::iso($booking->arrivalDate);
        $departure = DateInput::iso($booking->departureDate);

        if ($arrival === null || $departure === null) {
            return $booking->reference;
        }

        return $booking->reference . ' — du ' . $arrival->format('d/m/Y')
            . ' au ' . $departure->format('d/m/Y');
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

    public function describeReference(string $businessReference): ?string
    {
        // « LOC-2027-0012 » is already the name a manager uses out loud.
        return null;
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
            'plusieurs réservations du même locataire dans la période : une proposition par réservation, '
                . 'aucune n\'est choisie',
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

        // Idempotent per file: a message moved onto a booking whose
        // documents were moved there first, or confirmed twice, must not
        // file the same attachment twice.
        $alreadyFiled = [];
        foreach ($this->documentService->forBooking($booking->id) as $document) {
            $alreadyFiled[$document->fileId] = true;
        }

        foreach ($message->attachments as $attachment) {
            if (isset($alreadyFiled[$attachment->fileId])) {
                continue;
            }

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
     *
     * **Only what is still `Non classé`.** A document a manager has since
     * re-classified — the signed contract, the invoice — is theirs, not the
     * message's: they read the file and said what it is, and the message
     * leaving the booking must not take that decision with it. Deleting
     * every email-sourced row regardless of type was a real data loss: the
     * communication service kept the re-classified file and handed it to
     * the booking, and this callback then removed the document row it
     * had just been asked to keep, leaving an orphaned file nothing
     * listed.
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
                && $document->type === DocumentType::UNSORTED
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
     * §7.6 level 3: the renter's own address, and only inside the window.
     *
     * Both halves matter. Without the address this would attach anything;
     * without the window it would attach a message about this year's camp
     * to a booking from three years ago. And with several bookings in
     * range, it attaches nothing at all.
     */
    /**
     * @return RentalBooking[]
     */
    private function bookingsInWindow(CandidateMessage $message): array
    {
        if ($message->fromEmail === '') {
            return [];
        }

        return array_values(array_filter(
            $this->bookingRepository->findByRenterEmail($message->fromEmail),
            fn(RentalBooking $booking) => $this->covers($booking, $message->sentAt)
        ));
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
