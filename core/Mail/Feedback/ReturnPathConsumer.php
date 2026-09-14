<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback;

use Modules\InboundMail\Api\AnalysisResult;
use Modules\InboundMail\Api\CandidateMessage;
use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\MessageConsumerInterface;
use Modules\InboundMail\Api\MessageLink;

/**
 * Recognises the round-trip messages the site wrote to itself (roadmap
 * IT-03).
 *
 * **The core implementing a module's contract, and only its `Api\`.** The
 * arrow points the allowed way (§7.5, `Tests\Architecture\
 * ModuleBoundariesTest`): this class names nothing but
 * `Modules\InboundMail\Api\*`, and the composition root builds it only
 * inside the branch that runs when the module is enabled. With the module
 * off, nothing here is ever loaded and
 * {@see ReturnPathVerifier::isPossible()} answers « non » — which is the
 * whole of D2.
 *
 * **It claims nothing, on purpose.** A message it recognises is recorded
 * against its probe and then answered `nothing()`, so it is never
 * associated with anything: the unit's ordinary unassociated-mail
 * retention removes it on schedule, and nobody's triage list gains a row
 * for a message the site sent to itself. The alternative — linking it so
 * that `Api\MessageRetentionPreference` could drop its body — would buy
 * nothing: the body is a fixed French sentence this site wrote, with no
 * personal data in it to protect and nothing in it anybody needs kept.
 */
final class ReturnPathConsumer implements MessageConsumerInterface
{
    public function __construct(private ReturnPathVerifier $verifier)
    {
    }

    public function consumerId(): string
    {
        return ReturnPathVerifier::CONSUMER_ID;
    }

    public function displayName(): string
    {
        return 'Courrier sortant';
    }

    public function analyze(CandidateMessage $message): AnalysisResult
    {
        $this->verifier->claim($message->subject, $message->mailboxId, $message->sentAt);

        return AnalysisResult::nothing();
    }

    public function analyzeStored(InboundMessage $message): AnalysisResult
    {
        // Everything this consumer reads is on the candidate: a key in a
        // subject line, and the box it arrived in.
        return AnalysisResult::nothing();
    }

    public function onLinked(InboundMessage $message, MessageLink $link): void
    {
    }

    public function onUnlinked(InboundMessage $message, MessageLink $link): void
    {
    }

    /**
     * This consumer owns no business object, so there is no attachment of
     * « one of its own » for anybody to open. A refusal is the only
     * honest answer, and never a grant.
     */
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
        return ['référence de vérification émise par ce site, présente dans le sujet'];
    }

    public function triageAudienceLabel(): string
    {
        return 'personne : cette vérification ne classe aucun courrier et n\'en montre aucun';
    }

    public function triageAudienceCount(): int
    {
        // The warning next to the scope choice counts the people a box
        // would be opened to. This consumer shows no message to anybody —
        // it writes down an arrival time and forgets the message — so the
        // honest figure is zero, and inflating it would make every other
        // consumer's count read as noise.
        return 0;
    }
}
