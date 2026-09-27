<?php

declare(strict_types=1);

namespace Tests\Modules\Covoiturage;

use PHPUnit\Framework\TestCase;

/**
 * The organiser's map has a height only if the page loads the stylesheet
 * that gives it one.
 *
 * Issue #570: « Placer le point sur la carte » showed nothing. The rule
 * was there — `.carpool-point-map { height: … }` in components.css — but
 * base.html.twig deliberately does not load components.css, every page
 * opts in through its own `{% block stylesheets %}`, and this one never
 * did. Leaflet drawn into a zero-height box renders nothing, in every
 * browser. The markup was valid and the CSS was correct: only the link
 * between the two was missing, which is what this test holds.
 */
final class OrganizerMapStylesheetTest extends TestCase
{
    private const TEMPLATE = '/modules/covoiturage/views/organize/form.html.twig';
    private const STYLESHEET = '/public/assets/css/components.css';

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public function testTheMapContainerIsGivenAHeightInComponentsCss(): void
    {
        $css = (string) file_get_contents($this->root() . self::STYLESHEET);

        $this->assertMatchesRegularExpression(
            '/\.carpool-point-map\s*\{[^}]*\bheight\s*:\s*[1-9][^;]*;/',
            $css,
            'components.css no longer gives .carpool-point-map a height: Leaflet renders nothing in a zero-height box'
        );
    }

    public function testTheOrganiserFormLoadsComponentsCss(): void
    {
        $template = (string) file_get_contents($this->root() . self::TEMPLATE);

        $this->assertStringContainsString('class="carpool-point-map"', $template, 'the map container moved: update this test');

        $matched = preg_match('/\{%\s*block stylesheets\s*%\}(?P<body>.*?)\{%\s*endblock\s*%\}/s', $template, $block);
        $this->assertSame(1, $matched, 'the organiser form has no stylesheets block any more');

        // Comments stripped: a link mentioned in a Twig comment loads nothing.
        $links = (string) preg_replace('/\{#.*?#\}/s', '', $block['body']);
        $this->assertStringContainsString(
            "asset('/assets/css/components.css')",
            $links,
            'the organiser form does not link components.css: base.html.twig does not load it, '
            . 'so the map box has no height and shows nothing (issue #570)'
        );
    }
}
