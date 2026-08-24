<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

/**
 * What is true of a person in ONE scout year: the fields Desk re-exports every
 * year, and the functions they held that year.
 *
 * Everything here can change from one year to the next — a totem earned, a
 * fee category changed, a section left — which is exactly what makes the
 * dataset worth replaying three times instead of once.
 */
final class PersonYear
{
    /**
     * @param non-empty-list<FunctionAssignment> $functions
     */
    public function __construct(
        public readonly array $functions,
        public readonly string $feeCode,
        public readonly ?string $totem = null,
        public readonly ?string $quali = null,
        public readonly ?string $patrol = null,
        public readonly string $formationLevel = 'Aucun',
    ) {
    }
}
