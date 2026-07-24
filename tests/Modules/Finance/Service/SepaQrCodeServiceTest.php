<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

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
}
