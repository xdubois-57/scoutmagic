<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Export;

/**
 * One member_year as the export reads it, already decrypted (issue #629).
 *
 * {@see MemberExportRowBuilder} used to take the raw `member_years` and
 * `member_addresses` rows and decrypt seventeen columns itself, which put
 * seventeen purpose strings outside the layer that reads the columns they
 * name (SECURITY.md §5). {@see \Core\Member\SectionRosterRepository::findExportRecords()}
 * spells them once and hands back this.
 *
 * The address fields are those of the member_year's FIRST address (lowest
 * id), the one the export has always shown; all six are null when it has
 * none. Nothing is formatted here — phone normalisation and the e-mail
 * list stay the builder's, because they are presentation, not reading.
 */
final readonly class MemberExportRecord
{
    /**
     * @param string $firstName decrypted; NOT NULL in the schema
     * @param string $lastName decrypted; NOT NULL in the schema
     */
    public function __construct(
        public int $memberYearId,
        public int $memberId,
        public string $deskId,
        public string $firstName,
        public string $lastName,
        public ?string $totem,
        public ?string $quali,
        public ?string $gender,
        public ?string $birthDate,
        public ?string $email,
        public ?string $phone,
        public ?string $mobile,
        public ?string $street,
        public ?string $number,
        public ?string $box,
        public ?string $postalCode,
        public ?string $city,
        public ?string $country,
        public bool $isActive,
        public int $scoutYearOffset,
        public ?string $formationLevel,
        public ?string $supplementaryInsurance,
        public bool $leaving,
        public ?string $leavingComment,
        public ?string $handicap
    ) {
    }
}
