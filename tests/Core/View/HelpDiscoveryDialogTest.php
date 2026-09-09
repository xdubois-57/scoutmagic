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
 * base.html.twig's « Le saviez-vous ? » dialog (ARCHITECTURE.md §8.95) —
 * the render-time half, tested through the real layout for the same
 * reason as HelpSearchBlobTest: a Twig global wired to the wrong key
 * renders a perfectly fine page with no dialog in it, and nothing else
 * would say so.
 *
 * The inclusion is a SERVER decision — public/index.php sets the global
 * only when there is something to show — so the two states worth pinning
 * are "the markup is absent entirely" and "the markup is there, whole".
 * A dialog hidden by a CSS class would be neither.
 */
final class HelpDiscoveryDialogTest extends TestCase
{
    /**
     * @param array<string, mixed> $globals
     */
    private function render(array $globals = []): string
    {
        $templateDir = dirname(__DIR__, 3) . '/core/View/templates';
        $twig = new Environment(new FilesystemLoader($templateDir), [
            'cache' => false,
            'autoescape' => 'html',
        ]);
        $twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $path): string => $path));
        $twig->addGlobal('site_name', 'Test Unit');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'parent@test.be');
        $twig->addGlobal('current_user_role', 'identified');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('csp_nonce', 'nonce123');

        foreach ($globals as $key => $value) {
            $twig->addGlobal($key, $value);
        }

        $twig->addFunction(new \Twig\TwigFunction(
            'csrf_field',
            fn (): string => '<input type="hidden" name="_csrf_token" value="test">',
            ['is_safe' => ['html']]
        ));
        $twig->addFunction(new \Twig\TwigFunction('get_flash', fn (): ?array => null));
        $twig->addFunction(new \Twig\TwigFunction('csrf_token', fn (): string => 'test'));

        return $twig->render('base.html.twig');
    }

    /**
     * @param array<int, array{id: string, title: string, summary: string, question: ?string, url: string}> $cards
     * @return array{cards: array<int, array<string, mixed>>, more: bool}
     */
    private function dialog(array $cards, bool $more = false): array
    {
        return ['cards' => $cards, 'more' => $more];
    }

    /**
     * @return array{id: string, title: string, summary: string, question: ?string, url: string}
     */
    private function card(string $id, ?string $question = null): array
    {
        return [
            'id' => $id,
            'title' => 'Titre de ' . $id,
            'summary' => 'Résumé de ' . $id,
            'question' => $question,
            'url' => '/aide/' . $id,
        ];
    }

    public function testNoDialogIsRenderedAtAllWhenTheGlobalIsAbsent(): void
    {
        $html = $this->render();

        $this->assertStringNotContainsString('help-discovery-modal', $html);
        $this->assertStringNotContainsString('Le saviez-vous', $html);
    }

    public function testTheDialogRendersOneBlockPerCard(): void
    {
        $html = $this->render(['help_discovery' => $this->dialog([
            $this->card('publipostage', "Comment mettre le prénom de chacun dans un e-mail groupé ?"),
            $this->card('camps-encoder'),
        ])]);

        $this->assertStringContainsString('id="help-discovery-modal"', $html);
        $this->assertStringContainsString('Le saviez-vous ?', $html);
        $this->assertSame(2, substr_count($html, 'data-discovery-card'));
        $this->assertStringContainsString('data-discovery-id="publipostage"', $html);
        $this->assertStringContainsString('/aide/camps-encoder', $html);
    }

    /**
     * The order inside a card is the design, not a preference: somebody
     * recognises their own problem in the question and would recognise
     * nothing in « Publipostage ». A title rendered first would still
     * look fine, which is why this is a test.
     */
    public function testTheQuestionComesBeforeTheTitle(): void
    {
        $html = $this->render(['help_discovery' => $this->dialog([
            $this->card('publipostage', 'Comment fusionner un e-mail ?'),
        ])]);

        $this->assertLessThan(
            strpos($html, 'Titre de publipostage'),
            strpos($html, 'Comment fusionner un e-mail'),
            'A tip leads with the question it answers — a table of contents leads with the title.'
        );
    }

    /**
     * The title and the summary are the ANSWER, and the frame is what
     * says where the answer starts. A card is a question in the reader's
     * words followed by a help topic in the site's; unframed, the three
     * paragraphs read as one, and the title stops looking like a title.
     *
     * Asserted as "both lines inside one bordered box" rather than on a
     * class list, because what would break this is somebody framing the
     * title alone and leaving the summary outside it — which looks
     * deliberate in a diff and wrong on screen.
     */
    public function testTheTitleAndItsSummaryShareOneBorderedFrame(): void
    {
        $html = $this->render(['help_discovery' => $this->dialog([
            $this->card('publipostage', 'Comment fusionner un e-mail ?'),
        ])]);

        $this->assertSame(
            1,
            preg_match(
                '/<div class="border rounded[^"]*">\s*'
                    . '<p[^>]*>Titre de publipostage<\/p>\s*'
                    . '<p[^>]*>Résumé de publipostage<\/p>\s*<\/div>/u',
                $html
            ),
            'The topic title and its summary belong in one light frame, together.'
        );

        // The question stays OUTSIDE the frame: it is what the reader
        // recognises, not part of what the site answers.
        $this->assertLessThan(
            strpos($html, '<div class="border rounded'),
            strpos($html, 'Comment fusionner un e-mail'),
            'The question introduces the frame — it does not live inside it.'
        );
    }

    public function testACardWithoutAQuestionStillRenders(): void
    {
        $html = $this->render(['help_discovery' => $this->dialog([$this->card('camps-encoder')])]);

        $this->assertStringContainsString('Titre de camps-encoder', $html);
        $this->assertStringContainsString('Résumé de camps-encoder', $html);
    }

    /**
     * Only one card is on screen at a time — the script walks between
     * them, it does not have to hide them first.
     */
    public function testEveryCardButTheFirstStartsHidden(): void
    {
        $html = $this->render(['help_discovery' => $this->dialog([
            $this->card('un'),
            $this->card('deux'),
            $this->card('trois'),
        ])]);

        $this->assertSame(1, preg_match_all('/class="" data-discovery-card/', $html));
        $this->assertSame(2, preg_match_all('/class="d-none" data-discovery-card/', $html));
    }

    public function testVoirDAutresAstucesIsOfferedOnlyWhenThereIsMore(): void
    {
        $without = $this->render(['help_discovery' => $this->dialog([$this->card('un')], more: false)]);
        $this->assertStringNotContainsString('data-discovery-more', $without);

        $with = $this->render(['help_discovery' => $this->dialog([$this->card('un')], more: true)]);
        $this->assertStringContainsString('data-discovery-more', $with);
        $this->assertStringContainsString("Voir d'autres astuces", $with);
    }

    public function testTheFooterOffersTheDelayAndTheRefusal(): void
    {
        $html = $this->render(['help_discovery' => $this->dialog([$this->card('un')])]);

        $this->assertStringContainsString('data-discovery-snooze', $html);
        $this->assertStringContainsString('Pas avant une semaine', $html);
        $this->assertStringContainsString('data-discovery-never', $html);
        $this->assertStringContainsString('Ne plus me proposer', $html);
    }

    /**
     * The persistence is a fetch, so nothing here is submitted — and a
     * <form> spanning a scrollable modal's body and footer is what pushes
     * that footer off a phone's screen (design.md §7.11).
     */
    public function testTheDialogWrapsNothingInAForm(): void
    {
        $html = $this->render(['help_discovery' => $this->dialog([$this->card('un')])]);

        $dialogStart = strpos($html, 'id="help-discovery-modal"');
        $this->assertIsInt($dialogStart);
        $this->assertStringNotContainsString('<form', substr($html, $dialogStart));
    }

    /**
     * A topic's title and summary are text somebody wrote in a Markdown
     * file, and they reach this template as data. Twig escapes them; a
     * `|raw` slipped in here would not look any different on a corpus
     * that contains no angle bracket.
     */
    public function testATitleIsEscapedRatherThanRendered(): void
    {
        $card = $this->card('un');
        $card['title'] = '<script>alert(1)</script>';
        $card['question'] = '<img src=x onerror=alert(1)>';

        $html = $this->render(['help_discovery' => $this->dialog([$card])]);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
