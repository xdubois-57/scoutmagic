<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Covoiturage\Service;

/**
 * An event chosen for a new carpool already has one. The page offers to
 * join it — two chiefs of two sections creating one each for the same
 * unit party is how the families end up split over two lists.
 */
final class DuplicateCarpoolException extends CarpoolException
{
    public function __construct(string $message, public readonly int $existingCarpoolId)
    {
        parent::__construct($message);
    }
}
