<?php

declare(strict_types=1);

namespace Tests\Core\View;

use Core\Security\HumanCheck\HumanCheckChallenge;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * partials/human_check_fields.html.twig — same rendering-only precedent as
 * Tests\Modules\Calendar\CalendarPickerRenderingTest. Checks the two
 * properties the module spec calls out explicitly: a robot's DOM/form
 * scanner reaches the trap field (it's a real <input>, not type="hidden"),
 * while a human on keyboard or a screen reader never does.
 */
class HumanCheckFieldsRenderingTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $coreTemplates = dirname(__DIR__, 3) . '/core/View/templates';
        $loader = new FilesystemLoader($coreTemplates);
        $this->twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);
    }

    public function testRendersATrapFieldReachableByARobotButNotByKeyboardOrScreenReader(): void
    {
        $challenge = new HumanCheckChallenge('hc_abc123', 'signed-token-value');

        $html = $this->twig->render('partials/human_check_fields.html.twig', ['human_check' => $challenge]);

        // Reachable by a robot: a real, non-hidden text input under the
        // trap field's name — never type="hidden", which bots skip.
        $this->assertMatchesRegularExpression('/<input[^>]*id="hc_abc123"[^>]*>/', $html);
        $this->assertStringContainsString('name="hc_abc123"', $html);
        preg_match('/<input[^>]*id="hc_abc123"[^>]*>/', $html, $trapInputMatch);
        $this->assertNotEmpty($trapInputMatch);
        $this->assertStringContainsString('type="text"', $trapInputMatch[0]);
        $this->assertStringNotContainsString('type="hidden"', $trapInputMatch[0]);
        $this->assertStringNotContainsString('style="display', $html);

        // Unreachable by keyboard navigation or a screen reader.
        $this->assertStringContainsString('tabindex="-1"', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringContainsString('class="hc-trap"', $html);
        $this->assertStringContainsString('autocomplete="off"', $html);

        // The signed token travels alongside it.
        $this->assertStringContainsString('name="human_check_token"', $html);
        $this->assertStringContainsString('value="signed-token-value"', $html);
    }

    public function testRendersNothingWhenChallengeIsNull(): void
    {
        $html = $this->twig->render('partials/human_check_fields.html.twig', ['human_check' => null]);

        $this->assertSame('', trim($html));
    }
}
