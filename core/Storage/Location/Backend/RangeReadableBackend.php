<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Backend;

/**
 * A backend that can read an arbitrary slice of an object — declared as
 * {@see \Core\Storage\Location\StorageCapability::RangeRead}.
 *
 * This is what makes a video playable. A browser asking for a film sends
 * `Range:` requests and expects to be able to jump into the middle of one;
 * a storage that only ever hands back whole objects can start a playback
 * and never seek in it, and materialising a 1080p film in memory to answer
 * one range is not an alternative, it is a fatal on the first large file.
 *
 * A consumer checks the capability and then narrows to this interface;
 * `instanceof` and the declared capability are two spellings of the same
 * fact, and {@see \Core\Storage\Location\StorageCapabilities::require()}
 * is what turns the negative answer into a sentence.
 */
interface RangeReadableBackend extends StorageBackendInterface
{
    /**
     * Reads exactly $length bytes of $key from $offset. May return fewer
     * when the range runs past the end of the object.
     *
     * @throws \RuntimeException when $key cannot be read at all
     */
    public function getRange(string $key, int $offset, int $length): string;
}
