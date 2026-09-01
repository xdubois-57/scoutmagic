<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Task;

use Core\Config\SettingService;
use Core\File\FileRepository;
use Core\File\UploadHandler;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\Security\HtmlSanitizer;
use Core\Service\DateInput;
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

    /**
     * The reference the chain's one pending row is found by.
     *
     * It reads as a lie now that the interval is a setting, and it stays
     * anyway: the value is what `find()` matches on, so renaming it would
     * make every already-queued run invisible on upgrade — the chain would
     * look unarmed, get armed a second time, and the box would be polled
     * twice on every cycle from then on. A name in a private constant is
     * not worth that.
     */
    private const REFERENCE = 'quarter_hourly';

    public const SETTING_INTERVAL_MINUTES = 'inbound_mail_sync_interval_minutes';

    /**
     * Fifteen minutes. Mail is not urgent enough to justify hammering
     * somebody's IMAP server — several hosts throttle or temporarily block
     * a client that reconnects every minute — and it is urgent enough that
     * an hour would make a manager wonder whether a reply arrived.
     *
     * The default, not the rule: a unit whose box is quiet says an hour,
     * one running a rental season says five minutes, and neither should
     * have to edit a constant to say it.
     */
    public const DEFAULT_INTERVAL_MINUTES = 15;

    /**
     * The floor exists to protect the unit from itself. Reconnecting every
     * minute does not deliver mail faster — the messages are not there yet
     * — and it is what makes a mail host throttle or temporarily block the
     * account, which costs the unit every relève until the block lifts.
     *
     * The ceiling is a day, because a setting that can be answered with a
     * number nobody meant (a stray keystroke turning 60 into 6000) is a
     * mailbox that silently stops being read for four months.
     */
    public const MIN_INTERVAL_MINUTES = 5;
    public const MAX_INTERVAL_MINUTES = 1440;

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

        // Through rearm(), and NOT through bootstrap(): bootstrap() also
        // CANCELS and re-queues to pull a run forward, which is not what
        // the end of a run wants. rearm()'s own guard is the right one
        // here — `claimOverdue()` marked this row `processing` before
        // calling us, so it does not find itself and does queue the
        // successor, while a DUPLICATE chain (born when a page view seeds
        // the task during that same `processing` window) finds the
        // successor pending and stands down. That last half is the fix:
        // this call used to be an unguarded scheduleAfter(), so on the
        // reference installation nine copies of this chain kept each other
        // alive and the mailbox was polled nine times per pass.
        //
        // Re-read on every run rather than captured once: the chain is the
        // only thing that ever re-arms it, so a value read at startup would
        // be the value in force until the site restarts.
        $scheduler = new SchedulerService(new SchedulerRepository($pdo));
        $scheduler->rearmAfter(
            'inbound_mail',
            self::TASK_KEY,
            self::REFERENCE,
            self::intervalSeconds($context->settings)
        );
    }

    /**
     * The configured interval, in seconds, clamped.
     *
     * Clamped here and not only at the form: the row can also be written by
     * a restore, by a hand-edited database, or by a manifest default that
     * changes under an installation's feet, and a task that re-arms itself
     * from an unchecked number is a task that can re-arm itself for the
     * next century — or for one second from now, against somebody's mail
     * host. The bounds are the same ones the setting's own
     * `validation_regex` states, so the page and the scheduler cannot
     * disagree about what is allowed.
     */
    public static function intervalSeconds(SettingService $settings): int
    {
        $configured = $settings->get(self::SETTING_INTERVAL_MINUTES, 'inbound_mail', '');
        $minutes = $configured !== null && trim((string) $configured) !== ''
            ? (int) $configured
            : self::DEFAULT_INTERVAL_MINUTES;

        return max(self::MIN_INTERVAL_MINUTES, min(self::MAX_INTERVAL_MINUTES, $minutes)) * 60;
    }

    /**
     * Queue the very first run, and keep a queued one honest about the
     * configured interval.
     *
     * Idempotent, so calling it on every request costs one indexed lookup
     * and re-arms the chain by itself if a run ever failed before
     * scheduling its successor.
     *
     * **Why it also pulls a run forward.** The chain re-arms itself at the
     * end of each run, so a shortened interval would otherwise not apply
     * until the run already queued at the OLD interval had fired: a unit
     * that went from six hours to fifteen minutes would watch nothing
     * happen for up to six hours, with no way to tell the setting from a
     * broken one. Lengthening needs nothing — the queued run is already
     * sooner than the new interval asks, it fires, and the next one waits
     * the new delay.
     *
     * Only ever brings a run *closer*, and only when the queued moment is
     * further out than a whole interval from now — which no run scheduled
     * under the current setting ever is. So this cannot become a loop that
     * keeps a mailbox permanently one page view away from a poll.
     *
     * `$settings` is nullable so the pre-existing single-argument call
     * remains valid: without it the default interval applies, which is what
     * this method did before the setting existed.
     */
    public static function bootstrap(SchedulerService $scheduler, ?SettingService $settings = null): void
    {
        $intervalSeconds = $settings !== null
            ? self::intervalSeconds($settings)
            : self::DEFAULT_INTERVAL_MINUTES * 60;

        $pending = $scheduler->find('inbound_mail', self::TASK_KEY, self::REFERENCE);

        // Drop a run queued further out than a whole interval — that is
        // the pull-forward. Everything else is left where it is, and
        // seedAfter() below then either queues the first run or finds
        // the chain already alive and stands down.
        //
        // seedAfter() and not rearmAfter(): this method runs on every
        // page view, and rearm()'s guard only sees `pending` rows — so
        // for the whole length of a cron pass, during which this chain's
        // row is `processing`, every request queued another copy due
        // immediately. See SchedulerService::seed().
        if ($pending !== null && self::isFurtherOutThan($pending, $intervalSeconds)) {
            $scheduler->cancel((int) $pending['id']);
        }

        $scheduler->seedAfter('inbound_mail', self::TASK_KEY, self::REFERENCE, $intervalSeconds);
    }

    /**
     * Whether a queued run is due later than a full interval from now.
     *
     * `run_at` is a stored timestamp, so it is read through
     * `DateInput::fromStorage()` (SECURITY.md § 35) — which answers null
     * for the blank and the malformed alike, where the bare constructor
     * would answer *now* for one and throw on the other.
     *
     * Null answers no. Leaving the row alone is the outcome that cannot
     * cost anybody a poll, and re-arming on a moment nothing could read
     * would replace a working chain with a guess.
     *
     * @param array<string, mixed> $pending
     */
    private static function isFurtherOutThan(array $pending, int $intervalSeconds): bool
    {
        $due = DateInput::fromStorage(is_string($pending['run_at'] ?? null) ? $pending['run_at'] : null);

        return $due !== null && $due->getTimestamp() > time() + $intervalSeconds;
    }
}
