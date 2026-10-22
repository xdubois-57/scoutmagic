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
 *
 * « Signé sur papier » (#989, lot F, decision Q3) is the fourth: both
 * parties signed the printed checklist by hand, and nobody signs on the
 * screen — neither the renter nor the manager. A photo of that sheet is
 * then required and goes into the PDF (`Stay\PaperSheet`).
 */
enum RenterSigning: string
{
    case ON_SITE = 'on_site';
    case ABSENT = 'absent';
    case REFUSED = 'refused';
    case PAPER = 'paper';

    /** The choice as the signing screen offers it. */
    public function choice(): string
    {
        return match ($this) {
            self::ON_SITE => 'Le locataire signe ici',
            self::ABSENT => 'Le locataire est absent',
            self::REFUSED => 'Le locataire refuse de signer',
            self::PAPER => 'Signé sur papier',
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
            self::PAPER => 'signé sur papier par les deux parties',
        };
    }

    public function renterSigns(): bool
    {
        return $this === self::ON_SITE;
    }

    /** Signed by hand on the printed sheet: no electronic signature at all. */
    public function isOnPaper(): bool
    {
        return $this === self::PAPER;
    }
}
