<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Core\Module\MenuEntry;
use Core\Module\MenuEntryProvider;
use Core\View\MenuBuilder;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Repository\RentalAssetRepository;

/**
 * Core\Module\MenuEntryProvider implementation (ARCHITECTURE.md §7.4),
 * wired into the composition root only when this module is enabled. It
 * contributes two different kinds of entry, which is exactly why the hook
 * had to be generalised beyond "Espace animés" in the first place:
 *
 * - **"Notre unité"** — one public entry per asset whose "afficher dans le
 *   menu" box is ticked, plus the "Locations" index page. Public, so it is
 *   contributed for an anonymous visitor too (`$email === null`).
 * - **"Espace animés"** — "Mes locations", visible only to a visitor who
 *   actually manages at least one asset.
 *
 * **Menu visibility is never a permission.** Every route these entries
 * point at carries its own `role_min`, and the managed space re-checks
 * per-asset authority server-side on every action
 * (Service\RentalAuthorizationService). An absent entry hides nothing and a
 * present one grants nothing (ARCHITECTURE.md §12).
 *
 * This runs on **every request that builds a menu**, so it stays to two
 * bounded indexed queries and never a per-asset walk.
 */
class RentalMenuHookService implements MenuEntryProvider
{
    /**
     * Sorts the index page ahead of the individual assets, and the assets
     * among themselves in the repository's own name order. Both stay well
     * clear of the core pages' small orders — MenuBuilder ranks by group
     * first anyway, so this only orders module entries against each other.
     */
    private const INDEX_ORDER = 500;

    public function __construct(
        private RentalAssetRepository $assetRepository,
        private RentalAuthorizationService $authorizationService,
        private int $scoutYearId
    ) {
    }

    public function getMenuEntries(?string $email): array
    {
        $entries = [];

        // The "Locations" index exists as soon as ANY public asset does —
        // not only when one is pinned. Gating it on a pinned asset would
        // make an unpinned public asset unreachable: it would have no menu
        // entry of its own and no index page to be listed on (roadmap §6.2).
        $publicAssets = $this->assetRepository->findAllPublic();
        if ($publicAssets !== []) {
            $entries[] = new MenuEntry(
                MenuBuilder::MENU_NOTRE_UNITE,
                'Locations',
                '/locations',
                'public',
                self::INDEX_ORDER
            );
        }

        // One entry per pinned asset. Filtered from the already-loaded
        // public list rather than re-queried: same rows, one query.
        $order = self::INDEX_ORDER + 1;
        foreach ($publicAssets as $asset) {
            if (!$asset->isPinnedToMenu()) {
                continue;
            }

            $entries[] = new MenuEntry(
                MenuBuilder::MENU_NOTRE_UNITE,
                $asset->name,
                '/locations/' . $asset->slug,
                'public',
                $order++
            );
        }

        // "Mes locations" — the managers' daily entry point (§6.5). Only
        // for someone who actually manages something, so an ordinary
        // identified visitor never sees it.
        if ($email !== null && $this->authorizationService->managesAnyAsset($email, $this->scoutYearId)) {
            $entries[] = new MenuEntry(
                MenuBuilder::MENU_ESPACE_ANIMES,
                'Mes locations',
                '/mes-locations',
                'identified',
                self::INDEX_ORDER
            );
        }

        return $entries;
    }

    /**
     * The assets the public index page lists — the same query the menu
     * entries are derived from, exposed so the controller and the hook can
     * never disagree about what "public" means.
     *
     * @return RentalAsset[]
     */
    public function listPublicAssets(): array
    {
        return $this->assetRepository->findAllPublic();
    }
}
