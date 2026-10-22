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
 * The signature dialog modules open to sign a document (issue #926, lot
 * C1, `partials/signature_dialog.html.twig`), in its three modes: with the
 * account's stored signature, with one given now, and for a witness who
 * has no account.
 */
final class SignatureDialogTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $this->twig = TestTwig::create([], [
            'csrf_field' => fn (): string => '<input type="hidden" name="_csrf_token" value="jeton">',
        ]);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function dialog(array $options = []): string
    {
        return $this->twig->render('partials/signature_dialog.html.twig', $options + [
            'id' => 'countersign-40',
            'title' => 'Contresigner le contrat',
            'action' => '/mes-locations/contrat-contresigner',
            'fields' => ['asset_id' => 3, 'booking_id' => 12, 'document_id' => 40],
            'consequence' => 'Le locataire reçoit le contrat signé par les deux parties.',
            'manage_return' => '/mes-locations/chalet/reservations/12',
        ]);
    }

    public function testTheStoredModeSaysWhatWillBeSignedAndOffersNoOtherDrawing(): void
    {
        $html = $this->dialog(['has_stored_signature' => true]);

        $this->assertStringContainsString(
            'Ce document sera signé avec la signature enregistrée dans votre compte. Vérifiez son contenu avant de confirmer.',
            $html
        );
        $this->assertStringContainsString('Le locataire reçoit le contrat signé par les deux parties.', $html);
        $this->assertStringContainsString('name="signature_source" value="stored"', $html);
        $this->assertStringContainsString(
            'href="/account/signature?retour=%2Fmes-locations%2Fchalet%2Freservations%2F12">Gérer ma signature</a>',
            $html
        );
        $this->assertStringContainsString('data-signature-preview', $html);
        $this->assertStringNotContainsString('data-signature-pad', $html);
        $this->assertStringNotContainsString('name="signature_file"', $html);
        $this->assertStringNotContainsString('remember_signature', $html);
        $this->assertButtons($html);
    }

    /** The page carries no image: the preview is fetched when the dialog opens (decision Q5). */
    public function testTheStoredModeNeverEmbedsTheImage(): void
    {
        $html = $this->dialog(['has_stored_signature' => true]);

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('data:image', $html);
        $this->assertStringNotContainsString('/account/signature/image', $html);
    }

    public function testTheNewModeOffersThePadTheImportAndAnUntickedBox(): void
    {
        $html = $this->dialog(['has_stored_signature' => false]);

        $this->assertStringContainsString('name="signature_source" value="new"', $html);
        $this->assertStringContainsString('data-signature-pad', $html);
        $this->assertStringContainsString('name="signature_data" value=""', $html);
        $this->assertStringContainsString('name="signature_file"', $html);
        $this->assertStringContainsString('accept="image/png,image/jpeg,image/webp"', $html);
        $this->assertStringContainsString('enctype="multipart/form-data"', $html);
        $this->assertStringContainsString(
            'Mémoriser ma signature dans mon compte pour une utilisation future',
            $html
        );
        $this->assertMatchesRegularExpression('#<input class="form-check-input" type="checkbox" name="remember_signature" value="1"\s+id="countersign-40-remember"#', $html);
        $this->assertStringNotContainsString('checked', $html);
        $this->assertStringNotContainsString('Ce document sera signé avec la signature enregistrée', $html);
        $this->assertButtons($html);
    }

    public function testTheNewModeMayLeaveOutTheImportAndTheBox(): void
    {
        $html = $this->dialog(['allow_import' => false, 'allow_remember' => false]);

        $this->assertStringContainsString('data-signature-pad', $html);
        $this->assertStringNotContainsString('name="signature_file"', $html);
        $this->assertStringNotContainsString('remember_signature', $html);
    }

    /** Ticket §6a: a person without an account types their name and draws; nothing else. */
    public function testTheWitnessModeAsksForANameAndADrawingOnly(): void
    {
        $html = $this->dialog(['witness' => true, 'has_stored_signature' => true, 'allow_import' => true]);

        $this->assertMatchesRegularExpression('#name="signer_name"\s+maxlength="120" required#', $html);
        $this->assertStringContainsString('data-signature-pad', $html);
        $this->assertStringContainsString('data-signature-mode="witness"', $html);
        $this->assertStringNotContainsString('name="signature_file"', $html);
        $this->assertStringNotContainsString('remember_signature', $html);
        $this->assertStringNotContainsString('Gérer ma signature', $html);
        $this->assertStringNotContainsString('enregistrée dans votre compte', $html);
        $this->assertStringNotContainsString('checked', $html);
    }

    /** The dialog is the confirmation: no data-confirm after it, and never an account id. */
    public function testTheFormCarriesItsFieldsAndNothingThatNamesAnAccount(): void
    {
        foreach ([true, false] as $stored) {
            $html = $this->dialog(['has_stored_signature' => $stored]);

            $this->assertStringContainsString('action="/mes-locations/contrat-contresigner" id="countersign-40-form"', $html);
            $this->assertStringContainsString('name="document_id" value="40"', $html);
            $this->assertStringContainsString('name="_csrf_token"', $html);
            $this->assertStringNotContainsString('data-confirm', $html);
            $this->assertStringNotContainsString('user_account', $html);
            $this->assertStringContainsString('data-bs-backdrop="static"', $html);
            $this->assertStringContainsString('aria-labelledby="countersign-40-title"', $html);
        }
    }

    /** A page whose script turns forms into fetches can tell it to stand aside. */
    public function testTheModuleMayAddAttributesToTheForm(): void
    {
        $html = $this->dialog(['form_attributes' => ['data-async' => 'off']]);

        $this->assertStringContainsString('data-signature-mode="new" data-async="off">', $html);
    }

    private function assertButtons(string $html): void
    {
        $this->assertStringContainsString(
            '<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>',
            $html
        );
        $this->assertStringContainsString(
            '<button type="submit" class="btn btn-primary" form="countersign-40-form">Signer le document</button>',
            $html
        );
    }
}
