<?php

declare(strict_types=1);

namespace Tests\Core\Mail;

use Core\Mail\SendTracking;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a send origin may be (issue #1454): a short plain reference, stored
 * as it is beside a deferred message, never anything that could carry an
 * address — and never one that merely LOOKS valid to a regex.
 */
final class SendTrackingTest extends TestCase
{
    public function testAnOrdinaryOriginAndNoneAtAllAreAccepted(): void
    {
        $this->assertSame('mass_mail.delivery:42', (new SendTracking('mass_mail.delivery:42'))->origin);
        $this->assertSame(
            str_repeat('a', SendTracking::ORIGIN_MAX),
            (new SendTracking(str_repeat('a', SendTracking::ORIGIN_MAX)))->origin
        );
        $this->assertNull((new SendTracking())->origin);
    }

    /**
     * **A final newline is not « the end ».** `$` matches just before one,
     * so a full-width origin followed by "\n" passed the check and then
     * reached a column one character too narrow for it.
     *
     * @return iterable<string, array{string}>
     */
    public static function invalidOrigins(): iterable
    {
        yield 'full width plus a newline' => [str_repeat('a', SendTracking::ORIGIN_MAX) . "\n"];
        yield 'a short one plus a newline' => ["mass_mail.delivery:4\n"];
        yield 'too long' => [str_repeat('a', SendTracking::ORIGIN_MAX + 1)];
        yield 'empty' => [''];
        yield 'an address' => ['parent@exemple.test'];
        yield 'upper case' => ['Mass_mail.delivery:4'];
    }

    #[DataProvider('invalidOrigins')]
    public function testAnOriginOutsideTheAlphabetIsRefused(string $origin): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SendTracking($origin);
    }
}
