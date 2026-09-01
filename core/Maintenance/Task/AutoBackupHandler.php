<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Task;

use Core\File\FileRepository;
use Core\Maintenance\BackupRepository;
use Core\Maintenance\BackupService;
use Core\Maintenance\BackupServiceInterface;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;

/**
 * Recurring automatic full-site backup (database + files, gallery always
 * excluded — module spec: "complete website - without photo gallery"),
 * frequency configurable from Configuration > Maintenance
 * (Core\Config\SettingService key backup_auto_frequency: none/daily/
 * weekly/biweekly/monthly, default monthly). Self-reschedules at the end
 * of every run (same pattern as Modules\LlmConnector\Task\
 * RefreshModelsHandler's weekly refresh — Core\Scheduler has no
 * first-class recurring-task concept), re-reading the setting each time so
 * a frequency change takes effect on the next run without needing to touch
 * the currently-pending scheduled action.
 *
 * Unencrypted (same as Task\ResetSettingsHandler/Task\InstallUpdateHandler's
 * own safety backups) — there is no admin present to supply a password on
 * a fully automatic run. No requester, so no push notification (mirrors
 * Core\Maintenance\GitHubWebhookService's own "no human requester"
 * reasoning for a webhook-triggered install).
 */
class AutoBackupHandler implements TaskHandlerInterface
{
    private const KEEP_BACKUPS = 5;
    private const REFERENCE = 'auto';

    /** @var array<string, string> */
    private const INTERVALS = [
        'daily' => '+1 day',
        'weekly' => '+1 week',
        'biweekly' => '+2 weeks',
        'monthly' => '+1 month',
    ];

    public function __construct(private ?BackupServiceInterface $backupService = null)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $frequency = (string) ($context->settings->get('backup_auto_frequency') ?: 'monthly');

        if (isset(self::INTERVALS[$frequency])) {
            $this->performBackup($context);
        }

        $this->scheduleNext($context, $frequency);
    }

    private function performBackup(TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();
        $basePath = dirname($context->storagePath);
        $backupService = $this->backupService ?? new BackupService($context->connection, $context->storagePath, $basePath);
        $backupRepository = new BackupRepository($pdo);
        $fileRepository = new FileRepository($pdo);

        try {
            $dbDumpPath = $backupService->createDatabaseDump();
            $filesZipPath = $backupService->createFileBackup(false);

            $backupId = $backupRepository->create('auto_backup', null);
            $zipFileId = $fileRepository->create(
                $this->relativePath($context->storagePath, $filesZipPath),
                'sauvegarde.zip',
                'application/zip',
                (int) filesize($filesZipPath),
                'admin',
                null,
                null
            );
            $dbDumpFileId = $fileRepository->create(
                $this->relativePath($context->storagePath, $dbDumpPath),
                'database.sql',
                'application/sql',
                (int) filesize($dbDumpPath),
                'admin',
                null,
                null
            );
            $backupRepository->markCompleted($backupId, $zipFileId, $dbDumpFileId);

            $context->settings->setInternal('backup_auto_last_run', (new \DateTimeImmutable())->format('Y-m-d H:i:s'));

            $context->journal->log('core', 'auto_backup_completed', 'info', 'Sauvegarde automatique effectuée', ['backup_id' => $backupId]);

            $this->purgeBeyondLimit($backupRepository, $fileRepository, $context->storagePath);
        } catch (\Throwable $e) {
            $context->journal->log('core', 'auto_backup_failed', 'info', 'Échec de la sauvegarde automatique', ['error' => $e->getMessage()]);
        }
    }

    private function scheduleNext(TaskContext $context, string $frequency): void
    {
        $schedulerRepository = new SchedulerRepository($context->connection->getPdo());
        $schedulerService = new SchedulerService($schedulerRepository);

        $existing = $schedulerService->find('core', 'auto_backup', self::REFERENCE);
        if ($existing !== null && $existing['status'] === 'pending' && strtotime($existing['run_at']) > time()) {
            return;
        }

        // 'none' (or an unrecognized value) still gets re-checked daily, so
        // switching the setting back to an active frequency later takes
        // effect within a day rather than needing a manual nudge.
        // A relative expression, and one of a fixed set: the lookup with a
        // literal fallback means $interval is always one of this class's
        // own constants, whatever $frequency holds. So this is deliberately
        // NOT DateInput::fromStorage(), which refuses relative expressions
        // on purpose (SECURITY.md § 35).
        $interval = self::INTERVALS[$frequency] ?? '+1 day';
        $schedulerService->rearm('core', 'auto_backup', self::REFERENCE, new \DateTimeImmutable($interval));
    }

    /**
     * Deletes (file + row) every backup beyond the KEEP_BACKUPS most recent
     * — same purge as every other background Maintenance task, sharing the
     * same quota across all backup types.
     */
    private function purgeBeyondLimit(BackupRepository $backupRepository, FileRepository $fileRepository, string $storagePath): void
    {
        foreach ($backupRepository->findBeyond(self::KEEP_BACKUPS) as $old) {
            foreach ([$old->fileId, $old->dbDumpFileId] as $fileId) {
                if ($fileId === null) {
                    continue;
                }
                $file = $fileRepository->findById($fileId);
                if ($file !== null) {
                    @unlink($storagePath . '/' . $file->relativePath);
                    $fileRepository->delete($fileId);
                }
            }
            $backupRepository->delete($old->id);
        }
    }

    private function relativePath(string $storagePath, string $absolutePath): string
    {
        return ltrim(substr($absolutePath, strlen($storagePath)), '/');
    }
}
