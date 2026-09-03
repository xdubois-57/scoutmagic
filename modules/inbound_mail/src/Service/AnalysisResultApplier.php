<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Service;

use Modules\InboundMail\Api\AnalysisResult;
use Modules\InboundMail\Api\MessageLink;
use Modules\InboundMail\Repository\InboundMessageRepository;

/**
 * Turning what the consumers said into rows, and telling them what was
 * actually written.
 *
 * Shared by both analysis paths — the synchronisation's arrival pass and
 * the deferred content pass — because "apply an AnalysisResult" must mean
 * exactly the same thing in both, down to which consumer gets called back.
 *
 * **A consumer only ever associates on its own behalf.** Whatever
 * `consumerId` a returned `MessageLink` carries is ignored in favour of the
 * id of the consumer that returned it: without that, a module could quietly
 * file a message under another module's reference, and the access rules of
 * §8.58 would be answering about an association its own module never made.
 */
class AnalysisResultApplier
{
    public function __construct(private InboundMessageRepository $messageRepository)
    {
    }

    /**
     * Write every association and proposition, and report the associations
     * that were actually new.
     *
     * Only the new ones: `onLinked()` fires once per association, ever. A
     * re-read after a UIDVALIDITY reset, or a manual re-analysis, must not
     * make `rental` file the same attachment as a booking document a second
     * time.
     *
     * @param array<string, AnalysisResult> $resultsByConsumer
     * @return array<int, array{consumerId: string, link: MessageLink}> newly created associations
     */
    public function apply(int $messageId, array $resultsByConsumer): array
    {
        return $this->applyAndReport($messageId, $resultsByConsumer)->links;
    }

    /**
     * The same, also reporting the propositions that were actually new —
     * the ones a consumer implementing `Api\PropositionListener` is told
     * about, once each.
     *
     * @param array<string, AnalysisResult> $resultsByConsumer
     */
    public function applyAndReport(int $messageId, array $resultsByConsumer): AppliedAnalysis
    {
        $created = [];
        $proposed = [];

        foreach ($resultsByConsumer as $consumerId => $result) {
            foreach ($result->links as $link) {
                $wasCreated = $this->messageRepository->addLink(
                    $messageId,
                    $consumerId,
                    $link->businessReference,
                    $link->origin,
                    $link->attachmentId,
                    $link->createdByUserAccountId
                );

                if ($wasCreated) {
                    $created[] = [
                        'consumerId' => $consumerId,
                        'link' => new MessageLink(
                            $consumerId,
                            $link->businessReference,
                            $link->origin,
                            $link->attachmentId,
                            $link->createdByUserAccountId
                        ),
                    ];
                }
            }

            foreach ($result->candidates as $candidate) {
                // Refuses to re-create a proposition somebody set aside —
                // `dismissed_at` is final (A3/D10).
                if ($this->messageRepository->addCandidate($messageId, $consumerId, $candidate)) {
                    $proposed[] = ['consumerId' => $consumerId, 'candidate' => $candidate];
                }
            }
        }

        return new AppliedAnalysis($created, $proposed);
    }
}
