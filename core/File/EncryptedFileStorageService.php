<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\File;

use Core\Security\EncryptionService;

/**
 * Generic encrypted-at-rest file storage, built on top of the same
 * files table and master key as everything else (Core\Security\
 * EncryptionService) — not specific to any module. A file stored here is
 * never written to disk in plaintext; FileController::serve() decrypts
 * on the way out for any FileRecord with encrypted = true.
 *
 * store() takes more than the bare (content, mimeType) a minimal
 * interface would suggest, because the files table's original_name and
 * role_min columns are NOT NULL — there is no way to create a valid
 * FileRecord without them.
 */
class EncryptedFileStorageService
{
    private const EXTENSION_BY_MIME = [
        'application/pdf' => 'pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/msword' => 'doc',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.oasis.opendocument.text' => 'odt',
        'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
        'application/vnd.oasis.opendocument.presentation' => 'odp',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    public function __construct(
        private FileRepository $fileRepository,
        private EncryptionService $encryption,
        private string $storagePath
    ) {
    }

    /**
     * Encrypts $content and writes it under $subDirectory (relative to
     * the storage root), then registers a FileRecord with
     * encrypted = true. Returns the new file_id.
     */
    public function store(
        string $content,
        string $mimeType,
        string $originalName,
        string $subDirectory,
        string $roleMin,
        ?string $moduleId = null,
        ?int $createdBy = null,
        ?int $ownerMemberId = null,
        ?string $ownerType = null,
        ?int $ownerId = null
    ): int {
        $extension = self::EXTENSION_BY_MIME[$mimeType] ?? 'bin';
        $randomName = bin2hex(random_bytes(16)) . '.' . $extension . '.enc';
        $relativePath = $subDirectory . '/' . $randomName;
        $targetDir = $this->storagePath . '/' . $subDirectory;

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $encrypted = $this->encryption->encrypt($content, 'file_storage');
        if (file_put_contents($this->storagePath . '/' . $relativePath, $encrypted) === false) {
            throw new \RuntimeException('Impossible d\'écrire le fichier chiffré sur le disque.');
        }

        return $this->fileRepository->create(
            $relativePath,
            $originalName,
            $mimeType,
            strlen($content),
            $roleMin,
            $moduleId,
            $createdBy,
            true,
            $ownerMemberId,
            $ownerType,
            $ownerId
        );
    }

    /**
     * Decrypts and returns a stored file's content.
     *
     * @throws \RuntimeException if the file record or the file on disk is missing
     */
    public function retrieve(int $fileId): string
    {
        $file = $this->fileRepository->findById($fileId);
        if ($file === null) {
            throw new \RuntimeException("Fichier {$fileId} introuvable.");
        }

        $path = $this->storagePath . '/' . $file->relativePath;
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Fichier {$fileId} illisible sur le disque.");
        }

        return $this->encryption->decrypt($raw, 'file_storage');
    }

    /**
     * Swaps a stored file's content in place — same file id and
     * relative_path, re-encrypted, size_bytes updated. Used by the PDF
     * compression background task (Core\Member\Task\
     * CompressSectionDocumentHandler): the document keeps pointing at the
     * same file_id throughout, so nothing referencing it needs to change.
     *
     * @throws \RuntimeException if the file record is missing or the disk write fails
     */
    public function replace(int $fileId, string $newContent): void
    {
        $file = $this->fileRepository->findById($fileId);
        if ($file === null) {
            throw new \RuntimeException("Fichier {$fileId} introuvable.");
        }

        $encrypted = $this->encryption->encrypt($newContent, 'file_storage');
        if (file_put_contents($this->storagePath . '/' . $file->relativePath, $encrypted) === false) {
            throw new \RuntimeException('Impossible d\'écrire le fichier chiffré sur le disque.');
        }

        $this->fileRepository->updateSizeBytes($fileId, strlen($newContent));
    }

    /**
     * Removes both the encrypted file on disk and its FileRecord. Unlike
     * finance_attachments (which are only ever archived), this is a real
     * deletion — callers that need "never truly delete" semantics
     * (Modules\Finance\Service\ReceiptService) simply never call this for
     * an active attachment.
     */
    public function delete(int $fileId): void
    {
        $file = $this->fileRepository->findById($fileId);
        if ($file === null) {
            return;
        }

        $path = $this->storagePath . '/' . $file->relativePath;
        if (is_file($path)) {
            @unlink($path);
        }

        $this->fileRepository->delete($fileId);
    }
}
