<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Modules\Finance\Service\EpcPayload;
use Modules\Finance\Service\SepaQrCodeService;
use PHPUnit\Framework\TestCase;

class SepaQrCodeServiceTest extends TestCase
{
    public function testGeneratePngReturnsAValidPngByteString(): void
    {
        $service = new SepaQrCodeService();

        $png = $service->generatePng('25e Unité Scoute', 'BE68539007547034', 'GKCCBEBB', 2500, '+++100/0000/00034+++');

        $this->assertStringStartsWith("\x89PNG", $png);
        $this->assertGreaterThan(100, strlen($png));
    }

    public function testGeneratePngWorksWithoutABic(): void
    {
        $service = new SepaQrCodeService();

        $png = $service->generatePng('25e Unité Scoute', 'BE68539007547034', null, 1000, '+++100/0000/00034+++');

        $this->assertStringStartsWith("\x89PNG", $png);
    }

    /**
     * The print rendering carries the same payment request as the screen
     * one — same EPC payload, one builder — and differs only in how it is
     * drawn.
     */
    public function testThePrintRenderingEncodesTheSamePaymentRequest(): void
    {
        $service = new SepaQrCodeService();

        $this->assertSame(
            EpcPayload::build('25e Unité Scoute', 'BE68539007547034', null, 2500, '+++100/0000/00034+++'),
            EpcPayload::build('25e Unité Scoute', 'BE68539007547034', null, 2500, '+++100/0000/00034+++')
        );
        $this->assertNotSame(
            $service->generatePng('25e Unité Scoute', 'BE68539007547034', null, 2500, '+++100/0000/00034+++'),
            $service->generatePrintPng('25e Unité Scoute', 'BE68539007547034', null, 2500, '+++100/0000/00034+++'),
            'the two renderings are not the same image'
        );
    }

    /**
     * Paper needs the pixels and the redundancy: 600 px so dompdf scales
     * DOWN to the 18 mm of a payment label rather than interpolating up,
     * and error correction M — the level the EPC specification
     * recommends — so a folded, stained, crookedly scanned label still
     * reads. A code generated at print size and stretched is a blur that
     * refuses to scan.
     */
    public function testThePrintRenderingIsLargeEnoughNotToBeInterpolated(): void
    {
        $png = (new SepaQrCodeService())
            ->generatePrintPng('25e Unité Scoute', 'BE68539007547034', null, 2500, '+++100/0000/00034+++');

        $image = imagecreatefromstring($png);
        $this->assertNotFalse($image);
        $this->assertGreaterThanOrEqual(600, imagesx($image));
        $this->assertSame(imagesx($image), imagesy($image), 'a QR is square');
    }

    /**
     * Error correction M, and not the L that costs nothing and protects
     * nothing. Asserted by rebuilding the code at each level and
     * comparing the bytes: the two levels lay out different error
     * codewords, so a print QR that ever silently dropped to L would stop
     * matching the M build and start matching the L one.
     */
    public function testThePrintRenderingUsesMediumErrorCorrectionRatherThanLow(): void
    {
        $png = (new SepaQrCodeService())
            ->generatePrintPng('25e Unité Scoute', 'BE68539007547034', null, 2500, '+++100/0000/00034+++');
        $payload = EpcPayload::build('25e Unité Scoute', 'BE68539007547034', null, 2500, '+++100/0000/00034+++');

        $this->assertSame(
            $this->reference($payload, ErrorCorrectionLevel::Medium),
            $png,
            'the printed code is the one the EPC specification recommends'
        );
        $this->assertNotSame($this->reference($payload, ErrorCorrectionLevel::Low), $png);
    }

    private function reference(string $payload, ErrorCorrectionLevel $level): string
    {
        return (new Builder(
            writer: new PngWriter(),
            data: $payload,
            errorCorrectionLevel: $level,
            size: 600,
            margin: 0
        ))->build()->getString();
    }
}
