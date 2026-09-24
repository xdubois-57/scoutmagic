<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Documents\Service;

use Core\Config\AppClock;
use Core\File\AttachedFileRemover;
use Core\File\FileRepository;
use Core\File\UploadException;
use Core\File\UploadHandler;
use Core\Journal\JournalService;
use Core\Page\TextPageService;
use Core\Pdf\PdfCompressor;
use Core\Security\Role;
use Modules\Documents\File\DocumentFileOwnershipChecker;
use Modules\Documents\Repository\Document;
use Modules\Documents\Repository\DocumentRepository;

/**
 * The unit's shared documents: what a reader may see, and what the chef
 * d'unité may change.
 *
 * **The module never serves bytes.** A document is a title, a visibility
 * and a pointer to a `files` row; the file itself is downloaded through
 * /files/{id}, where FileAccessGuard enforces the role_min this class
 * keeps in step with the visibility (SECURITY.md §6). Every write that
 * changes the visibility or the file therefore re-derives that role_min
 * here — a document whose list entry and file disagreed would be exactly
 * the silent hole §6 exists to prevent.
 */
class DocumentService
{
    /**
     * Every document type the unit is likely to share: office files,
     * OpenDocument, plain text, and images. Checked on the REAL MIME type
     * by UploadHandler, never on the extension. No archives and no
     * executables: nothing a parent should be asked to open blind.
     */
    public const ALLOWED_MIMES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
        'application/vnd.oasis.opendocument.presentation',
        'text/plain',
        'text/csv',
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    public const MAX_BYTES = 25 * 1024 * 1024;

    public const TITLE_MAX_LENGTH = 200;
    public const DESCRIPTION_MAX_LENGTH = 1000;

    /** Where UploadHandler writes, under storage/. */
    private const STORAGE_SUBDIRECTORY = 'documents';

    /**
     * Bytes of randomness in an unlisted document's address: twelve hex
     * characters, 48 bits — enough that the address cannot be found by
     * trying, short enough to survive being read out over the phone.
     */
    private const UNLISTED_SLUG_RANDOM_BYTES = 6;

    /** `documents.slug` is VARCHAR(190); the suffixes need room under it. */
    private const SLUG_MAX_LENGTH = 150;

    public function __construct(
        private DocumentRepository $repository,
        private UploadHandler $uploadHandler,
        private FileRepository $fileRepository,
        private AttachedFileRemover $fileRemover,
        private JournalService $journalService,
        private string $storagePath,
        private ?PdfCompressor $pdfCompressor = null
    ) {
    }

    /**
     * Every document, for the management screen.
     *
     * @return list<Document>
     */
    public function all(): array
    {
        return $this->repository->findAll();
    }

    public function findById(int $id): ?Document
    {
        return $this->repository->findById($id);
    }

    public function findBySlug(string $slug): ?Document
    {
        return $this->repository->findBySlug($slug);
    }

    /**
     * What the public page shows to a reader holding $role, and how many
     * listed documents it keeps from them.
     *
     * An unlisted document is neither shown nor counted: counting it
     * would tell every member that something unlisted exists.
     *
     * @return array{documents: list<Document>, hidden: int}
     */
    public function listFor(Role $role): array
    {
        $visible = [];
        $hidden = 0;
        foreach ($this->repository->findAll() as $document) {
            if (!$document->visibility->isListed()) {
                continue;
            }
            if ($document->visibility->isListedFor($role)) {
                $visible[] = $document;
            } else {
                $hidden++;
            }
        }

        return ['documents' => $visible, 'hidden' => $hidden];
    }

    /**
     * @param array<string, mixed> $uploadedFile a $_FILES entry
     * @throws DocumentException
     * @throws UploadException
     */
    public function create(
        string $title,
        ?string $description,
        mixed $visibilityInput,
        array $uploadedFile,
        ?int $actorId
    ): Document {
        $title = $this->cleanTitle($title);
        $description = $this->cleanDescription($description);
        $visibility = $this->cleanVisibility($visibilityInput);

        if (!$this->hasUpload($uploadedFile)) {
            throw new DocumentException('Choisissez le fichier à partager.');
        }

        $fileId = $this->storeUpload($uploadedFile, $visibility, $actorId);

        try {
            $id = $this->repository->create(
                $this->uniqueSlug($title, $visibility),
                $title,
                $description,
                $visibility,
                $fileId,
                $actorId,
                $this->now()
            );
        } catch (\Throwable $e) {
            // The file is stored and nothing points at it yet.
            $this->fileRemover->removeOrphan($fileId);
            throw $e;
        }
        // Only now does the document have the id its file is owned by.
        $this->fileRepository->updateOwner($fileId, DocumentFileOwnershipChecker::OWNER_TYPE, $id);

        $this->journalService->log(
            'documents',
            'document_created',
            'info',
            'Document partagé ajouté',
            ['document_id' => $id, 'visibility' => $visibility->value],
            $actorId
        );

        $document = $this->repository->findById($id);
        \assert($document !== null);
        return $document;
    }

    /**
     * The one editing gesture: title, description, visibility, and — only
     * when one was chosen — a new file. The address never changes.
     *
     * @param array<string, mixed>|null $uploadedFile a $_FILES entry, or null
     * @throws DocumentException
     * @throws UploadException
     */
    public function update(
        int $id,
        string $title,
        ?string $description,
        mixed $visibilityInput,
        ?array $uploadedFile,
        ?int $actorId
    ): Document {
        $document = $this->requireDocument($id);
        $title = $this->cleanTitle($title);
        $description = $this->cleanDescription($description);
        $visibility = $this->cleanVisibility($visibilityInput);
        $now = $this->now();

        $newFileId = null;
        if ($uploadedFile !== null && $this->hasUpload($uploadedFile)) {
            $newFileId = $this->storeUpload($uploadedFile, $visibility, $actorId, $id);
        }

        try {
            $this->repository->updateDetails($id, $title, $description, $visibility, $actorId, $now);
            if ($newFileId !== null) {
                $this->repository->replaceFile($id, $newFileId, $actorId, $now);
            }
        } catch (\Throwable $e) {
            // The new file is stored and the document does not point at it.
            if ($newFileId !== null) {
                $this->fileRemover->removeOrphan($newFileId);
            }
            throw $e;
        }

        if ($newFileId !== null) {
            $this->fileRemover->removeOrphan($document->fileId);
            $this->journalService->log(
                'documents',
                'document_file_replaced',
                'info',
                'Fichier d\'un document partagé remplacé',
                ['document_id' => $id],
                $actorId
            );
        } elseif ($visibility !== $document->visibility) {
            $this->fileRepository->updateRoleMin($document->fileId, $visibility->fileRoleMin());
        }

        $this->journalService->log(
            'documents',
            'document_updated',
            'info',
            'Document partagé modifié',
            ['document_id' => $id, 'visibility' => $visibility->value],
            $actorId
        );

        $updated = $this->repository->findById($id);
        \assert($updated !== null);
        return $updated;
    }

    /**
     * @throws DocumentException
     */
    public function delete(int $id, ?int $actorId): void
    {
        $document = $this->requireDocument($id);
        $this->fileRemover->remove($this->repository, $id, $document->fileId, true);

        $this->journalService->log(
            'documents',
            'document_deleted',
            'info',
            'Document partagé supprimé',
            ['document_id' => $id],
            $actorId
        );
    }

    /**
     * @param list<int> $ids
     */
    public function reorder(array $ids): void
    {
        $this->repository->reorder($ids);
    }

    /**
     * The frozen address for a new document.
     *
     * Derived from the title like a text page's (TextPageService::slugify,
     * the same accent folding on every host). An unlisted document gets a
     * random segment on top: /documents/pv-ag-2026 can be guessed,
     * /documents/pv-ag-2026-a7f3c90b41e2 cannot.
     */
    public function uniqueSlug(string $title, DocumentVisibility $visibility): string
    {
        $base = TextPageService::slugify($title);
        if (strlen($base) > self::SLUG_MAX_LENGTH) {
            $base = rtrim(substr($base, 0, self::SLUG_MAX_LENGTH), '-');
        }
        if ($base === '') {
            $base = 'document';
        }

        if ($visibility === DocumentVisibility::DIRECT_LINK) {
            do {
                $candidate = $base . '-' . bin2hex(random_bytes(self::UNLISTED_SLUG_RANDOM_BYTES));
            } while ($this->repository->slugExists($candidate));
            return $candidate;
        }

        if (!$this->repository->slugExists($base)) {
            return $base;
        }
        for ($suffix = 2; $suffix < 1000; $suffix++) {
            $candidate = $base . '-' . $suffix;
            if (!$this->repository->slugExists($candidate)) {
                return $candidate;
            }
        }

        throw new DocumentException('Trop de documents portent déjà ce titre.');
    }

    /**
     * @param array<string, mixed> $uploadedFile
     */
    private function hasUpload(array $uploadedFile): bool
    {
        $error = (int) ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE);
        return $error !== UPLOAD_ERR_NO_FILE;
    }

    /**
     * UploadHandler's refusals are UploadException, a user-facing class
     * whose messages are already written for the person who chose the
     * file: they go up as they are, never re-wrapped (AGENTS.md
     * § Exception messages that reach a visitor).
     *
     * @param array<string, mixed> $uploadedFile
     * @throws UploadException
     */
    private function storeUpload(
        array $uploadedFile,
        DocumentVisibility $visibility,
        ?int $actorId,
        ?int $documentId = null
    ): int {
        $fileId = $this->uploadHandler->handle(
            $uploadedFile,
            self::STORAGE_SUBDIRECTORY,
            self::ALLOWED_MIMES,
            self::MAX_BYTES,
            $visibility->fileRoleMin(),
            'documents',
            $actorId,
            $documentId === null ? null : DocumentFileOwnershipChecker::OWNER_TYPE,
            $documentId
        );

        $this->compressIfPdf($fileId);

        return $fileId;
    }

    /**
     * Shrinks a PDF in place when a compression backend is installed, and
     * does nothing otherwise — never a reason to refuse the upload.
     *
     * Synchronous, unlike section documents' background task: one file,
     * uploaded by the one person waiting for the page, and the result is
     * the file the address serves from the first second.
     */
    private function compressIfPdf(int $fileId): void
    {
        if ($this->pdfCompressor === null || $this->storagePath === '') {
            return;
        }
        $file = $this->fileRepository->findById($fileId);
        if ($file === null || $file->mimeType !== 'application/pdf') {
            return;
        }

        try {
            $backend = $this->pdfCompressor->detectBackend();
            if ($backend === PdfCompressor::BACKEND_NONE) {
                return;
            }
            $path = $this->storagePath . '/' . $file->relativePath;
            $content = @file_get_contents($path);
            if ($content === false) {
                return;
            }
            $compressed = $this->pdfCompressor->compress($content, $backend, PdfCompressor::QUALITY_BALANCED);
            if ($compressed === null || strlen($compressed) >= strlen($content)) {
                return;
            }
            if (@file_put_contents($path, $compressed) === false) {
                return;
            }
            $this->fileRepository->updateSizeBytes($fileId, strlen($compressed));
        } catch (\Throwable) {
            // The uncompressed file is a perfectly good file.
        }
    }

    /**
     * @throws DocumentException
     */
    private function requireDocument(int $id): Document
    {
        $document = $this->repository->findById($id);
        if ($document === null) {
            throw new DocumentException('Ce document n\'existe plus.');
        }
        return $document;
    }

    /**
     * @throws DocumentException
     */
    private function cleanTitle(string $title): string
    {
        $title = trim($title);
        if ($title === '') {
            throw new DocumentException('Donnez un titre au document.');
        }
        if (mb_strlen($title) > self::TITLE_MAX_LENGTH) {
            throw new DocumentException('Le titre est trop long (' . self::TITLE_MAX_LENGTH . ' caractères au plus).');
        }
        return $title;
    }

    /**
     * @throws DocumentException
     */
    private function cleanDescription(?string $description): ?string
    {
        $description = trim((string) $description);
        if ($description === '') {
            return null;
        }
        if (mb_strlen($description) > self::DESCRIPTION_MAX_LENGTH) {
            throw new DocumentException(
                'La description est trop longue (' . self::DESCRIPTION_MAX_LENGTH . ' caractères au plus).'
            );
        }
        return $description;
    }

    /**
     * @throws DocumentException
     */
    private function cleanVisibility(mixed $input): DocumentVisibility
    {
        $visibility = DocumentVisibility::fromInput($input);
        if ($visibility === null) {
            throw new DocumentException('Choisissez à qui ce document est destiné.');
        }
        return $visibility;
    }

    private function now(): string
    {
        return AppClock::now()->format('Y-m-d H:i:s');
    }
}
