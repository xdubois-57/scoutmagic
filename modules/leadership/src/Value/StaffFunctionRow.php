<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Leadership\Value;

/**
 * One `member_functions` row of a staff member, with the identity fields
 * the pages display already decrypted.
 *
 * One row per FUNCTION, not per person: somebody who is both an animateur
 * and an intendant appears twice, which is what the Stewards page needs
 * (it counts a registration per intendance function) and what the
 * Obligations page needs (a "candidat" prefix sits on one function, not on
 * a person). The services deduplicate by memberId wherever the question is
 * about the person instead.
 *
 * Every field here comes out of Repository\LeadershipRepository, the only
 * place in this module that touches PDO or EncryptionService.
 */
final class StaffFunctionRow
{
    public function __construct(
        public readonly int $memberId,
        public readonly int $memberYearId,
        public readonly int $memberFunctionId,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly ?string $totem,
        /** Decrypted birth date as Desk stored it, or null when not encoded. */
        public readonly ?string $birthDate,
        /**
         * Decrypted personal e-mail as Desk holds it, or null when the
         * member has none there.
         *
         * Carried so the lists this module draws can be acted on: a page
         * that names sixteen people to talk to and gives no way to reach
         * any of them sends a chef d'unité back to Desk sixteen times.
         */
        public readonly ?string $email,
        public readonly int $scoutYearOffset,
        /** Raw `member_years.formation_level`, verbatim. */
        public readonly ?string $formationLevel,
        public readonly string $functionLabel,
        /** Site role of the function: intendant, chief or admin. */
        public readonly string $functionRole,
        public readonly bool $isMainFunction,
        public readonly ?int $sectionId,
        public readonly ?string $sectionName,
        /** `member_functions.start_date` — of THIS function, never of the person's engagement. */
        public readonly ?string $functionStartDate,
    ) {
    }

    /** Site-wide convention: totem when there is one, otherwise first name. */
    public function displayName(): string
    {
        return ($this->totem !== null && $this->totem !== '') ? $this->totem : $this->firstName;
    }

    /** Full legal name, for the staff-facing lists where a totem is not enough to act on. */
    public function fullName(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }

    /** Chief or chef d'unité — the roles this module treats as "animation". */
    public function isAnimation(): bool
    {
        return in_array($this->functionRole, ['chief', 'admin'], true);
    }

    public function isSteward(): bool
    {
        return $this->functionRole === 'intendant';
    }
}
