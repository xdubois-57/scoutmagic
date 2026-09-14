<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service;

use Core\Storage\Location\StorageLocation;

/**
 * What {@see GalleryLocationService::chooseForNewAlbums()} did, told to
 * the caller that has to journal it.
 *
 * Three facts rather than one, because the journal entry needs all three
 * and none of them survives the write: {@see $changed} cannot be
 * recomputed once the setting holds the new value, and
 * {@see $location} is what turns an identifier into the name a human
 * reads in « les nouveaux albums iront désormais sur « … » ».
 */
final class NewAlbumLocationChoice
{
    public function __construct(
        /** What the setting held before this call, 0 for « the site's default ». */
        public readonly int $previousId,
        /** What it holds now, 0 for « the site's default ». */
        public readonly int $selectedId,
        /**
         * The chosen location, or null when {@see $selectedId} is 0 — the
         * site's default, which this module deliberately does not name:
         * it is resolved at album creation, and naming it here would
         * record a decision the administrator did not make.
         */
        public readonly ?StorageLocation $location
    ) {
    }

    public function changed(): bool
    {
        return $this->previousId !== $this->selectedId;
    }

    /** The location's name, or the sentence that stands for « no choice ». */
    public function frenchName(): string
    {
        return $this->location !== null ? $this->location->label : 'l\'emplacement par défaut du site';
    }
}
