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

    public function testFormFieldWiresHelpAndRequiredCorrectly(): void
    {
        $html = $this->render(
            "{% include 'partials/form_field.html.twig' with {
                field_id: 'asset-name', field_name: 'name', label: 'Nom du bien',
                help: 'Affiché publiquement.', required: true, maxlength: 100,
            } only %}"
        );

        $this->assertStringContainsString('for="asset-name"', $html);
        // The one thing 135 of the 136 hand-written help texts missed.
        $this->assertStringContainsString('aria-describedby="asset-name-help"', $html);
        $this->assertStringContainsString('id="asset-name-help"', $html);
        $this->assertStringContainsString(' required', $html);
        $this->assertStringContainsString('aria-hidden="true">*</span>', $html);
        $this->assertStringContainsString('maxlength="100"', $html);
    }

    public function testFormFieldSelectAndTextareaVariants(): void
    {
        $select = $this->render(
            "{% include 'partials/form_field.html.twig' with {
                field_id: 'kind', field_name: 'kind', label: 'Type', type: 'select',
                options: [ { value: 'a', label: 'A' }, { value: 'b', label: 'B', selected: true } ],
            } only %}"
        );
        $this->assertStringContainsString('<option value="b" selected>B</option>', $select);

        $textarea = $this->render(
            "{% include 'partials/form_field.html.twig' with {
                field_id: 'note', field_name: 'note', label: 'Note', type: 'textarea', value: 'Ligne <un>',
            } only %}"
        );
        $this->assertStringContainsString('rows="4"', $textarea);
        $this->assertStringContainsString('Ligne &lt;un&gt;', $textarea);
    }

    /**
     * The compact variant exists because 34 templates could not use this
     * partial at all: their labels carried `small`/`fw-semibold`/`mb-1` and
     * their controls `-sm`. Two sizes, and only two — a third would put the
     * partial back where the fourteen hand-written variants left it.
     */
    public function testFormFieldCompactVariantShrinksLabelAndControlTogether(): void
    {
        $input = $this->render(
            "{% include 'partials/form_field.html.twig' with {
                field_id: 'qty', field_name: 'qty', label: 'Quantité', type: 'number', size: 'sm',
            } only %}"
        );
        $this->assertStringContainsString('class="form-label small"', $input);
        $this->assertStringContainsString('class="form-control form-control-sm"', $input);

        $select = $this->render(
            "{% include 'partials/form_field.html.twig' with {
                field_id: 'kind', field_name: 'kind', label: 'Type', type: 'select', size: 'sm',
                options: [ { value: 'a', label: 'A' } ],
            } only %}"
        );
        $this->assertStringContainsString('class="form-select form-select-sm"', $select);

        $standard = $this->render(
            "{% include 'partials/form_field.html.twig' with {
                field_id: 'qty', field_name: 'qty', label: 'Quantité',
            } only %}"
        );
        $this->assertStringContainsString('class="form-label"', $standard);
        $this->assertStringNotContainsString('form-control-sm', $standard);
    }

    public function testFormFieldCarriesTheRemainingControlAttributes(): void
    {
        $html = $this->render(
            "{% include 'partials/form_field.html.twig' with {
                field_id: 'code', field_name: 'code', label: 'Code',
                pattern: '[0-9]{4}', list: 'code-suggestions', autofocus: true, readonly: true,
            } only %}"
        );

        $this->assertStringContainsString('pattern="[0-9]{4}"', $html);
        $this->assertStringContainsString('list="code-suggestions"', $html);
        $this->assertStringContainsString(' autofocus', $html);
        $this->assertStringContainsString(' readonly', $html);

        $disabledOption = $this->render(
            "{% include 'partials/form_field.html.twig' with {
                field_id: 'kind', field_name: 'kind', label: 'Type', type: 'select', disabled: true,
                options: [ { value: '', label: 'Choisissez…', selected: true, disabled: true } ],
            } only %}"
        );
        $this->assertStringContainsString('<select class="form-select" id="kind" name="kind" disabled', $disabledOption);
        $this->assertStringContainsString('<option value="" selected disabled>Choisissez…</option>', $disabledOption);
    }

    /**
     * A field whose error or hint node is rendered outside this partial
     * (auth/login.html.twig's `#email-error`, filled by auth.js) still has
     * to be announced. Both ids end up in one aria-describedby.
     */
    public function testFormFieldDescribedByCombinesHelpAndAnExternalNode(): void
    {
        $both = $this->render(
            "{% include 'partials/form_field.html.twig' with {
                field_id: 'email', field_name: 'email', label: 'Adresse email', type: 'email',
                help: 'Nous ne la partageons jamais.', describedby_extra: 'email-error',
            } only %}"
        );
        $this->assertStringContainsString('aria-describedby="email-help email-error"', $both);

        $externalOnly = $this->render(
            "{% include 'partials/form_field.html.twig' with {
                field_id: 'email', field_name: 'email', label: 'Adresse email', type: 'email',
                describedby_extra: 'email-error',
            } only %}"
        );
        $this->assertStringContainsString('aria-describedby="email-error"', $externalOnly);

        $neither = $this->render(
            "{% include 'partials/form_field.html.twig' with {
                field_id: 'email', field_name: 'email', label: 'Adresse email', type: 'email',
            } only %}"
        );
        $this->assertStringNotContainsString('aria-describedby', $neither);
    }

    public function testPagePickerFeedsChipPickerAndMatchesPrefixes(): void
    {
        $html = $this->render(
            "{% include 'partials/page_picker.html.twig' with {
                picker_id: 'finance-pages',
                pages: [ { url: '/finance', label: 'Tableau de bord' }, { url: '/finance/movements', label: 'Mouvements' } ],
                current_path: '/finance/movements/12',
                match_prefix: true,
            } only %}"
        );

        $this->assertStringContainsString('Tableau de bord', $html);
        $this->assertStringContainsString('Mouvements', $html);
        // Longest match wins: the sub-route selects « Mouvements » and
        // never also « Tableau de bord » for happening to sit at /finance.
        $this->assertMatchesRegularExpression('/data-id="\/finance\/movements"\s+data-selected="true"/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-id="\/finance"\s+data-selected="true"/', $html);
    }
}
