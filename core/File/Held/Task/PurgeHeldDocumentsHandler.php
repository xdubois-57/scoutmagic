<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\File\Held\Task;

use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\File\Held\HeldDocumentRepository;
use Core\File\Held\HeldDocumentService;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;

/**
 * Deletes the documents the installed application put aside once their
 * half hour is over, opened or not — encrypted file first, then row.
 *
 * An expired document is already unreachable (HeldDocumentService checks
 * the expiry on every request), so this is housekeeping, not the
 * guarantee: it keeps storage/held-documents/ from holding files nobody
 * can open any more. Hourly rather than daily, because what it holds is a
 * member's own documents — a health sheet, a parental authorisation — and
 * a day is a long time for those to sit on a disk for no reason.
 *
 * Re-arms itself through rearmAfter(), never schedule()
 * (Tests\Architecture\RecurringTasksRearmTest).
 */
class PurgeHeldDocumentsHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'purge_held_documents';
    public const REFERENCE = 'hourly';
    private const INTERVAL_SECONDS = 3600;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();
        $files = new FileRepository($pdo);
        $service = new HeldDocumentService(
            new HeldDocumentRepository($pdo),
            new EncryptedFileStorageService($files, $context->encryption, $context->storagePath),
            $files,
            $context->journal
        );

        $deleted = $service->purgeExpired(new \DateTimeImmutable());
        if ($deleted > 0) {
            $context->journal->log(
                'core',
                'held_documents_purged',
                'info',
                sprintf('%d document(s) mis de côté expiré(s) effacé(s).', $deleted),
                ['count' => $deleted]
            );
        }

        SchedulerService::forPdo($pdo)->rearmAfter('core', self::TASK_KEY, self::REFERENCE, self::INTERVAL_SECONDS);
    }
}
