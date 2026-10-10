<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Documents\Task;

use Core\File\FileRepository;
use Core\Pdf\PdfCompressor;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\Documents\Repository\DocumentVersionRepository;

/**
 * Background PDF compression of a shared document's file
 * (documents/compress_document, scheduled by Service\DocumentService when
 * the CRON's PHP is known to compress, #804).
 *
 * It runs in the cron's PHP, which is the point: the web PHP of a shared
 * host may be forbidden to launch any program while the cron's compresses
 * very well, and the upload used to decide in the web request.
 *
 * - **The original is served until this runs, then replaced IN PLACE**: same
 *   file id, so /documents/{slug} and /files/{id} stay valid. The shortfall
 *   against the old synchronous path — a compressed file « from the first
 *   second » — is the price of working on such a host.
 * - **The replacement is atomic**: written beside the file then renamed over
 *   it, so a download in progress receives the old file whole or the new one
 *   whole, never a truncated one.
 * - **It stops without an error** when there is nothing to do: the file is
 *   gone, is not a PDF, is a past version (compressing a version nobody can
 *   download is wasted work), no tool answers, or there is no gain. The
 *   uncompressed file is a perfectly good file.
 * - Quality is {@see PdfCompressor::QUALITY_BALANCED}, as before.
 */
class CompressDocumentHandler implements TaskHandlerInterface
{
    /**
     * Optional injectable override, same precedent as
     * Core\Member\Task\CompressSectionDocumentHandler: lets a test run this
     * handler's branching without a real Ghostscript. Production leaves it
     * null and gets a real PdfCompressor.
     */
    public function __construct(private ?PdfCompressor $compressorOverride = null)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $fileId = (int) ($payload['file_id'] ?? 0);
        if ($fileId <= 0) {
            return;
        }

        $pdo = $context->connection->getPdo();
        $files = new FileRepository($pdo);
        $file = $files->findById($fileId);
        if ($file === null || $file->mimeType !== 'application/pdf') {
            return;
        }
        if ((new DocumentVersionRepository($pdo))->versionNumberOf($fileId) !== null) {
            return;
        }

        $path = $context->storagePath . '/' . $file->relativePath;
        $content = @file_get_contents($path);
        if ($content === false) {
            return;
        }

        $compressor = $this->compressorOverride ?? new PdfCompressor($context->storagePath . '/temp');
        $backend = $compressor->detectBackend();
        if ($backend === PdfCompressor::BACKEND_NONE) {
            return;
        }
        $compressed = $compressor->compress($content, $backend, PdfCompressor::QUALITY_BALANCED);
        if ($compressed === null || strlen($compressed) >= strlen($content)) {
            return;
        }
        if (!self::replaceAtomically($path, $compressed)) {
            return;
        }
        $files->updateSizeBytes($fileId, strlen($compressed));

        $context->journal->log(
            'documents',
            'document_compressed',
            'info',
            'Document partagé compressé',
            ['file_id' => $fileId, 'size_before' => strlen($content), 'size_after' => strlen($compressed)]
        );
    }

    /** Written next to the file, then renamed over it: never a half-written file. */
    private static function replaceAtomically(string $path, string $content): bool
    {
        $temporary = @tempnam(dirname($path), '.compress_');
        if ($temporary === false) {
            return false;
        }
        if (@file_put_contents($temporary, $content) === false || !@rename($temporary, $path)) {
            @unlink($temporary);

            return false;
        }

        return true;
    }
}
