<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Every configuration screen whose feature depends on an INSTANT warns
 * its administrator when no real crontab is detected (issue #248).
 *
 * Under the poor man's cron a scheduled task runs at the occasion of a
 * visit to the site. Three screens said so; five that needed it did not
 * — including the SOS duty redirection, which switches an emergency
 * number at a programmed moment. This test is what stops the next timed
 * feature shipping silent: it names the screen AND its controller, since
 * a template asking for `cron_detected` that nobody passes renders
 * nothing at all under Twig's strict_variables=false.
 */
class TimedFeaturesWarnAboutCronTest extends TestCase
{
    /**
     * Screen => the controller that renders it. The three screens that
     * carried the warning before this test existed are here too: they
     * are the reason it is worth having, and a regression in them would
     * be as silent as the five absences were.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function screens(): array
    {
        return [
            'SOS — la redirection de garde' => [
                'modules/sos_staff/views/config.html.twig',
                'modules/sos_staff/src/Controller/SosConfigController.php',
            ],
            'Calendrier — les rappels multi-jours' => [
                'modules/calendar/views/config.html.twig',
                'modules/calendar/src/Controller/CalendarConfigController.php',
            ],
            'Camps — relecture et géocodage' => [
                'modules/camps/views/config.html.twig',
                'modules/camps/src/Controller/CampsConfigController.php',
            ],
            'Actualités — le résumé quotidien' => [
                'modules/news/views/partials/_form_settings.html.twig',
                'modules/news/src/Controller/NewsController.php',
            ],
            'Support — le rapport quotidien' => [
                'core/View/templates/config/support.html.twig',
                'core/Http/Controller/SupportController.php',
            ],
            'Locations — les rappels' => [
                'modules/rental/views/config/index.html.twig',
                'modules/rental/src/Controller/RentalConfigController.php',
            ],
            'Courrier entrant — la relève' => [
                'modules/inbound_mail/views/config/index.html.twig',
                'modules/inbound_mail/src/Controller/InboundMailConfigController.php',
            ],
            'Notifications — les envois différés' => [
                'core/View/templates/config/notifications.html.twig',
                'core/Http/Controller/NotificationConfigController.php',
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('screens')]
    public function testTheScreenRendersTheWarning(string $template, string $controller): void
    {
        $source = self::read($template);

        $this->assertStringContainsString(
            'cron_detected',
            $source,
            "{$template} configures a feature that depends on an instant and never mentions the crontab."
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('screens')]
    public function testTheControllerActuallyPassesTheVerdict(string $template, string $controller): void
    {
        $source = self::read($controller);

        $this->assertStringContainsString(
            "'cron_detected'",
            $source,
            "{$controller} renders a screen that reads cron_detected but never passes it — under "
            . 'strict_variables=false that renders as « tout va bien », silently.'
        );
    }

    /**
     * The five screens added by #248 go through the shared partial rather
     * than each restating the warning: eight wordings of one fact is how
     * five of them came to say nothing at all.
     */
    public function testTheScreensAddedByIssue248ShareOneWording(): void
    {
        foreach ([
            'modules/sos_staff/views/config.html.twig',
            'modules/calendar/views/config.html.twig',
            'modules/camps/views/config.html.twig',
            'modules/news/views/partials/_form_settings.html.twig',
            'core/View/templates/config/support.html.twig',
        ] as $template) {
            $this->assertStringContainsString(
                "partials/cron_warning.html.twig",
                self::read($template),
                "{$template} must include the shared warning rather than word its own."
            );
        }
    }

    public function testTheSharedPartialNamesTheCommandToConfigure(): void
    {
        $partial = self::read('core/View/templates/partials/cron_warning.html.twig');

        $this->assertStringContainsString('cron.php', $partial);
        $this->assertStringContainsString("visite du site", $partial);
    }

    private static function read(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
