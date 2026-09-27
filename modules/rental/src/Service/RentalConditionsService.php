<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Core\View\EditableContentService;
use Modules\Rental\Document\AssetConditions;
use Modules\Rental\Document\ConditionsVersion;
use Modules\Rental\Repository\RentalConditionsVersionRepository;

/**
 * An asset's conditions as versions: the one in force, and every one that
 * ever was (issue #494).
 *
 * **Why a version is archived when it is READ, not only when it is saved.**
 * The text in force can reach the store by three doors: a manager's
 * « Enregistrer » on the asset's settings, a superadmin in configuration
 * mode through the generic `POST /api/editable-content`, and — while nobody
 * has written anything — the standard body the module ships. Only the first
 * goes through this module. So the version in force is archived the first
 * time anybody asks for it, whichever door it came through: the request
 * form, the public conditions page, a save. By the time a renter can tick
 * the box, the text they are ticking is in the archive.
 *
 * That same rule is what covers the bookings made before this archive
 * existed. Their `conditions_hash` matches the text in force if nobody has
 * changed it since, and that text is archived on the first read after the
 * upgrade — and {@see recordSave()} archives the outgoing text before a
 * manager's save replaces it. A booking whose text had already been
 * overwritten before the upgrade matches no version and keeps no link:
 * that wording is gone, and inventing one would be worse than saying so.
 */
class RentalConditionsService
{
    public function __construct(
        private RentalConditionsVersionRepository $versions,
        private EditableContentService $store
    ) {
    }

    /**
     * The version in force, archived if it was not yet.
     *
     * @param ?int $userAccountId Who is saving it, when this is called on a
     *   save; null on a read.
     */
    public function current(int $assetId, ?int $userAccountId = null): ConditionsVersion
    {
        $html = AssetConditions::textFor($this->store, $assetId);

        return $this->versions->archive(
            $assetId,
            RentalBookingService::hashAcceptedText($html),
            $html,
            new \DateTimeImmutable(),
            $userAccountId
        );
    }

    /** One archived version, or null when this asset never had it. */
    public function find(int $assetId, string $version): ?ConditionsVersion
    {
        if (preg_match('/^[0-9a-f]{12}$/', $version) !== 1) {
            return null;
        }

        return $this->versions->findByVersion($assetId, $version);
    }

    /**
     * A manager saves new conditions: the outgoing text is archived FIRST,
     * then the new one is written and archived under the manager's name.
     *
     * The first half is what keeps a pre-archive booking's proof alive: if
     * nobody read the conditions between the upgrade and this save, the
     * outgoing text is archived here, one statement before it would have
     * been lost.
     */
    public function recordSave(int $assetId, string $sanitizedHtml, int $userAccountId): ConditionsVersion
    {
        $this->current($assetId);

        $this->store->set(AssetConditions::key($assetId), $sanitizedHtml, 'rich_text', $userAccountId);

        // Read back rather than hashing `$sanitizedHtml`: the store is what
        // the next visitor is served, so it is what the version must match.
        return $this->current($assetId, $userAccountId);
    }
}
