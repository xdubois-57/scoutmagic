<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Task;

use Core\Config\ScoutYearService;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Import\ImportJournalRepository;
use Core\Import\ImportRetentionService;
use Core\Import\RosterSnapshotRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\Finance\Repository\CampaignRepository;
use Modules\Finance\Repository\CampaignRowRepository;

/**
 * Forgets a campaign's source spreadsheet once its scout year has fallen
 * out of the retention window.
 *
 * **The window is the Desk import's own**, read from
 * Core\Import\ImportRetentionService — the same setting
 * (`import_retention_scout_years`, two seasons by default), the same
 * arithmetic, the same class. A second retention period to maintain is a
 * second retention period to get wrong, and the two files are the same
 * kind of thing: a roster of members a chief downloaded, kept only so
 * that somebody can later ask where a number came from.
 *
 * **What goes and what stays.** The file goes, and so does the copy of
 * its columns kept for the reminder's merge variables — keeping a copy of
 * data we have promised to delete would make the promise decorative. The
 * campaign, its rows, its amounts and its receivables stay: those are the
 * unit's financial history, they were never temporary, and once the file
 * is gone they name nobody but by an internal identifier.
 *
 * Runs daily and reschedules itself in a `finally`, the same shape as
 * Core\Import\Task\PurgeImportsHandler: Core\Scheduler has no
 * first-class recurring task, so a run that fails must still put the next
 * one on the clock or the purge stops for good on its first bad day.
 */
class PurgeCampaignFilesHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'purge_campaign_files';
    public const REFERENCE = 'daily';
    public const INTERVAL_SECONDS = 86400;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();

        try {
            $retention = new ImportRetentionService(
                $pdo,
                new ImportJournalRepository($pdo),
                new RosterSnapshotRepository($pdo),
                new FileRepository($pdo),
                new ScoutYearService($pdo),
                $context->settings,
                $context->journal,
                $context->storagePath
            );

            $expiredYears = $retention->yearsBeyondRetention();
            if ($expiredYears === []) {
                return;
            }

            $campaigns = new CampaignRepository($pdo);
            $rows = new CampaignRowRepository($pdo, $context->encryption);
            $storage = new EncryptedFileStorageService(new FileRepository($pdo), $context->encryption, $context->storagePath);

            $forgotten = 0;
            foreach ($expiredYears as $scoutYearId) {
                foreach ($campaigns->findByScoutYear($scoutYearId) as $campaign) {
                    if ($campaign->sourceFileId === null && $campaign->mergeColumns === []) {
                        continue; // already forgotten on an earlier run
                    }

                    if ($campaign->sourceFileId !== null) {
                        $storage->delete($campaign->sourceFileId);
                    }
                    $rows->forgetMergeDataForCampaign($campaign->id);
                    $campaigns->forgetSourceFile($campaign->id);
                    $forgotten++;
                }
            }

            if ($forgotten > 0) {
                $context->journal->log(
                    'finance',
                    'campaign_files_purged',
                    'info',
                    'Fichiers sources de campagnes supprimés au terme de leur durée de conservation',
                    ['campaign_count' => $forgotten, 'scout_year_count' => count($expiredYears)]
                );
            }
        } finally {
            $scheduler = new SchedulerService(new SchedulerRepository($pdo));
            $scheduler->rearmAfter('finance', self::TASK_KEY, self::REFERENCE, self::INTERVAL_SECONDS);
        }
    }
}
