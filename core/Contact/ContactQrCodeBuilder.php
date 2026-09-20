<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Contact;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

/**
 * The QR variant of a contact card, as PNG bytes.
 *
 * `endroid/qr-code` is already a justified dependency of this project
 * (event posters, the SEPA payment QR), and this is the same gesture
 * `Core\Pdf\PosterPdfService::buildQrCodeDataUri()` makes. **No new
 * dependency and no call to an external API**: a QR code service would be
 * handed a member's full name, phone number and home address, which is
 * the whole thing this feature is careful not to do.
 *
 * The payload is a complete vCard, so a phone's camera offers « Ajouter
 * aux contacts » straight from the viewfinder with nothing to download and
 * no round trip to the site.
 */
class ContactQrCodeBuilder
{
    /** Pixels. Large enough to scan from a laptop screen at arm's length. */
    private const SIZE = 480;

    private const MARGIN = 10;

    /**
     * The largest payload a QR code can hold at all: version 40, byte
     * mode, the Low error-correction level `endroid` defaults to. A
     * refusal here means the card itself must shrink, never the symbol —
     * which is why {@see VCardVariant::Qr} drops the portrait and caps the
     * history before we ever get here.
     */
    public const MAX_PAYLOAD_BYTES = 2953;

    /**
     * @throws ContactCardException when the card does not fit in a symbol.
     */
    public function build(string $vcard): string
    {
        if (strlen($vcard) > self::MAX_PAYLOAD_BYTES) {
            throw new ContactCardException(
                'Cette fiche est trop longue pour un code QR. Téléchargez le fichier de contact à la place.'
            );
        }

        return (new Builder(
            writer: new PngWriter(),
            data: $vcard,
            size: self::SIZE,
            margin: self::MARGIN
        ))->build()->getString();
    }
}
