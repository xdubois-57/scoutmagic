<?php

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
 * every other outcome (no backend, disabled setting, not a PDF, no size
 * win) marks the document 'skipped', never 'pending' forever.
 *
 * Registered directly in both public/index.php and public/cron.php —
 * ARCHITECTURE.md §8.17 explicitly calls out that a core task handler
 * missing from the real cron entry point has caused silent production
 * failures before.
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

        $quality = (string) ($context->settings->get('section_document_compression_quality') ?: PdfCompressor::QUALITY_BALANCED);
        $compressed = $compressor->compress($content, $backend, $quality);

        if ($compressed === null) {
            $documentRepository->markSkipped($documentId);
            $context->journal->log(
                'core', 'section_document_compression_skipped', 'info', "Compression du document de section ignorée (pas de gain ou échec)",
                ['section_document_id' => $documentId]
            );
            return;
        }

        $fileStorage->replace($document->fileId, $compressed);
        $documentRepository->markCompressed($documentId, strlen($compressed));

        $context->journal->log(
            'core', 'section_document_compressed', 'info', 'Document de section compressé',
            ['section_document_id' => $documentId, 'size_before' => strlen($content), 'size_after' => strlen($compressed)]
        );
    }
}
