<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Task;

use PHPUnit\Framework\TestCase;

/**
 * The retention purge's wiring (§6.35).
 *
 * The purge deletes a booking's Finance receivables, and Finance is only
 * reachable from a composition root — the handler's self-built fallback
 * cannot see whether that module is even enabled. So the service has to be
 * assembled in **both** entry points, and both are pinned here at the
 * source level, exactly as `RentalReminderWiringTest` pins the reminder
 * task. A handler wired in one entry point only is the shape of a bug this
 * codebase has already shipped once (`create_backup`, §8.17/§8.20).
 */
class PurgeRentalBookingsWiringTest extends TestCase
{
    private static function source(string $file): string
    {
        $contents = file_get_contents(dirname(__DIR__, 4) . '/public/' . $file);
        self::assertNotFalse($contents);

        return $contents;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function entryPoints(): array
    {
        return ['web' => ['index.php'], 'cron' => ['cron.php']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('entryPoints')]
    public function testThePurgeHandlerIsRegisteredInBothEntryPoints(string $file): void
    {
        $this->assertStringContainsString(
            'Modules\\Rental\\Task\\PurgeRentalBookingsHandler::TASK_KEY',
            self::source($file),
            $file . ' must register the rentals retention purge handler.'
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('entryPoints')]
    public function testBothEntryPointsBuildTheRetentionServiceThemselves(string $file): void
    {
        // The handler's self-built fallback reaches neither `inbound_mail`
        // nor Finance. Left to it, a purged booking keeps its emails and
        // its receivables — the deletion would be incomplete in the two
        // ways that matter.
        $this->assertStringContainsString(
            'new \\Modules\\Rental\\Service\\RentalRetentionService(',
            self::source($file),
            $file . ' must build the retention service rather than let the handler self-build it.'
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('entryPoints')]
    public function testBothEntryPointsHandThePurgeAPaymentService(string $file): void
    {
        $this->assertMatchesRegularExpression(
            '/new \\\\Modules\\\\Rental\\\\Service\\\\RentalPaymentService\\(|\\$rentalPaymentService/',
            self::source($file),
            $file . ' must give the retention service a payment service so receivables are forgotten.'
        );
    }

    public function testThePurgeForgetsTheBookingsReceivables(): void
    {
        // The one call that reaches Finance. `forgetBooking()` had no
        // production caller at all before this: every purged booking left
        // its receivable behind, and the unit went on being owed money for
        // a stay that no longer existed.
        $service = file_get_contents(
            dirname(__DIR__, 4) . '/modules/rental/src/Service/RentalRetentionService.php'
        );
        $this->assertNotFalse($service);

        $this->assertStringContainsString('$this->payments?->forgetBooking($booking->id)', $service);
    }
}
