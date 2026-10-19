<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ScoutYear;

use Core\Config\SettingService;
use Core\Import\ImportJournalRepository;
use Modules\Registration\Api\ScoutYearTransitionVetoProvider;

/**
 * Write operations for the scout-year state (public current year and staff year).
 *
 * Values are stored as core settings and written via SettingService::setInternal
 * (they are not hand-editable through the settings UI).
 *
 * **This is the only write path to both settings, and therefore where the
 * hard blocks of the « Année scoute » workflow live** (specifications.md
 * §16.3): checked here, in the service that executes the change, so they
 * hold whichever controller, JSON variant or replayed request calls it —
 * the page's disabled buttons are only a display of the same answer
 * (staffActivationBlockers() / publicActivationBlockers(), which the
 * workflow's steps 9 and 14 read).
 *
 * « A successful import of the year » is a row of import_journal for it:
 * that table is written only inside a successful import's transaction, so
 * a rejected import never leaves one (no status column exists, and none
 * must be added).
 *
 * $transitionVeto is a nullable dependency on the registration module's own
 * public API (ARCHITECTURE.md §7.5/§8.38) — wired by the composition root
 * only when that module is enabled. Core never references any of the
 * module's concrete classes, only this interface, and degrades to "no
 * veto" when it is null (module absent or disabled) — the exact same shape
 * as Core\Member\FeeEstimationService's own nullable
 * Api\HouseholdRegistrationCountProvider dependency.
 */
class ScoutYearAdminService
{
    public function __construct(
        private SettingService $settingService,
        private ImportJournalRepository $importJournal,
        private ?ScoutYearTransitionVetoProvider $transitionVeto = null
    ) {
    }

    /**
     * Activate a staff year: chiefs/intendants see this year, the public keeps
     * the current public year.
     *
     * @throws StaffYearActivationBlockedException when the year has no successful import
     */
    public function activateStaffYear(int $yearId): void
    {
        $blockers = $this->staffActivationBlockers($yearId);
        if ($blockers !== []) {
            throw new StaffYearActivationBlockedException($yearId, $blockers);
        }

        $this->settingService->setInternal(ScoutYearResolver::SETTING_STAFF_YEAR, (string) $yearId);
    }

    /**
     * Clear the staff year. Never refused: it only ever brings the staff
     * back to the year everyone sees.
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
     * Refused while the staff year is not this year, while this year has
     * no successful import, or while the registration module reports open
     * requests (specifications.md §19.2) — module spec: "vérifié côté
     * serveur ... pas seulement à l'affichage", including a direct replay
     * of the request.
     *
     * @throws PublicYearActivationBlockedException
     */
    public function activatePublicYear(int $yearId): void
    {
        $blockers = $this->publicActivationBlockers($yearId);
        if ($blockers !== []) {
            throw new PublicYearActivationBlockedException($yearId, $blockers);
        }

        $this->settingService->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $yearId);
        $this->settingService->setInternal(ScoutYearResolver::SETTING_STAFF_YEAR, '0');
    }

    /**
     * What stands in the way of activateStaffYear($yearId), empty when
     * nothing does.
     *
     * @return list<ActivationBlocker>
     */
    public function staffActivationBlockers(int $yearId): array
    {
        return $this->hasSuccessfulImport($yearId)
            ? []
            : [new ActivationBlocker(ActivationBlocker::NO_SUCCESSFUL_IMPORT)];
    }

    /**
     * What stands in the way of activatePublicYear($yearId), every reason
     * at once and in the order the workflow resolves them (staff year,
     * import, registrations); empty when nothing does.
     *
     * @return list<ActivationBlocker>
     */
    public function publicActivationBlockers(int $yearId): array
    {
        $blockers = [];

        if ($this->staffYearId() !== $yearId) {
            $blockers[] = new ActivationBlocker(ActivationBlocker::STAFF_YEAR_NOT_TARGET);
        }

        if (!$this->hasSuccessfulImport($yearId)) {
            $blockers[] = new ActivationBlocker(ActivationBlocker::NO_SUCCESSFUL_IMPORT);
        }

        $blocking = $this->countBlockingRegistrationRequests();
        if ($blocking > 0) {
            $blockers[] = new ActivationBlocker(ActivationBlocker::REGISTRATION_VETO, $blocking);
        }

        return $blockers;
    }

    /**
     * 0 when the registration module is disabled/absent (no veto provider
     * wired) or reports nothing open. One of the public activation's hard
     * blocks (above), and a plain reminder on the staff-year and the
     * registrations steps of the workflow.
     */
    public function countBlockingRegistrationRequests(): int
    {
        return $this->transitionVeto?->countBlockingRequests() ?? 0;
    }

    private function hasSuccessfulImport(int $yearId): bool
    {
        return $this->importJournal->countForYear($yearId) > 0;
    }

    /**
     * Read from the setting directly rather than through ScoutYearResolver:
     * the resolver answers which year a caller is SERVED (eligibility,
     * adjacency), whereas this asks what the setting this service writes
     * says.
     */
    private function staffYearId(): ?int
    {
        $value = (int) $this->settingService->get(ScoutYearResolver::SETTING_STAFF_YEAR, null, '0');

        return $value > 0 ? $value : null;
    }
}
