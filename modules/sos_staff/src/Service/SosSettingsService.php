<?php

declare(strict_types=1);

namespace Modules\SosStaff\Service;

use Core\Config\SettingService;
use Core\Import\MemberYearRepository;
use Core\Member\SectionService;
use Core\Member\UnitStaffSectionService;
use Modules\SosStaff\Repository\ExcludedSectionRepository;
use Modules\SosStaff\Repository\SosSettingsRepository;
use Modules\Trombinoscope\Repository\TrombinoscopeRepository;

/**
 * The module's "global settings" (module spec §1.4, §2.3, §2.4, §2.5):
 * excluded sections, the fallback redirect number, the transition hour,
 * and the email-notifications toggle. The scalar settings (hour, toggle)
 * go through the generic core SettingService (declared in module.json, so
 * they're also visible/editable from the generic Paramètres page) — the
 * default number doesn't fit that system (it's a live member lookup, never
 * a free-typed number), so it has its own table.
 */
class SosSettingsService
{
    private const TRANSITION_HOUR_KEY = 'transition_hour';
    private const EMAIL_NOTIFICATIONS_KEY = 'email_notifications_enabled';

    /**
     * age_branches.sort_order values for Baladins/Louveteaux/Éclaireurs/
     * Pionniers (Core\Import\AgeBranchRepository::canonicalSortOrder) — the
     * branches included in the duty-planning grid by default. Everything
     * else (Staff d'U, Route, Iama, unknown branches) is excluded by
     * default, since it doesn't carry the same "sections activity ->
     * likely SOS calls" risk profile.
     */
    private const DEFAULT_INCLUDED_BRANCH_SORT_ORDERS = [10, 20, 30, 40];

    /**
     * $trombinoscopeRepository is null when the trombinoscope module is
     * disabled — same soft, optional cross-module dependency pattern as
     * this module's calendar integration (Service\CalendarSyncService):
     * no hard schema/class dependency, wired only from the composition
     * root when the other module is actually enabled.
     */
    public function __construct(
        private ExcludedSectionRepository $excludedSectionRepository,
        private SosSettingsRepository $settingsRepository,
        private SectionService $sectionService,
        private MemberYearRepository $memberYearRepository,
        private UnitStaffSectionService $unitStaffSectionService,
        private SettingService $coreSettingService,
        private ?TrombinoscopeRepository $trombinoscopeRepository = null
    ) {
    }

    /**
     * STAFFDU is always excluded, whether or not it's stored — so there is
     * no state that could accidentally let it be un-excluded (module spec
     * §1.4).
     *
     * @return int[]
     */
    public function getExcludedSectionIds(): array
    {
        $staffduId = $this->unitStaffSectionService->ensureSection();
        $this->ensureDefaultInclusionsSeeded();
        $stored = $this->excludedSectionRepository->findAll();
        return array_values(array_unique([...$stored, $staffduId]));
    }

    /**
     * @param int[] $sectionIds
     */
    public function updateExcludedSections(array $sectionIds): void
    {
        $staffduId = $this->unitStaffSectionService->ensureSection();
        $filtered = array_values(array_filter($sectionIds, fn(int $id) => $id !== $staffduId));
        $this->excludedSectionRepository->replaceAll($filtered);
        $this->settingsRepository->markSectionDefaultsSeeded();
    }

    /**
     * Runs exactly once (module spec follow-up: "included sections"
     * picker defaults to Baladins/Louveteaux/Éclaireurs/Pionniers, other
     * branches excluded by default). Guarded by a persisted flag rather
     * than "table is empty" so that an admin explicitly re-including
     * everything (leaving the exclusion table empty on purpose) is never
     * silently overwritten on the next page load.
     */
    private function ensureDefaultInclusionsSeeded(): void
    {
        if ($this->settingsRepository->areSectionDefaultsSeeded()) {
            return;
        }

        $defaultExcluded = [];
        foreach ($this->sectionService->getAllWithBranches() as $section) {
            if (!in_array($section['branch_sort_order'], self::DEFAULT_INCLUDED_BRANCH_SORT_ORDERS, true)) {
                $defaultExcluded[] = $section['id'];
            }
        }

        if ($defaultExcluded !== []) {
            $this->excludedSectionRepository->replaceAll($defaultExcluded);
        }
        $this->settingsRepository->markSectionDefaultsSeeded();
    }

    /**
     * Staff d'U roster for the default-number dropdown (module spec §2.3)
     * — only members with a known mobile number are listed, since a
     * member with none can't usefully be picked as a redirect target.
     *
     * @return array<int, array{member_id: int, label: string, mobile: string}>
     */
    public function getStaffOptions(int $scoutYearId): array
    {
        $staffduId = $this->unitStaffSectionService->ensureSection();
        $profiles = $this->sectionService->getSectionStaff($staffduId, $scoutYearId);

        $options = [];
        foreach ($profiles as $profile) {
            if ($profile->mobile === null || $profile->mobile === '') {
                continue;
            }
            $options[] = [
                'member_id' => $profile->memberId,
                'label' => $profile->getDisplayName(),
                'mobile' => $profile->mobile,
            ];
        }
        return $options;
    }

    /**
     * The effective fallback number (module spec §2.3) — resolved live
     * against the member's *current* mobile, so a later Desk re-import is
     * picked up automatically rather than going stale. There is always an
     * effective member as long as the Staff d'U roster has at least one
     * member with a known mobile — see resolveDefaultNumberMemberId().
     */
    public function getDefaultNumber(int $scoutYearId): ?string
    {
        $memberId = $this->resolveDefaultNumberMemberId($scoutYearId);
        if ($memberId === null) {
            return null;
        }

        $memberYear = $this->memberYearRepository->findByMemberAndYear($memberId, $scoutYearId);
        if ($memberYear === null) {
            return null;
        }
        return $this->sectionService->hydrateMemberProfile($memberYear['id'])?->mobile;
    }

    /**
     * The member_id backing the default number, for pre-filling the admin
     * page's dropdown — same resolution as getDefaultNumber(), exposed
     * separately so the dropdown can show *which* member without another
     * round trip through mobile-number resolution.
     *
     * Explicit admin choice (sos_settings.default_number_member_id) wins
     * when set; otherwise auto-resolves to the Staff d'U section's
     * "responsable" (trombinoscope module, if enabled) or else the first
     * Staff d'U roster member — never persisted, so it naturally follows
     * section-leadership changes over time until an admin explicitly
     * overrides it.
     */
    public function resolveDefaultNumberMemberId(int $scoutYearId): ?int
    {
        $explicit = $this->settingsRepository->findDefaultNumberMemberId();
        if ($explicit !== null) {
            return $explicit;
        }

        $responsableId = $this->resolveSectionResponsableMemberId($scoutYearId);
        if ($responsableId !== null) {
            return $responsableId;
        }

        $staffOptions = $this->getStaffOptions($scoutYearId);
        return $staffOptions[0]['member_id'] ?? null;
    }

    private function resolveSectionResponsableMemberId(int $scoutYearId): ?int
    {
        if ($this->trombinoscopeRepository === null) {
            return null;
        }

        $staffduId = $this->unitStaffSectionService->ensureSection();
        foreach ($this->trombinoscopeRepository->getEligibleStaffForSection($staffduId, $scoutYearId) as $entry) {
            if (!$entry['is_lead']) {
                continue;
            }
            $profile = $this->sectionService->hydrateMemberProfile($entry['member_year_id']);
            if ($profile !== null && $profile->mobile !== null && $profile->mobile !== '') {
                return $profile->memberId;
            }
        }

        return null;
    }

    public function setDefaultNumberFromMember(int $memberId): void
    {
        $this->settingsRepository->saveDefaultNumberMember($memberId);
    }

    /**
     * Display name ("totem ?? prénom") for a Staff d'U member, for the
     * live-state banner (§2.2: "quelle personne — totem + nom") and the
     * planned-redirections list (§2.7). Null if the member has no row for
     * that scout year.
     */
    public function labelForMember(int $memberId, int $scoutYearId): ?string
    {
        $memberYear = $this->memberYearRepository->findByMemberAndYear($memberId, $scoutYearId);
        if ($memberYear === null) {
            return null;
        }
        return $this->sectionService->hydrateMemberProfile($memberYear['id'])?->getDisplayName();
    }

    public function getTransitionHour(): string
    {
        return (string) $this->coreSettingService->get(self::TRANSITION_HOUR_KEY, 'sos_staff', '10:00');
    }

    /**
     * @throws SosException on an invalid HH:MM format
     */
    public function setTransitionHour(string $hour): void
    {
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hour)) {
            throw new SosException('Heure invalide — format HH:MM attendu (ex : 10:00).');
        }
        $this->coreSettingService->set(self::TRANSITION_HOUR_KEY, $hour, 'sos_staff');
    }

    public function isEmailNotificationsEnabled(): bool
    {
        return $this->coreSettingService->get(self::EMAIL_NOTIFICATIONS_KEY, 'sos_staff', '1') === '1';
    }

    public function setEmailNotificationsEnabled(bool $enabled): void
    {
        $this->coreSettingService->set(self::EMAIL_NOTIFICATIONS_KEY, $enabled ? '1' : '0', 'sos_staff');
    }
}
