<?php

declare(strict_types=1);

namespace Core\ScoutYear;

use Core\Config\SettingService;
use Modules\Registration\Api\ScoutYearTransitionVetoProvider;

/**
 * Write operations for the scout-year state (public current year and staff year).
 *
 * Values are stored as core settings and written via SettingService::setInternal
 * (they are not hand-editable through the settings UI).
 *
 * $transitionVeto is a nullable dependency on the registration module's own
 * public API (ARCHITECTURE.md §7.5/§8.38) — wired by the composition root
 * only when that module is enabled. Core never references any of the
 * module's concrete classes, only this interface, and degrades to "no
 * veto, transition always allowed" when it is null (module absent or
 * disabled) — the exact same shape as Core\Member\FeeEstimationService's
 * own nullable Api\HouseholdRegistrationCountProvider dependency.
 */
class ScoutYearAdminService
{
    public function __construct(
        private SettingService $settingService,
        private ?ScoutYearTransitionVetoProvider $transitionVeto = null
    ) {
    }

    /**
     * Activate a staff year: chiefs/intendants see this year, the public keeps
     * the current public year.
     */
    public function activateStaffYear(int $yearId): void
    {
        $this->settingService->setInternal(ScoutYearResolver::SETTING_STAFF_YEAR, (string) $yearId);
    }

    /**
     * Clear the staff year.
     */
    public function deactivateStaffYear(): void
    {
        $this->settingService->setInternal(ScoutYearResolver::SETTING_STAFF_YEAR, '0');
    }

    /**
     * Activate a year for everyone. This transitions the whole site: it sets the
     * public current year and clears any staff year (the staff and public are now
     * aligned on the same year).
     *
     * Refused (ScoutYearTransitionVetoedException) while the registration
     * module reports open requests — checked here, in the service that
     * actually executes the transition, so the refusal holds regardless of
     * which controller or code path calls this method (module spec: "vérifié
     * côté serveur ... pas seulement à l'affichage", including a direct
     * replay of the request).
     */
    public function activatePublicYear(int $yearId): void
    {
        $blocking = $this->countBlockingRegistrationRequests();
        if ($blocking > 0) {
            throw new ScoutYearTransitionVetoedException($blocking);
        }

        $this->settingService->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $yearId);
        $this->settingService->setInternal(ScoutYearResolver::SETTING_STAFF_YEAR, '0');
    }

    /**
     * 0 when the registration module is disabled/absent (no veto provider
     * wired) or reports nothing open. Used both for step 4's hard block
     * (above) and step 3's non-blocking warning (ScoutYearController).
     */
    public function countBlockingRegistrationRequests(): int
    {
        return $this->transitionVeto?->countBlockingRequests() ?? 0;
    }
}
