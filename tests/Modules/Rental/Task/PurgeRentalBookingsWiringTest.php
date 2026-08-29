<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Task;

use PHPUnit\Framework\TestCase;

/**
 * The retention purge's wiring (§6.35).
 *
 * The purge deletes a booking's Finance receivables and its inbound-mail
 * correspondence — cross-module needs that now arrive as capabilities
 * (TaskContext::getOptional()), so the handler is auto-resolved from the
 * manifest and builds its full service itself, identically under both
 * entry points. What this file pins is that nothing re-grows a
 * per-entry-point hand wiring (the shape of the `create_backup` bug,
 * §8.17/§8.20), that the self-build really reads the capabilities, and
 * that the one Finance call the purge exists to make is still made.
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
    public function testNoEntryPointHandRegistersThePurgeHandlerAnyMore(string $file): void
    {
        // A hand registration re-grown in one entry point would replace
        // the capability-fed self-build with whatever that one file wired
        // — the exact per-entry-point divergence this iteration removed
        // (public/cron.php's construction could never reach inbound_mail,
        // leaving a purged booking's emails behind under a real crontab).
        $this->assertStringNotContainsString(
            'new \\Modules\\Rental\\Task\\PurgeRentalBookingsHandler(',
            self::source($file),
            $file . ' must leave the purge handler to manifest auto-resolution.'
        );
    }

    public function testTheManifestDeclaresTheHandlerSoAutoResolutionFindsIt(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/modules/rental/module.json'),
            true
        );
        $handlers = array_column($manifest['scheduled_tasks'] ?? [], 'handler', 'key');

        $this->assertSame(
            \Modules\Rental\Task\PurgeRentalBookingsHandler::class,
            $handlers[\Modules\Rental\Task\PurgeRentalBookingsHandler::TASK_KEY] ?? null
        );
    }

    public function testTheFirstRunIsSeededByTheSharedBootstrap(): void
    {
        $this->assertStringContainsString(
            'Modules\\Rental\\Task\\PurgeRentalBookingsHandler::bootstrap($schedulerService)',
            self::source('scheduler-bootstrap.php')
        );
    }

    public function testTheSelfBuiltServiceReadsTheFinanceAndInboundMailCapabilities(): void
    {
        // A purged booking must lose its receivables AND its mail on both
        // entry points — from one single construction reading the
        // capabilities, never a per-entry-point wiring decision.
        $handler = file_get_contents(
            dirname(__DIR__, 4) . '/modules/rental/src/Task/PurgeRentalBookingsHandler.php'
        );
        $this->assertNotFalse($handler);

        foreach ([
            'Modules\\Finance\\Api\\ExpectedReceivableInterface::class',
            'Modules\\InboundMail\\Api\\InboundMailInterface::class',
        ] as $capability) {
            $this->assertStringContainsString(
                '$context->getOptional(\\' . $capability . ')',
                $handler,
                'The self-built service must read ' . $capability . ' off the context.'
            );
        }
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
