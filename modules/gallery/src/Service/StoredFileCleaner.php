<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service;

use Core\File\FileRepository;

/**
 * Drops a `files` row and the bytes behind it.
 *
 * Every original this module uploads through Core\File\UploadHandler (a
 * media's staging original, an external album's cached og:image) used to
 * outlive whatever referenced it: Task\ProcessPhotoHandler /
 * ProcessVideoHandler unlink the staging bytes only on a SUCCESSFUL run, and
 * neither media deletion nor album deletion ever came back for the rest —
 * so a 'failed' video left its full upload (up to
 * gallery_max_video_upload_mb) on disk forever, and every deleted external
 * album left its cached preview behind.
 *
 * Deliberately best-effort and idempotent: a missing row or missing file is
 * a no-op, never an error that would abort the deletion the caller is in the
 * middle of. Note this is narrower than the project-wide convention of
 * keeping `files` rows as an audit trail — these are derived/staging assets
 * regenerated from scratch on re-upload, not documents anyone can ask for
 * later.
 */
class StoredFileCleaner
{
    public function __construct(
        private FileRepository $fileRepository,
        private string $storagePath
    ) {
    }

    public function delete(?int $fileId): void
    {
        if ($fileId === null) {
            return;
        }

        $file = $this->fileRepository->findById($fileId);
        if ($file === null) {
            return;
        }

        if ($this->storagePath !== '') {
            $path = $this->storagePath . '/' . $file->relativePath;
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->fileRepository->delete($fileId);
    }
}
