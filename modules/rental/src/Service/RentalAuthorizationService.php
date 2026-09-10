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
     * The same three questions, asked over **every year an access decision
     * may be taken in** (`Core\ScoutYear\AuthorizationYearService`) rather
     * than in one.
     *
     * **For a caller with no session and no dated record to work from**,
     * and for no other. Everything on a screen holds a session, and asks
     * in the one year that person is served
     * (`ScoutYearResolver::getAuthorizationYear()`); everything holding a
     * record scoped to a year asks in that year. Neither is this.
     *
     * The reason a background caller widens rather than picking one year:
     * during a transition BOTH animateurs walk through the doors on the
     * screens, so a background job that recognised only the one leaving
     * would deny the one arriving every notification about screens they
     * can open — and go on alerting the one leaving about things they can
     * still see. **The failure modes are not symmetric.** A chief notified
     * once too often ignores an e-mail; a chief never notified misses a
     * booking request or a compliance deadline.
     *
     * The widening is bounded on every side: the staff year exists only
     * during a transition, the set is capped at one year past the public
     * year, a role from a non-public year counts only from `intendant` up,
     * and there is no session here at all — so there is no preview to
     * smuggle a year in through.
     *
     * `rental_assets` and `rental_bookings` deliberately carry no
     * `scout_year_id` (see this module's schema.sql, top of file: a
     * booking from 28 August to 2 September straddles two of them), so no
     * caller in this module can derive a year from the record it is
     * judging. That is why they all end up here rather than under the
     * derive-your-own-year rule.
     *
     * @param int[] $scoutYearIds
     */
    public function isUnitStaffInAnyYear(?string $email, array $scoutYearIds): bool
    {
        foreach ($scoutYearIds as $scoutYearId) {
            if ($this->isUnitStaff($email, $scoutYearId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param int[] $scoutYearIds
     * @see isUnitStaffInAnyYear() for when a caller may use this shape
     */
    public function canManageAssetIdInAnyYear(?string $email, array $scoutYearIds, int $assetId): bool
    {
        foreach ($scoutYearIds as $scoutYearId) {
            if ($this->canManageAssetId($email, $scoutYearId, $assetId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * **A union of AUTHORITY, not of a year-scoped list.** An asset carries
     * no scout year at all, so this merges nothing: it answers which of the
     * unit's assets this person may act on, and the answer is one set of
     * assets whichever year established it. Nothing here contradicts the
     * rule that no list ever merges two scout years.
     *
     * @param int[] $scoutYearIds
     * @return RentalAsset[] ordered by name, deduplicated by id
     * @see isUnitStaffInAnyYear() for when a caller may use this shape
     */
    public function listManageableAssetsInAnyYear(?string $email, array $scoutYearIds): array
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
