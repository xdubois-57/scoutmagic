<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Volume;

use Core\Storage\ByteFormatter;

/**
 * One directory the site declared, and what was learned about it while
 * grouping it onto a volume.
 *
 * It carries its own measured size rather than only the volume's, because
 * the screen answers two different questions from one reading: « how full
 * is this disk » is about the volume, « what does the gallery occupy » is
 * about this directory.
 */
final class VolumeDirectory
{
    /**
     * @param string $path the resolved absolute directory
     * @param string $label what to call it in French on a screen
     * @param int|null $sizeBytes what walking it measured, or null when it
     *        could not be walked — which is not zero. A directory the web
     *        user may not enter has an unknown size, and reporting it as
     *        empty would understate the volume's occupation by exactly the
     *        amount nobody could see.
     * @param bool $isUnderStoragePath whether it lives under `storage/`,
     *        and therefore whether a full reset erases it
     * @param bool $exists whether it was there at all when this reading was
     *        taken — a declared location whose mount is gone is a fact the
     *        screen states rather than an occupation of zero
     */
    public function __construct(
        public readonly string $path,
        public readonly string $label,
        public readonly ?int $sizeBytes,
        public readonly bool $isUnderStoragePath,
        public readonly bool $exists
    ) {
    }

    /**
     * What this directory occupies, as a person reads it — or an empty
     * string when nobody could measure it, which is not « 0 o ».
     *
     * Formatted here rather than by a Twig filter, and that is the same
     * decision {@see \Core\Storage\StorageUsage} records for itself: the
     * site already has a `filesize` filter with its own rounding, and two
     * spellings of « 1,5 Go » on two screens describing one disk is how a
     * reader stops trusting either. {@see \Core\Storage\ByteFormatter} is
     * the one spelling, and every storage screen goes through it.
     */
    public function sizeLabel(): string
    {
        return $this->sizeBytes !== null ? ByteFormatter::format($this->sizeBytes) : '';
    }
}
