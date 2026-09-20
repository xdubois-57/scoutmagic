<?php

declare(strict_types=1);

namespace Tests\Core\Template;

use Core\Template\TokenEngine;
use Core\Template\TokenSyntax;
use PHPUnit\Framework\TestCase;

/**
 * The shared `{{ … }}` engine (ARCHITECTURE.md §8.114).
 *
 * Two modules used to carry a copy each of the four rules asserted here —
 * substitute after sanitizing, always escape, leave an unknown token
 * visible, rescue a token the sanitizer percent-encoded. The fifth is new
 * and is what the rental document editor needed to stop being a
 * `<textarea>`: a token a rich-text surface broke apart with inline markup
 * is welded back together before anything tries to substitute it.
 */
class TokenEngineTest extends TestCase
{
    private function identifiers(): TokenEngine
    {
        return new TokenEngine(TokenSyntax::identifiers());
    }

    private function freeText(): TokenEngine
    {
        return new TokenEngine(TokenSyntax::freeText());
    }

    // ── Escaping: the rule that makes substitution safe ─────────────────

    public function testASubstitutedValueIsEscapedInAnHtmlContext(): void
    {
        $rendered = $this->identifiers()->substitute(
            '<p>{{ locataire_nom }}</p>',
            static fn(string $name): ?string => '<script>alert(1)</script>',
            true
        );

        $this->assertStringNotContainsString('<script>', $rendered);
        $this->assertStringContainsString('&lt;script&gt;', $rendered);
    }

    public function testASubstitutedValueIsVerbatimInAPlainTextContext(): void
    {
        $rendered = $this->identifiers()->substitute(
            'Objet : {{ bien }}',
            static fn(string $name): ?string => 'Local « Petit Ry » <>',
            false
        );

        $this->assertSame('Objet : Local « Petit Ry » <>', $rendered);
    }

    public function testQuotesAreEscapedToo(): void
    {
        $rendered = $this->identifiers()->substitute(
            '<p title="{{ bien }}"></p>',
            static fn(string $name): ?string => 'Le "Petit" Ry',
            true
        );

        $this->assertStringNotContainsString('"Petit"', $rendered);
    }

    // ── An unrecognised token stays visible ─────────────────────────────

    public function testAResolverThatAnswersNullLeavesTheTokenExactlyWhereItIs(): void
    {
        $rendered = $this->identifiers()->substitute(
            '<p>{{ prix_ttc }}</p>',
            static fn(string $name): ?string => null,
            true
        );

        $this->assertSame('<p>{{ prix_ttc }}</p>', $rendered);
    }

    public function testUnknownTokensAreReportedDistinctAndInOrder(): void
    {
        $unknown = $this->identifiers()->unknownTokens(
            '{{ prix_ttc }} {{ bien }} {{ prix_ttc }} {{ tva }}',
            static fn(string $name): bool => $name === 'bien'
        );

        $this->assertSame(['prix_ttc', 'tva'], $unknown);
    }

    public function testTheNormaliserDecidesWhatIsLookedUpAndWhatIsReported(): void
    {
        $unknown = $this->freeText()->unknownTokens(
            '{{#Montant}}…{{/Montant}}',
            static fn(string $name): bool => false,
            static fn(string $name): string => ltrim($name, '#/ ')
        );

        $this->assertSame(['Montant'], $unknown);
    }

    public function testANormaliserThatEmptiesANameDropsItFromTheReport(): void
    {
        $unknown = $this->freeText()->unknownTokens(
            '{{#}}',
            static fn(string $name): bool => false,
            static fn(string $name): string => ltrim($name, '#/ ')
        );

        $this->assertSame([], $unknown);
    }

    // ── The percent-encoded rescue ──────────────────────────────────────

    public function testAPercentEncodedTokenIsDecodedBackToItsSource(): void
    {
        $this->assertSame(
            '<a href="{{QR 1}}">x</a>',
            $this->freeText()->decodeEncodedTokens('<a href="%7B%7BQR%201%7D%7D">x</a>')
        );
    }

    public function testContainsTokenSeesAPercentEncodedOneToo(): void
    {
        $this->assertTrue($this->freeText()->containsToken('<a href="%7B%7BQR%201%7D%7D">x</a>'));
        $this->assertFalse($this->freeText()->containsToken('<p>Rien à personnaliser</p>'));
    }

    // ── The repair pass: a token broken by inline markup ────────────────

    public function testInlineMarkupInsideATokenIsRemoved(): void
    {
        $this->assertSame(
            '<p>{{ prix_total }}</p>',
            $this->identifiers()->repairTokensSplitByMarkup('<p>{{ pri<b>x</b>_total }}</p>')
        );
    }

    public function testALineBreakDroppedInsideATokenIsRemoved(): void
    {
        $this->assertSame(
            '{{ date_arrivee }}',
            $this->identifiers()->repairTokensSplitByMarkup('{{ date<br>_arrivee }}')
        );
    }

    public function testABraceBrokenApartIsWeldedBackTogether(): void
    {
        $this->assertSame(
            '{{ prix_total }}',
            $this->identifiers()->repairTokensSplitByMarkup('{<strong>{</strong> prix_total }</em>}')
        );
    }

    public function testProseBetweenBracesIsLeftAloneUnderTheIdentifierSyntax(): void
    {
        $prose = '<p>{{ le <strong>prix</strong> à payer }}</p>';

        $this->assertSame($prose, $this->identifiers()->repairTokensSplitByMarkup($prose));
    }

    public function testABlockBoundaryIsNeverWeldedTogether(): void
    {
        $across = '<p>{{ prix</p><p>_total }}</p>';

        $this->assertSame($across, $this->identifiers()->repairTokensSplitByMarkup($across));
    }

    public function testTwoNeighbouringTokensAreNeverMergedIntoOne(): void
    {
        $this->assertSame(
            '<p>{{ prix_total }} et {{ caution }}</p>',
            $this->identifiers()->repairTokensSplitByMarkup('<p>{{ pri<i>x</i>_total }} et {{ cau<i>t</i>ion }}</p>')
        );
    }

    public function testTextWithNoMarkupAtAllIsReturnedUnchanged(): void
    {
        $this->assertSame(
            '{{ prix_total }}',
            $this->identifiers()->repairTokensSplitByMarkup('{{ prix_total }}')
        );
    }

    public function testARepairedTokenSubstitutes(): void
    {
        $engine = $this->identifiers();
        $repaired = $engine->repairTokensSplitByMarkup('<p>Total : {{ pri<b>x</b>_total }}</p>');

        $this->assertSame(
            '<p>Total : 467,50 €</p>',
            $engine->substitute(
                $repaired,
                static fn(string $name): ?string => $name === 'prix_total' ? '467,50 €' : null,
                true
            )
        );
    }

    // ── Raw names ───────────────────────────────────────────────────────

    public function testRawTokenNamesKeepsOrderAndDuplicates(): void
    {
        $this->assertSame(
            ['Prénom', 'Nom', 'Prénom'],
            $this->freeText()->rawTokenNames('{{Prénom}} {{Nom}} {{Prénom}}')
        );
    }
}
