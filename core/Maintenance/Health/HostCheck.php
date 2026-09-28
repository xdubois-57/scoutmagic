<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Health;

/**
 * One line of Configuration › Maintenance › Santé de l'hébergement: one
 * thing the site depends on at the host.
 *
 * It always says three things (docs/chantiers/CHANTIER-maintenance.md,
 * IT-02): its state, what stops working without it, and what to ask the
 * host. « ffmpeg : absent » without the second line helps nobody, so the
 * consequence and the request are part of the value, not left to the
 * template to improvise.
 */
final class HostCheck
{
    public const STATE_OK = 'ok';
    /** Works, but less well than it should: a fallback, an untested version. */
    public const STATE_DEGRADED = 'degraded';
    /** Absent or not working: whatever depends on it does not run. */
    public const STATE_MISSING = 'missing';

    /**
     * @param string $consequence what stops working without it — shown on every line, so a green line
     *                            still says what it is there for
     * @param string $ask         what to ask the host — shown only on a line that is not ok
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $state,
        public readonly string $status,
        public readonly string $consequence,
        public readonly string $ask,
    ) {
    }

    public function isOk(): bool
    {
        return $this->state === self::STATE_OK;
    }
}
