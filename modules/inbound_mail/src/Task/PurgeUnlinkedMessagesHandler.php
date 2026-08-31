<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Task;

use Core\Config\SettingService;
use Core\File\FileRepository;
use Core\Journal\JournalService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\InboundMail\Repository\InboundMessageRepository;

/**
 * The retention that makes storing everything defensible.
 *
 * This module keeps every message it reads (§8.58). That is only tenable
 * because of three things, and this task is one of them: **a message
 * nothing points at goes**. The other two are the screen that lets somebody
 * orient it (`/courrier`) and the RGPD page that says the archive exists.
 *
 * **What "nothing points at" means, exactly.** No association, and no
 * proposition still standing. A proposition somebody set aside protects
 * nothing (A3) — `dismissed_at` records a decision that this message is not
 * that module's business, and treating it as a reason to keep the message
 * would make « écarter » mean the opposite of what it says.
 *
 * **The clock runs from the message's own date, not from when it was
 * detached.** A message from 2024 that somebody detaches today does not
 * earn a fresh 90 days — otherwise detaching would be a way to keep things
 * indefinitely. It does earn a floor of thirty days
 * (`InboundMessageRepository::UNLINK_GRACE_DAYS`), so a mis-click has a
 * window to be noticed.
 *
 * Bounded per run and self-rescheduling daily, like every other task here:
 * `poor_mans_cron` runs inside a page view, and a purge that tried to walk
 * five years of mail in one request would be killed halfway.
 */
class PurgeUnlinkedMessagesHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'purge_unlinked_messages';
    public const REFERENCE = 'inbound-mail-retention';

    public const SETTING_RETENTION_DAYS = 'inbound_mail_unlinked_retention_days';
    public const DEFAULT_RETENTION_DAYS = 90;

    /**
     * The value an installation that had Camps' own six-month setting
     * inherits (A8).
     *
     * **Not shortened to 90 in silence.** A unit that configured six months
     * of unsorted camp mail expects to find six months of it; quietly
     * erasing three of them on upgrade would be the module deleting data
     * nobody asked it to delete. A fresh installation starts at 90.
     */
    public const CAMPS_LEGACY_RETENTION_DAYS = 180;
    public const CAMPS_LEGACY_SETTING = 'camps_unsorted_retention_months';

    /** How many messages one run removes. */
    public const BATCH_SIZE = 100;

    public const INTERVAL_SECONDS = 86400;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();
        $settings = new SettingService(new \Core\Config\SettingRepository($pdo));
        $messages = new InboundMessageRepository($pdo, $context->encryption);
        $files = new FileRepository($pdo);

        $purged = $this->purge($messages, $files, $this->retentionDays($settings), new \DateTimeImmutable());

        if ($purged > 0) {
            // A count and nothing else. Naming a sender or a subject here
            // would put in the journal exactly what the retention exists to
            // stop keeping (§7.9).
            (new JournalService(new \Core\Journal\JournalRepository($pdo)))->log(
                'inbound_mail',
                'inbound_messages_purged',
                'info',
                'Courrier entrant sans association purgé',
                ['purged' => $purged],
                null
            );
        }

        // Unconditionally, and NOT through bootstrap(): SchedulerRunner
        // marks a task done only after handle() returns, so this very task
        // is still `pending` and a guard would find it, skip, and end the
        // chain after a single run.
        (new SchedulerService(new SchedulerRepository($pdo)))
            ->scheduleAfter('inbound_mail', self::TASK_KEY, self::INTERVAL_SECONDS, [], self::REFERENCE);
    }

    /**
     * @return int the number of messages removed
     */
    public function purge(
        InboundMessageRepository $messages,
        ?FileRepository $files,
        int $retentionDays,
        \DateTimeImmutable $now
    ): int {
        $purged = 0;

        foreach ($messages->findPurgeableMessageIds($now, $retentionDays, self::BATCH_SIZE) as $messageId) {
            // Read the files BEFORE the delete: the attachment rows are
            // what names them, and they go with the message.
            $fileIds = $messages->findFileIdsForMessage($messageId);

            if (!$messages->deleteMessage($messageId)) {
                continue;
            }

            foreach (array_unique($fileIds) as $fileId) {
                // Another message may have deduplicated onto the same
                // bytes; the count is live, so it is asked after the rows
                // are gone.
                if ($messages->countAttachmentsForFile($fileId) === 0) {
                    $files?->delete($fileId);
                }
            }

            $purged++;
        }

        return $purged;
    }

    /**
     * The configured retention, or the value this installation inherits.
     *
     * The Camps reprise is read live rather than migrated once, on purpose:
     * a unit that never opens the new setting keeps the six months it
     * chose, and one that does opens a field already holding 180 rather
     * than a default that would quietly shorten it.
     */
    public function retentionDays(SettingService $settings): int
    {
        $configured = $settings->get(self::SETTING_RETENTION_DAYS, 'inbound_mail', '');
        if ($configured !== null && trim((string) $configured) !== '') {
            return max(1, (int) $configured);
        }

        $campsMonths = $settings->get(self::CAMPS_LEGACY_SETTING, 'camps', '');
        if ($campsMonths !== null && trim((string) $campsMonths) !== '') {
            return self::CAMPS_LEGACY_RETENTION_DAYS;
        }

        return self::DEFAULT_RETENTION_DAYS;
    }

    public static function bootstrap(SchedulerService $scheduler): void
    {
        $scheduler->scheduleAfter('inbound_mail', self::TASK_KEY, self::INTERVAL_SECONDS, [], self::REFERENCE);
    }
}
