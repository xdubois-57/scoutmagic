<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\OfficialDocuments\Task;

use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\OfficialDocuments\Repository\HealthSheetRepository;
use Modules\OfficialDocuments\Service\HealthSheetService;

/**
 * The retention purge: a health sheet no family has touched for
 * `official_documents_health_sheet_retention_months` is deleted.
 *
 * **What « touched » means is the whole design.** The clock runs on
 * `last_used_at`, which moves when a family saves the sheet AND when they
 * generate a document from it (IT-04). Re-downloading last September's
 * form without changing a word is not a passive act: the parent opened the
 * page, saw the answers, and decided to print them. Treating that as
 * abandonment would delete data still in use, once a year, silently.
 *
 * **Silent towards the family, on purpose.** No notification and no
 * warning beforehand, per the chantier. The alternative — « votre fiche
 * santé sera effacée dans un mois » — is an e-mail about a child's medical
 * record arriving in an inbox the site does not control, to prevent a
 * deletion the family can undo by simply opening the page.
 *
 * Nothing is decrypted to decide any of this: `last_used_at` is a plain
 * indexed column (`HealthSheetRepository::deleteUnusedSince()`).
 *
 * Self-reschedules at the end of every run rather than being a first-class
 * recurring task — `Core\Scheduler` has no such concept — and re-reads the
 * setting each time, so a change takes effect on the next run. Its FIRST
 * occurrence is seeded in `public/index.php`: declaring a handler in
 * `module.json` teaches the runner which class handles the key and queues
 * nothing at all, so a self-rescheduling task nobody ever queued
 * reschedules itself never (ARCHITECTURE.md §8.49).
 */
class PurgeHealthSheetsHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'purge_health_sheets';
    public const REFERENCE = 'daily';
    public const INTERVAL_SECONDS = 86400;

    public const RETENTION_SETTING = 'official_documents_health_sheet_retention_months';
    public const DEFAULT_RETENTION_MONTHS = 18;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        try {
            $service = new HealthSheetService(
                new HealthSheetRepository($context->connection->getPdo(), $context->encryption),
                $context->journal
            );

            $months = self::retentionMonths($context);
            $service->purgeUnusedSince(
                (new \DateTimeImmutable())->modify("-{$months} months"),
                $months
            );
        } finally {
            // In a `finally`: a run that threw — a database hiccup, a
            // member row vanishing mid-pass — must not be a chain that
            // stops. An unrescheduled purge is a retention promise the
            // site quietly abandons, and nothing would ever say so.
            //
            // `rearmAfter()` and not `scheduleAfter()`: the guarded form
            // is the only acceptable one for a recurring chain, so a
            // duplicate chain collapses on its next pass instead of living
            // for ever (Tests\Architecture\RecurringTasksRearmTest).
            (new SchedulerService(new SchedulerRepository($context->connection->getPdo())))
                ->rearmAfter('official_documents', self::TASK_KEY, self::REFERENCE, self::INTERVAL_SECONDS);
        }
    }

    /**
     * How many months of disuse before a sheet goes, read fresh on every
     * run and scoped to this module.
     *
     * **The module id is not optional.** `SettingService` files a module's
     * setting under its own scope, so reading it without one looks up
     * `_core_::…`, finds nothing and answers the default — with no error
     * anywhere. The key being prefixed with the module's name makes that
     * easy to get wrong (issue #433, where exactly this silently disabled
     * the unit code on the parental authorization).
     *
     * A zero or negative value falls back to the default rather than
     * deleting everything the moment somebody types « 0 » into a box
     * labelled « durée de conservation ».
     */
    public static function retentionMonths(TaskContext $context): int
    {
        $months = (int) ($context->settings->get(
            self::RETENTION_SETTING,
            'official_documents'
        ) ?: self::DEFAULT_RETENTION_MONTHS);

        return $months > 0 ? $months : self::DEFAULT_RETENTION_MONTHS;
    }
}
