<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Core\Member\MemberService;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Repository\RentalAssetManagerRepository;
use Modules\Rental\Repository\RentalAssetRepository;

/**
 * The single answer to "may this person operate this asset".
 *
 * ```
 * Staff d'U     → every asset
 * Manager       → their own ACTIVE assets only
 * Anyone else   → refused
 * ```
 *
 * **Called server-side on every action, never inferred from a screen.** The
 * managed space's routes are `role_min: identified` — deliberately low,
 * because a manager is not necessarily a chief — so the route guard alone
 * grants almost nothing and this service is what actually protects the
 * data. A hidden "Gérer ce bien" button, an absent menu entry and a
 * breadcrumb are presentation, never a boundary (ARCHITECTURE.md §12).
 *
 * **Badges are never an ACL.** Per-asset authority comes from exactly two
 * places: Staff d'U membership, and an active row in
 * `rental_asset_managers`. Nothing else — not a badge, not a function
 * label, not a section — grants anything here.
 *
 * Staff d'U is resolved through `MemberService::isUnitChief()` rather than
 * stored as a manager row, so a unit chief can never be locked out of their
 * own unit's assets by a revoked or import-deactivated grant.
 */
class RentalAuthorizationService
{
    public function __construct(
        private MemberService $memberService,
        private RentalAssetRepository $assetRepository,
        private RentalAssetManagerRepository $managerRepository
    ) {
    }

    /**
     * Whether $email may manage $asset.
     *
     * An **archived** asset stays manageable by whoever could manage it
     * before: archiving hides an asset from the public and from new
     * bookings, it does not seal off the history its managers still have to
     * settle. Refusing here would strand a manager mid-booking the moment a
     * chief archived the asset.
     */
    public function canManageAsset(?string $email, int $scoutYearId, RentalAsset $asset): bool
    {
        return $this->canManageAssetId($email, $scoutYearId, $asset->id);
    }

    public function canManageAssetId(?string $email, int $scoutYearId, int $assetId): bool
    {
        if ($email === null || $email === '') {
            return false;
        }

        if ($this->isUnitStaff($email, $scoutYearId)) {
            return true;
        }

        return in_array($assetId, $this->managedAssetIdsFor($email, $scoutYearId), true);
    }

    /**
     * Every asset $email may manage, resolved in one pass.
     *
     * Used by the "Mes locations" page and by anything that has to render a
     * list rather than answer a single yes/no — calling canManageAsset() in
     * a loop over every asset would be the same answer at N times the cost,
     * and would re-resolve the visitor's Staff d'U status on every
     * iteration.
     *
     * @return RentalAsset[] Ordered by name. Archived assets included — see canManageAsset().
     */
    public function listManageableAssets(?string $email, int $scoutYearId): array
    {
        if ($email === null || $email === '') {
            return [];
        }

        if ($this->isUnitStaff($email, $scoutYearId)) {
            return $this->assetRepository->findAll();
        }

        $assetIds = $this->managedAssetIdsFor($email, $scoutYearId);

        return $assetIds === [] ? [] : $this->assetRepository->findAllByIds($assetIds);
    }

    /**
     * The same list over the years an access decision may consider.
     *
     * This is the ENTRY POINT of « Mes locations », and that is why it
     * matters more than the checks further in: the page 403s on an empty
     * list before any of them runs, so widening those alone would have
     * been cosmetic. A Staff d'U or an asset manager whose member_year row
     * sits in the other of the two adjacent years is exactly who this
     * exists to let back in.
     *
     * Deduplicated by asset id and re-sorted by name: the union of two
     * individually ordered lists is not an ordered list, and Staff d'U in
     * either year already returns every asset.
     *
     * @param list<int> $scoutYearIds
     * @return RentalAsset[]
     */
    public function listManageableAssetsAcrossYears(?string $email, array $scoutYearIds): array
    {
        $byId = [];
        foreach ($scoutYearIds as $scoutYearId) {
            foreach ($this->listManageableAssets($email, $scoutYearId) as $asset) {
                $byId[$asset->id] = $asset;
            }
        }

        $assets = array_values($byId);
        usort($assets, static fn(RentalAsset $a, RentalAsset $b): int => strcmp($a->name, $b->name));

        return $assets;
    }

    /**
     * @param list<int> $scoutYearIds
     */
    public function managesAnyAssetAcrossYears(?string $email, array $scoutYearIds): bool
    {
        foreach ($scoutYearIds as $scoutYearId) {
            if ($this->managesAnyAsset($email, $scoutYearId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether $email manages at least one asset — the cheap gate behind the
     * "Mes locations" menu entry, which must not run a full asset load on
     * every request that builds a menu.
     */
    public function managesAnyAsset(?string $email, int $scoutYearId): bool
    {
        if ($email === null || $email === '') {
            return false;
        }

        if ($this->isUnitStaff($email, $scoutYearId)) {
            // Staff d'U implicitly manages every asset — but only if there
            // is one. An empty installation must not sprout a menu entry
            // leading to an empty page.
            return $this->assetRepository->findAllActive() !== [];
        }

        return $this->managedAssetIdsFor($email, $scoutYearId) !== [];
    }

    /**
     * The four questions above, over the years an access decision may
     * consider — Core\ScoutYear\ScoutYearResolver::getAccessYearIds(),
     * whose docblock carries the reasoning and the one-year bound.
     *
     * Rental is the widest surface this affects: isUnitStaff() makes Staff
     * d'U an implicit manager of every asset, so on the date-computed year
     * alone the whole management area empties out for them between the 1st
     * of September and the import of the new roster.
     *
     * @param list<int> $scoutYearIds
     */
    public function canManageAssetAcrossYears(?string $email, array $scoutYearIds, RentalAsset $asset): bool
    {
        return $this->canManageAssetIdAcrossYears($email, $scoutYearIds, $asset->id);
    }

    /**
     * @param list<int> $scoutYearIds
     */
    public function canManageAssetIdAcrossYears(?string $email, array $scoutYearIds, int $assetId): bool
    {
        foreach ($scoutYearIds as $scoutYearId) {
            if ($this->canManageAssetId($email, $scoutYearId, $assetId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<int> $scoutYearIds
     */
    public function isUnitStaffAcrossYears(?string $email, array $scoutYearIds): bool
    {
        foreach ($scoutYearIds as $scoutYearId) {
            if ($this->isUnitStaff($email, $scoutYearId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether $email is a unit chief (Staff d'U), and therefore an implicit
     * manager of every asset.
     */
    public function isUnitStaff(?string $email, int $scoutYearId): bool
    {
        if ($email === null || $email === '') {
            return false;
        }

        return $this->memberService->isUnitChief($email, $scoutYearId);
    }

    /**
     * The asset ids granted to whichever members $email is linked to.
     *
     * An email can be linked to several members (a parent and their
     * children share one address), so every linked member's grants count —
     * resolved with one query over all of them rather than one query each.
     *
     * @return int[]
     */
    private function managedAssetIdsFor(string $email, int $scoutYearId): array
    {
        $memberIds = array_map(
            fn($profile) => $profile->memberId,
            $this->memberService->getLinkedMembers($email, $scoutYearId)
        );

        return $memberIds === []
            ? []
            : $this->managerRepository->findActiveAssetIdsForMembers($memberIds);
    }
}
