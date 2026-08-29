<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Reminder;

use PHPUnit\Framework\TestCase;

/**
 * The reminder task's wiring, and the cron warning (§6.29).
 *
 * Both live where no unit test reaches them — the composition roots and a
 * Twig template — so both are pinned at the source level, the precedent
 * `tests/Security/` sets for exactly this.
 *
 * The handler is auto-resolved from the manifest and builds its full
 * service itself, reaching Finance through TaskContext::getOptional() —
 * so there is exactly ONE construction and the drift this file used to
 * guard against (a crontab-run reminder pass assembled WITHOUT Finance
 * while the web path assembled it WITH, §8.17-class) cannot recur. What
 * remains to pin: nothing re-grew a hand registration, the self-build
 * really reads the capabilities, and the first run is still seeded.
 */
class RentalReminderWiringTest extends TestCase
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
    public function testNoEntryPointHandRegistersTheReminderHandlerAnyMore(string $file): void
    {
        // A hand registration re-grown in one entry point would replace
        // the capability-fed self-build with whatever that one file wired
        // — the exact per-entry-point divergence this iteration removed.
        $this->assertStringNotContainsString(
            'new \\Modules\\Rental\\Task\\SendRentalRemindersHandler(',
            self::source($file),
            $file . ' must leave the reminder handler to manifest auto-resolution.'
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
            \Modules\Rental\Task\SendRentalRemindersHandler::class,
            $handlers[\Modules\Rental\Task\SendRentalRemindersHandler::TASK_KEY] ?? null
        );
    }

    public function testTheFirstRunIsSeededByTheSharedBootstrap(): void
    {
        // Without the initial nudge the self-rescheduling chain never
        // starts, and the reminders go out exactly never. Seeded in the
        // shared scheduler bootstrap, so a site reached only by its
        // crontab still gets them.
        $this->assertStringContainsString(
            'Modules\\Rental\\Task\\SendRentalRemindersHandler::bootstrap($schedulerService)',
            self::source('scheduler-bootstrap.php')
        );
    }

    public function testTheSelfBuiltServiceReadsTheFinanceCapabilities(): void
    {
        // The money reminders exist exactly when Finance is enabled, on
        // BOTH entry points, because the one construction reads the
        // capability — never a per-entry-point wiring decision.
        $handler = file_get_contents(
            dirname(__DIR__, 4) . '/modules/rental/src/Task/SendRentalRemindersHandler.php'
        );
        $this->assertNotFalse($handler);

        foreach ([
            'Modules\\Finance\\Api\\ExpectedReceivableInterface::class',
            'Modules\\Finance\\Api\\StructuredCommunicationInterface::class',
            'Modules\\Finance\\Api\\SepaQrCodeInterface::class',
            'Modules\\Finance\\Api\\FinanceAccountInterface::class',
        ] as $capability) {
            $this->assertStringContainsString(
                '$context->getOptional(\\' . $capability . ')',
                $handler,
                'The self-built service must read ' . $capability . ' off the context.'
            );
        }
    }

    // ── The cron warning (§6.29) ────────────────────────────────────────

    public function testTheConfigurationPageWarnsWhenNoRealCronIsDetected(): void
    {
        // On shared hosting without a crontab the reminders still go out,
        // but hours late — and a unit that does not know that reads the
        // delay as a bug. Saying it is the whole point.
        $template = file_get_contents(
            dirname(__DIR__, 4) . '/modules/rental/views/config/index.html.twig'
        );
        $this->assertNotFalse($template);

        $this->assertStringContainsString('{% if not cron_detected %}', $template);
        $this->assertStringContainsString('cron.php', $template);
    }

    public function testTheWarningUsesTheSameSignalAsThePushNotificationPage(): void
    {
        // `cron_last_run` is stamped only by public/cron.php, never by a web
        // request — which is exactly what makes it able to tell a real
        // crontab from the request-driven scheduler standing in for one.
        $controller = file_get_contents(
            dirname(__DIR__, 4) . '/modules/rental/src/Controller/RentalConfigController.php'
        );
        $this->assertNotFalse($controller);

        $this->assertStringContainsString("'cron_last_run'", $controller);
        $this->assertStringContainsString('cron_detected', $controller);
    }

    public function testTheCronStampIsNeverWrittenByAWebRequest(): void
    {
        // If it were, the warning would never fire and the page would
        // reassure every unit that their cron is fine.
        $index = self::source('index.php');

        $this->assertDoesNotMatchRegularExpression(
            "/set\\(\\s*'cron_last_run'/",
            $index,
            'Only public/cron.php may stamp cron_last_run.'
        );
    }
}
