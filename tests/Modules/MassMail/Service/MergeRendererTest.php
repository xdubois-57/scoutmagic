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

    // ── sections: {{#Colonne}} … {{/Colonne}} ───────────────────────────

    /**
     * The reason sections exist: a personalised body is not the same
     * LENGTH for everybody. A payment reminder carries one block per
     * receivable, and a household with one child must not receive the
     * empty blocks the household with three needs.
     */
    public function testASectionIsKeptWhenItsColumnHasAValue(): void
    {
        $rendered = $this->renderer->renderHtml(
            '<p>{{Prénom 1}}</p>{{#Prénom 2}}<p>{{Prénom 2}}</p>{{/Prénom 2}}',
            ['Prénom 1' => 'Lucie', 'Prénom 2' => 'Antoine']
        );

        $this->assertSame('<p>Lucie</p><p>Antoine</p>', $rendered);
    }

    public function testASectionDisappearsWhenItsColumnIsEmptyForThisRow(): void
    {
        $rendered = $this->renderer->renderHtml(
            '<p>{{Prénom 1}}</p>{{#Prénom 2}}<p>{{Prénom 2}}</p>{{/Prénom 2}}',
            ['Prénom 1' => 'Lucie', 'Prénom 2' => '']
        );

        $this->assertSame('<p>Lucie</p>', $rendered);
    }

    public function testAWhitespaceOnlyValueCountsAsEmpty(): void
    {
        $rendered = $this->renderer->renderHtml(
            '{{#Bloc}}visible{{/Bloc}}',
            ['Bloc' => "  \n "]
        );

        $this->assertSame('', $rendered);
    }

    /**
     * Same rule as an unknown token: a section naming no column stays
     * visible in the preview rather than swallowing its block silently.
     */
    public function testASectionNamingNoColumnIsLeftUntouchedAndReported(): void
    {
        $template = '{{#Inconnue}}bloc{{/Inconnue}}';

        $this->assertSame($template, $this->renderer->renderHtml($template, ['Prénom' => 'Lucie']));
        $this->assertSame(['Inconnue'], $this->renderer->findUnknownTokens($template, ['Prénom']));
    }

    public function testSeveralSiblingSectionsAreResolvedIndependently(): void
    {
        $rendered = $this->renderer->renderHtml(
            '{{#A}}[a]{{/A}}{{#B}}[b]{{/B}}{{#C}}[c]{{/C}}',
            ['A' => 'x', 'B' => '', 'C' => 'z']
        );

        $this->assertSame('[a][c]', $rendered);
    }

    public function testASectionWorksInThePlainTextSubjectToo(): void
    {
        $this->assertSame(
            'Rappel',
            $this->renderer->renderText('Rappel{{#Suite}} et suite{{/Suite}}', ['Suite' => ''])
        );
    }

    /**
     * A column used only to open a section is EXPECTED to be empty for
     * some rows — warning about it would flag the mechanism itself on
     * every single-child family.
     */
    public function testAnEmptyColumnUsedOnlyAsASectionIsNotReportedAsMissing(): void
    {
        $this->assertSame(
            [],
            $this->renderer->findMissingValues('{{#Prénom 2}}<p>{{Prénom 2}}</p>{{/Prénom 2}}', ['Prénom 2' => ''])
        );
    }

    public function testAnEmptyColumnUsedOUTSIDEASectionIsStillReportedAsMissing(): void
    {
        $this->assertSame(
            ['Montant'],
            $this->renderer->findMissingValues('<p>{{Montant}}</p>', ['Montant' => ''])
        );
    }

    /**
     * Escaping is not relaxed inside a section: the values are still a
     * chief's spreadsheet.
     */
    public function testAValueInsideASectionIsEscapedLikeAnyOther(): void
    {
        $rendered = $this->renderer->renderHtml(
            '{{#Nom}}<p>{{Nom}}</p>{{/Nom}}',
            ['Nom' => '<script>alert(1)</script>']
        );

        $this->assertStringNotContainsString('<script>', $rendered);
    }

    // ── tokens that went through a URL attribute ────────────────────────

    /**
     * A token inside an href or a src comes back percent-encoded: the
     * rich-text sanitizer parses the body with DOMDocument, which
     * URL-encodes every URI attribute on the way out. Left unrecognised
     * the variable would never substitute and the recipient would get a
     * broken link — silently, which is the worst of both.
     */
    public function testAPercentEncodedTokenInAnAttributeStillSubstitutes(): void
    {
        $rendered = $this->renderer->renderHtml(
            '<img src="%7B%7BQR%201%7D%7D" alt="">',
            ['QR 1' => 'https://scoutmagic.test/finance/qr/1/abc']
        );

        $this->assertSame('<img src="https://scoutmagic.test/finance/qr/1/abc" alt="">', $rendered);
    }

    public function testAPercentEncodedTokenIsReportedWhenItNamesNoColumn(): void
    {
        $this->assertSame(
            ['QR 1'],
            $this->renderer->findUnknownTokens('<img src="%7B%7BQR%201%7D%7D">', ['Prénom'])
        );
    }
}
