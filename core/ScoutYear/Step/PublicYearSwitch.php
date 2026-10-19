<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ScoutYear\Step;

/**
 * « Dernière bascule : {ancienne} → {nouvelle}, le … par … » — the latest
 * `scout_year_public_activated` entry of the journal, read rather than
 * stored anywhere new. Absent when the journal holds none, which is
 * also the case once the journal retention has purged it.
 *
 * $fromLabel is null when the switch was a site's first (no public year
 * was configured) or the old year no longer exists.
 */
final class PublicYearSwitch
{
    public function __construct(
        public readonly ?string $fromLabel,
        public readonly string $toLabel,
        public readonly \DateTimeImmutable $switchedAt,
        public readonly ?string $switchedBy
    ) {
    }
}
