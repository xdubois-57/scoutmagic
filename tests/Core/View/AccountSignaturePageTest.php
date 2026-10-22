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
 * « Ma signature » and its card on Mon compte, as a visitor's page renders
 * them (issue #926, lot B).
 *
 * The card on `/account` says whether there is a signature and links to
 * the page, and never shows the image: `/account` is kept for offline
 * reading, and a signature must never rest in that cache.
 */
final class AccountSignaturePageTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $this->twig = TestTwig::create([], ['param' => fn (string $key): string => 'Test Unité']);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function page(array $context): string
    {
        return $this->twig->render('account/signature.html.twig', $context + [
            'is_authenticated' => true,
            'config_mode' => false,
            'cookie_consent_given' => true,
            'has_signature' => false,
            'saved_at' => null,
            'return_to' => null,
        ]);
    }

    public function testWithoutASignatureThePageOffersToDrawOrImportOne(): void
    {
        $html = $this->page([]);

        $this->assertStringContainsString('data-signature-pad', $html);
        $this->assertStringContainsString('name="signature_file"', $html);
        $this->assertStringContainsString('accept="image/png,image/jpeg,image/webp"', $html);
        $this->assertStringContainsString('enctype="multipart/form-data"', $html);
        $this->assertStringContainsString('Enregistrer ma signature', $html);
        $this->assertStringNotContainsString('/account/signature/image', $html);
        $this->assertStringNotContainsString('/account/signature/delete', $html);
        $this->assertStringContainsString('/assets/js/signature-pad.js', $html);
    }

    public function testWithASignatureThePageShowsItAndAsksBeforeDeletingIt(): void
    {
        $html = $this->page(['has_signature' => true, 'saved_at' => new \DateTimeImmutable('2026-10-21 09:30:00')]);

        $this->assertStringContainsString('src="/account/signature/image"', $html);
        $this->assertStringContainsString('Remplacer ma signature', $html);
        $this->assertMatchesRegularExpression(
            '#<form method="post" action="/account/signature/delete"\s+data-confirm="Supprimer votre signature \?#u',
            $html
        );
        $this->assertStringContainsString('Enregistrée le 21/10/2026 à 09:30', $html);
    }

    public function testTheReturnAddressTravelsWithTheForm(): void
    {
        $html = $this->page(['return_to' => '/mes-locations/chalet/reservations/12']);

        $this->assertStringContainsString('name="retour" value="/mes-locations/chalet/reservations/12"', $html);
        $this->assertStringContainsString('Continuer sans modifier ma signature', $html);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function account(array $context): string
    {
        return $this->twig->render('account/index.html.twig', $context + [
            'is_authenticated' => true,
            'config_mode' => false,
            'cookie_consent_given' => true,
            'vapid_public_key' => 'test',
        ]);
    }

    public function testTheAccountCardSaysThereIsNoneAndLinksToThePage(): void
    {
        $html = $this->account(['has_personal_signature' => false]);

        $this->assertStringContainsString('Aucune signature enregistrée.', $html);
        $this->assertStringContainsString('href="/account/signature"', $html);
        $this->assertStringContainsString('Gérer ma signature', $html);
    }

    public function testTheAccountCardNeverCarriesTheImage(): void
    {
        $html = $this->account([
            'has_personal_signature' => true,
            'personal_signature_saved_at' => new \DateTimeImmutable('2026-10-21 09:30:00'),
        ]);

        $this->assertStringContainsString('Signature enregistrée le 21/10/2026.', $html);
        $this->assertStringNotContainsString('/account/signature/image', $html);
        $this->assertStringNotContainsString('data:image/png', $html);
    }

    /** With no signature service wired, the card is not offered at all. */
    public function testWithoutTheServiceTheCardIsAbsent(): void
    {
        $this->assertStringNotContainsString('Gérer ma signature', $this->account([]));
    }
}
