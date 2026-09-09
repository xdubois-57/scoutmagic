<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\MassMail;

use Modules\MassMail\Repository\Email;
use PHPUnit\Framework\TestCase;

/**
 * `@mass_mail/compose.html.twig` in TEST mode — the screen a chief reads
 * just before pressing « Lancer l'envoi ».
 *
 * A publipostage renders its final, variable-resolved email on this page,
 * below « Envoyer un email de test ». It also used to render the
 * composition above it: the same email a second time, unresolved and
 * locked, with nothing saying which of the two was the real one. What is
 * pinned here is the shape that fixed that — and, just as much, the
 * shapes it must NOT take, since hiding the composition on a list type
 * whose final email this page never renders would leave a test screen
 * with no email on it at all.
 */
final class ComposeTestModeRenderingTest extends TestCase
{
    private function render(string $listType, string $status): string
    {
        return ComposePageRenderer::render(ComposePageRenderer::draft($listType, $status));
    }

    public function testAPublipostageInTestModeDropsTheCompositionEntirely(): void
    {
        $html = $this->render(Email::LIST_TYPE_MAIL_MERGE, Email::STATUS_TEST);

        $this->assertStringNotContainsString('id="mm-compose-form"', $html);
        $this->assertStringNotContainsString('Section expéditrice', $html);
        $this->assertStringNotContainsString('Liste de diffusion', $html);
        $this->assertStringNotContainsString('Fichier de publipostage', $html);
        $this->assertStringNotContainsString('id="mm-subject"', $html);
        $this->assertStringNotContainsString('id="mm-body-content"', $html);
    }

    /**
     * The part that survives, and the reason the rest could go: the final
     * email is here, in full, and « Envoyer un email de test » is
     * untouched.
     */
    public function testThePublipostageTestScreenKeepsTheTestSendAndThePreview(): void
    {
        $html = $this->render(Email::LIST_TYPE_MAIL_MERGE, Email::STATUS_TEST);

        $this->assertStringContainsString('Envoyer un email de test', $html);
        $this->assertStringContainsString('id="mm-test-send-form"', $html);
        $this->assertStringContainsString('id="mm-merge-preview-zone"', $html);
        $this->assertStringContainsString('id="mm-merge-preview-body"', $html);
        // And the way back to editing, which is the only reason hiding
        // the composition is not a dead end.
        $this->assertStringContainsString('Repasser en brouillon', $html);
    }

    /**
     * The header of a real message. « Sujet » alone left two questions
     * the composition above used to answer, and answering them is the
     * whole reason a preview exists: this is what the recipient sees.
     */
    public function testThePreviewCarriesAMessageHeaderWithDeAndA(): void
    {
        $html = $this->render(Email::LIST_TYPE_MAIL_MERGE, Email::STATUS_TEST);

        $this->assertStringContainsString('De :', $html);
        $this->assertStringContainsString('À :', $html);
        $this->assertStringContainsString('Sujet :', $html);

        // « De » is server-rendered, and is the sender the recipient will
        // actually read — never the site default guessed at by a template.
        $this->assertStringContainsString('meute-a@unite.test', $html);
        // « À » changes with the previewed row, so it is an empty hook
        // the script fills.
        $this->assertStringContainsString('id="mm-merge-preview-recipient"', $html);

        $this->assertLessThan(
            strpos($html, 'Sujet :'),
            strpos($html, 'De :'),
            'De, À, Sujet — the order every mail client uses.'
        );
    }

    /**
     * The script disables « Envoyer le test » until the first preview has
     * landed, because the offset the form carries only names the row on
     * screen once there IS one. It needs a hook to do that — and the
     * button must NOT ship disabled, or this form stops working with the
     * script absent, which is the guarantee it is built on.
     */
    public function testTheTestSendButtonOffersAHookAndShipsEnabled(): void
    {
        $html = $this->render(Email::LIST_TYPE_MAIL_MERGE, Email::STATUS_TEST);

        $this->assertStringContainsString('id="mm-test-send-btn"', $html);

        $button = substr($html, (int) strpos($html, 'id="mm-test-send-btn"') - 200, 260);
        $this->assertStringNotContainsString('disabled', $button);
    }

    /**
     * Attachments belong under the email they travel with, not above the
     * screen that shows it.
     */
    public function testAttachmentsComeBelowTheTestSendBlock(): void
    {
        $html = $this->render(Email::LIST_TYPE_MAIL_MERGE, Email::STATUS_TEST);

        $this->assertLessThan(
            strpos($html, 'Pièces jointes'),
            strpos($html, 'Envoyer un email de test'),
            'The attachment list follows the email; it does not introduce it.'
        );
    }

    /**
     * The rule is « the final email is already on the page », not « the
     * email is in test mode ». For every other list type the read-only
     * composition IS the only rendering of it there is, so it stays.
     */
    public function testAnOrdinaryListInTestModeStillShowsItsComposition(): void
    {
        $html = $this->render(Email::LIST_TYPE_DEFAULT_SECTION, Email::STATUS_TEST);

        $this->assertStringContainsString('id="mm-compose-form"', $html);
        $this->assertStringContainsString('Section expéditrice', $html);
        $this->assertStringContainsString('Envoyer un email de test', $html);
    }

    public function testADraftPublipostageStillShowsEverythingItIsEditedWith(): void
    {
        $html = $this->render(Email::LIST_TYPE_MAIL_MERGE, Email::STATUS_DRAFT);

        $this->assertStringContainsString('id="mm-compose-form"', $html);
        $this->assertStringContainsString('Fichier de publipostage', $html);
        // No test send yet: that belongs to the test state.
        $this->assertStringNotContainsString('Envoyer un email de test', $html);
    }
}
