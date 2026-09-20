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
 * **It claims the message, and claiming is what earns the deletion.**
 * `MailboxSyncService` asks about pruning only of a consumer whose answer
 * was kept, which is what makes « a human reply that lands in a seed box
 * stays put » true without a second rule: this consumer recognises a copy
 * by its header and shrugs at everything else.
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
     * Records the landing, and claims only what it actually recognised.
     *
     * The run reference comes from the header the transport stamped, never
     * from the subject: the subject is the campaign's, unchanged, because
     * it is what is being measured.
     *
     * A copy whose row is gone — purged, or a box removed from the scope
     * and put back — is not recorded and not claimed, so it stays in the
     * mailbox rather than being quietly deleted on the strength of a
     * header anybody could write.
     */
    public function analyze(CandidateMessage $message): AnalysisResult
    {
        $run = self::runReferenceIn($message->rawHeaders ?? '');
        if ($run === null || $message->folder === null) {
            return AnalysisResult::nothing();
        }

        foreach ($this->copies->forRun($run) as $copy) {
            // The box this copy was addressed to. `toEmails` is what the
            // message names, and a seed box is one of them.
            if (!self::addressedTo($message->toEmails, $copy->address)) {
                continue;
            }

            $this->copies->recordLanding($run, $copy->address, $message->folder, new \DateTimeImmutable());

            // **Remembered, so that the pruning answer is about a message
            // this consumer actually recorded** and never about one it
            // merely saw. That is the whole of its standing to have a
            // message deleted.
            $this->recognised = $message->messageId;

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
     * Asked only about a message this consumer CLAIMED, so a human reply
     * that happens to land in a seed box is never touched.
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
    private static function runReferenceIn(string $rawHeaders): ?string
    {
        if ($rawHeaders === '') {
            return null;
        }

        $pattern = '/^' . preg_quote(SeedMailboxes::HEADER, '/') . ':\s*(\S+)\s*$/mi';

        return preg_match($pattern, $rawHeaders, $matches) === 1 ? $matches[1] : null;
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
