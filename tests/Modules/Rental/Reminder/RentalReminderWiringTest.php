<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Reminder;

use PHPUnit\Framework\TestCase;

/**
 * The reminder task's wiring, and the cron warning (§6.29).
 *
 * Both live where no unit test reaches them — the procedural bootstrap and
 * a Twig template — so both are pinned at the source level, the precedent
 * `tests/Security/` sets for exactly this.
 *
 * The bug this file exists to prevent has shipped in this codebase before:
 * `create_backup` was registered in `public/index.php` and absent from
 * `public/cron.php`'s hand-maintained list, so every backup under a real
 * crontab failed silently with "No handler registered" (§8.17/§8.20).
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
    public function testTheReminderHandlerIsRegisteredInBothEntryPoints(string $file): void
    {
        $this->assertStringContainsString(
            'Modules\\Rental\\Task\\SendRentalRemindersHandler::TASK_KEY',
            self::source($file),
            $file . ' must register the rentals reminder handler.'
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('entryPoints')]
    public function testBothEntryPointsGuardOnTheModuleBeingEnabled(string $file): void
    {
        $this->assertMatchesRegularExpression(
            "/in_array\\('rental', \\\$moduleManager->getEnabledModuleIds\\(\\), true\\)/",
            self::source($file),
            $file . ' must only wire the reminder handler when rental is enabled.'
        );
    }

    public function testTheFirstRunIsBootstrappedOnTheWebPath(): void
    {
        // Without the initial nudge the self-rescheduling chain never
        // starts, and the reminders go out exactly never.
        $this->assertStringContainsString(
            'Modules\\Rental\\Task\\SendRentalRemindersHandler::bootstrap($schedulerService)',
            self::source('index.php')
        );
    }

    public function testTheWebPathHandsTheHandlerAFullyWiredService(): void
    {
        // The money reminders need Finance's public API, which only a
        // composition root can build. Auto-resolved from the manifest
        // instead, the handler builds its own service and stays silent
        // about money rather than guessing.
        $this->assertMatchesRegularExpression(
            '/new \\\\Modules\\\\Rental\\\\Task\\\\SendRentalRemindersHandler\\(\\$rentalReminderService\\)/',
            self::source('index.php')
        );
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
