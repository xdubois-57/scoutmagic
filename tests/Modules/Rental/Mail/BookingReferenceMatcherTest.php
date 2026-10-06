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
            'LOC-K7Q2M4',
            $this->matcher->match('Re: Votre réservation [LOC-K7Q2M4]', 'Bonjour,')
        );
    }

    public function testABareReferenceInTheSubjectIsFoundToo(): void
    {
        // Some clients strip brackets from a subject on reply; that must
        // not cost the unit the match.
        $this->assertSame(
            'LOC-K7Q2M4',
            $this->matcher->match('Re: reservation LOC-K7Q2M4', '')
        );
    }

    public function testAReferenceInTheBodyIsFoundWhenTheSubjectHasNone(): void
    {
        $this->assertSame(
            'LOC-K7Q2M4',
            $this->matcher->match('Une question', 'Bonjour, au sujet de [LOC-K7Q2M4] :')
        );
    }

    public function testTheSubjectWinsOverTheBody(): void
    {
        // The subject's reference is the one the module put there; a body
        // is full of quoted history.
        $this->assertSame(
            'LOC-K7Q2M4',
            $this->matcher->match('[LOC-K7Q2M4]', 'Le 12 juillet, à propos de [LOC-R7S8T9]…')
        );
    }

    public function testTheReferenceIsNormalisedToUppercase(): void
    {
        $this->assertSame('LOC-K7Q2M4', $this->matcher->match('re: loc-k7q2m4', ''));
    }

    public function testTheSameReferenceRepeatedIsStillOneMatch(): void
    {
        // A quoted reply chain repeats the subject line several times.
        $this->assertSame(
            'LOC-K7Q2M4',
            $this->matcher->match('Re: Re: [LOC-K7Q2M4]', "> [LOC-K7Q2M4]\n>> [LOC-K7Q2M4]")
        );
    }

    public function testTwoDifferentReferencesMeanNoMatch(): void
    {
        // A renter forwarding one booking's email while asking about
        // another leaves both in the text. Guessing which they meant is how
        // a message lands on the wrong file.
        $this->assertNull(
            $this->matcher->match('[LOC-K7Q2M4] et [LOC-K7Q2M5]', '')
        );
    }

    public function testTwoDifferentReferencesInTheBodyMeanNoMatchEither(): void
    {
        $this->assertNull(
            $this->matcher->match('Une question', 'Comme pour LOC-K7Q2M4 et LOC-R7S8T9…')
        );
    }

    public function testTextWithNoReferenceMatchesNothing(): void
    {
        $this->assertNull($this->matcher->match('Bonjour', 'Est-ce que le local est libre en août ?'));
    }

    public function testSomethingThatMerelyLooksLikeAReferenceIsNotOne(): void
    {
        $this->assertNull($this->matcher->match('XLOC-K7Q2M4', ''));
        $this->assertNull($this->matcher->match('LOC-27-42', ''));
        $this->assertNull($this->matcher->match('ALLOCATION-K7Q2M4', ''));
    }

    public function testOrdinaryWordsAndNumbersAfterLocAreNotReferences(): void
    {
        // Without a year, six letters or six digits after « loc- » is how
        // ordinary text reads; a reference always mixes both.
        $this->assertNull($this->matcher->match('Re: loc-marche du samedi', ''));
        $this->assertNull($this->matcher->match('[LOC-MARCHE]', ''));
        $this->assertNull($this->matcher->match('LOC-234567', ''));
    }

    public function testAReferenceWithAYearIsNotOne(): void
    {
        // The earlier forms are not recognised any more (#720): nothing
        // migrates them, and their bookings are found by address instead.
        $this->assertNull($this->matcher->match('[LOC-2027-0042]', ''));
        $this->assertNull($this->matcher->match('[LOC-2027-K7Q2MX]', ''));
    }

    public function testABracketedReferenceBeatsABareOneElsewhere(): void
    {
        // The bracketed form is what the module writes; a bare one in a
        // body is more often somebody quoting a number.
        $this->assertSame(
            'LOC-K7Q2M4',
            $this->matcher->match('Une question', "Objet : [LOC-K7Q2M4]\nVoir aussi LOC-R7S8T9")
        );
    }

    public function testAReferenceRetypedInLowerCaseIsFoundAndCanonicalised(): void
    {
        // The booking is looked up by the stored, upper-case form.
        $this->assertSame(
            'LOC-K7Q2MX',
            $this->matcher->match('Une question', 'Bonjour, ma réservation loc-k7q2mx :')
        );
    }

    public function testAReferenceTooLongOrWithACharacterNoDrawUsesIsNotOne(): void
    {
        $this->assertNull($this->matcher->match('LOC-K7Q2MXA', ''));
        $this->assertNull($this->matcher->match('LOC-K7Q2MO', ''));
        $this->assertNull($this->matcher->match('LOC-K7Q2', ''));
        $this->assertNull($this->matcher->match('[LOC-K7Q2MXA]', ''));
    }
}
