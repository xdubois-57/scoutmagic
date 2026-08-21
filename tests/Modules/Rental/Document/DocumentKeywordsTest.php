<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Document;

use Modules\Rental\Document\DocumentKeywords;
use PHPUnit\Framework\TestCase;

/**
 * Keyword substitution in contracts and invoices (§6.25).
 *
 * The security half of this file is the point. dompdf renders HTML, and the
 * values substituted here come from a form an anonymous visitor filled in —
 * a renter's name, their organisation, their address. If any of that is
 * inserted unescaped, a renter can reshape or break the contract they are
 * about to sign, and nobody would notice until it was printed.
 */
class DocumentKeywordsTest extends TestCase
{
    // ── The escaping that makes this safe ───────────────────────────────

    public function testAValueContainingMarkupIsEscapedNotInterpreted(): void
    {
        $rendered = DocumentKeywords::substitute(
            '<p>Locataire : {{ locataire_nom }}</p>',
            ['locataire_nom' => '<script>alert(1)</script>']
        );

        $this->assertStringNotContainsString('<script>', $rendered);
        $this->assertStringContainsString('&lt;script&gt;', $rendered);
    }

    public function testAnOrganisationWithAnglesRendersAsTypedRatherThanAsATag(): void
    {
        // The realistic case, far more likely than a script tag: a name
        // that merely happens to contain characters HTML cares about.
        $rendered = DocumentKeywords::substitute(
            '<p>{{ locataire_organisation }}</p>',
            ['locataire_organisation' => 'Scouts <de> Nulle Part & Cie']
        );

        $this->assertStringContainsString('Scouts &lt;de&gt; Nulle Part &amp; Cie', $rendered);
    }

    public function testAValueCannotCloseTheSurroundingElementAndInjectItsOwn(): void
    {
        $rendered = DocumentKeywords::substitute(
            '<p>Locataire : {{ locataire_nom }}</p>',
            ['locataire_nom' => '</p><h1>Contrat annulé</h1><p>']
        );

        $this->assertStringNotContainsString('<h1>', $rendered);
        // Exactly one paragraph survives: the injected markup did not
        // become structure.
        $this->assertSame(1, substr_count($rendered, '<p>'));
    }

    public function testAValueCannotBreakOutOfAnAttribute(): void
    {
        $rendered = DocumentKeywords::substitute(
            '<td title="{{ locataire_nom }}">x</td>',
            ['locataire_nom' => '" onmouseover="evil()']
        );

        // The words survive as ATTRIBUTE TEXT, which is harmless — what
        // matters is that the quotes closing the attribute did not.
        $this->assertSame('<td title="&quot; onmouseover=&quot;evil()">x</td>', $rendered);
        $this->assertSame(2, substr_count($rendered, '"'), 'Only the template\'s own quotes may remain.');
    }

    public function testAValueThatLooksLikeAnotherKeywordIsNotSubstitutedAgain(): void
    {
        // A single pass, so a renter cannot smuggle a second keyword in
        // through their own name and reach a value the template never asked
        // for.
        $rendered = DocumentKeywords::substitute(
            '<p>{{ locataire_nom }}</p>',
            ['locataire_nom' => '{{ prix_total }}', 'prix_total' => '467,50 €']
        );

        $this->assertStringNotContainsString('467,50', $rendered);
    }

    // ── Ordinary substitution ───────────────────────────────────────────

    public function testEveryKnownKeywordIsReplaced(): void
    {
        $rendered = DocumentKeywords::substitute(
            '<p>{{ reference }} — {{ bien }} — {{ prix_total }}</p>',
            ['reference' => 'LOC-2027-0042', 'bien' => 'Local Saint-Georges', 'prix_total' => '467,50 €']
        );

        $this->assertSame('<p>LOC-2027-0042 — Local Saint-Georges — 467,50 €</p>', $rendered);
    }

    public function testSpacingInsideTheBracesIsToleratedBecausePeopleTypeIt(): void
    {
        $this->assertSame(
            'A A A',
            DocumentKeywords::substitute('{{reference}} {{ reference }} {{   reference   }}', [
                'reference' => 'A',
            ])
        );
    }

    public function testAMissingValueBecomesADashRatherThanAnEmptyGap(): void
    {
        // "Organisation : —" reads as "there isn't one"; "Organisation :"
        // reads as a bug.
        $rendered = DocumentKeywords::substitute(
            '<p>Organisation : {{ locataire_organisation }}</p>',
            ['locataire_organisation' => null]
        );

        $this->assertSame('<p>Organisation : —</p>', $rendered);
    }

    public function testAnEmptyStringIsTreatedAsMissing(): void
    {
        $this->assertSame('—', DocumentKeywords::substitute('{{ caution }}', ['caution' => '   ']));
    }

    public function testAKeywordWithNoValueSuppliedIsLeftAlone(): void
    {
        // Left visible rather than blanked: a contract showing
        // `{{ acompte }}` is obviously wrong to whoever reads it, whereas a
        // silently emptied one reads as a clause that says nothing.
        $this->assertSame('{{ acompte }}', DocumentKeywords::substitute('{{ acompte }}', []));
    }

    public function testAnUnknownKeywordSurvivesUntouched(): void
    {
        $this->assertSame(
            '{{ prix_ttc }}',
            DocumentKeywords::substitute('{{ prix_ttc }}', ['prix_ttc' => '600,00 €'])
        );
    }

    public function testTextWithNoKeywordsIsReturnedUnchanged(): void
    {
        $html = '<p>Le local est rendu balayé. Les poubelles sont évacuées.</p>';

        $this->assertSame($html, DocumentKeywords::substitute($html, ['reference' => 'X']));
    }

    public function testBracesThatAreNotKeywordsAreNotTouched(): void
    {
        // A contract's prose may legitimately contain braces; the pattern is
        // narrow so "unknown keyword" reporting stays useful.
        $html = '<p>Voir { annexe 1 } et {{ Article 3 }}.</p>';

        $this->assertSame($html, DocumentKeywords::substitute($html, []));
        $this->assertSame([], DocumentKeywords::unknownIn($html));
    }

    // ── Reporting unknown keywords at edit time ─────────────────────────

    public function testUnknownKeywordsAreReported(): void
    {
        $unknown = DocumentKeywords::unknownIn('<p>{{ reference }} {{ prix_ttc }} {{ remise }}</p>');

        $this->assertSame(['prix_ttc', 'remise'], $unknown);
    }

    public function testTheSameUnknownKeywordIsReportedOnce(): void
    {
        $this->assertSame(
            ['prix_ttc'],
            DocumentKeywords::unknownIn('{{ prix_ttc }} et encore {{ prix_ttc }}')
        );
    }

    public function testACleanTemplateReportsNothing(): void
    {
        $this->assertSame([], DocumentKeywords::unknownIn('<p>{{ reference }} {{ bien }}</p>'));
    }

    // ── The catalogue ───────────────────────────────────────────────────

    public function testTheCatalogueIsClosedAndEveryEntryIsDocumented(): void
    {
        $catalogue = DocumentKeywords::catalogue();

        $this->assertNotSame([], $catalogue);
        foreach ($catalogue as $entry) {
            $this->assertTrue(DocumentKeywords::isKnown($entry['keyword']));
            $this->assertSame('{{ ' . $entry['keyword'] . ' }}', $entry['placeholder']);
            // Shown beside the editor as the only explanation an author
            // ever gets.
            $this->assertNotSame('', trim($entry['description']));
        }
    }

    public function testTheSpecsOwnExamplesAreAllKnown(): void
    {
        foreach ([
            'bien', 'date_arrivee', 'date_depart', 'participants', 'prix_total',
            'acompte', 'caution', 'locataire_nom', 'locataire_organisation',
            'adresse_bailleur', 'reference',
        ] as $keyword) {
            $this->assertTrue(DocumentKeywords::isKnown($keyword), $keyword . ' must exist.');
        }
    }

    public function testAnInventedKeywordIsNotKnown(): void
    {
        $this->assertFalse(DocumentKeywords::isKnown('mot_de_passe_admin'));
    }
}
