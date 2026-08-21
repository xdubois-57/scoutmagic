<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Mail;

use Modules\Rental\Mail\BookingReferenceMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Finding a booking reference in text a stranger wrote (§7.6, level 1).
 *
 * The reference is the strongest signal this module has — the module put it
 * in the subject itself, so a reply carrying it back is as close to certain
 * as automatic attachment gets. Which makes the false-positive cases the
 * ones worth spelling out: two different references, or something that
 * merely looks like one.
 */
class BookingReferenceMatcherTest extends TestCase
{
    private BookingReferenceMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new BookingReferenceMatcher();
    }

    public function testABracketedReferenceInTheSubjectIsFound(): void
    {
        $this->assertSame(
            'LOC-2027-0042',
            $this->matcher->match('Re: Votre réservation [LOC-2027-0042]', 'Bonjour,')
        );
    }

    public function testABareReferenceInTheSubjectIsFoundToo(): void
    {
        // Some clients strip brackets from a subject on reply; that must
        // not cost the unit the match.
        $this->assertSame(
            'LOC-2027-0042',
            $this->matcher->match('Re: reservation LOC-2027-0042', '')
        );
    }

    public function testAReferenceInTheBodyIsFoundWhenTheSubjectHasNone(): void
    {
        $this->assertSame(
            'LOC-2027-0042',
            $this->matcher->match('Une question', 'Bonjour, au sujet de [LOC-2027-0042] :')
        );
    }

    public function testTheSubjectWinsOverTheBody(): void
    {
        // The subject's reference is the one the module put there; a body
        // is full of quoted history.
        $this->assertSame(
            'LOC-2027-0042',
            $this->matcher->match('[LOC-2027-0042]', 'Le 12 juillet, à propos de [LOC-2026-0007]…')
        );
    }

    public function testTheReferenceIsNormalisedToUppercase(): void
    {
        $this->assertSame('LOC-2027-0042', $this->matcher->match('re: loc-2027-0042', ''));
    }

    public function testTheSameReferenceRepeatedIsStillOneMatch(): void
    {
        // A quoted reply chain repeats the subject line several times.
        $this->assertSame(
            'LOC-2027-0042',
            $this->matcher->match('Re: Re: [LOC-2027-0042]', "> [LOC-2027-0042]\n>> [LOC-2027-0042]")
        );
    }

    public function testTwoDifferentReferencesMeanNoMatch(): void
    {
        // A renter forwarding one booking's email while asking about
        // another leaves both in the text. Guessing which they meant is how
        // a message lands on the wrong file.
        $this->assertNull(
            $this->matcher->match('[LOC-2027-0042] et [LOC-2027-0043]', '')
        );
    }

    public function testTwoDifferentReferencesInTheBodyMeanNoMatchEither(): void
    {
        $this->assertNull(
            $this->matcher->match('Une question', 'Comme pour LOC-2027-0042 et LOC-2026-0007…')
        );
    }

    public function testTextWithNoReferenceMatchesNothing(): void
    {
        $this->assertNull($this->matcher->match('Bonjour', 'Est-ce que le local est libre en août ?'));
    }

    public function testSomethingThatMerelyLooksLikeAReferenceIsNotOne(): void
    {
        $this->assertNull($this->matcher->match('XLOC-2027-0042', ''));
        $this->assertNull($this->matcher->match('LOC-27-42', ''));
        $this->assertNull($this->matcher->match('ALLOCATION-2027-0042', ''));
    }

    public function testABracketedReferenceBeatsABareOneElsewhere(): void
    {
        // The bracketed form is what the module writes; a bare one in a
        // body is more often somebody quoting a number.
        $this->assertSame(
            'LOC-2027-0042',
            $this->matcher->match('Une question', "Objet : [LOC-2027-0042]\nVoir aussi LOC-2026-0007")
        );
    }
}
