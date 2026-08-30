<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Mail;

use Core\Config\SettingService;
use Core\Security\EncryptionService;
use Core\Security\Role;
use Core\Service\DateInput;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Service\DocumentService;
use Modules\InboundMail\Api\AnalysisResult;
use Modules\InboundMail\Api\CandidateMessage;
use Modules\InboundMail\Api\InboundMailInterface;
use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\LinkOrigin;
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
 * **A dedicated mailbox** (`camps_dedicated_mailbox_ids`) — an address
 * whose whole contents are about camps, e.g. camps@unite.be. Everything
 * is claimed, and what cannot be attributed to a stay lands under the
 * reserved reference `unsorted`, which backs a "Courrier non classé"
 * screen. Nothing is discarded on arrival; the retention purge handles it
 * later.
 *
 * **Ambiguity is answered with silence.** Two stays matching one sender
 * inside the window means no attachment — putting a farmer's e-mail on
 * whichever of two stays sorted first is worse than leaving it where it
 * was, because the chief reading the wrong stay has no way to know.
 */
class CampsMessageConsumer implements MessageConsumerInterface
{
    public const CONSUMER_ID = 'camps';

    /**
     * The reserved business reference for a dedicated mailbox's
     * unattributable mail. Not a stay id, and no stay can ever collide
     * with it: stay references are 'camp-{id}'.
     */
    public const UNSORTED_REFERENCE = 'unsorted';

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
        private SettingService $settings,
        private ?InboundMailInterface $inboundMail = null,
        private ?DocumentService $documents = null,
        private ?MailFieldCompletionService $fieldCompletion = null,
        /**
         * `camps_auto_create_from_mail`. Null simply means a message
         * nobody could attribute stays unsorted, which is what this module
         * did before the setting had any code behind it.
         */
        private ?StayFromMailService $stayFromMail = null
    ) {
    }

    public function consumerId(): string
    {
        return self::CONSUMER_ID;
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
        //    they are a contact of.
        $campId = $this->campIdForSender($message);
        if ($campId !== null) {
            return AnalysisResult::linkedTo(self::CONSUMER_ID, self::referenceFor($campId), LinkOrigin::SENDER);
        }

        // 3. Dedicated mailbox only: keep everything else, unsorted. In a
        //    shared mailbox this is where the consumer says "not mine".
        if ($this->isDedicatedMailbox($message->mailboxId)) {
            return AnalysisResult::linkedTo(self::CONSUMER_ID, self::UNSORTED_REFERENCE, LinkOrigin::SENDER);
        }

        return AnalysisResult::nothing();
    }

    /**
     * Nothing to add once the message is on disk.
     *
     * Everything this module recognises is in the headers and the body, and
     * both were available on arrival. Reading a stay's attachments for more
     * would be guesswork on a file a stranger sent.
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
            'réponse dans une conversation déjà rattachée à un séjour',
            'adresse d\'un contact connu du séjour, pendant la fenêtre du camp',
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
            // Unsorted, in a dedicated mailbox. When the setting allows it
            // and the message states both its dates and a usable place, it
            // becomes a stay right here and stops being unsorted; when it
            // does not, it stays where it is and the screen offers the
            // same reading as a pre-filled form.
            if ($link->businessReference === self::UNSORTED_REFERENCE && $this->stayFromMail !== null) {
                $created = $this->stayFromMail->createFrom($message);
                if ($created !== null) {
                    $campId = $created;
                }
            }
            if ($campId === null) {
                return;
            }
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
    private function campIdForSender(CandidateMessage $message): ?int
    {
        $index = $this->encryption->blindIndex(
            EncryptionService::normalizeEmailForIndex($message->fromEmail),
            'camp_contacts.email'
        );

        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT camp_id FROM camp_contacts WHERE email_blind_index = ?'
        );
        $stmt->execute([$index]);
        $campIds = array_map(static fn(array $r): int => (int) $r['camp_id'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
        if ($campIds === []) {
            return null;
        }

        $inWindow = [];
        foreach ($campIds as $campId) {
            $camp = $this->camps->findById($campId);
            if ($camp !== null && $this->isInWindow($camp, $message->sentAt)) {
                $inWindow[] = $campId;
            }
        }

        // Exactly one, or nothing. A farmer who has hosted the unit three
        // times has three stays under one address, and guessing which one
        // this message is about would put it on the wrong page with no
        // way for the reader to tell.
        return count($inWindow) === 1 ? $inWindow[0] : null;
    }

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

    private function isDedicatedMailbox(int $mailboxId): bool
    {
        return in_array($mailboxId, $this->dedicatedMailboxIds(), true);
    }

    /**
     * @return int[]
     */
    public function dedicatedMailboxIds(): array
    {
        $raw = (string) ($this->settings->get('camps_dedicated_mailbox_ids', 'camps', '') ?? '');
        if (trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('intval', explode(',', $raw)),
            static fn(int $id): bool => $id > 0
        ));
    }
}
