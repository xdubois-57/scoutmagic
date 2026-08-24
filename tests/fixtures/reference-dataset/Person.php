<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

/**
 * One human across the whole dataset.
 *
 * `deskId` is the "Tiers" column and is the ONLY thing that carries identity
 * from one year to the next: MemberRepository::upsertByDeskId() keys on it,
 * and every continuity scenario in this dataset (a branch passage, a return
 * after a gap, a Pionnier becoming an animateur) is only visible because the
 * same Tiers reappears. Never renumber one.
 *
 * The identity fields are stable across years on purpose. Desk re-exports them
 * every year, and a person whose surname changed between two exports would be
 * a fourth kind of change on top of the three this dataset is built to
 * exercise — worth adding one day, deliberately and documented, not as a side
 * effect of the generator.
 */
final class Person
{
    /**
     * @param non-empty-list<PostalAddress>   $addresses
     * @param array<string, PersonYear>       $years  keyed by scout year label
     */
    public function __construct(
        public readonly string $deskId,
        public readonly string $lastName,
        public readonly string $firstName,
        public readonly string $gender,
        public readonly ?string $birthDate,
        public readonly ?string $phone,
        public readonly ?string $mobile,
        public readonly ?string $email,
        public readonly array $addresses,
        public array $years = [],
        public readonly string $handicap = 'false',
        public readonly ?string $insurance = null,
        public readonly bool $federationMailConsent = true,
        public readonly bool $unitMailConsent = true,
    ) {
    }

    public function isPresentIn(string $yearLabel): bool
    {
        return isset($this->years[$yearLabel]);
    }
}
