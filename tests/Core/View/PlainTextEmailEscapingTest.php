<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\View;

use Core\View\TwigFactory;
use PHPUnit\Framework\TestCase;

/**
 * The plain-text half of an email is not HTML, and must not be escaped as
 * if it were.
 *
 * Every `*.text.twig` in this repository was rendered under the same
 * `autoescape => 'html'` as the pages. Nothing renders those entities back
 * in a `text/plain` part, so a renter called O'Brien read « Bonjour
 * O&#039;Brien », and « c&#039;est votre seul accès » sat in the
 * acknowledgement of every rental request — in the one email the module
 * says cannot be switched off.
 *
 * Pinned here rather than left to each template: `|raw` on every variable
 * is the same decision made forty times and forgotten once, and the one
 * time it is forgotten is the one nobody reads.
 */
final class PlainTextEmailEscapingTest extends TestCase
{
    private function twig(): \Twig\Environment
    {
        return TwigFactory::create(
            dirname(__DIR__, 3) . '/core/View/templates',
            false,
            ['rental' => dirname(__DIR__, 3) . '/modules/rental/views']
        );
    }

    /**
     * The same values, through both halves of one real email.
     *
     * Real template files, deliberately: `createTemplate()` renames what it
     * compiles (`… (string template <hash>)`), so a probe built that way
     * would never end in `.text.twig` and would test nothing at all.
     *
     * @return array{text: string, html: string}
     */
    private function bothHalves(string $name, string $word): array
    {
        $context = [
            'decision_subject' => 'Votre réservation est confirmée',
            'renter_name' => $name,
            'reference' => 'LOC-2027-0042',
            'arrival_date' => '14/08/2027',
            'departure_date' => '17/08/2027',
            'asset_name' => 'Le Chalet',
            'announcement' => 'Votre réservation est confirmée.',
            'call_to_action' => '',
            'manager_word' => $word,
            'tracking_url' => '',
            'site_name' => 'Unité Test',
        ];
        $twig = $this->twig();

        return [
            'text' => $twig->render('@rental/email/decision.text.twig', $context),
            'html' => $twig->render('@rental/email/decision.html.twig', $context),
        ];
    }

    public function testAnApostropheSurvivesIntoAPlainTextPart(): void
    {
        $rendered = $this->bothHalves("O'Brien & fils", 'À bientôt !');

        $this->assertStringContainsString("O'Brien & fils", $rendered['text']);
        $this->assertStringNotContainsString('&#039;', $rendered['text']);
        $this->assertStringNotContainsString('&amp;', $rendered['text']);
    }

    public function testTheHtmlHalfOfTheSameMessageIsStillEscaped(): void
    {
        // The change is narrow on purpose: what protects the HTML body
        // from a name or a manager's sentence is unchanged.
        $rendered = $this->bothHalves('<script>alert(1)</script>', '<img src=x onerror=alert(1)>');

        $this->assertStringNotContainsString('<script>', $rendered['html']);
        $this->assertStringContainsString('&lt;script&gt;', $rendered['html']);
        $this->assertStringNotContainsString('<img', $rendered['html']);
    }

    public function testAPageTemplateIsStillEscaped(): void
    {
        // Everything that is not a `.text.twig` stays on the safe side of
        // the fence, including a partial rendered into a page.
        $rendered = $this->twig()->render('partials/empty_state.html.twig', [
            'message' => '<script>alert(1)</script>',
        ]);

        $this->assertStringNotContainsString('<script>', $rendered);
        $this->assertStringContainsString('&lt;script&gt;', $rendered);
    }

    public function testARealPlainTextEmailComesOutReadable(): void
    {
        // The acknowledgement itself, with the name that made this visible.
        $rendered = $this->twig()->render('@rental/email/decision.text.twig', [
            'decision_subject' => 'Une précision avant de vous répondre',
            'renter_name' => "O'Brien",
            'reference' => 'LOC-2027-0042',
            'arrival_date' => '14/08/2027',
            'departure_date' => '17/08/2027',
            'asset_name' => 'Le Chalet',
            'announcement' => "Nous avons besoin d'une précision avant de vous répondre.",
            'call_to_action' => '',
            'manager_word' => "Combien serez-vous, à peu près ?",
            'tracking_url' => '',
            'site_name' => 'Unité Test',
        ]);

        $this->assertStringNotContainsString('&#039;', $rendered);
        $this->assertStringNotContainsString('&amp;', $rendered);
        $this->assertStringContainsString("Bonjour O'Brien,", $rendered);
        $this->assertStringContainsString("besoin d'une précision", $rendered);
    }
}
