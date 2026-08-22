<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service;

use Modules\Gallery\Api\DelegatedAlbumDescriber;

/**
 * Resolves a delegated album's owner into a label an administrator can read
 * — same shape as Service\DelegatedAlbumAccessRegistry, and built the same
 * way in public/index.php.
 *
 * The one deliberate difference is the fallback. The access registry is
 * **fail-closed**: an owner_type nothing claims is denied, because guessing
 * would grant access nobody confirmed. This one is fail-OPEN, because the
 * question it answers is only "what do I call this row": an album whose
 * owning module is disabled still exists, still occupies a storage
 * location, and still has to appear on the page where an administrator
 * moves or accounts for it. Hiding it would be the harmful answer here.
 */
class DelegatedAlbumDescriberRegistry
{
    /**
     * @param DelegatedAlbumDescriber[] $describers
     */
    public function __construct(private array $describers = [])
    {
    }

    /**
     * A label for (ownerType, ownerId) — never empty.
     */
    public function describe(string $ownerType, int $ownerId): string
    {
        foreach ($this->describers as $describer) {
            if (!$describer->supports($ownerType)) {
                continue;
            }

            $label = $describer->describe($ownerId);
            if ($label !== null && trim($label) !== '') {
                return trim($label);
            }

            // The describer owns this type and says the owner is gone. Say
            // so rather than falling through to a label implying otherwise.
            return $ownerType . ' #' . $ownerId . ' (supprimé)';
        }

        return $ownerType . ' #' . $ownerId;
    }
}
