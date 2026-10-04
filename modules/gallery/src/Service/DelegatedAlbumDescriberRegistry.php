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
     * A label for (ownerType, ownerId) — never empty, and never an
     * identifier on its own.
     *
     * Both fallbacks read as a sentence first and carry `owner_type #id`
     * after it (issue #749): an administrator looking at this page is
     * accounting for storage, and `camp_camp #10` told them neither what
     * the album is nor what to do about it. The identifier stays, because
     * it is the only thing left to go on when the owner itself is gone —
     * but as diagnostic detail, not as the name.
     *
     * The two cases are deliberately worded differently, because the
     * answer differs: a deleted owner is an album to clean up, while a
     * module that is merely switched off will name its own albums again
     * the moment it is switched back on. Gallery cannot say WHICH module
     * that is — learning that `camp_camp` means "a stay" is exactly the
     * coupling Api\DelegatedAlbumDescriber exists to prevent — so it
     * names the situation and quotes the type verbatim.
     */
    public function describe(string $ownerType, int $ownerId): string
    {
        $technical = $ownerType . ' #' . $ownerId;

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
            return 'Propriétaire supprimé — ' . $technical;
        }

        return 'Module propriétaire indisponible — ' . $technical;
    }
}
