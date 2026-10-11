<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\View;

use Core\View\TwigFactory;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

/**
 * `phone()` and the sheet it opens (docs/chantiers/CHANTIER-telephone.md,
 * IT-02), rendered through the real Twig environment.
 */
final class PhoneExtensionTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $this->twig = TwigFactory::create(dirname(__DIR__, 3) . '/core/View/templates');
    }

    private function render(string $template, array $context = []): string
    {
        return $this->twig->createTemplate($template)->render($context);
    }

    public function testANumberIsATelLinkCarryingWhatItIs(): void
    {
        $html = $this->render('{{ phone(n) }}', ['n' => '0472/54.67.71']);

        $this->assertSame(
            '<a href="tel:+32472546771" class="phone-link" data-phone="+32472546771"'
            . ' data-phone-display="+32 472 54 67 71" data-phone-kind="mobile">+32 472 54 67 71</a>',
            $html
        );
    }

    public function testALandlineAndAForeignNumberSayWhatTheyAre(): void
    {
        $this->assertStringContainsString('data-phone-kind="landline"', $this->render('{{ phone("02/123.45.67") }}'));
        $this->assertStringContainsString('data-phone-kind="foreign"', $this->render('{{ phone("0033 6 12 34 56 78") }}'));
        $this->assertStringContainsString('href="tel:+33612345678"', $this->render('{{ phone("0033 6 12 34 56 78") }}'));
    }

    public function testATemplateCanAddItsOwnClasses(): void
    {
        $this->assertStringContainsString(
            'class="phone-link text-decoration-none"',
            $this->render('{{ phone(n, "text-decoration-none") }}', ['n' => '0472546771'])
        );
    }

    /**
     * An empty number is no link around nothing: the page shows whatever
     * it shows for a missing value.
     */
    public function testAnEmptyNumberRendersNothing(): void
    {
        $this->assertSame('', $this->render('{{ phone(n) }}', ['n' => null]));
        $this->assertSame('', $this->render('{{ phone(n) }}', ['n' => '']));
        $this->assertSame('', $this->render('{{ phone(n) }}', ['n' => '   ']));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notOneNumber(): array
    {
        return [
            'two numbers joined by a word' => ['0475 12 34 56 ou 02 123 45 67'],
            'a number and a note' => ['+33 6 12 34 56 78 (après 18h)'],
            'two numbers joined by a slash' => ['0475 12 34 56 / 02 123 45 67'],
            'too short to be a number' => ['112'],
            'no digit at all, which a template\'s if lets through' => ['- /'],
            'words only' => ['voir avec le chef'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notOneNumber')]
    public function testAValueThatIsNotOneNumberIsShownAsTypedWithoutALink(string $raw): void
    {
        $html = $this->render('{{ phone(n) }}', ['n' => $raw]);

        $this->assertStringNotContainsString('<a', $html);
        $this->assertSame(htmlspecialchars($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $html);
    }

    public function testATypedValueIsEscaped(): void
    {
        $this->assertSame(
            '&lt;b&gt;0475&lt;/b&gt;',
            $this->render('{{ phone(n) }}', ['n' => '<b>0475</b>'])
        );
    }

    public function testTheLongestNumbersStillAreNumbers(): void
    {
        $this->assertStringContainsString('href="tel:+352691123456"', $this->render('{{ phone("00352 691 123 456") }}'));
        $this->assertStringContainsString('href="tel:+3281223344"', $this->render('{{ phone("(081) 22.33.44") }}'));
    }

    public function testTheClassArgumentIsEscaped(): void
    {
        $html = $this->render('{{ phone(n, c) }}', ['n' => '0472546771', 'c' => '"><script>x</script>']);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&quot;&gt;&lt;script&gt;', $html);
    }

    /**
     * D2's order, the icons of the chantier, and D12: neither WhatsApp nor
     * Signal learns which page of the unit's site the tap came from.
     */
    public function testTheSheetOffersItsActionsInOrderWithoutAReferrer(): void
    {
        $html = $this->twig->render('partials/phone_sheet.html.twig');

        preg_match_all('/data-phone-target="(\w+)"/', $html, $targets);
        $this->assertSame(['tel', 'sms', 'whatsapp', 'signal'], $targets[1]);
        $this->assertGreaterThan(strpos($html, 'data-phone-target="signal"'), strpos($html, 'data-phone-copy'));

        foreach (['bi-telephone', 'bi-chat-dots', 'bi-whatsapp', 'bi-signal', 'bi-clipboard'] as $icon) {
            $this->assertStringContainsString($icon, $html);
        }
        foreach (['whatsapp', 'signal'] as $external) {
            $this->assertMatchesRegularExpression(
                '/data-phone-target="' . $external . '"[^>]*rel="noopener noreferrer"/s',
                $html
            );
        }
        $this->assertStringContainsString('Copier le numéro', $html);
        $this->assertStringContainsString('Un numéro fixe ne reçoit ni SMS ni message', $html);
    }

    public function testEveryPageCarriesTheSheetAndItsScript(): void
    {
        $base = (string) file_get_contents(dirname(__DIR__, 3) . '/core/View/templates/base.html.twig');

        $this->assertStringContainsString("{% include 'partials/phone_sheet.html.twig' %}", $base);
        $this->assertStringContainsString("asset('/assets/js/phone-link.js')", $base);
    }
}
