<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Core\Journal\JournalService;
use Core\Member\MemberService;
use Modules\Rental\Repository\RentalAssetManager;
use Modules\Rental\Repository\RentalAssetManagerRepository;

/**
 * Granting, revoking and listing an asset's managers, and resolving each
 * one's display name at render time.
 *
 * Managers are Desk members, not necessarily chiefs. Several may share one
 * asset with identical rights, and one member may manage several assets.
 * The unit staff is implicitly a manager of every asset and is deliberately
 * never stored as a grant — see Service\RentalAuthorizationService.
 */
class RentalManagerService
{
    public function __construct(
        private RentalAssetManagerRepository $managerRepository,
        private MemberService $memberService,
        private JournalService $journal
    ) {
    }

    /**
     * An asset's managers, each with the display name to show for them.
     *
     * Names are resolved in a single batched lookup rather than one call per
     * manager — this renders on a configuration page that may list several
     * assets, and a per-row lookup would decrypt the whole member table one
     * row at a time.
     *
     * A manager whose member has no active `member_years` row for the year
     * still appears, under a neutral placeholder rather than a blank: the
     * grant is real and an admin has to be able to see and revoke it. That
     * is exactly the person an import has just deactivated.
     *
     * @return array<int, array{manager: RentalAssetManager, display_name: string}>
     */
    public function listManagersForAsset(int $assetId, int $scoutYearId, bool $activeOnly = true): array
    {
        $managers = $this->managerRepository->findAllByAsset($assetId, $activeOnly);
        if ($managers === []) {
            return [];
        }

        $names = $this->memberService->findDisplayNamesByMemberIds(
            array_map(fn(RentalAssetManager $m) => $m->memberId, $managers),
            $scoutYearId
        );

        $result = [];
        foreach ($managers as $manager) {
            $result[] = [
                'manager' => $manager,
                'display_name' => $names[$manager->memberId] ?? 'Membre inconnu',
            ];
        }

        return $result;
    }

    public function grant(int $assetId, int $memberId, bool $isRenterContact, ?int $userId = null): void
    {
        $this->managerRepository->grant($assetId, $memberId, $isRenterContact);

        // Ids only, never a name: the journal must not become a place where
        // "who manages what" is readable as personal data (SECURITY.md §5).
        $this->journal->log(
            'rental',
            'rental_manager_granted',
            'info',
            'Gestionnaire ajouté sur un bien de location',
            ['asset_id' => $assetId, 'member_id' => $memberId],
            $userId
        );
    }

    /**
     * Removes a grant outright — the deliberate "this person no longer
     * manages this asset" action, as opposed to the import-driven
     * deactivation in Service\RentalDeskImportListener. A chief's explicit
     * decision is a real deletion; an import that simply did not mention
     * someone is not.
     */
    public function revoke(int $assetId, int $memberId, ?int $userId = null): void
    {
        $this->managerRepository->revoke($assetId, $memberId);

        $this->journal->log(
            'rental',
            'rental_manager_revoked',
            'info',
            'Gestionnaire retiré d\'un bien de location',
            ['asset_id' => $assetId, 'member_id' => $memberId],
            $userId
        );
    }

    /**
     * Whether this manager's contact details are shown to the renter on
     * their own tracking page. Never widens the audience to the public —
     * no manager's name, phone or email is ever shown publicly (§6.6).
     */
    public function setRenterContact(int $assetId, int $memberId, bool $isRenterContact): void
    {
        $this->managerRepository->setRenterContact($assetId, $memberId, $isRenterContact);
    }

    /**
     * The managers a renter is allowed to contact for this asset, name and
     * contact details included. Used by the renter-facing tracking page,
     * which is why the `is_renter_contact` filter is applied in SQL rather
     * than left to the template: a template that forgot the condition would
     * leak every manager's details to an external renter.
     *
     * The details come with the name deliberately — a list of bare names is
     * of no use to somebody who needs to reach a person about their booking,
     * and `is_renter_contact` exists precisely to say whose details may be
     * given out (§6.3, §6.6). It never widens beyond that: an unflagged
     * manager is absent from this list entirely, and nothing here is ever
     * rendered on a public page.
     *
     * One profile lookup per contact rather than a batch: an asset has a
     * handful of managers and only the flagged ones reach this far, so the
     * simpler call is the honest trade.
     *
     * @return array<int, array{manager: RentalAssetManager, display_name: string, email: ?string, phone: ?string}>
     */
    public function listRenterContactsForAsset(int $assetId, int $scoutYearId): array
    {
        $contacts = $this->managerRepository->findRenterContactsByAsset($assetId);
        if ($contacts === []) {
            return [];
        }

        $names = $this->memberService->findDisplayNamesByMemberIds(
            array_map(fn(RentalAssetManager $m) => $m->memberId, $contacts),
            $scoutYearId
        );

        $result = [];
        foreach ($contacts as $contact) {
            $profile = $this->memberService->findProfileByMemberAndYear($contact->memberId, $scoutYearId);
            $result[] = [
                'manager' => $contact,
                'display_name' => $names[$contact->memberId] ?? 'Membre inconnu',
                'email' => $profile?->email,
                // The mobile first: it is the number a renter actually
                // needs on the day, and a landline is the fallback.
                'phone' => $profile === null ? null : ($profile->mobile ?? $profile->phone),
            ];
        }

        return $result;
    }
}
