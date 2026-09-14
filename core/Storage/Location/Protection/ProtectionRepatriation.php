<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Protection;

use Core\Storage\Location\Backend\StorageBackendInterface;

/**
 * Bringing back, from the copy, what the source no longer has.
 *
 * **This is the repair a restore needs, and it is the reason the whole
 * mechanism is worth having.** A database restored to last month knows
 * about albums whose files were deleted since; the rows come back, the
 * files do not, and every one of those albums is holed. The copy still
 * holds them and the inventory already names them, so the repair is a
 * copy in the other direction and nothing more.
 *
 * **It asks the SOURCE what it is missing, rather than trusting the
 * marks.** An entry's `absent_from_source_since` is what the last nightly
 * pass concluded, and the moment this operation is most needed is exactly
 * the moment that conclusion is most out of date — the restore just
 * happened, and no pass has run since. So every complete entry is checked
 * against the source with `exists()`. On a bucket that is one request per
 * file, which is why this is an operation an administrator asks for and
 * never something that runs on its own.
 *
 * **A file that is back stops being marked absent**, in the same move: the
 * countdown that would have purged it from the copy is exactly what this
 * operation has just made wrong.
 */
class ProtectionRepatriation
{
    public function __construct(
        private readonly StorageInventoryStore $inventories,
        private readonly ProtectedCopier $copier
    ) {
    }

    /**
     * @param callable():bool $hasTimeLeft
     */
    public function run(
        StorageProtection $protection,
        StorageBackendInterface $source,
        StorageBackendInterface $destination,
        string $sourceLabel,
        callable $hasTimeLeft,
        ?string $fromCursor = null
    ): RepatriationResult {
        $inventory = $this->inventories->load(
            $destination,
            $protection->sourceLocationId,
            $sourceLabel,
            $protection->destinationLocationId
        );
        $fingerprint = $inventory->fingerprint();

        $keys = $inventory->completeKeys();
        sort($keys, SORT_STRING);

        $examined = 0;
        $restored = 0;
        $failures = [];
        $cursor = $fromCursor;

        foreach ($keys as $key) {
            if ($fromCursor !== null && strcmp($key, $fromCursor) <= 0) {
                continue;
            }
            if (!$hasTimeLeft()) {
                $this->inventories->save($destination, $inventory, $fingerprint);

                return new RepatriationResult(false, $cursor, $examined, $restored, $failures);
            }

            $examined++;

            if ($source->exists($key)) {
                $cursor = $key;
                continue;
            }

            $size = $destination->size($key);
            if ($size === null) {
                // The copy lost it too. Nothing to bring back, and the
                // entry is describing a file that is nowhere — which is
                // worse than no entry, because the next pass would read
                // it as « already protected ».
                $inventory->remove($key);
                $failures[$key] = 'La copie ne contient plus ce fichier.';
                $cursor = $key;
                continue;
            }

            $outcome = $this->copier->copy(
                $destination,
                $source,
                $key,
                $size,
                'application/octet-stream',
                $hasTimeLeft
            );

            if ($outcome->isPaused()) {
                // Not counted and the cursor does not advance: the next
                // run meets this key again and resumes from where the
                // partial object ends.
                $this->inventories->save($destination, $inventory, $fingerprint);

                return new RepatriationResult(false, $cursor, $examined, $restored, $failures);
            }

            if ($outcome->isCompleted()) {
                $entry = $inventory->get($key);
                if ($entry !== null) {
                    // Back at the source, so the countdown that would have
                    // purged it from the copy is exactly what this
                    // operation has just made wrong.
                    $inventory->put($key, $entry->withAbsentSince(null));
                }
                $restored++;
            } else {
                $failures[$key] = $outcome->reason ?? 'Échec sans motif.';
            }

            $cursor = $key;
        }

        $this->inventories->save($destination, $inventory, $fingerprint);

        return new RepatriationResult(true, null, $examined, $restored, $failures);
    }
}
