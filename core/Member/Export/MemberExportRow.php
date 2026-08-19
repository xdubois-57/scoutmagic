<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Export;

/**
 * The canonical, exhaustive "one member, one scout year" record the core
 * Excel export is built from. Any screen that wants to export members
 * builds an array of these (from whatever selection/filter is specific to
 * that screen) and hands it to MemberExportService — the column
 * definitions (MemberExportColumns) are the single place that decides what
 * gets written and in what order, so no consumer redefines columns itself.
 *
 * Deliberately member-domain only (identity, contacts, scout-life,
 * movement history) — never a module's own data (finance, registration,
 * gallery, ...): see MemberExportColumns' own docblock.
 */
final class MemberExportRow
{
    /**
     * @param string[] $emails every known email, primary (Desk) first
     * @param string[] $phones every known phone number, landline first then
     *        mobile — each entry already labeled ("Téléphone : ...",
     *        "GSM : ...") since both share a single export column, exactly
     *        like $emails share "Email(s)"
     * @param string[] $functionLabels every function this member holds this scout year, main function first
     */
    public function __construct(
        public readonly int $memberId,
        public readonly int $memberYearId,
        public readonly string $deskId,
        public readonly string $scoutYearLabel,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly ?string $totem,
        public readonly ?string $quali,
        public readonly ?string $gender,
        public readonly ?string $birthDate,
        public readonly array $emails,
        public readonly array $phones,
        public readonly ?string $street,
        public readonly ?string $number,
        public readonly ?string $box,
        public readonly ?string $postalCode,
        public readonly ?string $city,
        public readonly ?string $country,
        public readonly ?string $sectionName,
        public readonly ?string $sectionCode,
        public readonly ?string $branchName,
        public readonly string $roleBucketLabel,
        public readonly array $functionLabels,
        public readonly bool $isActive,
        public readonly int $scoutYearOffset,
        public readonly ?string $formationLevel,
        public readonly ?string $supplementaryInsurance,
        public readonly bool $leaving,
        public readonly ?string $leavingComment,
        public readonly ?string $handicap,
        public readonly string $movementStatusLabel,
        public readonly ?string $previousSectionName,
        public readonly ?string $previousBranchName
    ) {
    }
}
