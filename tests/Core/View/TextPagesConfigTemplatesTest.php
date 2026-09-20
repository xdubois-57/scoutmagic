<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\View;

use Core\View\TwigFactory;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

/**
 * The two templates of Configuration › Site › Pages de texte
 * (ARCHITECTURE.md §8.116), rendered through the real `TwigFactory`
 * against the real partials.
 *
 * `Tests\Core\Page\TextPageConfigControllerTest` deliberately renders
 * stand-ins, so that its assertions read what the controller PREPARED
 * rather than how the markup happens to be laid out. That leaves the
 * markup itself unexercised — a mistyped filter, a block name the embed
 * does not declare, a partial called with the wrong arguments would all
 * ship green. This closes that, on the two properties worth stating
 * rather than on the HTML as a whole.
 */
final class TextPagesConfigTemplatesTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $this->twig = TwigFactory::create(dirname(__DIR__, 3) . '/core/View/templates');
    }

    /**
     * @param array<string, mixed> $context
     */
    private function renderBlock(string $template, array $context): string
    {
        // `{% extends %}` needs base.html.twig and every global it reads;
        // rendering just the content block is what makes a template test
        // of an extending template possible at all — same approach as
        // Tests\Core\View\MemberShowTemplateTest.
        return $this->twig->load($template)->renderBlock('content', $context);
    }

    public function testTheListDrawsOneRowPerPageWithItsPlacementAndAddress(): void
    {
        $html = $this->renderBlock('config/text_pages/index.html.twig', [
            'sections' => [[
                'id' => 'configuration',
                'label' => 'Configuration',
                'pages' => [[
                    'id' => 3,
                    'is_active' => true,
                    'menu_label' => 'ASBL',
                    'title' => 'Notre ASBL',
                    'section_label' => 'Configuration',
                    'group_label' => 'Site',
                    'path' => '/pages/notre-asbl',
                ]],
            ]],
            'site_name' => 'Test Unité',
        ]);

        $this->assertStringContainsString('ASBL', $html);
        $this->assertStringContainsString('Configuration', $html);
        $this->assertStringContainsString('Site', $html);
        $this->assertStringContainsString('/pages/notre-asbl', $html);

        // The list_editor's own chrome, which is the whole point of
        // embedding it rather than hand-rolling a list.
        $this->assertStringContainsString('list-editor-item', $html);
        $this->assertStringContainsString('data-reorder-url="/config/pages-de-texte/ordre"', $html);
        $this->assertStringContainsString('data-active-url="/config/pages-de-texte/activation"', $html);
        $this->assertStringContainsString('data-delete-url="/config/pages-de-texte/suppression"', $html);
    }

    /**
     * **No extra checkbox.** The chantier is explicit: activation is the
     * toggle the shared list already draws, and a second control for the
     * same state is a second answer to one question.
     */
    public function testTheListCarriesTheToggleAndNoCheckboxOfItsOwn(): void
    {
        $html = $this->renderBlock('config/text_pages/index.html.twig', [
            'sections' => [[
                'id' => 'notre_unite',
                'label' => 'Notre unité',
                'pages' => [[
                    'id' => 3, 'is_active' => false, 'menu_label' => 'ASBL', 'title' => 'Notre ASBL',
                    'section_label' => 'Notre unité', 'group_label' => null, 'path' => '/pages/notre-asbl',
                ]],
            ]],
            'site_name' => 'Test Unité',
        ]);

        $this->assertStringContainsString('list-editor-active-toggle', $html);
        $this->assertStringContainsString('bi-toggle-off', $html);
        $this->assertStringNotContainsString('type="checkbox"', $html);
    }

    /**
     * The add affordance is a link, not the partial's own blind-create
     * button: a page cannot exist before it has a name, a title and a
     * place.
     */
    public function testAddingIsALinkToTheFormRatherThanABlindCreate(): void
    {
        $html = $this->renderBlock('config/text_pages/index.html.twig', [
            'sections' => [['id' => 'notre_unite', 'label' => 'Notre unité', 'pages' => []]],
            'site_name' => 'Test Unité',
        ]);

        $this->assertStringContainsString('href="/config/pages-de-texte/nouveau"', $html);
        $this->assertStringNotContainsString('list-editor-add-btn', $html);
    }

    public function testTheCreationFormPostsToTheCollectionAndSaysCreerEtOuvrir(): void
    {
        $html = $this->renderBlock('config/text_pages/form.html.twig', [
            'page' => null,
            'sections' => [['value' => 'notre_unite', 'label' => 'Notre unité', 'selected' => true]],
            'groups_by_section' => ['notre_unite' => []],
            'selected_group' => null,
            'site_name' => 'Test Unité',
            'csp_nonce' => 'n',
        ]);

        $this->assertStringContainsString('action="/config/pages-de-texte"', $html);
        $this->assertStringContainsString('Créer et ouvrir', $html);
        $this->assertStringContainsString('name="menu_label"', $html);
        $this->assertStringContainsString('name="title"', $html);
        $this->assertStringContainsString('name="menu_id"', $html);

        // No activation field: it lives on the list's row.
        $this->assertStringNotContainsString('name="is_active"', $html);
        // And no address field: the slug is derived once and frozen.
        $this->assertStringNotContainsString('name="slug"', $html);
    }

    /**
     * A section that declares no columns opens with the picker already
     * hidden, before any JavaScript runs — a form that flashes a control
     * it is about to remove reads as broken.
     */
    public function testTheColumnPickerStartsHiddenForASectionWithoutColumns(): void
    {
        $html = $this->renderBlock('config/text_pages/form.html.twig', [
            'page' => null,
            'sections' => [['value' => 'notre_unite', 'label' => 'Notre unité', 'selected' => true]],
            'groups_by_section' => ['notre_unite' => []],
            'selected_group' => null,
            'site_name' => 'Test Unité',
            'csp_nonce' => 'n',
        ]);

        $this->assertMatchesRegularExpression('/data-text-page-group-field[^>]*hidden/', $html);
    }

    public function testTheEditFormPostsToThePageAndOffersNoRenameOfItsAddress(): void
    {
        $page = new \Core\Page\TextPage(
            id: 7,
            slug: 'notre-asbl',
            menuLabel: 'ASBL',
            title: 'Notre ASBL',
            menuId: 'configuration',
            menuGroup: 'site',
            sortOrder: 0,
            isActive: true,
        );

        $html = $this->renderBlock('config/text_pages/form.html.twig', [
            'page' => $page,
            'sections' => [['value' => 'configuration', 'label' => 'Configuration', 'selected' => true]],
            'groups_by_section' => ['configuration' => ['site' => 'Site']],
            'selected_group' => 'site',
            'site_name' => 'Test Unité',
            'csp_nonce' => 'n',
        ]);

        $this->assertStringContainsString('action="/config/pages-de-texte/7"', $html);
        $this->assertStringContainsString('Enregistrer', $html);
        $this->assertStringNotContainsString('Créer et ouvrir', $html);
        $this->assertStringNotContainsString('name="slug"', $html);
    }

    /**
     * **One sortable list per section, and each one says so.** The screen
     * looked like a single list of everything, which is the shape that
     * invites a drag across a boundary — and `sort_order` is only ever
     * compared within a menu, so that drag could not be honoured.
     */
    public function testEachSectionGetsItsOwnSortableListUnderItsOwnHeading(): void
    {
        $html = $this->renderBlock('config/text_pages/index.html.twig', [
            'sections' => [
                ['id' => 'notre_unite', 'label' => 'Notre unité', 'pages' => [[
                    'id' => 1, 'is_active' => true, 'menu_label' => 'ASBL', 'title' => 'Notre ASBL',
                    'section_label' => 'Notre unité', 'group_label' => null, 'path' => '/pages/notre-asbl',
                ]]],
                ['id' => 'configuration', 'label' => 'Configuration', 'pages' => []],
            ],
            'site_name' => 'Test Unité',
        ]);

        $this->assertStringContainsString('id="text-page-list-notre_unite"', $html);
        $this->assertStringContainsString('id="text-page-list-configuration"', $html);
        $this->assertStringContainsString('Notre unité', $html);
        $this->assertStringContainsString('Aucune page dans cette section.', $html);
    }

    /**
     * **The list is inert without these two.** The partial draws the drag
     * handle, the toggle and the bin; `list-editor.js` is what binds them,
     * and `base.html.twig` loads neither globally. A page that embeds the
     * partial and forgets the scripts ships a screen where every control
     * does nothing — and nothing else in the suite would notice.
     */
    public function testTheListLoadsTheScriptsThatMakeItWork(): void
    {
        $html = $this->twig->load('config/text_pages/index.html.twig')->renderBlock('scripts', []);

        $this->assertStringContainsString('/assets/js/sortable.js', $html);
        $this->assertStringContainsString('/assets/js/list-editor.js', $html);
    }

    public function testTheFormLoadsTheScriptThatDrivesItsColumnPicker(): void
    {
        $html = $this->twig->load('config/text_pages/form.html.twig')->renderBlock('scripts', []);

        $this->assertStringContainsString('/assets/js/text-page-form.js', $html);
    }
}
