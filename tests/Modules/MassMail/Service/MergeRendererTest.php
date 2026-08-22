<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Service;

use Modules\MassMail\Service\MergeRenderer;
use PHPUnit\Framework\TestCase;

class MergeRendererTest extends TestCase
{
    private MergeRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new MergeRenderer();
    }

    public function testRenderHtmlSubstitutesTokens(): void
    {
        $result = $this->renderer->renderHtml(
            '<p>Cher {{Prenom}}, tu devras payer {{Montant}} €.</p>',
            ['Prenom' => 'Louis', 'Montant' => '145']
        );

        $this->assertSame('<p>Cher Louis, tu devras payer 145 €.</p>', $result);
    }

    public function testTokensMatchCaseInsensitivelyWithWhitespace(): void
    {
        $result = $this->renderer->renderHtml('<p>{{ prenom }} et {{PRENOM}}</p>', ['Prenom' => 'Louis']);

        $this->assertSame('<p>Louis et Louis</p>', $result);
    }

    /**
     * The values come from a chief-uploaded Excel file — arbitrary input.
     * Without escaping, a cell containing markup would be injected
     * verbatim into the email body that HtmlSanitizer already cleaned.
     */
    public function testRenderHtmlEscapesSubstitutedValues(): void
    {
        $result = $this->renderer->renderHtml('<p>{{Nom}}</p>', ['Nom' => '<script>alert(1)</script>']);

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
    }

    public function testRenderTextDoesNotHtmlEscape(): void
    {
        $result = $this->renderer->renderText('Infos {{Qui}}', ['Qui' => "L'équipe & co"]);

        $this->assertSame("Infos L'équipe & co", $result);
    }

    public function testUnknownTokenIsLeftUntouched(): void
    {
        $result = $this->renderer->renderHtml('<p>{{Inconnu}} et {{Prenom}}</p>', ['Prenom' => 'Louis']);

        $this->assertSame('<p>{{Inconnu}} et Louis</p>', $result);
    }

    public function testEmptyValueSubstitutesToEmptyString(): void
    {
        $result = $this->renderer->renderHtml('<p>x{{Montant}}y</p>', ['Montant' => '']);

        $this->assertSame('<p>xy</p>', $result);
    }

    public function testFindUnknownTokens(): void
    {
        $unknown = $this->renderer->findUnknownTokens(
            'Cher {{Prenom}}, {{Montnat}} € — {{ montnat }}',
            ['Prenom', 'Montant']
        );

        $this->assertSame(['Montnat', 'montnat'], $unknown);
    }

    public function testFindMissingValuesOnlyReportsUsedEmptyColumns(): void
    {
        $missing = $this->renderer->findMissingValues(
            '<p>{{Prenom}} {{Montant}}</p>',
            ['Prenom' => 'Louis', 'Montant' => '', 'Autre' => '']
        );

        // 'Autre' is empty too but the template never uses it.
        $this->assertSame(['Montant'], $missing);
    }
}
