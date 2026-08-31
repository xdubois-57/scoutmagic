<?php

declare(strict_types=1);

namespace Tests\Modules\Attestations\Service;

use Modules\Attestations\Service\MemberNameDirectory;
use PHPUnit\Framework\TestCase;

/**
 * The name table, on its own. Everything the reader can conclude about who
 * a certificate belongs to comes out of this class, so its edges are the
 * feature's edges.
 */
class MemberNameDirectoryTest extends TestCase
{
    public function testANameIsFoundInBothOrders(): void
    {
        $directory = new MemberNameDirectory();
        $directory->add(7, 'Margaux', 'Vandenbrande');

        // A printed certificate carries no clue which half is the surname,
        // so both spellings have to resolve to the same person.
        $this->assertSame([7], $directory->lookup('Vandenbrande Margaux'));
        $this->assertSame([7], $directory->lookup('Margaux Vandenbrande'));
    }

    public function testMatchingIgnoresCaseAccentsAndPunctuation(): void
    {
        $directory = new MemberNameDirectory();
        $directory->add(3, 'Timéo', 'Roskam');
        $directory->add(4, 'Zoé', 'Herremans-Dupuis');

        $this->assertSame([3], $directory->lookup('TIMEO ROSKAM'));
        $this->assertSame([3], $directory->lookup('  Timeo   Roskam  '));
        // A hyphen a template dropped, or added, must not lose the match.
        $this->assertSame([4], $directory->lookup('HERREMANS DUPUIS Zoe'));
        $this->assertSame([4], $directory->lookup('Herremans-Dupuis Zoé'));
    }

    /**
     * The single most important behaviour in this module. Two members of
     * one name give TWO candidates, never the first one found — the
     * previous site's silent pick is what sent one family's certificate to
     * another, on a document staff cannot re-read afterwards.
     */
    public function testTwoMembersOfOneNameBothCome(): void
    {
        $directory = new MemberNameDirectory();
        $directory->add(11, 'Zoé', 'Herremans');
        $directory->add(12, 'Zoé', 'Herremans');

        $this->assertSame([11, 12], $directory->lookup('Herremans Zoé'));
    }

    public function testTheSameMemberAddedTwiceIsStillOneCandidate(): void
    {
        // What a member present in several seasons looks like to the
        // repository: one row per year, all naming the same person.
        $directory = new MemberNameDirectory();
        $directory->add(5, 'Sacha', 'Meunier');
        $directory->add(5, 'Sacha', 'Meunier');

        $this->assertSame([5], $directory->lookup('Meunier Sacha'));
    }

    /**
     * A member who changed name between two seasons is reachable under
     * both — a certificate printed last year carries last year's spelling.
     */
    public function testASecondSpellingForOnePersonIsIndexedToo(): void
    {
        $directory = new MemberNameDirectory();
        $directory->add(9, 'Carine', 'Lagard');
        $directory->add(9, 'Carine', 'Lagard-Meunier');

        $this->assertSame([9], $directory->lookup('Lagard Carine'));
        $this->assertSame([9], $directory->lookup('Lagard-Meunier Carine'));
    }

    public function testAnUnknownNameResolvesToNobody(): void
    {
        $directory = new MemberNameDirectory();
        $directory->add(1, 'Margaux', 'Vandenbrande');

        $this->assertSame([], $directory->lookup('Camille Delacroix'));
    }

    /**
     * Half a name would match half the unit, and an over-broad key here
     * becomes a wrong family's certificate rather than a missing match.
     */
    public function testASurnameAloneMatchesNobody(): void
    {
        $directory = new MemberNameDirectory();
        $directory->add(1, 'Margaux', 'Vandenbrande');

        $this->assertSame([], $directory->lookup('Vandenbrande'));
    }

    public function testTextWithNoLettersAtAllMatchesNobody(): void
    {
        $directory = new MemberNameDirectory();
        $directory->add(1, 'Margaux', 'Vandenbrande');

        $this->assertSame([], $directory->lookup('   '));
        $this->assertSame([], $directory->lookup('---'));
    }

    public function testAnEmptyDirectorySaysSo(): void
    {
        $directory = new MemberNameDirectory();
        $this->assertTrue($directory->isEmpty());
        $this->assertSame(0, $directory->size());

        $directory->add(1, 'Margaux', 'Vandenbrande');
        $this->assertFalse($directory->isEmpty());
        // Both spellings, one member.
        $this->assertSame(2, $directory->size());
    }
}
