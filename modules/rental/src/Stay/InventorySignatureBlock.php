<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Stay;

use Core\Config\AppClock;
use Core\Signature\AccountSignature;
use Core\Signature\SignatureOrigin;
use Core\Signature\SignaturePng;

/**
 * The two signatures at the foot of a signed inventory (#989, lot E): two
 * frames side by side — the renter's, the manager's — and the line saying
 * where and when it was signed.
 *
 * Pure: the signatures are handed in already decoded and re-encoded
 * (`Core\Signature\SignatureImage`), and HTML comes out, every text
 * escaped — the renter typed their name on the manager's phone and dompdf
 * renders what it is given. An image is printed only as a
 * `data:image/png;base64,` URI checked by a regular expression, after the
 * PNG's own header check (`SignaturePng`); PNG because a signature needs
 * its transparency, where the photos of the annex are JPEG.
 *
 * What each frame says is the record of how it was signed:
 * - the renter: « Accepté sur place par {nom tapé}, signé au doigt », or
 *   « Le locataire était absent », or « Le locataire a refusé de signer »;
 * - the manager: « Signature enregistrée appliquée sur confirmation de
 *   {nom du compte} », or « Signé au doigt par {nom du compte} » — the
 *   account's own name, never a totem (#825), « un gestionnaire » without
 *   one;
 * - then « Signé sur place, {bien}, {adresse}, le JJ/MM/AAAA à HH:MM »,
 *   the name alone while the asset has no address, the time on the
 *   application clock (`AppClock::zone()`).
 *
 * **Signed on paper** (#989, lot F, decision Q3), there is no frame and no
 * image: nobody signed on the screen, the sheet carries both signatures,
 * so the block says « Signé sur papier par les deux parties le … : voir la
 * feuille signée, photo n » — n being the number the annex gives the
 * sheet's photo. A manager's signature is then refused rather than
 * printed: the two modes never mix.
 */
final readonly class InventorySignatureBlock
{
    /** The box a signature is fitted into, in CSS pixels: a 3 : 1 drawing fills it. */
    public const IMAGE_WIDTH = 225;
    public const IMAGE_HEIGHT = 75;

    /** Said for a manager whose account carries no name. */
    public const UNNAMED_MANAGER = 'un gestionnaire';

    private const DATA_URI = '#^data:image/png;base64,[A-Za-z0-9+/]++={0,2}$#';

    public function __construct(
        public InventorySigning $renter,
        /** The manager's signature; null exactly when the sheet was signed on paper. */
        public ?AccountSignature $manager,
        public string $assetName,
        public ?string $assetAddress,
        public \DateTimeImmutable $signedAt
    ) {
        if ($renter->choice->isOnPaper() !== ($manager === null)) {
            throw new \InvalidArgumentException(
                'An inventory signed on paper carries no electronic signature, and any other carries the manager\'s.'
            );
        }
    }

    /** The account's own name, or « un gestionnaire ». */
    public function managerName(): string
    {
        $name = trim((string) $this->manager?->signerName);

        return $name !== '' ? $name : self::UNNAMED_MANAGER;
    }

    /** What the renter's frame says under (or instead of) the drawing. Unescaped. */
    public function renterCaption(): string
    {
        return match ($this->renter->choice) {
            RenterSigning::ON_SITE => 'Accepté sur place par ' . ($this->renter->signature->typedName ?? '')
                . ', signé au doigt',
            RenterSigning::ABSENT => 'Le locataire était absent',
            RenterSigning::REFUSED => 'Le locataire a refusé de signer',
            RenterSigning::PAPER => 'Signé sur papier',
        };
    }

    /** What the manager's frame says under the signature. Unescaped. */
    public function managerCaption(): string
    {
        return match ($this->manager?->origin) {
            null => 'Signé sur papier',
            SignatureOrigin::Stored => 'Signature enregistrée appliquée sur confirmation de ' . $this->managerName(),
            SignatureOrigin::DrawnNow => 'Signé au doigt par ' . $this->managerName(),
            SignatureOrigin::ImportedNow => 'Image de signature importée par ' . $this->managerName(),
        };
    }

    /** « Signé sur place, Local Saint-Georges, Rue …, le 04/07/2027 à 11:30 ». Unescaped. */
    public function placeAndTime(): string
    {
        $place = trim($this->assetName);
        $address = trim((string) preg_replace('/\s*[\r\n]+\s*/u', ', ', (string) $this->assetAddress));
        if ($address !== '') {
            $place .= ', ' . $address;
        }

        return 'Signé sur place, ' . $place . ', le '
            . $this->signedAt->setTimezone(AppClock::zone())->format('d/m/Y à H:i');
    }

    /**
     * « Signé sur papier par les deux parties le 04/07/2027 à 11:30 : voir
     * la feuille signée, photo 7 ». Unescaped.
     */
    public function onPaper(?int $sheetPhotoNumber): string
    {
        return 'Signé sur papier par les deux parties le '
            . $this->signedAt->setTimezone(AppClock::zone())->format('d/m/Y à H:i')
            . ' : voir la feuille signée, '
            . ($sheetPhotoNumber !== null ? 'photo ' . $sheetPhotoNumber : 'en annexe');
    }

    /**
     * @param int|null $sheetPhotoNumber the number the annex gives the
     *   photo of the sheet signed on paper; unused otherwise
     */
    public function html(?int $sheetPhotoNumber = null): string
    {
        $e = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if ($this->manager === null) {
            // Set left, against the frame's justified body: a justified
            // line spreads its words apart, and a reader of the text layer
            // gets them in pieces.
            return '<h2>Signatures</h2><p class="validated" style="text-align: left;">'
                . $e($this->onPaper($sheetPhotoNumber)) . '.</p>';
        }
        // The frame's `.body td` rule draws a thin border already; the text
        // is set left, against the frame's justified body.
        $cell = 'width: 50%; vertical-align: top; padding: 6px 8px; text-align: left;';

        return '<h2>Signatures</h2>'
            . '<table class="signatures" style="width: 100%; table-layout: fixed;"><tr>'
            . '<td style="' . $cell . '"><strong>Le locataire</strong>'
            . self::image($this->renter->signature?->png, 'Signature du locataire')
            . '<div>' . $e($this->renterCaption()) . '</div></td>'
            . '<td style="' . $cell . '"><strong>Le gestionnaire</strong>'
            . self::image($this->manager->png, 'Signature du gestionnaire')
            . '<div>' . $e($this->managerCaption()) . '</div></td>'
            . '</tr></table>'
            . '<p class="validated">' . $e($this->placeAndTime()) . '.</p>';
    }

    /**
     * The signature as an image fitted into the box, or nothing: a PNG
     * whose size cannot be read, or a URI of another shape, is never
     * handed to dompdf.
     */
    private static function image(?SignaturePng $png, string $alt): string
    {
        if ($png === null) {
            return '';
        }
        $size = @getimagesizefromstring($png->bytes());
        if ($size === false || $size[0] <= 0 || $size[1] <= 0) {
            return '';
        }
        $dataUri = 'data:image/png;base64,' . base64_encode($png->bytes());
        if (preg_match(self::DATA_URI, $dataUri) !== 1) {
            return '';
        }

        $scale = min(self::IMAGE_WIDTH / $size[0], self::IMAGE_HEIGHT / $size[1]);
        $width = max(1, (int) round($size[0] * $scale));
        $height = max(1, (int) round($size[1] * $scale));

        return '<div style="height: ' . self::IMAGE_HEIGHT . 'px; margin: 4px 0;"><img src="' . $dataUri
            . '" alt="' . $alt . '" width="' . $width . '" height="' . $height . '"></div>';
    }
}
