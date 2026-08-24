<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Service;

use Core\Audit\AuditService;
use Core\Audit\AuditSource;
use Core\File\FileRepository;
use Core\File\UploadException;
use Core\File\UploadHandler;
use Modules\Camps\Repository\Document;
use Modules\Camps\Repository\DocumentRepository;

/**
 * The files attached to a stay: a contract, a quote, a map of the field.
 *
 * Everything goes through Core\File\UploadHandler and is served by
 * /files/{id} behind Core\File\FileAccessGuard — nothing this module
 * stores ever lands under public/.
 */
class DocumentService
{
    public const ALLOWED_MIMES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/heic',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public const MAX_BYTES = 25 * 1024 * 1024;

    public function __construct(
        private DocumentRepository $documents,
        private FileRepository $files,
        private UploadHandler $uploadHandler,
        private AuditService $audit,
        private string $storagePath
    ) {
    }

    /**
     * @param array<string, mixed> $uploadedFile a $_FILES entry
     */
    public function upload(int $campId, array $uploadedFile, ?string $title, ?int $actorUserAccountId): int
    {
        try {
            $fileId = $this->uploadHandler->handle(
                $uploadedFile,
                "camps/{$campId}/documents",
                self::ALLOWED_MIMES,
                self::MAX_BYTES,
                'chief',
                'camps',
                $actorUserAccountId,
                CampFileOwnershipChecker::OWNER_TYPE,
                $campId
            );
        } catch (UploadException $e) {
            // UploadException's messages are already written for the
            // person who chose the file ("Le fichier dépasse la taille
            // maximale autorisée (25 Mo).") — rewrapping them would only
            // make them vaguer.
            throw new CampsException($e->getMessage(), 0, $e);
        }

        $title = $this->cleanTitle($title, $uploadedFile);
        $id = $this->documents->create($campId, $title, $fileId);

        $this->audit->record(
            CampService::ENTITY_TYPE, $campId, 'document', null, $title,
            AuditSource::Human, 'Document ajouté', null, $actorUserAccountId
        );

        return $id;
    }

    /**
     * Attaches a file that already exists — an inbound message's own
     * attachment. The bytes are NOT copied: the row points at the same
     * `files` id the message uses.
     */
    public function attachExistingFile(
        int $campId,
        int $fileId,
        string $title,
        string $sourceReference,
        ?int $actorUserAccountId
    ): int {
        $id = $this->documents->create($campId, $title, $fileId, Document::SOURCE_EMAIL, $sourceReference);

        $this->audit->record(
            CampService::ENTITY_TYPE, $campId, 'document', null, $title,
            AuditSource::Email, 'Pièce jointe rattachée depuis un message', $sourceReference, $actorUserAccountId
        );

        return $id;
    }

    /**
     * Removes a document, and its file ONLY when this module owns the
     * bytes.
     *
     * A document whose source is 'email' points at an inbound message's
     * own attachment: the message still owns it and still serves it, so
     * deleting the bytes here would blank the message too. Only the link
     * between the stay and the file goes.
     */
    public function delete(Document $document, ?int $actorUserAccountId): void
    {
        $this->documents->delete($document->id);

        if ($document->ownsItsFile()
            && !$this->documents->isFileReferencedElsewhere($document->fileId, $document->id)
        ) {
            $this->deleteStoredFile($document->fileId);
        }

        $this->audit->record(
            CampService::ENTITY_TYPE, $document->campId, 'document', $document->title, null,
            AuditSource::Human, 'Document supprimé', null, $actorUserAccountId
        );
    }

    public function rename(Document $document, string $title, ?int $actorUserAccountId): void
    {
        $title = trim($title);
        if ($title === '') {
            throw new CampsException('Un document a besoin d\'un nom.');
        }
        if ($title === $document->title) {
            return;
        }

        $this->documents->rename($document->id, $title);
        $this->audit->record(
            CampService::ENTITY_TYPE, $document->campId, 'document', $document->title, $title,
            AuditSource::Human, null, null, $actorUserAccountId
        );
    }

    /**
     * The row goes first, the bytes second: an interruption between the
     * two leaves a stored file nothing points at (recoverable, invisible)
     * rather than a row pointing at bytes that are gone (a broken
     * download on a page that says the document is there).
     */
    private function deleteStoredFile(int $fileId): void
    {
        $file = $this->files->findById($fileId);
        if ($file === null) {
            return;
        }

        if ($this->storagePath !== '') {
            @unlink($this->storagePath . '/' . $file->relativePath);
        }
        $this->files->delete($fileId);
    }

    /**
     * @param array<string, mixed> $uploadedFile
     */
    private function cleanTitle(?string $title, array $uploadedFile): string
    {
        $title = $title !== null ? trim($title) : '';
        if ($title !== '') {
            return mb_substr($title, 0, 255);
        }

        // Falling back to the original filename beats "Document 4": a
        // chief who uploaded "contrat-mozet-2028.pdf" already named it.
        $name = is_string($uploadedFile['name'] ?? null) ? trim($uploadedFile['name']) : '';

        return $name !== '' ? mb_substr($name, 0, 255) : 'Document';
    }
}
