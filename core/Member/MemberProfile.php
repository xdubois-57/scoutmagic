<?php

declare(strict_types=1);

namespace Core\Member;

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
