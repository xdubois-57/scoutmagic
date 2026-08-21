<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Support;

use Modules\Groups\Support\SearchTerm;
use PHPUnit\Framework\TestCase;

class SearchTermTest extends TestCase
{
    public function testItTrimsWhatTheMemberTyped(): void
    {
        $this->assertSame('camp', SearchTerm::normalise("  camp \n"));
    }

    public function testItCapsAVeryLongTermRatherThanRefusingIt(): void
    {
        $normalised = SearchTerm::normalise(str_repeat('a', 500));

        $this->assertSame(SearchTerm::MAX_LENGTH, mb_strlen($normalised));
    }

    public function testATermShorterThanTheMinimumIsNotRunAtAll(): void
    {
        $this->assertFalse(SearchTerm::isUsable('ca'));
        $this->assertTrue(SearchTerm::isUsable('cam'));
    }

    /**
     * The whole point of the class. Unescaped, a lone "%" is a LIKE
     * wildcard matching every message in the group — a member typing it
     * would be handed the entire conversation rather than a search
     * result.
     */
    public function testAPercentSignIsEscapedSoItMatchesItself(): void
    {
        $this->assertSame('%100!% de%', SearchTerm::pattern('100% de'));
    }

    public function testAnUnderscoreIsEscapedToo(): void
    {
        $this->assertSame('%prix!_final%', SearchTerm::pattern('prix_final'));
    }

    /**
     * The escape character is itself escapable, or a term containing one
     * would silently neutralise the character after it.
     */
    public function testTheEscapeCharacterEscapesItself(): void
    {
        $this->assertSame('%bravo!!!%%', SearchTerm::pattern('bravo!%'));
    }

    public function testAnOrdinaryTermIsWrappedUntouched(): void
    {
        $this->assertSame('%camp d\'été%', SearchTerm::pattern('camp d\'été'));
    }
}
