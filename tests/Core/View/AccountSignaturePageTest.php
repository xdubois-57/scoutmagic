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
     * Issue #1356: the page shows a button, and the form lives in a dialog
     * it opens — the shared signature dialog, in « new » mode, with its
     * own label and without « Mémoriser », recording being the point here.
     */
    public function testTheFormLivesInADialogThatAButtonOpens(): void
    {
        $html = $this->page(['has_signature' => true]);

        $this->assertMatchesRegularExpression(
            '#<button type="button" class="btn btn-primary"\s+data-bs-toggle="modal" data-bs-target="\#account-signature">Remplacer ma signature</button>#u',
            $html
        );
        $this->assertMatchesRegularExpression('#<div class="modal fade" id="account-signature"[^>]*data-bs-backdrop="static"#', $html);
        $this->assertStringContainsString('<h2 class="modal-title h5" id="account-signature-title">Remplacer ma signature</h2>', $html);

        // The form is in the dialog's body, its submit button in the footer
        // and tied to it by form="…".
        $this->assertMatchesRegularExpression(
            '#<form method="post" action="/account/signature" id="account-signature-form" enctype="multipart/form-data"\s+data-signature-form data-signature-dialog-form\s+data-signature-mode="new"#',
            $html
        );
        $this->assertStringContainsString('<button type="submit" class="btn btn-primary" form="account-signature-form">Enregistrer la signature</button>', $html);
        $this->assertStringContainsString('data-bs-dismiss="modal">Annuler</button>', $html);
        $this->assertStringNotContainsString('Signer le document', $html);
        $this->assertStringNotContainsString('remember_signature', $html);

        $pad = strpos($html, 'data-signature-pad');
        $this->assertNotFalse($pad);
        $this->assertGreaterThan(strpos($html, 'id="account-signature"'), $pad, 'the pad is inside the dialog');
        $this->assertLessThan(
            strpos($html, '/assets/js/signature-dialog.js'),
            (int) strpos($html, '/assets/js/signature-pad.js'),
            'signature-pad.js first, then signature-dialog.js'
        );
    }

    public function testWithoutASignatureTheButtonAndTheDialogSayEnregistrer(): void
    {
        $html = $this->page([]);

        $this->assertStringContainsString('data-bs-target="#account-signature">Enregistrer ma signature</button>', $html);
        $this->assertStringContainsString('id="account-signature-title">Enregistrer ma signature</h2>', $html);
    }

    /** It opens by itself only when the controller asks (arrived from a document to sign). */
    public function testTheDialogOpensByItselfOnlyWithAReturnAddress(): void
    {
        $this->assertStringNotContainsString('data-signature-dialog-open', $this->page([]));
        $this->assertStringNotContainsString(
            'data-signature-dialog-open',
            $this->page(['return_to' => '/mes-locations/chalet/reservations/12'])
        );
        $this->assertStringContainsString(
            'data-signature-dialog-open=""',
            $this->page(['return_to' => '/mes-locations/chalet/reservations/12', 'open_dialog' => true])
        );
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
