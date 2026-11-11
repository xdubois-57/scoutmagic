<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Api;

/**
 * A place already on this site that an imported one looks like.
 *
 * `$isCertain` is the duplicate detector's own verdict: the same name in
 * the same locality, or the same address. Anything less (a typo away, one
 * name inside the other in another town) is a resemblance a person has to
 * settle. `$name` and `$locality` are what the import's report shows — a
 * plot of land, never a person (D8 of the data migration chantier).
 */
final class ExistingPlace
{
    public function __construct(
        public readonly int $id,
        public readonly bool $isCertain,
        public readonly string $name,
        public readonly ?string $locality
    ) {
    }
}
