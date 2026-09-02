<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Mail;

use Core\Security\EncryptionService;
use Core\Security\Role;
use Core\Service\DateInput;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Service\CampLabels;
use Modules\Camps\Service\DocumentService;
use Modules\InboundMail\Api\AnalysisResult;
use Modules\InboundMail\Api\CandidateMessage;
use Modules\InboundMail\Api\InboundMailInterface;
use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Api\MessageCandidate;
use Modules\InboundMail\Api\MessageConsumerInterface;
use Modules\InboundMail\Api\MessageLink;

/**
 * Which stay an incoming message belongs to (§7.6).
 *
 * This consumer has TWO modes, and the difference between them is the
 * mailbox the message arrived in:
 *
 * **A shared mailbox** — the unit's ordinary address, also read by other
 * modules. Claiming is NARROW here, and deliberately so: everything this
 * consumer takes is a message another module will never see, and a camp
 * module that claimed on subject keywords would quietly swallow the
 * unit's mail. Two identifications only, both close to certain:
 *   1. a reply in a thread already attached to a stay;
 *   2. a sender matching a known contact's blind index, bounded by a time
 *      window around that stay.
 * Nothing weaker. Never a place name in a subject, never auto-creation.
 *
 * **A dedicated mailbox** — an address whose whole contents are about
 * camps, e.g. camps@unite.be. Everything above still applies, and one
 * reading more is allowed:
 *   3. the PERIOD the message announces, when the module already holds
 *      exactly one stay running over exactly those days
 *      (`Mail\ExistingStayMatcher`).
 * That third rule exists because the first two miss the ordinary case:
 * two messages about one booking — a contract and, weeks later, a note
 * asking about the arrival time — carry different subjects, share no
 * `References` chain, and are as often sent BY the unit as to it. Nothing
 * in a thread id or a sender address connects them; the dates do, and
 * they are stated in both.
 *
 * Beyond that, mail nobody could attribute produces no association at
 * all: the message is stored like every other (§8.58), the chief sees it
 * in the unit's mail, and the module's own users see it too because a
 * dedicated box grants them `ReadMode::ALL`. The reserved `unsorted`
 * reference this replaced was a business object that was not one — a
 * bucket masquerading as a stay, with its own retention, its own screen
 * and its own purge task, all duplicating what `inbound_mail` now does
 * once for everybody.
 *
 * **Ambiguity produces propositions, never a guess.** Two stays matching
 * one sender inside the window — or two stays over the same days —
 * means two propositions and no attachment:
 * putting a farmer's e-mail on whichever of two stays sorted first is
 * worse than leaving it unattached, because the chief reading the wrong
 * stay has no way to know. Saying « c'est l'un de ces deux, choisissez »
 * is the honest middle the module used to lack.
 */
class CampsMessageConsumer implements MessageConsumerInterface
{
    public const CONSUMER_ID = 'camps';

    /**
     * How many propositions one ambiguous message may produce. A farmer
     * who has hosted the unit ten times would otherwise turn one e-mail
     * into a wall nobody reads, which is a different way of saying
     * nothing.
     */
    public const MAX_PROPOSITIONS = 5;

    /**
     * How far around a stay a sender-matched message is still assumed to
     * be about it. Wide before — booking a field starts a year ahead —
     * and narrower after, so next year's enquiry from the same farmer
     * does not land on last year's camp.
     */
    public const WINDOW_DAYS_BEFORE = 400;
    public const WINDOW_DAYS_AFTER = 90;

    public function __construct(
        private CampRepository $camps,
        private \PDO $pdo,
        private EncryptionService $encryption,
        private ?InboundMailInterface $inboundMail = null,
        private ?DocumentService $documents = null,
        private ?MailFieldCompletionService $fieldCompletion = null,
        /**
         * `camps_auto_create_from_mail`. Null simply means a message
         * nobody could attribute stays unsorted, which is what this module
         * did before the setting had any code behind it.
         */
        private ?StayFromMailService $stayFromMail = null,
        /**
         * Recognising a stay the module ALREADY has
         * (`Mail\ExistingStayMatcher`).
         *
         * Separate from the service above, and not optional in the same
         * sense: it costs no model call, obeys no setting, and writes
         * nothing. Null only because every collaborator here is, and a
         * caller that builds none of them still gets the two
         * identifications this consumer has always had.
         */
        private ?ExistingStayMatcher $existingStay = null
    ) {
    }

    public function consumerId(): string
    {
        return self::CONSUMER_ID;
    }

    public function displayName(): string
    {
        return 'Camps';
    }

    public static function referenceFor(int $campId): string
    {
        return 'camp-' . $campId;
    }

    public static function campIdFromReference(string $reference): ?int
    {
        if (!str_starts_with($reference, 'camp-')) {
            return null;
        }
        $id = (int) substr($reference, 5);

        return $id > 0 ? $id : null;
    }

    public function analyze(CandidateMessage $message): AnalysisResult
    {
        // 1. A reply in a thread already attached to a stay. The ids were
        //    minted by a client answering a specific message, so this is
        //    as close to certain as it gets — and it works identically in
        //    both modes.
        $threadIds = array_values(array_filter(array_merge(
            $message->inReplyTo !== null ? [$message->inReplyTo] : [],
            $message->references
        )));
        if ($threadIds !== [] && $this->inboundMail !== null) {
            $reference = $this->inboundMail->findReferenceByThread(
                self::CONSUMER_ID,
                $message->mailboxId,
                $threadIds
            );
            if ($reference !== null) {
                return AnalysisResult::linkedTo(self::CONSUMER_ID, $reference, LinkOrigin::THREAD);
            }
        }

        // 2. A known contact writing, bounded by a window around the stay
        //    they are a contact of. One match is an association; several
        //    are propositions, and none is chosen.
        $bySender = $this->fromSender($message);
        if ($bySender->links !== [] || $bySender->candidates !== []) {
            return $bySender;
        }

        // 3. The period the message announces, on a dedicated box only.
        //
        //    Third and last because it is the weakest of the three, and
        //    last is also the only place it is safe: on the unit's shared
        //    address a parent writing « on part du 18 au 20 » about a
        //    week-end de section would land on the camp booked those days.
        //    A box whose whole contents are about camps is the one place
        //    that reading is worth acting on — the same asymmetry this
        //    class already applies to auto-creation.
        return $this->fromPeriod($message);
    }

    /**
     * The stay whose days this message states, when there is exactly one.
     *
     * Reads the subject and the body and nothing else: this runs on
     * arrival, inside the synchronisation loop, where an attachment's
     * bytes may not be touched (§8.58). The deferred pass reads the
     * contract too — see `analyzeStored()` — and that is the difference
     * between the two, not a difference of rule.
     */
    private function fromPeriod(CandidateMessage $message): AnalysisResult
    {
        if ($this->existingStay === null || $message->mailboxDedicatedTo !== self::CONSUMER_ID) {
            return AnalysisResult::nothing();
        }

        return $this->resultForPeriod(
            $this->existingStay->matching(trim($message->subject . "\n" . $message->bodyText))
        );
    }

    /**
     * One stay is an association, several are propositions, none is
     * nothing — the shape `fromSender()` already answers in, so a chief
     * reading either explanation reads the same kind of sentence.
     *
     * @param Camp[] $camps
     */
    private function resultForPeriod(array $camps): AnalysisResult
    {
        if (count($camps) === 1) {
            return AnalysisResult::linkedTo(
                self::CONSUMER_ID,
                self::referenceFor($camps[0]->id),
                LinkOrigin::PERIOD
            );
        }

        if (count($camps) < 2) {
            return AnalysisResult::nothing();
        }

        $candidates = [];
        foreach (array_slice($camps, 0, self::MAX_PROPOSITIONS) as $camp) {
            $candidates[] = new MessageCandidate(
                businessReference: self::referenceFor($camp->id),
                label: $this->labelFor($camp),
                evidenceType: 'period',
                explanation: 'Le message annonce exactement les dates de ce séjour. '
                    . count($camps) . ' séjours couvrent cette période : '
                    . 'ScoutMagic n\'en choisit aucun.'
            );
        }

        return new AnalysisResult([], $candidates);
    }

    /**
     * @return int[] the stays this sender could plausibly be writing about
     */
    private function campIdsForSender(CandidateMessage $message): array
    {
        if ($message->fromEmail === '') {
            return [];
        }

        $index = $this->encryption->blindIndex(
            EncryptionService::normalizeEmailForIndex($message->fromEmail),
            'camp_contacts.email'
        );

        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT camp_id FROM camp_contacts WHERE email_blind_index = ?'
        );
        $stmt->execute([$index]);

        $inWindow = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $camp = $this->camps->findById((int) $row['camp_id']);
            if ($camp !== null && $this->isInWindow($camp, $message->sentAt)) {
                $inWindow[] = (int) $row['camp_id'];
            }
        }

        return $inWindow;
    }

    private function fromSender(CandidateMessage $message): AnalysisResult
    {
        $inWindow = $this->campIdsForSender($message);

        if (count($inWindow) === 1) {
            return AnalysisResult::linkedTo(
                self::CONSUMER_ID,
                self::referenceFor($inWindow[0]),
                LinkOrigin::SENDER
            );
        }

        if ($inWindow === []) {
            // Including on a dedicated mailbox. The message is stored
            // anyway, the chief sees it in the unit's mail, and this
            // module's own users see it because a dedicated box grants
            // them the whole box — none of which needs a fake business
            // object to hang it on.
            return AnalysisResult::nothing();
        }

        $candidates = [];
        foreach (array_slice($inWindow, 0, self::MAX_PROPOSITIONS) as $campId) {
            $camp = $this->camps->findById($campId);
            if ($camp === null) {
                continue;
            }

            $candidates[] = new MessageCandidate(
                businessReference: self::referenceFor($campId),
                label: $this->labelFor($camp),
                evidenceType: 'sender_window',
                explanation: 'L\'expéditeur est un contact de ce séjour, et le message est arrivé '
                    . 'dans sa période. ' . count($inWindow) . ' séjours de ce contact correspondent : '
                    . 'ScoutMagic n\'en choisit aucun.'
            );
        }

        return new AnalysisResult([], $candidates);
    }

    /**
     * A stay as a chief recognises it. The reference (`camp-51`) is an
     * identifier, not something anybody reads at a glance.
     *
     * Through `Service\CampLabels`, which is what every other camps screen
     * uses: a second way of writing a stay's dates would drift from the
     * first within a season, and this string is read next to those screens.
     */
    private function labelFor(Camp $camp): string
    {
        $dates = CampLabels::dateRange($camp->startDate, $camp->endDate, $camp->yearOnly);
        $type = CampLabels::stayType($camp->stayType);

        return $dates === '' ? $type : $type . ' — ' . $dates;
    }

    /**
     * The deferred pass: recognise a stay this module already has, and
     * failing that, create one.
     *
     * It belongs here rather than on arrival for two reasons. It needs a
     * **stored** message — the attachments' bytes and, for creation, the
     * AI connector, neither of which `CandidateMessage` can carry — and it
     * is bounded and hourly, so a first synchronisation of a five-year-old
     * box does not try to invent four hundred stays inside one page view.
     *
     * **Recognising comes first, and obeys none of creation's settings.**
     * That ordering is the whole point: attaching a message to a stay the
     * unit already booked writes nothing new down, so gating it on
     * `camps_auto_create_from_mail` — a setting about INVENTING stays —
     * was a unit with automatic creation off getting no association
     * either, and a unit with it on getting one only as a side effect of
     * `StayFromMailService::createFrom()` failing to find a place to
     * duplicate. Reading the contract's own dates and putting the message
     * on the booking they name needs neither a setting nor a model.
     *
     * The guards that remain are still load-bearing:
     *
     * - **a dedicated mailbox only**, for both readings. On the unit's
     *   shared address a supplier's quotation would become a camp, and a
     *   parent's « on part du 18 au 20 » would land on one.
     * - **`camps_auto_create_from_mail`** for creation and creation
     *   alone, through `StayFromMailService::isAutomatic()`.
     * - **nothing already attached.** A message that a chief has since
     *   oriented by hand must not sprout a second stay because an hourly
     *   task got to it afterwards.
     */
    public function analyzeStored(InboundMessage $message): AnalysisResult
    {
        // A message something else already claimed is not a message anybody
        // is wondering about, and it leaves nothing behind: the journal
        // entries below are for the mail that produced NOTHING, which is
        // the only case a chief comes looking for.
        if ($message->links !== []) {
            return AnalysisResult::nothing();
        }

        if (!$this->isDedicatedMailbox($message->mailboxId)) {
            $this->stayFromMail?->journalSkip($message->id, StayFromMailService::SKIP_MAILBOX_NOT_DEDICATED);

            return AnalysisResult::nothing();
        }

        $matched = $this->fromStoredPeriod($message);
        if ($matched->links !== [] || $matched->candidates !== []) {
            return $matched;
        }

        if ($this->stayFromMail === null) {
            return AnalysisResult::nothing();
        }

        // Only creation is left, and the setting that governs it says so
        // by name — « ma boîte camps ne crée rien » used to have five
        // possible causes and no way to tell them apart. See
        // Mail\StayFromMailService::journalSkip().
        if (!$this->stayFromMail->isAutomatic()) {
            $this->stayFromMail->journalSkip($message->id, StayFromMailService::SKIP_NOT_AUTOMATIC);

            return AnalysisResult::nothing();
        }

        $campId = $this->stayFromMail->createFrom($message);

        return $campId === null
            ? AnalysisResult::nothing()
            : AnalysisResult::linkedTo(self::CONSUMER_ID, self::referenceFor($campId), LinkOrigin::SENDER);
    }

    /**
     * The period reading again, with the contract in it.
     *
     * The difference from `fromPeriod()` is the text, not the rule: here
     * the attachments have been read (`Mail\AttachmentTextReader`), which
     * is what makes it work on the ordinary booking — a PDF stating
     * « Arrivée : 18-09-26 / Départ : 20-09-26 » under a covering note
     * that states nothing at all.
     *
     * A model call happens in exactly one case: two stays over the same
     * days, where the venue is the only thing that can separate them.
     * That price is worth paying to avoid a wrong attachment and is not
     * worth paying for anything else here.
     */
    private function fromStoredPeriod(InboundMessage $message): AnalysisResult
    {
        if ($this->existingStay === null) {
            return AnalysisResult::nothing();
        }

        // The subject and the body first, and on their own. They answer
        // the ordinary case — « Camp complet du 18 au 20 septembre 2026 »
        // in the subject line — for the price of a regular expression, and
        // an answer found here costs no file read, no OCR call and nothing
        // leaving the installation.
        $service = $this->stayFromMail;
        $text = trim($message->subject . "\n" . $message->bodyText);
        $camps = $this->existingStay->matching($text);

        // Only when they said nothing is the contract worth opening: a
        // booking often arrives as a PDF under a covering note that states
        // no date at all, and that PDF is the one place the period is
        // written down.
        if ($camps === [] && $service !== null) {
            $text = $service->fullTextOf($message);
            $camps = $this->existingStay->matching($text);
        }

        if (count($camps) > 1 && $service !== null) {
            $camps = ExistingStayMatcher::narrowedToPlace(
                $camps,
                $service->matchPlaceIdFor($message, $service->readValues($message)['place_name'])
            );
        }

        $result = $this->resultForPeriod($camps);
        if ($result->links !== []) {
            $this->existingStay->journalMatch(
                $message->id,
                $text,
                $camps,
                ExistingStayMatcher::OUTCOME_LINKED
            );
        } elseif ($result->candidates !== []) {
            $this->existingStay->journalMatch(
                $message->id,
                $text,
                $camps,
                ExistingStayMatcher::OUTCOME_PROPOSED
            );
        }

        return $result;
    }

    public function describeReference(string $businessReference): ?string
    {
        // Not named yet: this module's reference is a slug like every
        // other, and naming it means a repository lookup this class does
        // not do today. Null keeps the screen exactly as it is rather than
        // guessing.
        return null;
    }

    /**
     * @return string[]
     */
    public function describeEvidence(): array
    {
        return [
            'réponse dans une conversation déjà rattachée à un séjour',
            'adresse d\'un contact connu du séjour, pendant la fenêtre du camp',
            'sur une boîte dédiée uniquement : période annoncée par le message, '
                . 'quand un seul séjour couvre exactement ces dates',
            'sur une boîte dédiée uniquement : tout le courrier, non classé par défaut',
        ];
    }

    public function triageAudienceLabel(): string
    {
        return 'le staff d\'unité';
    }

    /**
     * Every chief of the unit sees every stay — this module has no per-camp
     * visibility — so the audience is the staff.
     *
     * Counted on the scout year actually in effect, never estimated and
     * never frozen: opening a mailbox to this module is opening it to these
     * people, and the warning that says so is the only guard-rail on that
     * choice.
     */
    public function triageAudienceCount(): int
    {
        $stmt = $this->pdo->query(
            'SELECT COUNT(DISTINCT my.member_id)
               FROM member_years my
               JOIN member_functions mf ON mf.member_year_id = my.id
               JOIN functions f ON mf.function_id = f.id
               JOIN scout_years sy ON sy.id = my.scout_year_id
              WHERE sy.is_current = 1 AND my.is_active = 1
                AND f.role IN (\'chief\', \'admin\', \'superadmin\')'
        );

        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }

    /**
     * Attaches the message's own attachments to the stay as documents.
     *
     * The bytes are NOT copied — a camp_documents row points at the same
     * `files` id the message uses, so removing the document later leaves
     * the message intact (Service\DocumentService::delete()).
     *
     * Whatever happens here is beside the point of the synchronisation: a
     * throw would already have cost nothing, since the message is stored
     * before this runs.
     */
    public function onLinked(InboundMessage $message, MessageLink $link): void
    {
        $campId = self::campIdFromReference($link->businessReference);
        if ($campId === null) {
            // Not one of this module's references. Automatic stay creation
            // used to live here, hanging off the `unsorted` association;
            // it has moved to analyzeStored(), which is where a stored
            // message and a deferred, bounded pass already meet.
            return;
        }

        // The reference names a stay; it does not prove one still exists.
        // A message can be claimed under `camp-{id}` and the stay deleted
        // or merged away before the sync that stores it gets here — and
        // camp_documents.camp_id is a foreign key, so attaching would fail
        // the whole synchronisation pass over a row nobody can even see.
        if ($this->camps->findById($campId) === null) {
            return;
        }

        if ($this->documents === null || $message->attachments === []) {
            // No attachments to file, but the body may still say
            // something about the stay.
            $this->completeFields($campId, $message);

            return;
        }

        foreach ($message->attachments as $attachment) {
            $this->documents->attachExistingFile(
                $campId,
                $attachment->fileId,
                $attachment->filename,
                'inbound-message-' . $message->id,
                null
            );
        }

        $this->completeFields($campId, $message);
    }

    /**
     * Take back what `onLinked()` filed on that stay.
     *
     * A message moved from one stay to another used to leave its documents
     * behind on the first: invisible to whoever manages the second, and
     * unexplainable to whoever manages the first. The bytes are never
     * touched — a document sourced from an email points at the message's
     * own file, which the message still owns.
     *
     * The field completions are deliberately NOT undone: a chief may have
     * accepted one, and silently reverting a value somebody validated is
     * worse than leaving a field filled from a message that has moved on.
     */
    public function onUnlinked(InboundMessage $message, MessageLink $link): void
    {
        $campId = self::campIdFromReference($link->businessReference);
        if ($campId === null || $this->documents === null) {
            return;
        }

        $this->documents->detachSourced($campId, 'inbound-message-' . $message->id, null);
    }

    /**
     * Who may read an attachment of a message attached to a stay.
     *
     * Every stay is readable by every chief of the unit — this module has
     * no per-camp visibility — so the answer is the role and nothing else,
     * exactly as `Service\CampFileOwnershipChecker` already answers for a
     * stay's own documents. Answering anything narrower here would make an
     * emailed contract less reachable than the same file filed by hand.
     *
     * @param array<int, int> $linkedMemberIds
     */
    public function canRead(string $businessReference, array $linkedMemberIds, string $role): bool
    {
        return (Role::tryFrom($role) ?? Role::PUBLIC)->hasAccess(Role::CHIEF);
    }

    /**
     * Reads what the message says about the stay and either fills an
     * empty field or parks a proposal next to a full one
     * (Mail\MailFieldCompletionService).
     */
    private function completeFields(int $campId, InboundMessage $message): void
    {
        if ($this->fieldCompletion === null) {
            return;
        }
        $camp = $this->camps->findById($campId);
        if ($camp === null) {
            return;
        }

        $this->fieldCompletion->apply(
            $camp,
            trim($message->subject . "\n" . $message->bodyText),
            'inbound-message-' . $message->id
        );
    }

    /**
     * The stay a known contact's address points at, or null when there is
     * none — or when there are SEVERAL.
     */
    private function isInWindow(Camp $camp, \DateTimeImmutable $sentAt): bool
    {
        $start = $camp->startDate ?? $camp->endDate;
        $end = $camp->endDate ?? $camp->startDate;
        if ($start === null || $end === null) {
            // A year-only stay has no day to measure from. Its whole year
            // plus the run-up is the honest window.
            if ($camp->yearOnly === null) {
                return false;
            }
            $start = $camp->yearOnly . '-01-01';
            $end = $camp->yearOnly . '-12-31';
        }

        $from = DateInput::fromStorage($start)?->modify('-' . self::WINDOW_DAYS_BEFORE . ' days');
        $to = DateInput::fromStorage($end)?->modify('+' . self::WINDOW_DAYS_AFTER . ' days');
        if ($from === null || $to === null) {
            // Same answer as a stay with no day at all: a window we cannot
            // compute does not get to claim a message.
            return false;
        }

        return $sentAt >= $from && $sentAt <= $to;
    }

    /**
     * Whether that box is the one the operator declared to be the camps
     * box — asked of `inbound_mail`, which owns the answer.
     *
     * **It used to be read from `camps_dedicated_mailbox_ids`, this
     * module's own list of ids, and that had stopped being true.** The
     * configuration screen « Portée des modules » took the question over
     * (`Service\MailboxScopeService`), migrated the old list into the new
     * model once, and never wrote to it again — so on every installation
     * that declared its camps box on the new screen, this method answered
     * « non » and automatic stay creation was silently off. The setting is
     * kept declared for the one-time reprise of an installation still on
     * the old model, and is read by nothing else.
     *
     * No `inbound_mail`, no answer, and therefore no creation: §7.5's
     * degradation, and the safe direction — a stay invented on a box
     * nobody declared is worse than a stay nobody invented.
     */
    private function isDedicatedMailbox(int $mailboxId): bool
    {
        return $this->inboundMail?->isDedicatedTo(self::CONSUMER_ID, $mailboxId) ?? false;
    }
}
