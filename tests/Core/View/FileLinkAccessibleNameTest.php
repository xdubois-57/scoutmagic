<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * `partials/file_link.html.twig` and WCAG 2.5.3 « Label in Name ».
 *
 * The criterion is about somebody driving the page by voice: they say
 * what they READ. A row showing « Télécharger » and answering only to
 * « Attestation de Léa » is a control that cannot be reached by speech,
 * while looking perfectly correct to everyone else — which is why no
 * amount of manual checking finds it.
 *
 * The partial's own documentation invites exactly that mistake: it offers
 * `aria_label` for « a list repeats Télécharger on every row without
 * saying of what », and a caller writing the obvious thing there drops
 * the read word. So the rule is computed in the partial rather than asked
 * of each caller, and this file holds it.
 *
 * Renders the real template through a real Twig environment rather than
 * grepping it: the rule is a conditional over two inputs, and the three
 * cases below are what it has to get right.
 */
final class FileLinkAccessibleNameTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $this->twig = new Environment(
            new FilesystemLoader(dirname(__DIR__, 3) . '/core/View/templates'),
            ['cache' => false, 'autoescape' => 'html']
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(array $context): string
    {
        return $this->twig->render('partials/file_link.html.twig', $context);
    }

    /**
     * The case the partial was written for, and the one that used to lose
     * the read word.
     */
    public function testAVisibleLabelIsPutInFrontOfAnAccessibleNameThatOmitsIt(): void
    {
        $html = $this->render([
            'href' => '/files/12',
            'label' => 'Télécharger',
            'aria_label' => "l'attestation de Léa",
        ]);

        // Escaped as Twig writes it into the attribute, which is also
        // what the browser reads back as the accessible name.
        $this->assertStringContainsString(
            'aria-label="' . htmlspecialchars('Télécharger — l\'attestation de Léa', ENT_QUOTES) . '"',
            $html,
            'a file link whose accessible name drops its visible label is unreachable by voice '
            . 'control (WCAG 2.5.3), and the partial is where that is supposed to be impossible.',
        );
    }

    /**
     * A caller that already did the right thing must not be made to say
     * it twice — « Télécharger Télécharger l'archive » is its own defect.
     */
    public function testAnAccessibleNameThatAlreadyCarriesTheLabelIsLeftAlone(): void
    {
        $html = $this->render([
            'href' => '/files/12',
            'label' => 'Télécharger',
            'aria_label' => "Télécharger l'archive du ticket 42",
        ]);

        $this->assertStringContainsString(
            'aria-label="' . htmlspecialchars("Télécharger l'archive du ticket 42", ENT_QUOTES) . '"',
            $html
        );
        $this->assertStringNotContainsString('Télécharger — Télécharger', $html);
    }

    /**
     * The icon-only link, which is most of this partial's callers today:
     * there is no visible word to say, so there is nothing to prefix and
     * the criterion does not apply.
     */
    public function testAnIconOnlyLinkKeepsItsAccessibleNameUntouched(): void
    {
        $html = $this->render([
            'href' => '/files/12',
            'label' => '',
            'icon' => 'download',
            'aria_label' => 'Télécharger la sauvegarde « Complète » du 12 mars',
        ]);

        $this->assertStringContainsString(
            'aria-label="Télécharger la sauvegarde « Complète » du 12 mars"',
            $html
        );
        $this->assertStringNotContainsString('—', $html);
    }

    /**
     * And a link with a visible label and nothing else emits no
     * `aria-label` at all: an accessible name equal to the visible text
     * is what the browser already computes, and writing it out is one
     * more thing to keep in sync.
     */
    public function testALinkWithNoAccessibleNameGetsNoAttribute(): void
    {
        $html = $this->render([
            'href' => '/files/12',
            'label' => "Télécharger l'archive",
        ]);

        $this->assertStringNotContainsString('aria-label', $html);
    }
}
