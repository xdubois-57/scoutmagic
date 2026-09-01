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
 * base.html.twig's #help-search-index blob and the always-present help
 * panel — the render-time half of the instant help search, tested end to
 * end for the same reason as OfflineConfigBlobTest: a Twig global wired
 * to the wrong key renders a perfectly fine page carrying no corpus at
 * all, and nothing else would say so.
 *
 * The panel used to be emitted only when a topic covered the page. It is
 * unconditional now, because the search field lives inside it and a page
 * no topic covers is exactly where searching the corpus is most useful —
 * so "the panel is on every page" is itself an invariant worth pinning:
 * putting the condition back would silently leave the help button opening
 * markup that isn't there.
 */
final class HelpSearchBlobTest extends TestCase
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
        $twig->addGlobal('is_authenticated', false);
        $twig->addGlobal('current_user_email', null);
        $twig->addGlobal('current_user_role', 'public');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('csp_nonce', 'nonce123');

        foreach ($globals as $key => $value) {
            $twig->addGlobal($key, $value);
        }

        $twig->addFunction(new \Twig\TwigFunction('csrf_field', fn (): string => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('get_flash', fn (): ?array => null));
        $twig->addFunction(new \Twig\TwigFunction('csrf_token', fn (): string => 'test'));

        return $twig->render('base.html.twig');
    }

    /**
     * @return array<int, mixed>
     */
    private function extractIndex(string $html): array
    {
        $this->assertSame(
            1,
            preg_match('#<script type="application/json" id="help-search-index">(.*?)</script>#s', $html, $matches),
            'base.html.twig must serialize the help corpus into #help-search-index.'
        );
        $index = json_decode($matches[1], true);
        $this->assertIsArray($index);

        return $index;
    }

    public function testEmbedsTheCorpusVerbatim(): void
    {
        $entries = [
            [
                'id' => 'journal',
                'title' => 'Consulter le journal',
                'summary' => 'Qui a fait quoi.',
                'category' => "Espace chefs d'U",
                'questions' => ['Comment savoir qui a changé une section ?'],
                'link' => ['path' => '/admin/journal', 'label' => 'Journal'],
            ],
        ];

        $this->assertSame($entries, $this->extractIndex($this->render(['help_search_index' => $entries])));
    }

    public function testTheBlobIsPresentAndEmptyWhenNoIndexIsWired(): void
    {
        // Non-web entry points and the tests that predate the global.
        // An absent blob is fine for the script (it simply does nothing);
        // a MISSING one would be, too — but an empty array keeps the
        // markup shape identical either way.
        $this->assertSame([], $this->extractIndex($this->render()));
    }

    public function testThePanelAndItsSearchMarkersAreOnEveryPage(): void
    {
        // No route_help at all: the page a topic does not cover.
        $html = $this->render();

        $this->assertStringContainsString('id="help-panel"', $html);
        $this->assertStringContainsString('data-help-search-scope', $html);
        $this->assertStringContainsString('data-help-search-input', $html);
        $this->assertStringContainsString('data-help-search-results', $html);
        $this->assertStringContainsString('data-help-search-default', $html);
        $this->assertStringContainsString('/assets/js/help-search.js', $html);
        // …and it says what to do rather than showing an empty drawer.
        $this->assertStringContainsString('Aucun sujet ne décrit précisément cette page', $html);
    }

    public function testThePanelStillCarriesTheTopicsCoveringThePage(): void
    {
        $html = $this->render(['route_help' => [
            ['id' => 'journal', 'title' => 'Journal', 'summary' => 'Résumé.', 'html' => '<p>Corps du sujet.</p>', 'page_link' => null],
        ]]);

        $this->assertStringContainsString('data-help-topic="journal"', $html);
        $this->assertStringContainsString('Corps du sujet.', $html);
        $this->assertStringContainsString('href="/aide/journal"', $html);
        $this->assertStringNotContainsString('Aucun sujet ne décrit précisément cette page', $html);
    }

    public function testTheHelpButtonAlwaysOpensThePanelRatherThanLinkingToAide(): void
    {
        // It used to be a plain /aide link on an uncovered page. The panel
        // now always has the search in it, so there is one shape.
        $button = $this->render();

        $this->assertMatchesRegularExpression(
            '#<button[^>]+data-bs-target="\#help-panel"#',
            $button,
            'The help button must open the panel on every page.'
        );
    }
}
