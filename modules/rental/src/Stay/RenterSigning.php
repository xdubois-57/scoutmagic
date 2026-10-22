<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Stay;

/**
 * What the renter did when the inventory was signed (#989, lot E): signed
 * on the manager's phone, was not there, or would not sign. Stored as
 * `rental_inventory_validations.renter_signing`, and the only thing kept
 * about the renter's part — never the name they typed nor their drawing,
 * which exist in the PDF alone.
 *
 * Without the renter's signature the manager still signs, and the PDF
 * still goes to the renter by e-mail: it says which of the three it was.
 */
enum RenterSigning: string
{
    case ON_SITE = 'on_site';
    case ABSENT = 'absent';
    case REFUSED = 'refused';

    /** The choice as the signing screen offers it. */
    public function choice(): string
    {
        return match ($this) {
            self::ON_SITE => 'Le locataire signe ici',
            self::ABSENT => 'Le locataire est absent',
            self::REFUSED => 'Le locataire refuse de signer',
        };
    }

    /**
     * What the booking's history says of it — the choice, never the
     * typed name, which is the renter's and stays in the PDF.
     */
    public function historyLabel(): string
    {
        return match ($this) {
            self::ON_SITE => 'signé sur place par le locataire',
            self::ABSENT => 'locataire absent',
            self::REFUSED => 'le locataire a refusé de signer',
        };
    }

    public function renterSigns(): bool
    {
        return $this === self::ON_SITE;
    }
}
