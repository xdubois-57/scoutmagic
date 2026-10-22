<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Stay;

use Core\Signature\WitnessSignature;

/**
 * The renter's part of a signed inventory (#989, lot E): what they did
 * and, when they signed on the spot, their signature — the drawing and
 * the name they typed, as `PersonalSignatureService::witnessFromRequest()`
 * read them before any lock.
 *
 * **Nothing here is ever stored.** The service puts the signature in the
 * PDF and writes `$choice` alone to the database; the history and the
 * journal never see the typed name.
 *
 * A signature comes with « on_site » and with nothing else: the three
 * constructors below are the only ways in, so an « absent » renter cannot
 * carry a drawing, nor an « on site » one go without — and an inventory
 * « Signé sur papier » (#989, lot F) carries the photo of the signed sheet
 * and no drawing at all, nor can it go without that photo.
 */
final readonly class InventorySigning
{
    private function __construct(
        public RenterSigning $choice,
        public ?WitnessSignature $signature,
        public ?PaperSheet $paperSheet = null
    ) {
    }

    public static function onSite(WitnessSignature $signature): self
    {
        return new self(RenterSigning::ON_SITE, $signature);
    }

    /**
     * Both parties signed the printed sheet by hand (decision Q3): its
     * photo, and nobody's signature on the screen.
     */
    public static function onPaper(PaperSheet $sheet): self
    {
        return new self(RenterSigning::PAPER, null, $sheet);
    }

    /**
     * The renter was absent or refused to sign: no signature of theirs.
     *
     * @throws \InvalidArgumentException for « on_site », which needs one,
     *   and for « paper », which needs the photo of the sheet
     */
    public static function without(RenterSigning $choice): self
    {
        if ($choice->renterSigns()) {
            throw new \InvalidArgumentException('A renter signing on site signs: use onSite().');
        }
        if ($choice->isOnPaper()) {
            throw new \InvalidArgumentException('An inventory signed on paper carries its sheet: use onPaper().');
        }

        return new self($choice, null);
    }
}
