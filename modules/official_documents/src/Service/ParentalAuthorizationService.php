<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\OfficialDocuments\Service;

use Core\Config\SettingService;
use Core\Member\MemberProfile;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Module\HookRegistry;
use Core\Module\SectionResponsableProvider;

/**
 * Everything the parental authorization needs that the parent does not type.
 *
 * Kept apart from `ParentalAuthorizationPdfService`, which knows how to draw
 * on the form and nothing about where a responsable or a unit name comes
 * from — so the screen and the document ask the same question of the same
 * service, and cannot answer it differently.
 *
 * Not `final`, for the ordinary reason most services here are not: the
 * controller test doubles it to exercise the access boundary without
 * standing up a section table and a settings table behind it.
 */
class ParentalAuthorizationService
{
    /**
     * The federation's own code for this unit, as it prints on the form
     * beside the unit's name: « LgVI/25 ». A module setting rather than
     * something derived, because nothing this site imports carries it —
     * `member_years.unit_code` is the Desk column « Fonction au sein de
     * l'unité », which is a function and not a unit.
     */
    public const UNIT_CODE_SETTING = 'official_documents_unit_code';

    public function __construct(
        private readonly MemberService $memberService,
        private readonly SectionService $sectionService,
        private readonly SettingService $settings,
        private readonly ?HookRegistry $hooks = null
    ) {
    }

    /**
     * The section's responsable, with their postal address loaded.
     *
     * Two calls rather than one, and that is the point: the hook answers
     * from `SectionService::hydrateMemberProfile()`, which never loads
     * addresses (ARCHITECTURE.md §8.22), while the form asks for the
     * responsable's « Adresse complète ». So the hook says WHO and
     * `findProfileByMemberAndYear()` says everything about them.
     *
     * Null when the trombinoscope module is absent, when the section has
     * designated nobody, or when the member has no section at all — the
     * form then keeps its own blank lines, which a pen can fill.
     */
    public function responsableFor(MemberProfile $member, int $scoutYearId): ?MemberProfile
    {
        $sectionCode = $member->getMainFunction()?->sectionCode;
        if ($sectionCode === null) {
            return null;
        }

        $section = $this->sectionService->findByDeskCode($sectionCode);
        if ($section === null) {
            return null;
        }

        $lead = $this->hooks?->getOptional(SectionResponsableProvider::class)?->getResponsable(
            (int) $section['id'],
            $scoutYearId
        );
        if ($lead === null) {
            return null;
        }

        return $this->memberService->findProfileByMemberAndYear($lead->memberId, $scoutYearId) ?? $lead;
    }

    /**
     * The numeric id of a section from the Desk code a member's function
     * carries — which is all `MemberFunctionInfo` holds.
     *
     * Null when the code matches no section this installation knows, which
     * the event picker reads as « no section to narrow to » rather than as
     * an error.
     */
    public function sectionIdFor(string $deskCode): ?int
    {
        $section = $this->sectionService->findByDeskCode($deskCode);

        return $section === null ? null : (int) $section['id'];
    }

    /**
     * What goes on the « de l'unité … » line: the federation's code and the
     * unit's name, or just the name while nobody has filled the code in.
     *
     * The separator is an em dash rather than a slash or a comma because the
     * two are a code and a name, not a path.
     */
    public function unitLabel(): string
    {
        $name = trim((string) ($this->settings->get('site_name') ?? ''));
        $code = trim((string) ($this->settings->get(self::UNIT_CODE_SETTING) ?? ''));

        if ($code === '') {
            return $name;
        }

        return $name === '' ? $code : $code . ' — ' . $name;
    }

    /**
     * What the « Fait à » field starts on: the member's own city.
     *
     * A default rather than a decision — the field is free text, because the
     * person signing may well be somewhere else that day.
     */
    public function defaultPlaceFor(MemberProfile $member): string
    {
        foreach ($member->addresses as $address) {
            $city = trim((string) ($address->city ?? ''));
            if ($city !== '') {
                return $city;
            }
        }

        return '';
    }
}
