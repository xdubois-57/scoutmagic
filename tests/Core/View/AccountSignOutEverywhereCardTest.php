<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;
use Tests\TestTwig;
use Twig\Environment;

/**
 * The « Déconnecter partout » card on « Mon compte » (issue #1257): it says
 * the password goes too, and offers to keep it only when there is one.
 */
final class AccountSignOutEverywhereCardTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $this->twig = TestTwig::create([], ['param' => fn (string $key): string => 'Test Unité']);
    }

    private function card(bool $hasPassword): string
    {
        $html = $this->twig->render('account/index.html.twig', [
            'is_authenticated' => true,
            'config_mode' => false,
            'cookie_consent_given' => true,
            'current_user_role' => 'identified',
            'account' => null,
            'has_password' => $hasPassword,
            'passkeys' => [],
            'password_changed_at' => null,
            'has_account_photo' => false,
            'discovery_tips_seen' => 0,
            'has_personal_signature' => null,
            'personal_signature_saved_at' => null,
        ]);
        $start = strpos($html, 'action="/account/sign-out-everywhere"');
        $this->assertNotFalse($start, 'The card must be on the page.');
        $from = (int) strrpos(substr($html, 0, $start), '<h2');

        return substr($html, $from, strpos($html, '</form>', $start) - $from);
    }

    public function testWithAPasswordTheCardSaysItGoesAndOffersToKeepIt(): void
    {
        $card = $this->card(true);

        $this->assertStringContainsString('votre mot de passe', $card);
        $this->assertStringContainsString('votre mot de passe supprimé', $card);
        $this->assertMatchesRegularExpression('#<input type="checkbox"[^>]*name="keep_password"[^>]*value="1"#', $card);
        $this->assertStringNotContainsString('checked', $card, 'Keeping it is the exception: the box starts unticked.');
    }

    public function testWithoutAPasswordThereIsNothingToKeepAndNothingIsPromisedAboutOne(): void
    {
        $card = $this->card(false);

        $this->assertStringNotContainsString('keep_password', $card);
        $this->assertStringNotContainsString('mot de passe', $card);
        $this->assertStringContainsString('lien envoyé par e-mail', $card);
    }
}
