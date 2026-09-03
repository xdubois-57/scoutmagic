<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Service;

use Modules\InboundMail\Api\MessageConsumerInterface;
use Modules\InboundMail\Api\MessageLink;
use Modules\InboundMail\Api\PropositionListener;
use Modules\InboundMail\Repository\InboundMessageRepository;

/**
 * Telling a consumer that one of its associations was just created, so it
 * can do its own bookkeeping — turning a message's attachments into
 * documents of its own, for instance (§7.8).
 *
 * **This exists because only one of the two analysis passes was doing it.**
 * `Service\AnalysisResultApplier` writes the rows and reports which
 * associations are new; the arrival pass then called every consumer back,
 * and the deferred pass simply did not. So a stay created automatically
 * from a booking e-mail got the association and NOT the contract that
 * arrived with it: `Camps\Mail\CampsMessageConsumer::onLinked()` — the one
 * place that files a message's attachments as a stay's documents — was
 * never reached on the only path that creates such a stay.
 *
 * Nothing about that omission was visible from either call site. The
 * callback now has one owner, and both passes hand it the same thing.
 *
 * **Deliberately after the write, and deliberately unable to fail the
 * run**: the message is already stored, and one module's bookkeeping
 * throwing must not cost the unit the rest of its mail.
 */
class LinkedMessageNotifier
{
    public function __construct(
        private InboundMessageRepository $messageRepository,
        private MessageConsumerRegistry $consumerRegistry,
        private ?AnalysisJournal $analysisJournal = null
    ) {
    }

    /**
     * @param array<int, array{consumerId: string, link: MessageLink}> $created
     *   exactly what `AnalysisResultApplier::apply()` returns
     * @param array<int, array{consumerId: string, candidate: \Modules\InboundMail\Api\MessageCandidate}> $proposed
     */
    public function notify(int $messageId, array $created, array $proposed = []): void
    {
        $this->notifyProposed($messageId, $proposed);

        foreach ($created as $entry) {
            $consumer = $this->consumerRegistry->find($entry['consumerId']);
            if ($consumer === null) {
                continue;
            }

            $this->notifyConsumer($consumer, $messageId, $entry['link']);
        }
    }

    private function notifyConsumer(
        MessageConsumerInterface $consumer,
        int $messageId,
        MessageLink $link
    ): void {
        $stored = $this->messageRepository->findOneForReference(
            $link->consumerId,
            $link->businessReference,
            $messageId
        );
        if ($stored === null) {
            return;
        }

        try {
            $consumer->onLinked($stored, $link);
        } catch (\Throwable $e) {
            // Swallowed, as it has to be — but no longer in silence. A
            // module that throws while filing a contract leaves the stay
            // without it and says nothing else anywhere, which is the
            // shape of failure this journal exists to end.
            $this->analysisJournal?->failed(
                $link->consumerId,
                $stored->mailboxId,
                AnalysisJournal::PASS_FILING,
                $e
            );
        }
    }

    /**
     * Tell each consumer that implements `Api\PropositionListener` about
     * the propositions of its own that were just written — grouped, so a
     * message with two candidates of one module is one call, and one
     * notification to whoever settles them.
     *
     * @param array<int, array{consumerId: string, candidate: \Modules\InboundMail\Api\MessageCandidate}> $proposed
     */
    private function notifyProposed(int $messageId, array $proposed): void
    {
        if ($proposed === []) {
            return;
        }

        $byConsumer = [];
        foreach ($proposed as $entry) {
            $byConsumer[$entry['consumerId']][] = $entry['candidate'];
        }

        $stored = null;
        foreach ($byConsumer as $consumerId => $candidates) {
            $consumer = $this->consumerRegistry->find($consumerId);
            if (!$consumer instanceof PropositionListener) {
                continue;
            }

            $stored ??= $this->messageRepository->findAnyForAnalysis($messageId);
            if ($stored === null) {
                return;
            }

            try {
                $consumer->onProposed($stored, $candidates);
            } catch (\Throwable $e) {
                // The propositions are written; a notification that could
                // not go out must not undo the analysis that made them.
                $this->analysisJournal?->failed(
                    $consumerId,
                    $stored->mailboxId,
                    AnalysisJournal::PASS_FILING,
                    $e
                );
            }
        }
    }
}
