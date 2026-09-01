<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Task;

use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\Security\EncryptionService;
use Modules\InboundMail\Repository\InboundMessageRepository;
use Modules\InboundMail\Service\AnalysisResultApplier;
use Modules\InboundMail\Service\MessageConsumerRegistry;

/**
 * The second, content-level analysis pass — after the message is on disk,
 * and never inside the synchronisation loop.
 *
 * **Why it is a separate task at all.** Reading a PDF's text to find an
 * amount is expensive and unbounded; doing it while a mailbox is being
 * polled would blow through `max_execution_time` on shared hosting, and a
 * synchronisation that dies leaves the cursor unmoved — so the same doomed
 * run would repeat on every tick, and the box would never get past it. The
 * arrival pass therefore sees attachment **metadata** only, and everything
 * that needs the bytes happens here.
 *
 * **Once per message, and only once.** `stored_analysis_at` is the marker.
 * Nothing re-analyses a stored message on its own, ever: propositions
 * appearing and disappearing as modules are updated, with nobody able to
 * say why, is worse than none. The manual « Réanalyser le courrier
 * conservé » clears the marker for a whole mailbox when a superadmin
 * deliberately asks — and even then, a proposition somebody set aside stays
 * set aside.
 *
 * Bounded per run for the same reason the sync is: `poor_mans_cron` runs
 * inside a page view, and the task simply comes back for the rest.
 */
class AnalyzeStoredMessagesHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'analyze_stored_messages';
    public const REFERENCE = 'inbound-mail-stored-analysis';

    /**
     * How many messages one run puts through the deferred pass.
     *
     * Deliberately smaller than the synchronisation's batch: a message here
     * may cost a PDF text extraction, where one there costs a MIME parse.
     */
    public const BATCH_SIZE = 10;

    /** Hourly. The pass is not urgent — nothing waits on it interactively. */
    public const INTERVAL_SECONDS = 3600;

    public function __construct(private ?MessageConsumerRegistry $consumerRegistry = null)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();

        if ($this->consumerRegistry !== null && $this->consumerRegistry->hasConsumers()) {
            $this->analyzeBatch(new InboundMessageRepository($pdo, $context->encryption), $context->encryption);
        }

        // Unconditionally, and NOT through bootstrap(): SchedulerRunner
        // marks a task done only after handle() returns, so this very task
        // is still `pending` right now and a guard would find it, skip, and
        // end the chain after a single run.
        (new SchedulerService(new SchedulerRepository($pdo)))
            ->rearmAfter('inbound_mail', self::TASK_KEY, self::REFERENCE, self::INTERVAL_SECONDS);
    }

    private function analyzeBatch(InboundMessageRepository $messages, EncryptionService $encryption): void
    {
        $applier = new AnalysisResultApplier($messages);
        $now = new \DateTimeImmutable();

        foreach ($messages->findMessagesAwaitingStoredAnalysis(self::BATCH_SIZE) as $messageId) {
            // Marked before the work, not after. A message whose analysis
            // throws must not be retried forever at the head of the queue,
            // blocking everything behind it — the same reason the sync
            // cursor advances past a message it could not use.
            $messages->markStoredAnalysisDone($messageId, $now);

            $stored = $messages->findAnyForAnalysis($messageId);
            if ($stored === null) {
                continue;
            }

            $applier->apply($messageId, $this->consumerRegistry?->analyzeAllStored($stored) ?? []);
        }
    }

    /**
     * Seed the self-rescheduling chain. Without the first nudge nothing
     * ever runs.
     */
    public static function bootstrap(SchedulerService $scheduler): void
    {
        $scheduler->rearmAfter('inbound_mail', self::TASK_KEY, self::REFERENCE, self::INTERVAL_SECONDS);
    }
}
