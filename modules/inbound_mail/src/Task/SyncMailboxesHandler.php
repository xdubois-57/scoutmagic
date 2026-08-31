<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Task;

use Core\File\FileRepository;
use Core\File\UploadHandler;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\Security\HtmlSanitizer;
use Modules\InboundMail\Repository\InboundMailboxRepository;
use Modules\InboundMail\Repository\InboundMessageRepository;
use Modules\InboundMail\Service\AnalysisResultApplier;
use Modules\InboundMail\Service\AttachmentPolicy;
use Modules\InboundMail\Service\MailboxClientFactory;
use Modules\InboundMail\Service\MailboxErrorFormatter;
use Modules\InboundMail\Service\MailboxScopeService;
use Modules\InboundMail\Service\MailboxSyncService;
use Modules\InboundMail\Service\MessageConsumerRegistry;
use Modules\InboundMail\Service\MessageContentSanitizer;
use Modules\InboundMail\Service\StorageQuotaService;

/**
 * Polls every enabled mailbox (§7.4).
 *
 * Self-reschedules at the end of each run rather than being a first-class
 * recurring task, the same pattern as
 * `Modules\Rental\Task\ExpireRentalHoldsHandler` — `Core\Scheduler` has no
 * recurring-task concept.
 *
 * **This handler needs the consumer registry, which only the composition
 * root can build**, so unlike most module tasks it is registered explicitly
 * with `SchedulerRunner::registerHandler()` in *both* `public/index.php`
 * and `public/cron.php`. Registering it in only one of the two is a bug
 * this codebase has shipped before (ARCHITECTURE.md §8.17/§8.20), so a test
 * pins both call sites.
 *
 * An instance built without a registry — the auto-resolution path, if the
 * explicit registration were ever lost — connects to nothing: every message
 * would be fetched and discarded unclaimed, so there is no point opening
 * the connection at all. It still re-arms the chain, so restoring the
 * registration is enough to make it collect again.
 */
class SyncMailboxesHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'sync_mailboxes';
    private const REFERENCE = 'quarter_hourly';

    /**
     * Fifteen minutes. Mail is not urgent enough to justify hammering
     * somebody's IMAP server — several hosts throttle or temporarily block
     * a client that reconnects every minute — and it is urgent enough that
     * an hour would make a manager wonder whether a reply arrived.
     */
    private const INTERVAL_SECONDS = 900;

    public function __construct(
        private ?MessageConsumerRegistry $consumerRegistry = null,
        /**
         * The disk ceiling (D5). Null simply means no quota is enforced,
         * which is the right behaviour for a unit on its own server and
         * the safe one for a test that does not care.
         */
        private ?StorageQuotaService $quotaService = null,
        /**
         * What each box lets each module do (IT-05). Null means every
         * consumer is offered every message, which is what the contract was
         * before the configuration screen existed.
         */
        private ?MailboxScopeService $scopeService = null
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();

        if ($this->consumerRegistry !== null && $this->consumerRegistry->hasConsumers()) {
            $messageRepository = new InboundMessageRepository($pdo, $context->encryption);
            $service = new MailboxSyncService(
                new InboundMailboxRepository($pdo, $context->encryption),
                $messageRepository,
                $this->consumerRegistry,
                new MessageContentSanitizer(new HtmlSanitizer()),
                new AttachmentPolicy(),
                new MailboxErrorFormatter(),
                new MailboxClientFactory(),
                new AnalysisResultApplier($messageRepository),
                new UploadHandler(new FileRepository($pdo), $context->storagePath),
                $this->quotaService,
                new FileRepository($pdo),
                $this->scopeService
            );

            $service->syncAll(new \DateTimeImmutable());
        }

        // Unconditionally, and NOT through bootstrap(): SchedulerRunner
        // marks a task done only after handle() returns, so this very task
        // is still `pending` right now and bootstrap()'s guard would find
        // it, skip, and end the chain after a single run.
        $scheduler = new SchedulerService(new SchedulerRepository($pdo));
        $scheduler->scheduleAfter('inbound_mail', self::TASK_KEY, self::INTERVAL_SECONDS, [], self::REFERENCE);
    }

    /**
     * Queue the very first run. Idempotent, so calling it on every request
     * costs one indexed lookup and re-arms the chain by itself if a run
     * ever failed before scheduling its successor.
     */
    public static function bootstrap(SchedulerService $scheduler): void
    {
        if ($scheduler->find('inbound_mail', self::TASK_KEY, self::REFERENCE) !== null) {
            return;
        }

        $scheduler->scheduleAfter('inbound_mail', self::TASK_KEY, self::INTERVAL_SECONDS, [], self::REFERENCE);
    }
}
