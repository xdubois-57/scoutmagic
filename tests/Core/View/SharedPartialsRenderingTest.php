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
 * The shared page primitives (design.md §7): empty_state, page_header,
 * pagination, stat_tiles, status_badge, and the modal embed — rendered
 * through the real TwigFactory against the real partials, because these
 * exist precisely so that a hundred call sites stop hand-rolling their
 * own variants, and a silent rendering regression here is a regression
 * on every one of them at once.
 */
final class SharedPartialsRenderingTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $this->twig = TwigFactory::create(dirname(__DIR__, 3) . '/core/View/templates');
    }

    private function render(string $source, array $context = []): string
    {
        return $this->twig->createTemplate($source)->render($context);
    }

    public function testEmptyStateRendersMessageAndAction(): void
    {
        $html = $this->render(
            "{% include 'partials/empty_state.html.twig' with {
                message: 'Aucune actualité pour le moment.',
                action_label: 'Nouvel article',
                action_url: '/news/new',
            } only %}"
        );

        $this->assertStringContainsString('Aucune actualité pour le moment.', $html);
        $this->assertStringContainsString('href="/news/new"', $html);
        $this->assertStringContainsString('btn-primary', $html);
        $this->assertStringContainsString('text-center', $html);
    }

    public function testEmptyStateCompactVariantIsOneItalicLine(): void
    {
        $html = $this->render(
            "{% include 'partials/empty_state.html.twig' with {
                message: 'Aucun document pour cette année.', compact: true,
            } only %}"
        );

        $this->assertStringContainsString('fst-italic', $html);
        $this->assertStringNotContainsString('py-5', $html);
        $this->assertStringNotContainsString('btn', $html);
    }

    public function testEmptyStateColspanWrapsATableRow(): void
    {
        $html = $this->render(
            "{% include 'partials/empty_state.html.twig' with {
                message: 'Aucun email.', colspan: 6,
            } only %}"
        );

        $this->assertStringContainsString('<td colspan="6">', $html);
    }

    public function testPageHeaderRendersOneH1AtTheCanonicalSize(): void
    {
        $html = $this->render(
            "{% include 'partials/page_header.html.twig' with {
                title: 'Mouvements',
                subtitle: 'Compte principal',
                action_label: 'Nouveau reçu',
                action_url: '/finance/receipts/new',
            } only %}"
        );

        $this->assertStringContainsString('<h1 class="h3 mb-0">Mouvements</h1>', $html);
        $this->assertStringContainsString('Compte principal', $html);
        // The primary action: btn-primary, full width on mobile only.
        $this->assertStringContainsString('btn btn-primary w-100 w-sm-auto', $html);
        $this->assertStringContainsString('href="/finance/receipts/new"', $html);
    }

    public function testPaginationLinkModeWindowsAndEllipses(): void
    {
        $html = $this->render(
            "{% include 'partials/pagination.html.twig' with {
                page: 10, total_pages: 20, base_url: '/admin/journal?page=',
            } only %}"
        );

        // First, last, the ±2 window, two ellipses — and no page 4 or 16.
        foreach ([1, 8, 9, 10, 11, 12, 20] as $p) {
            $this->assertStringContainsString('href="/admin/journal?page=' . $p . '"', $html);
        }
        $this->assertStringNotContainsString('href="/admin/journal?page=4"', $html);
        $this->assertSame(2, substr_count($html, '…'));
        $this->assertStringContainsString('aria-current="page"', $html);
    }

    public function testPaginationAjaxModeRendersButtonsNotLinks(): void
    {
        $html = $this->render(
            "{% include 'partials/pagination.html.twig' with {
                page: 1, total_pages: 3, data_attr: 'data-transitions-page',
            } only %}"
        );

        $this->assertStringContainsString('data-transitions-page="2"', $html);
        $this->assertStringNotContainsString('<a ', $html);
        // Page 1: the previous control is disabled.
        $this->assertStringContainsString('page-item disabled', $html);
    }

    public function testPaginationRendersNothingForASinglePage(): void
    {
        $html = $this->render(
            "{% include 'partials/pagination.html.twig' with {
                page: 1, total_pages: 1, base_url: '/x?page=',
            } only %}"
        );

        $this->assertSame('', trim($html));
    }

    public function testStatTilesRenderLabelValueAndTonedIcon(): void
    {
        $html = $this->render(
            "{% include 'partials/stat_tiles.html.twig' with {
                tiles: [
                    { label: 'Animés', value: 42, icon: 'bi-people-fill' },
                    { label: 'Variation', value: '-2', icon: 'bi-arrow-left-right', tone: 'danger', sublabel: 'vs. 2025' },
                ],
            } only %}"
        );

        $this->assertStringContainsString('Animés', $html);
        $this->assertStringContainsString('42', $html);
        $this->assertStringContainsString('text-danger', $html);
        $this->assertStringContainsString('vs. 2025', $html);
        $this->assertStringContainsString('row-cols-2', $html);
    }

    public function testStatusBadgeMapsSeverityToOneColourEach(): void
    {
        $cases = [
            ['pending', "En attente", 'text-bg-warning'],
            ['confirmed', 'Confirmée', 'text-bg-success'],
            ['refused', 'Refusée', 'text-bg-danger'],
            ['cancelled', 'Annulée', 'text-bg-secondary'],
            ['draft', 'Brouillon', 'text-bg-info'],
            // An unknown state is a grey fact, never a silent green.
            ['weird_new_state', 'Autre', 'text-bg-secondary'],
        ];
        foreach ($cases as [$status, $label, $expectedClass]) {
            $html = $this->render(
                "{% include 'partials/status_badge.html.twig' with { status: status, label: label } only %}",
                ['status' => $status, 'label' => $label]
            );
            $this->assertStringContainsString($expectedClass, $html, $status);
            $this->assertStringContainsString($label, $html);
        }
    }

    public function testModalEmbedGeneratesTheAccessibilityWiring(): void
    {
        $html = $this->render(
            "{% embed 'partials/modal.html.twig' with { id: 'rename-modal', title: 'Renommer' } only %}
                {% block body %}<p>Contenu</p>{% endblock %}
                {% block footer %}<button type=\"submit\" class=\"btn btn-primary\">Enregistrer</button>{% endblock %}
            {% endembed %}"
        );

        $this->assertStringContainsString('aria-labelledby="rename-modal-title"', $html);
        $this->assertStringContainsString('<h2 class="modal-title h5" id="rename-modal-title">Renommer</h2>', $html);
        $this->assertStringContainsString('aria-label="Fermer"', $html);
        $this->assertStringContainsString('modal-fullscreen-sm-down', $html);
        $this->assertStringContainsString('modal-footer', $html);
        $this->assertStringContainsString('Contenu', $html);
    }

    public function testModalEmbedSkipsTheFooterWrapperWhenEmpty(): void
    {
        $html = $this->render(
            "{% embed 'partials/modal.html.twig' with { id: 'info-modal', title: 'Détail', fullscreen_sm: false } only %}
                {% block body %}<p>Contenu</p>{% endblock %}
            {% endembed %}"
        );

        $this->assertStringNotContainsString('modal-footer', $html);
        $this->assertStringNotContainsString('modal-fullscreen-sm-down', $html);
    }
}
