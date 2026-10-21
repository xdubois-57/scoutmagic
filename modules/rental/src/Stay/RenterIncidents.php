<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Stay;

/**
 * « Incidents et dégâts » on the renter's tracking page (#980, §22.5): the
 * decided incidents, and whether one is still being assessed.
 *
 * An assessment in progress is a flag and nothing else. Its description
 * and its amount are never decrypted for this page — the renter would read
 * a proposed figure as a demand — and the page says only that a damage is
 * being assessed and that the security deposit waits for it.
 */
final class RenterIncidents
{
    /**
     * @param RenterIncident[] $decided in the order they were reported
     */
    public function __construct(
        public readonly array $decided,
        public readonly bool $assessmentInProgress
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->decided === [] && !$this->assessmentInProgress;
    }
}
