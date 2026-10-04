<?php

declare(strict_types=1);

namespace Tests\Modules\News\Controller;

use PHPUnit\Framework\TestCase;
use Tests\TestTwig;
use Twig\Environment;

/**
 * The daily digest's recipient, as a form's settings present it (issue
 * #738).
 *
 * It was a switch, and a switch could say that somebody wanted the digest
 * and never where to send it: it went to the article's author, derived
 * from `created_by` on every run, so nobody could change it without
 * changing the author — and the registrations of an event usually want a
 * function's mailbox (`intendance@…`) rather than whoever typed the
 * article.
 *
 * Reading the rendered partial rather than the template file: what matters
 * is what reaches the browser, and a `digest_email_value` that stopped
 * being handed over would still look right in the source.
 */
final class FormDigestEmailFieldTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $this->twig = TestTwig::create(['news' => dirname(__DIR__, 4) . '/modules/news/views']);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(array $context = []): string
    {
        return $this->twig->render('@news/partials/_form_settings.html.twig', $context + [
            'form' => null,
            'cron_detected' => true,
        ]);
    }

    public function testTheSwitchIsGoneAndAnAddressFieldStandsInItsPlace(): void
    {
        $html = $this->render();

        $this->assertStringNotContainsString(
            'form_daily_digest_enabled',
            $html,
            'the daily-digest switch is back in the interface, which issue #738 removed: it could '
            . 'only say that a digest was wanted, never where to send it.'
        );
        $this->assertStringContainsString('name="form_digest_email"', $html);
        $this->assertStringContainsString('type="email"', $html);
    }

    /**
     * The address of an existing form, not the creator's: once the form
     * exists the field is its own setting, and that is what makes
     * changing the article's author leave it alone.
     */
    public function testAnExistingFormShowsTheAddressItCarries(): void
    {
        $html = $this->render(['digest_email_value' => 'intendance@unite.test']);

        $this->assertStringContainsString('value="intendance@unite.test"', $html);
    }

    /**
     * An empty field is the off position, and the help text has to say so
     * — otherwise it reads as a field somebody forgot to fill rather than
     * as a choice.
     */
    public function testTheHelpTextSaysThatAnEmptyFieldMeansNoEmail(): void
    {
        $html = $this->render(['digest_email_value' => '']);

        $this->assertStringContainsString('value=""', $html);
        $this->assertStringContainsString('Laissez ce champ vide pour ne pas recevoir d\'e-mail.', $html);
    }

    /**
     * The field is described by its help text for a screen reader, not
     * only visually next to it.
     */
    public function testTheFieldIsProgrammaticallyDescribedByItsHelpText(): void
    {
        $html = $this->render();

        $this->assertMatchesRegularExpression(
            '/id="form_digest_email"[^>]*aria-describedby="form_digest_email_help"/',
            $html
        );
        $this->assertStringContainsString('id="form_digest_email_help"', $html);
    }

    /**
     * The poor man's cron warning stays, and says what it now means: the
     * address is saved and nothing goes out, rather than « the box stays
     * ticked » (issue #248's wording, which described the switch).
     */
    public function testTheCronWarningDescribesTheAddressRatherThanATickedBox(): void
    {
        // `cron_detected: false` — the warning renders only when no real
        // crontab was found, which is the whole point of it.
        $html = $this->render(['cron_detected' => false]);

        // Without the leading « L' »: the consequence reaches the page
        // through `{{ consequence }}`, which Twig escapes, so the
        // apostrophe renders as `&#039;`. What this test is about is the
        // sentence, not its encoding.
        $this->assertStringContainsString('adresse ci-dessus reste enregistrée', $html);
        $this->assertStringNotContainsString('reste cochée', $html);
    }
}
