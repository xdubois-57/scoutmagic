<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Modules\Finance\Service\ReceiptDateNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Relocated from Tests\Modules\Finance\Task\ExtractReceiptDataHandlerTest,
 * where these cases used to reach a private method by reflection — the
 * normalization is now shared with Controller\ReceiptController::update(),
 * so it has its own class and its own direct tests.
 */
class ReceiptDateNormalizerTest extends TestCase
{
    public function testAcceptsIsoDate(): void
    {
        $this->assertSame('2026-10-27', ReceiptDateNormalizer::normalize('2026-10-27'));
    }

    public function testAcceptsIsoDatetimeWithTimeComponent(): void
    {
        $this->assertSame('2026-10-27', ReceiptDateNormalizer::normalize('2026-10-27T00:00:00'));
    }

    public function testAcceptsEuropeanSlashFormat(): void
    {
        $this->assertSame('2026-10-27', ReceiptDateNormalizer::normalize('27/10/2026'));
    }

    public function testAcceptsEuropeanDashFormat(): void
    {
        $this->assertSame('2026-10-27', ReceiptDateNormalizer::normalize('27-10-2026'));
    }

    public function testAcceptsEuropeanDotFormat(): void
    {
        $this->assertSame('2026-10-27', ReceiptDateNormalizer::normalize('27.10.2026'));
    }

    public function testAcceptsSingleDigitDayAndMonth(): void
    {
        $this->assertSame('2026-01-05', ReceiptDateNormalizer::normalize('5/1/2026'));
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        $this->assertSame('2026-10-27', ReceiptDateNormalizer::normalize("  2026-10-27\n"));
    }

    public function testRejectsAnImpossibleCalendarDate(): void
    {
        $this->assertNull(ReceiptDateNormalizer::normalize('30/02/2026'));
    }

    public function testRejectsAnImpossibleIsoCalendarDate(): void
    {
        $this->assertNull(ReceiptDateNormalizer::normalize('2026-02-30'));
    }

    public function testRejectsUnrecognizableText(): void
    {
        $this->assertNull(ReceiptDateNormalizer::normalize('27 octobre 2026'));
    }

    public function testRejectsEmptyString(): void
    {
        $this->assertNull(ReceiptDateNormalizer::normalize(''));
    }

    public function testRejectsWhitespaceOnlyString(): void
    {
        $this->assertNull(ReceiptDateNormalizer::normalize("   \t "));
    }

    /**
     * The value reaches a DATE column and, more dangerously,
     * new \DateTimeImmutable() in Service\ReceiptMatchingService — a
     * stray expression must never survive this far. Anything trailing a
     * valid ISO prefix is discarded rather than carried along (the ISO
     * branch is deliberately unanchored at the end so it also accepts a
     * datetime), so what comes out is always a bare YYYY-MM-DD or null.
     */
    public function testNeverEmitsAnythingButABareIsoDateOrNull(): void
    {
        $this->assertSame(
            '2026-10-27',
            ReceiptDateNormalizer::normalize("2026-10-27'; DROP TABLE finance_attachments; --")
        );
        $this->assertNull(ReceiptDateNormalizer::normalize('now'));
        $this->assertNull(ReceiptDateNormalizer::normalize('+1 day'));
        $this->assertNull(ReceiptDateNormalizer::normalize('0000-00-00'));
    }

    /**
     * Every accepted value must survive new \DateTimeImmutable() — that is
     * the whole reason this normalization exists.
     */
    public function testEveryAcceptedValueIsConstructibleAsADate(): void
    {
        foreach (['2026-10-27', '27/10/2026', '5/1/2026', '2026-10-27T13:45:00'] as $raw) {
            $normalized = ReceiptDateNormalizer::normalize($raw);
            $this->assertNotNull($normalized, $raw);
            $this->assertSame($normalized, (new \DateTimeImmutable($normalized))->format('Y-m-d'), $raw);
        }
    }
}
