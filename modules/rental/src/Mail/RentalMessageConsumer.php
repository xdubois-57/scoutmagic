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
use Modules\InboundMail\Api\HandlesOutboundMail;
use Modules\InboundMail\Api\InboundAttachment;
use Modules\InboundMail\Api\InboundMailInterface;
use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Api\MessageConsumerInterface;
use Modules\InboundMail\Api\MessageLink;
use Modules\InboundMail\Api\ReferenceDirectory;
use Modules\InboundMail\Api\ReferenceSuggestion;
use Modules\LlmConnector\Api\LlmException;
use Core\Service\TextNormalizerService;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\OtherRenterEmail;
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
 * wrong file has no way to know it is wrong. Nor is anybody asked to choose
 * (#720): there is no proposition and no screen to sort the rest — the
 * message simply appears on no booking.
 *
 * **A cancelled or archived booking still matches.** The correspondence
 * about why a stay fell through belongs on that stay.
 */
class RentalMessageConsumer implements
    MessageConsumerInterface,
    ReferenceDirectory,
    HandlesOutboundMail
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
        private ?\Modules\Rental\Repository\RentalAssetRepository $assetRepository = null,
        /**
         * Who tells the asset's managers that a message was filed under one
         * of its bookings (« Nouveau message du locataire », #720). Null:
         * nobody is told, and the message is on the booking's page all the
         * same.
         */
        private ?NewMessageNotifier $newMessageNotifier = null,
        /**
         * The model that settles what the rules could not (#720, step 6),
         * in the deferred pass only (`analyzeStored()`). Null, or a
         * connector with no cheap model: an ambiguous message is filed
         * nowhere, as before.
         */
        private ?BookingChoiceByModel $modelChoice = null
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
        if ($message->isSent()) {
            return $this->analyzeSent($message);
        }

        // Which boxes this module reads is the mailbox configuration's
        // answer (§8.58, `Service\MailboxScopeService`): a consumer is
        // only ever handed the messages of a box it was opened to. The
        // module's own list of box ids, which used to be checked here,
        // said something the operator's answer could contradict without
        // anything on either screen explaining why nothing arrived.
        // Level 0: the message answers a mail the site sent, at the signed
        // reply address that mail carried (§8.58). Minted and verified by
        // the gateway; only the booking's existence is checked here.
        // Upper-cased because that is what a booking reference IS
        // (`LOC-2027-0042`) and the mail layer lowercases recipients.
        $addressed = $message->addressedReferenceFor(self::CONSUMER_ID);
        $addressed = $addressed === null ? null : strtoupper($addressed);
        if ($addressed !== null && $this->bookingRepository->findByReference($addressed) !== null) {
            return AnalysisResult::linkedTo(self::CONSUMER_ID, $addressed, LinkOrigin::REPLY_ADDRESS);
        }

        // Level 1: a reference quoted in the subject or the body. The
        // reference is SEQUENTIAL and printed on every contract, so it is
        // guessable — and this rule used to ask nothing else, so anybody
        // writing `[LOC-2027-0042]` to a mailbox the operator opened to
        // this consumer had their message filed on that booking's internal
        // thread, attachments and all. The neighbours bound their
        // equivalent rule and said so: Modules\Finance's own consumer
        // ("the address behind it is not authenticated") requires a
        // resolved sender, and the camps one keeps its weakest rule behind
        // `mailboxDedicatedTo`.
        //
        // So the reference alone does not LINK: it links when the sender
        // is the renter of that booking, and otherwise it files nothing —
        // there is no proposition for a person to confirm any more (#720),
        // and a stranger quoting a reference must not reach a booking's
        // thread on that alone.
        $reference = $this->referenceMatcher->match($message->subject, $message->bodyText);
        $referenced = $reference !== null ? $this->bookingRepository->findByReference($reference) : null;
        if ($referenced !== null) {
            return $this->isRenterOf($referenced, $message->fromEmail)
                ? AnalysisResult::linkedTo(self::CONSUMER_ID, $referenced->reference, LinkOrigin::REFERENCE)
                : AnalysisResult::nothing();
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
     * A message the unit SENT, read in its box's « Envoyés » (#720).
     *
     * The same levels as for received mail, turned around: the person it
     * concerns is among the recipients, not the sender — the sender is the
     * unit. There is no signed reply address to read: that is something
     * the renter writes TO.
     *
     * **A copy of an e-mail the site sent is recognised first, and filed
     * nowhere.** Some providers file what the site sends through the box
     * in « Envoyés »; the booking's page already shows that e-mail from
     * the site's own log, with its document and « Renvoyer », and a second
     * entry for the same e-mail would only be noise. The site minted its
     * Message-ID, so this is certain.
     */
    private function analyzeSent(CandidateMessage $message): AnalysisResult
    {
        $messageId = $message->messageId;
        if ($messageId !== '' && $this->inboundMail->wasSentByThisSite(self::CONSUMER_ID, $messageId)) {
            return AnalysisResult::nothing();
        }

        $reference = $this->referenceMatcher->match($message->subject, $message->bodyText);
        $referenced = $reference !== null ? $this->bookingRepository->findByReference($reference) : null;
        if ($referenced !== null) {
            foreach ($message->toEmails as $recipient) {
                if ($this->isRenterOf($referenced, $recipient)) {
                    return AnalysisResult::linkedTo(self::CONSUMER_ID, $referenced->reference, LinkOrigin::REFERENCE);
                }
            }

            // A reference quoted to somebody who is not the renter — the
            // caretaker, the insurer — is not the renter's correspondence.
            return AnalysisResult::nothing();
        }

        $threaded = $this->inboundMail->findReferenceByThread(
            self::CONSUMER_ID,
            $message->mailboxId,
            $message->threadMessageIds()
        );
        if ($threaded !== null) {
            return AnalysisResult::linkedTo(self::CONSUMER_ID, $threaded, LinkOrigin::THREAD);
        }

        return $this->byAddress($message->toEmails, $message->sentAt, LinkOrigin::RECIPIENT);
    }

    /**
     * The sender-and-window level, which produces a link when it is sure
     * and **nothing when it is not** (#720).
     */
    private function fromSender(CandidateMessage $message): AnalysisResult
    {
        return $this->byAddress([$message->fromEmail], $message->sentAt, LinkOrigin::SENDER);
    }

    /**
     * The address-and-window level: the sender of a received message, the
     * recipients of a sent one.
     *
     * Several bookings in range mean no association here: a renter's email
     * on whichever of their two bookings sorted first is worse than none,
     * because the manager reading the wrong file has no way to know. Two
     * recipients who are each the renter of a booking are two bookings, and
     * the same answer. What the rules could not settle is the model's to
     * weigh later, in the deferred pass (`analyzeStored()`).
     *
     * @param string[] $emails
     */
    private function byAddress(array $emails, \DateTimeImmutable $sentAt, LinkOrigin $origin): AnalysisResult
    {
        [$decided] = $this->addressMatch($emails, $sentAt);

        return $decided === null
            ? AnalysisResult::nothing()
            : AnalysisResult::linkedTo(self::CONSUMER_ID, $decided->reference, $origin);
    }

    /**
     * The booking these addresses settle on, or — when they settle on none
     * — the bookings still standing, for the model to weigh: the live ones
     * in the window when there are several, else everything in it, else,
     * with nothing in the window, every live booking of those addresses.
     *
     * @param string[] $emails
     * @return array{0: ?RentalBooking, 1: list<RentalBooking>}
     */
    private function addressMatch(array $emails, \DateTimeImmutable $sentAt): array
    {
        $all = [];
        foreach ($emails as $email) {
            if (trim($email) === '') {
                continue;
            }

            foreach ($this->bookingRepository->findByRenterEmail($email) as $booking) {
                $all[$booking->id] = $booking;
            }
        }
        $all = array_values($all);
        if ($all === []) {
            return [null, []];
        }

        // A renter with exactly one booking that is still alive is not
        // ambiguous, whatever the date: their enquiry sent before the
        // request existed, or their question three months after the
        // stay, is about the one booking they have. The window below is
        // what tells two bookings apart, and it has nothing to tell here.
        $alive = array_values(array_filter($all, static fn(RentalBooking $booking): bool => self::isAlive($booking)));
        if (count($alive) === 1 && count($all) === 1) {
            return [$alive[0], []];
        }

        $inWindow = array_values(array_filter(
            $all,
            fn(RentalBooking $booking) => $this->covers($booking, $sentAt)
        ));

        // Among several in the window, the ones the unit refused,
        // cancelled or let lapse do not compete with the live one.
        $liveInWindow = array_values(array_filter($inWindow, static fn(RentalBooking $b): bool => self::isAlive($b)));
        if (count($liveInWindow) === 1) {
            return [$liveInWindow[0], []];
        }

        if (count($inWindow) === 1) {
            return [$inWindow[0], []];
        }

        // None in the window, or several: the rules file nothing.
        $standing = match (true) {
            count($liveInWindow) > 1 => $liveInWindow,
            $inWindow !== [] => $inWindow,
            default => $alive,
        };

        return [null, array_slice($standing, 0, self::MAX_MODEL_OPTIONS)];
    }

    /**
     * Whether this address is the one the booking was made with.
     *
     * Compared the way the mail layer hands addresses over — lowercased
     * and trimmed — and never a substring: an address is equal to the
     * renter's or it is not.
     */
    private function isRenterOf(RentalBooking $booking, string $email): bool
    {
        // The renter's own address or one of the booking's other ones
        // (#720, step 5): the treasurer quoting the reference is the
        // renter's correspondence as much as the renter doing it.
        return $this->bookingRepository->isAddressOfBooking($booking, $email);
    }

    /**
     * A booking still worth a renter's message: not refused, cancelled or
     * lapsed.
     */
    private static function isAlive(RentalBooking $booking): bool
    {
        return !in_array(
            $booking->status,
            [BookingStatus::REFUSED, BookingStatus::CANCELLED, BookingStatus::EXPIRED],
            true
        );
    }

    /** How many bookings the model is ever asked to choose between. */
    public const MAX_MODEL_OPTIONS = 8;

    /**
     * The model settles what the rules could not (#720, step 6) — once the
     * message is on disk, in the hourly deferred pass, never inside a
     * synchronisation.
     *
     * Asked only about a message this module filed nowhere, and only to
     * choose among the bookings the rules themselves put forward: several
     * of one renter in range, or the one booking a reference names when it
     * was quoted by (or, sent, to) an address the booking does not know. A
     * booking the message was detached from is never offered. Its pick is
     * filed `LinkOrigin::AI`, which `onLinked()` learns the address from.
     *
     * **Bounded by the pass, not by this method.** `AnalyzeStoredMessages
     * Handler` reads ten messages an hour, so this makes at most ten calls
     * an hour, each capped in size, tokens and time
     * (`BookingChoiceByModel`). A call that failed — the provider down, a
     * timeout — answers `readingFailed()`: the pass comes back for it, at
     * most `MAX_ANALYSIS_ATTEMPTS` times, then gives up and the message is
     * filed nowhere. A model that declines is an answer, and is not asked
     * again.
     */
    public function analyzeStored(InboundMessage $message): AnalysisResult
    {
        if ($this->modelChoice === null || !$this->modelChoice->isAvailable()) {
            return AnalysisResult::nothing();
        }

        foreach ($message->links as $link) {
            if ($link->consumerId === self::CONSUMER_ID) {
                return AnalysisResult::nothing();
            }
        }

        $candidates = array_values(array_filter(
            $this->standingBookings($message),
            fn(RentalBooking $booking): bool =>
                !$this->inboundMail->isExcluded(self::CONSUMER_ID, $message->id, $booking->reference)
        ));
        if ($candidates === []) {
            return AnalysisResult::nothing();
        }

        try {
            $choice = $this->modelChoice->choose(self::textForModel($message), $this->optionsFor($candidates));
        } catch (LlmException) {
            return AnalysisResult::readingFailed();
        }

        return $choice === null
            ? AnalysisResult::nothing()
            : AnalysisResult::linkedTo(self::CONSUMER_ID, $choice, LinkOrigin::AI);
    }

    /**
     * The bookings the rules put forward for this message without settling
     * on one — the same levels as `analyze()`, read the same way for both
     * directions.
     *
     * @return list<RentalBooking>
     */
    private function standingBookings(InboundMessage $message): array
    {
        if ($message->isSent()) {
            $messageId = $message->messageId;
            if ($messageId !== '' && $this->inboundMail->wasSentByThisSite(self::CONSUMER_ID, $messageId)) {
                return [];
            }
            $people = $message->toEmails;
        } else {
            $people = [$message->fromEmail];
        }

        $reference = $this->referenceMatcher->match($message->subject, $message->bodyText);
        $referenced = $reference !== null ? $this->bookingRepository->findByReference($reference) : null;
        if ($referenced !== null) {
            foreach ($people as $person) {
                if ($this->isRenterOf($referenced, $person)) {
                    // The rules filed this one; nothing is in doubt.
                    return [];
                }
            }

            // A reference quoted by an address the booking does not know.
            return [$referenced];
        }

        [$decided, $standing] = $this->addressMatch($people, $message->sentAt);

        return $decided === null ? $standing : [];
    }

    /**
     * What the model reads: which way the message went, when, about what —
     * and the text, which `BookingChoiceByModel` cuts to its own limit.
     * Never an address: the bookings it chooses between are the same
     * renter's, or the one a reference names, so an address would tell it
     * nothing and would be one more personal datum sent out (the RGPD page
     * lists what is).
     */
    private static function textForModel(InboundMessage $message): string
    {
        $direction = $message->isSent() ? 'Envoyé par l\'unité au locataire' : 'Reçu par l\'unité';

        return 'Objet : ' . $message->subject . "\n"
            . $direction . "\n"
            . 'Date : ' . $message->sentAt->format('Y-m-d') . "\n\n"
            . $message->bodyText;
    }

    /**
     * Each booking as the model is shown it: its asset, its dates, the
     * group and where it stands — what a person would compare.
     *
     * @param list<RentalBooking> $bookings
     * @return array<string, string>
     */
    private function optionsFor(array $bookings): array
    {
        $options = [];
        foreach ($bookings as $booking) {
            $asset = $this->assetRepository?->findById($booking->assetId);
            $parts = [
                $asset?->name,
                'du ' . $booking->arrivalDate . ' au ' . $booking->departureDate,
                // The group, never the renter's own name (the RGPD page
                // says which fields leave).
                $booking->renterOrganisation,
                $booking->status->label(),
            ];
            $options[$booking->reference] = implode(' · ', array_filter($parts));
        }

        return $options;
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
            'plusieurs réservations du même locataire dans la période, ou une référence citée par une '
                . 'adresse inconnue : l\'IA tranche parmi elles si elle est disponible, '
                . 'sinon le message n\'est rattaché à aucune',
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
        $booking = $this->bookingRepository->findByReference($link->businessReference);
        if ($booking === null) {
            return;
        }

        // Only a decision is worth learning from — a person's, or the AI's
        // among bookings the rules could not tell apart (#720, step 5). An
        // address the rules matched on their own is already known, and
        // the thread rule's may be anybody in the conversation.
        if ($link->attachmentId === 0 && in_array($link->origin, [LinkOrigin::MANUAL, LinkOrigin::AI], true)) {
            $this->learnFrom($message, $booking);
        }

        // What the unit sent is no news to its managers.
        if (!$message->isSent()) {
            $this->announce($booking, $link);
        }

        if ($message->attachments === []) {
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
     * « Nouveau message du locataire » (#720), once per message filed under
     * the booking — the association of the message itself, not one per
     * attachment.
     *
     * Never in the way of the filing: the notifier journals its own
     * failures (`NewMessageNotifier::messageFiled()`), and the attachments
     * below must still become documents of the booking.
     */
    private function announce(RentalBooking $booking, MessageLink $link): void
    {
        if ($this->newMessageNotifier === null || $link->attachmentId !== 0) {
            return;
        }

        try {
            $this->newMessageNotifier->messageFiled($booking);
        } catch (\Throwable) {
            // Only reached when the journal itself failed: the message is
            // filed whether or not anybody is told.
        }
    }

    /**
     * What a decision teaches the module, and what it does with it at once.
     *
     * The sender's address becomes one of the booking's « Autres adresses
     * du locataire » — the renter writing from work, the partner answering
     * from home — marked as learned from this message, so the next one
     * from it is recognised without anybody's help, the manager sees it
     * « ajoutée automatiquement », and « Détacher » takes it back. For a
     * message the unit SENT the person is the recipient, and only when
     * there is exactly one: a message to the renter and the caretaker
     * says nothing about which of the others is the renter's.
     *
     * And the unattributed mail is offered to this module again straight
     * away: the rest of that thread, and every earlier message from that
     * address, just became attributable. Bounded; it re-runs only the
     * rules, never the AI, so a decision cannot set off another.
     */
    private function learnFrom(InboundMessage $message, RentalBooking $booking): void
    {
        $address = self::taughtAddress($message);
        if ($address !== '' && !$this->bookingRepository->isAddressOfBooking($booking, $address)) {
            $this->bookingRepository->addRenterEmail($booking->id, $address, $message->id);
        }

        try {
            $this->inboundMail->reanalyzeUnlinked(self::CONSUMER_ID, self::REANALYSIS_AFTER_DECISION);
        } catch (\Throwable) {
            // The association a person just made is already written; a
            // re-run that fails must not undo their click or show them an
            // error about it.
        }
    }

    /**
     * The address a decision about this message teaches: its sender, or
     * for a sent message its recipient when there is exactly one —
     * normalised, '' when there is none.
     */
    private static function taughtAddress(InboundMessage $message): string
    {
        $address = $message->isSent()
            ? (count($message->toEmails) === 1 ? $message->toEmails[0] : '')
            : $message->fromEmail;

        return RentalBookingRepository::normalizeEmail($address);
    }

    /**
     * Forget what this message taught the booking: « Détacher » says it was
     * not about the booking, so neither is the address it came from —
     * unless another message still filed there by a decision teaches the
     * same address, in which case the address now hangs off that one. Only
     * one row exists per address (the unique index), so without that
     * hand-over detaching either of two teachers forgot it for both.
     */
    private function forgetWhatItTaught(RentalBooking $booking, InboundMessage $message): void
    {
        $learned = array_values(array_filter(
            $this->bookingRepository->otherRenterEmails($booking->id),
            static fn(OtherRenterEmail $other): bool => $other->learnedFromMessageId === $message->id
        ));
        if ($learned === []) {
            return;
        }

        $teachers = [];
        foreach ($this->inboundMail->findForReference(self::CONSUMER_ID, $booking->reference) as $filed) {
            if ($filed->id !== $message->id
                && in_array($filed->linkOrigin, [LinkOrigin::MANUAL, LinkOrigin::AI], true)
            ) {
                $teachers[self::taughtAddress($filed)] ??= $filed->id;
            }
        }

        foreach ($learned as $other) {
            $teacher = $teachers[RentalBookingRepository::normalizeEmail($other->email)] ?? null;
            if ($teacher === null) {
                $this->bookingRepository->removeRenterEmail($booking->id, $other->id);
            } else {
                $this->bookingRepository->repointLearnedEmail($booking->id, $other->id, $teacher);
            }
        }
    }

    /** How many unattributed messages one manual decision re-examines. */
    public const REANALYSIS_AFTER_DECISION = 50;

    /**
     * Take back what `onLinked()` filed on that booking: the address the
     * message taught it, and its documents.
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
        $booking = $this->bookingRepository->findByReference($link->businessReference);
        if ($booking === null) {
            return;
        }

        // The message was not about this booking, so neither is the address
        // its filing taught it (#720, step 5). Only that one: an address a
        // manager typed in, or another message taught, stays.
        $this->forgetWhatItTaught($booking, $message);

        if ($message->attachments === []) {
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
