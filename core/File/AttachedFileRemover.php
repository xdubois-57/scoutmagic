<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\File;

/**
 * The one rule every "document attached to something" obeys: the row
 * first, the bytes second, and the bytes only when this module owns them
 * and nothing else references them.
 *
 * **Row first, bytes second.** An interruption between the two leaves a
 * stored file nothing points at — recoverable, invisible, harmless —
 * rather than a row pointing at bytes that are gone, which is a broken
 * download on a page still claiming the document is there.
 *
 * **Bytes only if owned.** An attachment that arrived in an inbound email
 * shares its `files` row with the message itself (§8.58/§8.59): the
 * message still owns it and still serves it, so deleting the bytes when a
 * module detaches its own copy would blank the message. The module says
 * whether it owns them (`ownsItsFile()` on its own document object); core
 * never guesses.
 *
 * **Bytes only if unreferenced.** The same file id can legitimately be
 * linked twice inside one module — one attachment reclassified onto two
 * entities — so the owning repository is asked before the unlink.
 *
 * Camps and Locations both reached this rule the hard way; it lives here
 * so the third module does not have to.
 */
final class AttachedFileRemover
{
    /**
     * @param string $storagePath Absolute root of the private storage
     *   tree; an empty string means "database rows only", which is what
     *   an installation without a resolved storage path gets.
     */
    public function __construct(
        private FileRepository $files,
        private string $storagePath
    ) {
    }

    /**
     * Detaches one document and, when the invariant allows it, removes
     * its bytes.
     *
     * @param int  $attachmentId The module's own row id.
     * @param int  $fileId       The `files` row it points at.
     * @param bool $ownsItsFile  What the module's document object says.
     */
    public function remove(
        AttachedFileRepository $repository,
        int $attachmentId,
        int $fileId,
        bool $ownsItsFile
    ): void {
        $repository->delete($attachmentId);

        if (!$ownsItsFile || $repository->isFileReferencedElsewhere($fileId, $attachmentId)) {
            return;
        }

        $this->deleteStoredFile($fileId);
    }

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
}
