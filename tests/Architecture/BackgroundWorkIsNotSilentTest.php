<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * A scheduled task is the one place in this application where something
 * happens and nobody is watching.
 *
 * Everything else has a screen: a form says what it refused, a controller
 * flashes a message, a request that throws shows an error page. A task
 * runs at four in the morning, and whatever it decided is gone the moment
 * it returns — unless it wrote it down. The complaint this test exists for
 * was exactly that shape: mail sent to a mailbox for analysis, nothing
 * created, and the event journal — the one place anybody looks next, and
 * the one thing the diagnostic archive carries — with not a line about it.
 * A box that never synchronised, a module never allowed to look, a module
 * that looked and declined, and a module that crashed all produced the
 * same silence.
 *
 * So: a task handler must reach the journal, directly or through a service
 * it hands `$context->journal` to. The allowlist below is for the handlers
 * where silence is the right answer, each with the reason — and having to
 * write that reason is most of what this test is for.
 *
 * It does not check that the entries are GOOD. It checks that the door
 * exists, which is the failure that actually happened.
 */
class BackgroundWorkIsNotSilentTest extends TestCase
{
    /**
     * Handlers that deliberately write nothing, and why.
     *
     * Adding a name here is a decision to make something invisible. It
     * should be true of every entry that a unit could never ask a question
     * the journal would have answered.
     *
     * @var array<string, string>
     */
    private const SILENT_BY_DESIGN = [
        // Rate-limit rows and cached answers are counters with an expiry,
        // not events. Nobody has ever asked why one was deleted, and a
        // nightly line per module saying so would be pure housekeeping.
        'core/Help/Assistant/Task/PurgeHelpAssistantHandler.php' => 'rate-limit window and answer cache, not events',
        'core/Security/HumanCheck/Task/PurgeHumanCheckRateLimitsHandler.php' => 'rate-limit counters, not events',
        'modules/groups/src/Task/PurgeRateLimitHandler.php' => 'rate-limit counters, not events',
        'modules/retro/src/Task/PurgeRateLimitHandler.php' => 'rate-limit counters, not events',
        'modules/support_dashboard/src/Task/PurgeRateLimitsHandler.php' => 'rate-limit counters, not events',
        // A null object: it exists so a module without a link-preview
        // fetcher needs no branch. It performs no work to report.
        'modules/groups/src/Task/NullLinkPreviewFetcher.php' => 'null object, does no work',
    ];

    /**
     * Handlers that write nothing THEMSELVES because the service they hand
     * the work to writes it — and the class that does.
     *
     * The route is named rather than assumed, and the test follows it: a
     * handler listed here fails just as loudly if the class it points at
     * stops reaching the journal. Delegation is the honest reason a
     * handler is short; it is not a reason for the work to be invisible.
     *
     * @var array<string, string>
     */
    private const WRITTEN_BY = [
        'core/Notification/Task/SendNotificationsHandler.php' => 'core/Notification/NotificationService.php',
        'core/Statistics/Task/SendStatisticsHandler.php' => 'core/Statistics/StatisticsSender.php',
        'core/View/Task/GenerateRgpdContentHandler.php' => 'core/View/RgpdGenerationRunner.php',
        'modules/calendar/src/Task/EventReminderHandler.php' => 'core/Notification/NotificationService.php',
        'modules/groups/src/Task/CloseInactiveGroupsHandler.php'
            => 'modules/groups/src/Service/GroupLifecycleService.php',
        'modules/groups/src/Task/PurgeClosedGroupsHandler.php'
            => 'modules/groups/src/Service/GroupLifecycleService.php',
        'modules/groups/src/Task/PurgePostsHandler.php'
            => 'modules/groups/src/Service/GroupLifecycleService.php',
    ];

    /**
     * @return array<string, array{string}>
     */
    public static function handlers(): array
    {
        $root = dirname(__DIR__, 2);
        $cases = [];

        foreach ([$root . '/core', $root . '/modules'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                if (!str_contains(str_replace('\\', '/', $file->getPathname()), '/Task/')) {
                    continue;
                }

                $relative = ltrim(str_replace($root, '', str_replace('\\', '/', $file->getPathname())), '/');
                $cases[$relative] = [$relative];
            }
        }

        ksort($cases);

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('handlers')]
    public function testEveryTaskHandlerCanReachTheJournal(string $relativePath): void
    {
        if (isset(self::SILENT_BY_DESIGN[$relativePath])) {
            $this->assertNotSame('', self::SILENT_BY_DESIGN[$relativePath]);

            return;
        }

        $writer = self::WRITTEN_BY[$relativePath] ?? $relativePath;

        $this->assertMatchesRegularExpression(
            '/journal|Journal/',
            self::source($writer),
            $relativePath . " runs in the background and can reach the journal by no route at all.\n"
            . "Either write what it decided — a task that acts and says nothing cannot be investigated —\n"
            . "or name the service that writes it in WRITTEN_BY,\n"
            . 'or add it to SILENT_BY_DESIGN with the reason it never needs to be.'
        );
    }

    /**
     * A named writer that no longer exists is a route this test would
     * otherwise follow into thin air and report as green.
     */
    public function testEveryNamedWriterStillExists(): void
    {
        $missing = [];
        foreach (self::WRITTEN_BY as $handler => $writer) {
            if (!is_file(dirname(__DIR__, 2) . '/' . $writer)) {
                $missing[] = $handler . ' → ' . $writer;
            }
        }

        $this->assertSame([], $missing);
    }

    private static function source(string $relativePath): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
    }
}
