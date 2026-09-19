<?php

declare(strict_types=1);

namespace Tests\Modules\Finance;

use Core\View\TwigFactory;
use PHPUnit\Framework\TestCase;

/**
 * Full-page render of modules/finance/views/receipts/form.html.twig, in
 * both the shapes that one template renders.
 *
 * **What this holds that the Vitest suite cannot.**
 * `tests/js/finance-receipt-form.test.js` exercises the real script, but
 * it builds its own DOM — a hand-written copy of what this template
 * emits. Everything the script never reads is therefore invisible to it,
 * and `aria-live` is exactly that: the script writes text into the status
 * line without caring whether anyone is told. So the attribute that makes
 * « Envoi en cours… » reach a screen reader has no coverage on that side
 * at all, and would be removed by a tidy-up with every test still green.
 *
 * Issue #364 asked for an indication that the click had landed. The
 * visible half is the button — a spinner and a changed label, asserted in
 * the Vitest suite. This is the other half: the same notice, announced.
 * A disabled control is not reliably re-announced by assistive
 * technology, so the button's own label is not enough on its own.
 */
class ReceiptFormRenderingTest extends TestCase
{
    private function render(bool $replace): string
    {
        $templateDir = dirname(__DIR__, 3) . '/core/View/templates';
        $moduleViews = dirname(__DIR__, 3) . '/modules/finance/views';
        $twig = TwigFactory::create($templateDir, true, ['finance' => $moduleViews]);
        $twig->addGlobal('site_name', 'Test Unité');
        $twig->addGlobal('menus', null);
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'test@example.com');
        $twig->addGlobal('current_user_role', 'admin');
        $twig->addGlobal('current_path', '/finance/receipts/new');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('csp_nonce', 'n');
        $twig->addGlobal('effective_scout_year_id', 1);

        $account = (object) ['id' => 1, 'name' => 'Compte courant'];

        return $twig->render('@finance/receipts/form.html.twig', [
            'replace_id' => $replace ? 12 : null,
            'error' => null,
            'accounts' => [$account],
            'selected_account' => $account,
        ]);
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function bothShapes(): array
    {
        return [
            '« Nouveau reçu »' => [false],
            '« Remplacer le reçu »' => [true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bothShapes')]
    public function testTheStatusLineAnnouncesWhatTheScriptWritesIntoIt(bool $replace): void
    {
        $html = $this->render($replace);

        $this->assertMatchesRegularExpression(
            '/<div[^>]*id="receipt-file-status"[^>]*aria-live="polite"/s',
            $html,
            'the receipt form writes « Envoi en cours… » into this element; without aria-live nobody is told (#364).'
        );
    }

    /**
     * The control the lock closes, found the way the script finds it.
     *
     * `lockSubmit()` reaches the button through
     * `form.querySelector('button[type="submit"]')`. A future edit that
     * turned it into an `<input type="submit">`, or moved it outside the
     * form, would leave the lock silently gripping nothing — and the
     * Vitest suite, which builds its own DOM, would still pass.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bothShapes')]
    public function testTheSubmitButtonIsWhereTheLockLooksForIt(bool $replace): void
    {
        $html = $this->render($replace);

        $form = preg_match('/<form[^>]*id="receipt-form".*?<\/form>/s', $html, $m) === 1 ? $m[0] : '';
        $this->assertNotSame('', $form, 'the receipt form did not render.');
        $this->assertMatchesRegularExpression('/<button type="submit"/s', $form);
    }
}
