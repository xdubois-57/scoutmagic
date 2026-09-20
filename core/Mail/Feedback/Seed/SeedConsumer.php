<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback\Seed;

use Modules\InboundMail\Api\AnalysisResult;
use Modules\InboundMail\Api\CandidateMessage;
use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\MessageConsumerInterface;
use Modules\InboundMail\Api\MessageLink;
use Modules\InboundMail\Api\PruningConsumerInterface;

/**
 * Finds a seed copy, writes down where it landed, and has the message
 * removed (roadmap IT-07).
 *
 * **The core implementing a module's contract, and only its `Api\`** —
 * the allowed arrow (§7.5) that `BounceConsumer` and `DmarcConsumer`
 * already take. The composition root builds it only inside the branch
 * that runs with `inbound_mail` enabled, so the whole feature is absent
 * rather than broken when the module is off (D2).
 *
 * **The folder is the measurement.** Nothing else on the message answers
 * the question a seed box exists for: not the body, which is the
 * campaign's own, and not the subject, which is deliberately identical to
 * the campaign's. `Api\CandidateMessage::$folder` carries it, which is
 * what the first slice of this iteration opened.
 *
 * **It claims nothing, and the deletion rests on the scope instead.** A
 * claim would create an association, and an association keeps a full copy
 * of every mailing in the message table — one per seed box, bodies
 * included — which is the opposite of what a box that empties itself is
 * for. So `MailboxSyncService` asks about pruning the consumers of THAT
 * mailbox, and « what may I delete » has the same answer as « what may I
 * read ». Inside that, this consumer answers yes only about a copy whose
 * landing it has just RECORDED: a row of this installation's own,
 * addressed to one of its own boxes. A human reply that lands in a seed
 * box matches nothing and stays exactly where it is.
 */
final class SeedConsumer implements MessageConsumerInterface, PruningConsumerInterface
{
    public const CONSUMER_ID = SeedMailboxes::CONSUMER_ID;

    /**
     * The message id this consumer last recorded a landing for.
     *
     * One field rather than a set, because the sync analyses one message
     * at a time and asks about pruning immediately afterwards. It is the
     * link between « I recorded this » and « this may go », and without it
     * the second answer would rest on a header anybody can write.
     */
    private ?string $recognised = null;

    public function __construct(private SeedCopyRepository $copies)
    {
    }

    public function consumerId(): string
    {
        return self::CONSUMER_ID;
    }

    public function displayName(): string
    {
        return 'Courrier sortant — boîtes témoins';
    }

    /**
     * Records the landing, and remembers only what it actually
     * recognised.
     *
     * The run reference comes from the header the transport stamped, never
     * from the subject: the subject is the campaign's, unchanged, because
     * it is what is being measured.
     *
     * A copy whose row is gone — purged, or a box removed from the scope
     * and put back — is not recorded and not remembered, so it stays in
     * the mailbox rather than being quietly deleted on the strength of a
     * header anybody could write.
     */
    public function analyze(CandidateMessage $message): AnalysisResult
    {
        // **Cleared first, every time.** One instance serves a whole sync
        // pass, so a value left over from the previous message would be
        // this consumer's answer about a message it never looked at —
        // and messages with no `Message-ID` header all carry the same
        // empty string, so « stale » there means « the wrong message
        // deleted », not merely a wrong answer.
        $this->recognised = null;

        $run = $this->runReferenceIn($message->rawHeaders ?? '');
        if ($run === null || $message->folder === null) {
            return AnalysisResult::nothing();
        }

        foreach ($this->copies->forRun($run) as $copy) {
            // The box this copy was addressed to. `toEmails` is what the
            // message names, and a seed box is one of them.
            if (!self::addressedTo($message->toEmails, $copy->address)) {
                continue;
            }

            // **Only a landing that was actually WRITTEN earns the
            // deletion.** `recordLanding()` answers false when the pair
            // already has a verdict, and the first version discarded that
            // answer and set the field regardless — so a second arrival,
            // or a replay, was deleted on the strength of a row somebody
            // else's message had filled in.
            $recorded = $this->copies->recordLanding(
                $run,
                $copy->address,
                $message->folder,
                new \DateTimeImmutable()
            );

            // An empty `Message-ID` identifies nothing, so it may not
            // stand in for « this exact message »: every message without
            // the header would otherwise match every other one.
            if ($recorded && $message->messageId !== '') {
                $this->recognised = $message->messageId;
            }

            break;
        }

        // **It claims nothing**, like the bounce and DMARC consumers. A
        // claim would mean an association, and an association would keep
        // a full copy of every mailing in the message table — one per seed
        // box, bodies included — which is the opposite of what a box that
        // empties itself is for.
        return AnalysisResult::nothing();
    }

    /**
     * **Yes, and this is the one consumer on this site that says so.**
     *
     * A seed box receives a copy of every mailing and nobody ever reads
     * it: left alone it fills up for ever, and a measuring instrument that
     * never resets stops measuring. The verdict is already written down by
     * the time this is asked — `MailboxSyncService` prunes after the
     * analysis, never before — so what survives is the measurement and
     * what goes is the mail.
     *
     * Asked only about a box this consumer was opened to (the scope, in
     * `MailboxSyncService::pruneIfAsked()`), and answered yes only about
     * a message whose landing was just recorded — so a human reply that
     * happens to land in a seed box is never touched.
     */
    public function shouldPruneAfterAnalysis(CandidateMessage $message): bool
    {
        // **Only a message this consumer just RECORDED**, never one that
        // merely carried the header. The header is a string a stranger can
        // write; a recorded landing means a row of this installation's own
        // was found, addressed to one of its own boxes.
        return $this->recognised !== null && $this->recognised === $message->messageId;
    }

    /**
     * Nothing to re-read: everything this consumer needs is on the
     * candidate, and the message is gone by then anyway.
     */
    public function analyzeStored(InboundMessage $message): AnalysisResult
    {
        return AnalysisResult::nothing();
    }

    public function onLinked(InboundMessage $message, MessageLink $link): void
    {
    }

    public function onUnlinked(InboundMessage $message, MessageLink $link): void
    {
    }

    /** No business object of its own, so a refusal is the honest answer. */
    public function canRead(string $businessReference, array $linkedMemberIds, string $role): bool
    {
        return false;
    }

    public function describeReference(string $businessReference): ?string
    {
        return null;
    }

    public function describeEvidence(): array
    {
        return ['copie d\'un publipostage adressée à une boîte témoin de cette unité'];
    }

    public function triageAudienceLabel(): string
    {
        return 'personne : la copie est mesurée, puis retirée de la boîte';
    }

    public function triageAudienceCount(): int
    {
        return 0;
    }

    /**
     * The run reference the transport stamped, or null.
     *
     * Read off the raw headers because that is where it was put — and it
     * was put there rather than in the subject because the subject is the
     * measurement ({@see SeedMailboxes::HEADER}).
     */
    private function runReferenceIn(string $rawHeaders): ?string
    {
        if ($rawHeaders === '') {
            return null;
        }

        $pattern = '/^' . preg_quote(SeedMailboxes::HEADER, '/') . ':\s*(\S+)\s*$/mi';
        if (preg_match($pattern, $rawHeaders, $matches) !== 1) {
            return null;
        }

        // **The header is checked, not believed.** A run reference is
        // `mass_mail:<id>` — a plain auto-increment — and a seed box is
        // an ordinary mailbox whose address anyone may learn, so anybody
        // able to send mail to one could otherwise stamp a guessed
        // reference, land wherever they chose, and have the site record
        // that as a real mailing's verdict. `recordLanding()`'s
        // `verdict = 'pending'` guard makes it first-writer-wins, so the
        // forgery would simply need to arrive before the real copy is
        // polled — and with automatic routing on, enough of them move a
        // provider's traffic on fabricated evidence.
        //
        // The tag is keyed by this installation's own secret, so only the
        // site can produce one.
        return $this->copies->referenceFromStamp($matches[1]);
    }

    /**
     * @param list<string> $toEmails
     */
    private static function addressedTo(array $toEmails, string $address): bool
    {
        foreach ($toEmails as $recipient) {
            if (strcasecmp(trim($recipient), trim($address)) === 0) {
                return true;
            }
        }

        return false;
    }
}
