<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\File;

/**
 * The two questions `AttachedFileRemover` asks the module that owns the
 * attachment rows.
 *
 * Nothing else: a module's document table has its own columns, its own
 * titles, its own versions and its own keywords, and none of that is any
 * of core's business. What core does own is the invariant — remove the
 * row, then the bytes, and the bytes only when this module is the one
 * that put them there and nobody else still points at them.
 */
interface AttachedFileRepository
{
    /** Removes the attachment row itself (not the `files` row). */
    public function delete(int $id): void;

    /**
     * Whether any OTHER attachment row of this same module still points
     * at the file — the same inbound attachment can legitimately be
     * linked to two entities.
     */
    public function isFileReferencedElsewhere(int $fileId, int $exceptDocumentId): bool;
}
