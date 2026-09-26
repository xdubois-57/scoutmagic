<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Task;

use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Member\SectionDocumentRepository;
use Core\Pdf\PdfCompressor;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;

/**
 * Background PDF compression for a just-uploaded section document
 * (core/compress_section_document, scheduled by Core\Member\
 * SectionDocumentService::upload() for every PDF). The original stays
 * downloadable throughout — this only ever replaces the stored file's
 * content in place (Core\File\EncryptedFileStorageService::replace(),
 * same file_id) once a strictly smaller, still-valid PDF is produced;
 * every outcome this handler REACHES (no backend, disabled setting, not a
 * PDF, no size win) marks the document 'skipped'.
 *
 * The outcomes it never reaches are marked by the caller instead:
 * Core\Member\SectionDocumentService::upload() schedules this task only for
 * application/pdf with compression on, and marks the row 'skipped' itself in
 * the other branch. Until issue #556 it did not, and since a row is inserted
 * 'pending' whatever the type, the eleven other accepted types kept that
 * 'pending' for ever — wearing the « Compression en cours… » badge
 * chefs/staffs.html.twig renders for it. This docblock claimed « never
 * 'pending' forever » throughout, which is how the claim reached
 * ARCHITECTURE.md §8.28 as well (issue #532): it was copied from here.
 *
 * So 'pending' now means what it says, and this handler is the only thing
 * that clears it. Rows uploaded before #556 keep theirs: not migrated, on
 * purpose.
 *
 * Declared once in Core\Scheduler\CoreTaskHandlers::all() and applied by
 * registerAll() from public/scheduler-bootstrap.php, the single file both
 * public/index.php and public/cron.php require. It is NOT hand-registered
 * in either of them: doing that separately per entry point is what
 * ARCHITECTURE.md §8.17 records a silent production failure for, and
 * Tests\Core\CronEntryPointTest refuses it.
 */
class CompressSectionDocumentHandler implements TaskHandlerInterface
{
    /**
     * Optional injectable override, same precedent as Core\Maintenance\
     * Task\FullResetHandler's ?BackupServiceInterface param — lets a test
     * exercise this handler's own branching (skip reasons, DB writes,
     * journal entries) with a fake compression outcome, without a real
     * Ghostscript/qpdf/pdftocairo binary. Production always leaves this
     * null and gets a real PdfCompressor built from $context->storagePath.
     */
    public function __construct(private ?PdfCompressor $compressorOverride = null)
    {
    }

    public function handle(array $payload, TaskContext $context): void
    {
        $documentId = (int) ($payload['section_document_id'] ?? 0);
        if ($documentId <= 0) {
            return;
        }

        $pdo = $context->connection->getPdo();
        $documentRepository = new SectionDocumentRepository($pdo);
        $document = $documentRepository->findById($documentId);
        if ($document === null) {
            return;
        }

        $fileRepository = new FileRepository($pdo);
        $file = $fileRepository->findById($document->fileId);

        if ($file === null || $file->mimeType !== 'application/pdf') {
            $documentRepository->markSkipped($documentId);
            return;
        }

        if ($context->settings->get('section_document_compression_enabled') === '0') {
            $documentRepository->markSkipped($documentId);
            return;
        }

        $compressor = $this->compressorOverride ?? new PdfCompressor($context->storagePath . '/temp');
        $backend = $compressor->detectBackend();
        if ($backend === PdfCompressor::BACKEND_NONE) {
            $documentRepository->markSkipped($documentId);
            return;
        }

        $fileStorage = new EncryptedFileStorageService($fileRepository, $context->encryption, $context->storagePath);
        try {
            $content = $fileStorage->retrieve($document->fileId);
        } catch (\RuntimeException) {
            $documentRepository->markSkipped($documentId);
            return;
        }

        $quality = (string) (
            $context->settings->get('section_document_compression_quality') ?: PdfCompressor::QUALITY_BALANCED
        );
        $compressed = $compressor->compress($content, $backend, $quality);

        if ($compressed === null) {
            $documentRepository->markSkipped($documentId);
            $context->journal->log(
                'core',
                'section_document_compression_skipped',
                'info',
                "Compression du document de section ignorée "
                    . "(pas de gain ou échec)",
                ['section_document_id' => $documentId]
            );
            return;
        }

        $fileStorage->replace($document->fileId, $compressed);
        $documentRepository->markCompressed($documentId, strlen($compressed));

        $context->journal->log(
            'core',
            'section_document_compressed',
            'info',
            'Document de section compressé',
            [
                'section_document_id' => $documentId,
                'size_before' => strlen($content),
                'size_after' => strlen($compressed)
            ]
        );
    }
}
