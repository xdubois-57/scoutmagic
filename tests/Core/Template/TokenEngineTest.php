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

    #[\PHPUnit\Framework\Attributes\DataProvider('literalPercentNames')]
    public function testLiteralPercentBytesRemainInUnknownFreeTextDiagnostics(string $name): void
    {
        $this->assertSame(
            [$name],
            $this->freeText()->unknownTokens('{{' . $name . '}}', static fn(string $key): bool => false)
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('literalPercentNames')]
    public function testLiteralPercentBytesCanNameAFreeTextValue(string $name): void
    {
        $this->assertSame('42', $this->freeText()->substitute(
            '{{' . $name . '}}',
            static fn(string $key): ?string => $key === $name ? '42' : null,
            false
        ));
    }

    /** @return iterable<string, array{string}> */
    public static function literalPercentNames(): iterable
    {
        yield 'ASCII name' => ['Taux %FFtotal'];
        yield 'accented name' => ['Coût %FFtotal'];
        yield 'incomplete UTF-8 escape' => ['Taux %C3total'];
        yield 'literal encoded space' => ['Remise %20'];
        yield 'literal encoded letter' => ['Code %41'];
        yield 'literal encoded percent' => ['Taux %25'];
        yield 'literal encoded slash' => ['Code %2Fpart'];
        yield 'literal encoded braces' => ['Code %7Bnom%7D'];
        yield 'literal encoded closing delimiters' => ['Code %7D%7Dsuite'];
    }

    public function testLiteralAndEncodedSpellingsKeepTheirDistinctColumnNames(): void
    {
        $template = '{{Code %41}} / %7B%7BCode%20%2541%7D%7D / %7B%7BCode%20A%7D%7D';
        $values = ['Code %41' => '10', 'Code A' => '20'];

        $this->assertSame('10 / 10 / 20', $this->freeText()->substitute(
            $template,
            static fn(string $name): ?string => $values[$name] ?? null,
            false
        ));
    }

    public function testALiteralPercentNameIsUnknownEvenWhenItsDecodedSpellingExists(): void
    {
        $this->assertSame(['Remise %20'], $this->freeText()->unknownTokens(
            '{{Remise %20}}',
            static fn(string $name): bool => $name === 'Remise'
        ));
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

    public function testOnlyTheBracesOfAPercentEncodedTokenAreRestoredIntoHtml(): void
    {
        $this->assertSame(
            '<a href="{{QR%201}}">x</a>',
            $this->freeText()->substitute('<a href="%7B%7BQR%201%7D%7D">x</a>', static fn(): ?string => null, true)
        );
    }

    public function testMixedEncodedTokensAreRecognisedAndDecodedWithoutChangingOtherUrls(): void
    {
        foreach (['%7B%7B%20nom%20%7D%7D', '%7B%7B nom %7D%7D', '%7b{nom}%7D'] as $token) {
            $this->assertTrue($this->freeText()->containsToken($token));
            $this->assertSame('Alice', $this->freeText()->substitute(
                $token,
                static fn(string $name): ?string => $name === 'nom' ? 'Alice' : null,
                false
            ));
        }
        $this->assertSame('/photo%20de%20groupe', $this->freeText()->substitute(
            '/photo%20de%20groupe',
            static fn(): ?string => null,
            true
        ));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('encodedNames')]
    public function testEncodedNamesAreDecodedOnceForLookupAndReporting(string $name): void
    {
        $engine = $this->freeText();
        $token = '%7B%7B' . rawurlencode($name) . '%7D%7D';
        $template = $token;

        $this->assertSame([
            'value' => 'Camille &lt;&amp;&gt; &quot;M&quot;',
            'names' => [$name],
            'unknown' => [$name],
        ], [
            'value' => $engine->substitute(
                $template,
                static fn(string $key): ?string => $key === $name ? 'Camille <&> "M"' : null,
                true
            ),
            'names' => $engine->rawTokenNames($template),
            'unknown' => $engine->unknownTokens($template, static fn(string $key): bool => false),
        ]);
    }

    /** @return array<string, array{string}> */
    public static function encodedNames(): array
    {
        return [
            'accent and space' => ['Prénom 1'],
            'apostrophe' => ["Nom de l'enfant"],
            'HTML punctuation in a spreadsheet header' => ['Nom <civil> & "référence"'],
            'one decoding pass' => ['Étiquette %20'],
            'literal percent byte after one decoding pass' => ['Taux %FFtotal'],
        ];
    }

    public function testNormalisingAnUnknownNameNeverRecreatesMarkupOrAnAttribute(): void
    {
        $engine = $this->freeText();
        $name = rawurlencode('"><img src=x onerror="alert(1)">');
        $template = '<a href="%7B%7B' . $name . '%7D%7D">Lien</a>';
        $normalised = $engine->substitute($template, static fn(): ?string => null, true);

        $this->assertSame('<a href="{{' . $name . '}}">Lien</a>', $normalised);
        $this->assertSame($normalised, $engine->substitute($normalised, static fn(string $key): ?string => null, true));
    }

    public function testAnEncodedIdentifierCanBeRepairedAndLookedUp(): void
    {
        $engine = $this->identifiers();
        $template = $engine->repairTokensSplitByMarkup('%7B%7B%20pri<b>%78</b>_total%20%7D%7D');

        $this->assertSame('467,50 €', $engine->substitute(
            $template,
            static fn(string $name): ?string => $name === 'prix_total' ? '467,50 €' : null,
            true
        ));
    }

    public function testAPartiallyEncodedIdentifierUsesTheClosedKeywordVocabulary(): void
    {
        $resolved = [];
        $template = '{{prix%5Ftotal}}';
        $result = $this->identifiers()->substitute($template, static function (string $name) use (&$resolved): string {
            $resolved[] = $name;

            return '467,50 €';
        }, true);

        $this->assertSame('467,50 €', $result);
        $this->assertSame(['prix_total'], $resolved);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('formattedPercentHeaders')]
    public function testRepairRetainsTheEncodingOfFreeTextHeaders(string $template): void
    {
        $engine = $this->freeText();
        $values = ['Code %41' => '10', 'Code A' => '20'];
        $this->assertSame('10', $engine->substitute(
            $engine->repairTokensSplitByMarkup($template),
            static fn(string $name): ?string => $values[$name] ?? null,
            false
        ));
    }

    /** @return iterable<string, array{string}> */
    public static function formattedPercentHeaders(): iterable
    {
        yield 'literal name' => ['{{Code %<b>41</b>}}'];
        yield 'encoded name' => ['%7B%7BCode%20%25<b>41</b>%7D%7D'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidEncodedNames')]
    public function testInvalidDecodedNamesStayEncodedAndAreNeverResolved(string $name): void
    {
        $engine = $this->identifiers();
        $token = '{{' . $name . '}}';
        $resolved = [];
        $result = $engine->substitute($token, static function (string $key) use (&$resolved): ?string {
            $resolved[] = $key;

            return 'Valeur inattendue';
        }, true);

        $this->assertSame([$token, [], []], [$result, $resolved, $engine->rawTokenNames($token)]);
    }

    /** @return array<string, array{string}> */
    public static function invalidEncodedNames(): array
    {
        return [
            'brace in a name' => ['prix%7Btotal'],
            'markup in a name' => ['prix%3Cimg%3E'],
            'invalid UTF-8' => ['prix%FFtotal'],
        ];
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
