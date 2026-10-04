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
        public readonly array $badges = [],
        /**
         * Section id => the totem this member carries in that section this
         * year (« Akela », issue #722). Separate from the Desk totem, which
         * stays `$totem`.
         *
         * @var array<int, string>
         */
        public readonly array $sectionTotems = []
    ) {
    }

    /**
     * The section totem to show, by one rule for every caller (issue #722).
     *
     * A page that knows which section it is about asks for that section's
     * totem. A page that does not shows one only when there is exactly one
     * to show: of two (« Akela » in one section, « Baloo » in another),
     * picking either would name the person wrongly for half the unit.
     */
    public function sectionTotemFor(?int $sectionId = null): ?string
    {
        if ($sectionId !== null) {
            $totem = $this->sectionTotems[$sectionId] ?? null;
        } else {
            $totem = count($this->sectionTotems) === 1 ? array_values($this->sectionTotems)[0] : null;
        }

        return is_string($totem) && trim($totem) !== '' ? TextNormalizerService::normalizeTotem($totem) : null;
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
     *
     * A section totem joins the totem when there is one to show
     * (`$sectionId` the section the page is about, null when it does not
     * know): « Guépard – Akela (Élie Wathelet) ».
     */
    public function getDisplayNameFull(?int $sectionId = null): string
    {
        $full = trim(
            TextNormalizerService::normalizeName($this->firstName)
            . ' ' . TextNormalizerService::normalizeName($this->lastName)
        );

        $totem = $this->totem !== null && $this->totem !== ''
            ? TextNormalizerService::normalizeTotem($this->totem)
            : null;

        // « Guépard – Akela », or « Akela » alone (issue #722) — see
        // sectionTotemFor() for which section totem, if any.
        $sectionTotem = $this->sectionTotemFor($sectionId);
        if ($sectionTotem !== null) {
            $totem = $totem !== null ? $totem . ' – ' . $sectionTotem : $sectionTotem;
        }

        if ($totem === null) {
            return $full;
        }

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
