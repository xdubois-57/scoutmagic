<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\File;

/**
 * The bytes of a stored file, whichever way it was stored.
 *
 * **Two writers, two shapes, and nothing that read either.** A file
 * written by `Core\File\UploadHandler` sits on disk as itself
 * (`files.encrypted = false`); one written by
 * `Core\File\EncryptedFileStorageService` is an AES-256-GCM blob. The
 * column has always said which is which, and until now only
 * `Http\Controller\FileController::serve()` ever asked — every other
 * reader picked a storage service and assumed.
 *
 * That assumption was wrong in a way nothing could see. The finance
 * module reads an inbound-mail attachment to file it as a receipt; the
 * attachment is written by `UploadHandler`, and the read went through
 * `EncryptedFileStorageService::retrieve()`, which handed plaintext to
 * `decrypt()`. It threw, the caller's `catch` turned the throw into
 * "no bytes", and the receipt was silently never created.
 *
 * So the question « what does this file contain » gets one answer, here,
 * and callers stop choosing a decryption strategy for files they did not
 * write.
 */
class StoredFileReader
{
    public function __construct(
        private FileRepository $fileRepository,
        private EncryptedFileStorageService $encryptedStorage,
        private string $storagePath
    ) {
    }

    /**
     * Null when the file is unknown, missing from disk, or unreadable —
     * the three ways a caller cannot do anything about it anyway.
     *
     * Deliberately null rather than an exception: every caller of this is
     * a background pass over somebody else's file, and one unreadable
     * attachment must not fail the run that found it.
     */
    public function read(int $fileId): ?string
    {
        $file = $this->fileRepository->findById($fileId);
        if ($file === null) {
            return null;
        }

        if ($file->encrypted) {
            try {
                return $this->encryptedStorage->retrieve($fileId);
            } catch (\Throwable) {
                return null;
            }
        }

        $contents = @file_get_contents($this->storagePath . '/' . $file->relativePath);

        return $contents === false ? null : $contents;
    }
}
