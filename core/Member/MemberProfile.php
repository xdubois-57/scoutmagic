<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

use Core\Service\TextNormalizerService;

class MemberProfile
{
    /**
     * @param MemberAddress[] $addresses
     * @param MemberFunctionInfo[] $functions
     * @param \Core\Badge\Badge[] $badges Active badges assigned to this member for this scout year (see Core\Badge)
     */
    public function __construct(
        public readonly int $memberYearId,
        public readonly int $memberId,
        public readonly string $deskId,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly ?string $totem,
        public readonly ?string $quali,
        public readonly ?string $gender,
        public readonly ?string $birthDate,
        public readonly ?string $phone,
        public readonly ?string $mobile,
        public readonly ?string $email,
        public readonly ?string $patrol,
        public readonly ?string $formationLevel,
        public readonly bool $federationMailConsent,
        public readonly bool $unitMailConsent,
        public readonly array $addresses,
        public readonly array $functions,
        public readonly string $scoutYearLabel,
        public readonly ?string $handicap = null,
        public readonly ?string $supplementaryInsurance = null,
        public readonly int $scoutYearOffset = 0,
        public readonly array $badges = []
    ) {
    }

    /**
     * Display name: totem if available, otherwise first name.
     */
    public function getDisplayName(): string
    {
        return $this->totem ?? $this->firstName;
    }

    /**
     * The same person, named so that a reader who does not know the totem
     * can still tell who it is: "Chacal (Antonin Grandjean)", or just
     * "Antonin Grandjean" when there is no totem.
     *
     * The rule already existed as the `|display_name_full` Twig filter and
     * nowhere else, which is why a page assembling its labels in PHP —
     * the re-registration form — showed the bare totem instead and a
     * parent with two children in the same section could not tell which
     * card was whose. Here so both callers say the same thing.
     */
    public function getDisplayNameFull(): string
    {
        $full = trim(
            TextNormalizerService::normalizeName($this->firstName)
            . ' ' . TextNormalizerService::normalizeName($this->lastName)
        );

        if ($this->totem === null || $this->totem === '') {
            return $full;
        }

        $totem = TextNormalizerService::normalizeTotem($this->totem);

        // A member with a totem and no civil name on file is not a reason
        // to render "Chacal ()".
        return $full !== '' ? $totem . ' (' . $full . ')' : $totem;
    }

    /**
     * Main function (the one marked is_main_function=true, or first if none marked).
     */
    public function getMainFunction(): ?MemberFunctionInfo
    {
        foreach ($this->functions as $f) {
            if ($f->isMainFunction) {
                return $f;
            }
        }
        return $this->functions[0] ?? null;
    }

    /**
     * Section name from main function, or null.
     */
    public function getMainSectionName(): ?string
    {
        return $this->getMainFunction()?->sectionName;
    }
}
