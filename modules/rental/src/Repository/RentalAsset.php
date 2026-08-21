<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Repository;

/**
 * One rentable asset — a hall, a plot of ground, a tent, a trailer, a piece
 * of equipment. Hydrated by Repository\RentalAssetRepository, which is also
 * the only place $emergencyPhone is ever decrypted (SECURITY.md §5).
 *
 * $emergencyPhone is personal data and is shown **only** to a renter on
 * their own tracking page — never on a public page, never to a member who
 * does not manage the asset.
 */
final class RentalAsset
{
    public function __construct(
        public readonly int $id,
        public readonly string $assetType,
        public readonly string $name,
        public readonly string $slug,
        public readonly ?int $capacity,
        public readonly int $quantity,
        public readonly ?string $arrivalTime,
        public readonly ?string $departureTime,
        public readonly ?string $emergencyPhone,
        public readonly bool $isArchived,
        public readonly bool $isPublic,
        public readonly bool $showInMenu,
        /**
         * The sentence an invoice for this asset carries instead of a VAT
         * line (§6.27). **No VAT is ever computed** — a unit letting a hall
         * is not a VAT-registered business in the general case — but a
         * Belgian invoice with no VAT on it has to say why.
         */
        public readonly ?string $vatExemptionNote = null,
        /** §6.30 — occupancy published as virtual calendar events, never stored ones. */
        public readonly bool $calendarPublicationEnabled = false,
        public readonly ?int $calendarId = null,
        public readonly \Modules\Rental\Calendar\PublishFrom $calendarPublishFrom
            = \Modules\Rental\Calendar\PublishFrom::CONFIRMATION
    ) {
    }

    /**
     * Whether several units of this asset can be booked independently — the
     * distinction between "the hall" (one booking at a time) and "eight
     * tents". Availability is otherwise computed identically for both.
     */
    public function isStock(): bool
    {
        return $this->quantity > 1;
    }

    /**
     * Whether this asset earns its own entry in the "Notre unité" menu.
     * All three conditions matter: a private asset with the box ticked, or
     * an archived one, must never surface. Keeping the rule here (rather
     * than in a template) is what stops a future caller from re-deriving
     * two thirds of it and leaking the third.
     */
    public function isPinnedToMenu(): bool
    {
        return $this->showInMenu && $this->isPublic && !$this->isArchived;
    }

    /**
     * Whether the public may see this asset at all — on the /locations
     * index and on its own page. Archived assets drop out of public view
     * while staying fully readable internally, which is the whole point of
     * archiving rather than deleting.
     */
    public function isPubliclyVisible(): bool
    {
        return $this->isPublic && !$this->isArchived;
    }
}
