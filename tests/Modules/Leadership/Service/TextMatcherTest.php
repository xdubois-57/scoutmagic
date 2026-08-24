<?php

declare(strict_types=1);

namespace Tests\Modules\Leadership\Service;

use Modules\Leadership\Service\TextMatcher;
use PHPUnit\Framework\TestCase;

/**
 * The folding both sides of every comparison in this module go through.
 *
 * Tested directly rather than only through its callers: every other test
 * of a list's contents or order depends on this behaving the same way on a
 * host with ext-intl and on one without.
 */
class TextMatcherTest extends TestCase
{
    public function testFoldsCaseAccentsAndPunctuation(): void
    {
        $this->assertSame('candidat e animateur', TextMatcher::fold('Candidat·e Animateur'));
        $this->assertSame('candidat animateur', TextMatcher::fold('CANDIDAT-ANIMATEUR'));
        $this->assertSame('deuxieme etape', TextMatcher::fold('  Deuxième étape  '));
        $this->assertSame('', TextMatcher::fold(null));
        $this->assertSame('', TextMatcher::fold('   '));
    }

    public function testContainsIsASubstringMatch(): void
    {
        $this->assertTrue(TextMatcher::contains('Animateur T2', 't2'));
        // Which is exactly the trap containsWord() exists to close.
        $this->assertTrue(TextMatcher::contains('POST2015', 't2'));
        $this->assertFalse(TextMatcher::contains(null, 't2'));
    }

    public function testContainsWordStopsAtWordBoundaries(): void
    {
        $this->assertTrue(TextMatcher::containsWord('Animateur T2', 't2'));
        $this->assertTrue(TextMatcher::containsWord('T2', 't2'));
        $this->assertTrue(TextMatcher::containsWord('Formation : T2 (2026)', 't2'));
        $this->assertTrue(TextMatcher::containsWord('Animateur — T2', 't2'));

        $this->assertFalse(TextMatcher::containsWord('POST2015', 't2'));
        $this->assertFalse(TextMatcher::containsWord('LOT2B', 't2'));
        $this->assertFalse(TextMatcher::containsWord('', 't2'));
        $this->assertFalse(TextMatcher::containsWord('T2', ''));
    }

    public function testAccentedNamesSortWhereAReaderLooksForThem(): void
    {
        $names = ['Zoé', 'Émilie', 'Alix', 'Étienne'];
        usort($names, TextMatcher::compareNames(...));

        // strcasecmp() compares bytes and would put both accented names
        // last, which reads as if they had been appended to the list.
        $this->assertSame(['Alix', 'Émilie', 'Étienne', 'Zoé'], $names);
    }

    public function testTwoNamesDifferingOnlyByAnAccentStillHaveAStableOrder(): void
    {
        $this->assertNotSame(0, TextMatcher::compareNames('Zoe', 'Zoé'));
        $this->assertSame(0, TextMatcher::compareNames('Zoé', 'Zoé'));
    }
}
