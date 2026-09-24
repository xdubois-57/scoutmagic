<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Covoiturage\Service;

/**
 * The events chosen for one carpool do not give the same place. A carpool
 * has one destination: the chief confirms, or the events probably belong
 * to different trips.
 */
final class LocationMismatchException extends CarpoolException
{
    /**
     * @param list<string> $locations
     */
    public function __construct(string $message, public readonly array $locations)
    {
        parent::__construct($message);
    }
}
