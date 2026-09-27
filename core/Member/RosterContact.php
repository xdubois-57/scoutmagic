<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

/**
 * One member_year's identity and contact details, already decrypted
 * (issue #551).
 *
 * **It exists so that a Service never holds a purpose string.**
 * `SectionRosterService` used to take the raw row and call
 * `decrypt($row['first_name_encrypted'], 'member_years.first_name')` six
 * times over, which put six purpose strings in a Service — and a purpose
 * string is the one part of an encrypted read that must never be written
 * twice from memory: two layers spelling it differently is how a column
 * stops being readable at all. {@see SectionRosterRepository::findRosterContacts()}
 * spells them once, and hands back this.
 *
 * Only the six fields the roster actually shows. `member_years` carries
 * other encrypted columns — quali, gender, birth date — and they are
 * deliberately absent: a value object that carried everything would be a
 * second row reader, and the point is to have fewer of those, not more.
 */
final readonly class RosterContact
{
    /**
     * @param string $firstName decrypted; NOT NULL in the schema, so never null here
     * @param string $lastName decrypted; NOT NULL in the schema
     */
    public function __construct(
        public int $memberYearId,
        public string $firstName,
        public string $lastName,
        public ?string $totem,
        public ?string $email,
        public ?string $phone,
        public ?string $mobile
    ) {
    }
}
