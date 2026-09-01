<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Mail;

use Modules\InboundMail\Api\AnalysisResult;
use Modules\InboundMail\Api\CandidateMessage;
use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Api\MessageConsumerInterface;
use Modules\InboundMail\Api\MessageLink;
use Modules\InboundMail\Api\MessageRetentionPreference;
use Modules\SupportDashboard\Service\MailProbeService;

/**
 * Claims the diagnostic probes this receiver is waiting for
 * (roadmap IT-27).
 *
 * **It wants the headers and not the body.** That is the whole reason
 * `Api\MessageRetentionPreference` exists (IT-22): the diagnosis is
 * `Authentication-Results`, `Received-SPF`, `DKIM-Signature` and the
 * chain of `Received` lines, and a probe this installation sent to itself
 * has a body nobody needs to keep. Refusing it is the narrower position
 * and the honest one.
 *
 * **Only a message carrying a key this receiver issued.** Anything else
 * in the box is somebody else's mail and stays untouched — the consumer
 * answers `nothing()`, which is the ordinary answer for a consumer on a
 * shared box.
 *
 * There is deliberately **no `analyzeStored()` work**: everything this
 * consumer reads is in the headers, which are on the candidate already.
 */
class SupportMessageConsumer implements MessageConsumerInterface, MessageRetentionPreference
{
    public const CONSUMER_ID = 'support';

    public function __construct(private MailProbeService $probes)
    {
    }

    public function consumerId(): string
    {
        return self::CONSUMER_ID;
    }

    public function displayName(): string
    {
        return 'Support';
    }

    public function analyze(CandidateMessage $message): AnalysisResult
    {
        $key = MailProbeService::keyIn($message->subject);
        if ($key === null) {
            return AnalysisResult::nothing();
        }

        // The probe is recorded now, from what the candidate carries: the
        // arrival time and the subject are all the correlation needs, and
        // the headers reach the store through the retention preference
        // below.
        $claimed = $this->probes->claim(
            $message->subject,
            $message->rawHeaders,
            $message->sentAt,
            new \DateTimeImmutable()
        );

        if (!$claimed) {
            // A key that expired, or one this receiver never issued.
            // Keeping the message linked to nothing is right: it is not
            // ours, and the box's own retention will remove it.
            return AnalysisResult::nothing();
        }

        return AnalysisResult::linkedTo(self::CONSUMER_ID, $key, LinkOrigin::REFERENCE);
    }

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
     * Nobody but this module reads a probe, and this module reads it from
     * its own table rather than through `/files/{id}`. A refusal is the
     * only honest answer to « may this person download an attachment of
     * one of yours ».
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
        return ['clé de corrélation émise par ce receveur, présente dans le sujet'];
    }

    public function triageAudienceLabel(): string
    {
        return 'les super-administrateurs de cette installation';
    }

    public function triageAudienceCount(): int
    {
        // A probe is read on the support dashboard, which is superadmin
        // only. Counting the real superadmins would mean this module
        // reaching into core's accounts for a figure that is one by
        // construction on a receiver installation.
        return 1;
    }

    public function wantsRawHeaders(): bool
    {
        return true;
    }

    public function wantsBody(): bool
    {
        return false;
    }
}
