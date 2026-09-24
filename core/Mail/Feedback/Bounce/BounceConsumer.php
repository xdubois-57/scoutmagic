<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback\Bounce;

use Modules\InboundMail\Api\AnalysisResult;
use Modules\InboundMail\Api\CandidateMessage;
use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\MessageConsumerInterface;
use Modules\InboundMail\Api\MessageLink;

/**
 * Recognises a bounce and records it against the address that failed
 * (roadmap IT-05).
 *
 * **The core implementing a module's contract, and only its `Api\`** —
 * the same allowed arrow as {@see \Core\Mail\Feedback\ReturnPathConsumer}
 * (§7.5, `Tests\Architecture\ModuleBoundariesTest`). The composition root
 * builds it only inside the branch that runs when `inbound_mail` is
 * enabled, so with the module off nothing here is ever loaded and the
 * Rebonds sub-page is simply absent. That is the whole of D2.
 *
 * **The report arrives in the body, and that is not an accident of this
 * class.** A bounce is `multipart/report`, whose `message/delivery-status`
 * part carries no filename and no `Content-Disposition`, so
 * `Mime\MimeMessageParser` appends it to the text body — separated from
 * the human-readable part by the blank line it joins parts with, which is
 * exactly the boundary {@see DeliveryStatusReport::parseAll()} splits on.
 * `Api\CandidateAttachment` carries metadata and no bytes, so if that part
 * ever became an attachment instead, this consumer would go silently
 * blind. `Tests\Core\Mail\Feedback\Bounce\BounceConsumerTest` pins it.
 *
 * **It claims nothing.** A bounce is recorded against an address and then
 * answered `nothing()`: it is associated with no business object, so the
 * unit's ordinary unassociated-mail retention removes it on schedule and
 * nobody's triage list grows a row for a machine writing to a machine.
 */
final class BounceConsumer implements MessageConsumerInterface
{
    public const CONSUMER_ID = 'core_mail_bounce';

    public function __construct(private BounceService $bounces)
    {
    }

    public function consumerId(): string
    {
        return self::CONSUMER_ID;
    }

    public function displayName(): string
    {
        return 'Courrier sortant — rebonds';
    }

    public function analyze(CandidateMessage $message): AnalysisResult
    {
        foreach (DeliveryStatusReport::parseAll($message->bodyText) as $report) {
            $this->bounces->record($report);
        }

        return AnalysisResult::nothing();
    }

    /**
     * Everything this consumer reads is on the candidate, and re-reading a
     * stored message would double-count: the body is still there, so a
     * second pass would record the same failure twice and block an address
     * in half the bounces it should take.
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

    /**
     * This consumer owns no business object, so there is nothing of « its
     * own » for anybody to open. A refusal is the only honest answer.
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
        return ['rapport de non-remise (RFC 3464) désignant une adresse de cette unité'];
    }

    public function triageAudienceLabel(): string
    {
        return 'personne : un rebond est enregistré contre une adresse, puis le message est oublié';
    }

    public function triageAudienceCount(): int
    {
        // Nobody is shown these messages. Inflating the figure would make
        // every other consumer's count read as noise (§8.6).
        return 0;
    }
}
