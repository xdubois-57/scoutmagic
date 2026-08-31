<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Mail;

use Modules\Finance\Mail\ForwardedSenderExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Reading the original sender out of a forwarded body.
 *
 * Every client writes that header its own way, so the cases below are the
 * shapes a Belgian unit actually receives rather than one format. What
 * matters as much as the matches is the non-matches: this is untrusted
 * text, and a pattern that fired on a signature or on a URL would hand a
 * stranger's address to the resolver.
 */
class ForwardedSenderExtractorTest extends TestCase
{
    private ForwardedSenderExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new ForwardedSenderExtractor();
    }

    public function testTheFrenchGmailShape(): void
    {
        $body = "---------- Message transféré ----------\n"
            . "De : Anna Martin <anna@example.be>\n"
            . "Date : ven. 12 juil. 2027 à 09:30\n"
            . "Objet : Reçu\n\nVoici le reçu.";

        $this->assertSame('anna@example.be', $this->extractor->extract($body));
    }

    public function testTheEnglishShape(): void
    {
        $body = "---------- Forwarded message ----------\nFrom: Anna Martin <anna@example.be>\n";

        $this->assertSame('anna@example.be', $this->extractor->extract($body));
    }

    public function testABareAddressWithNoDisplayName(): void
    {
        $this->assertSame('anna@example.be', $this->extractor->extract("De : anna@example.be\nObjet : Reçu"));
    }

    public function testTheLabelWrittenExpediteur(): void
    {
        $this->assertSame('anna@example.be', $this->extractor->extract("Expéditeur : Anna <anna@example.be>"));
    }

    public function testALostAccentStillMatches(): void
    {
        // A body whose charset did not survive the trip arrives as
        // `Exp?diteur`. Losing the sender over a mangled accent would be a
        // receipt in the sorting pile for no reason at all.
        $this->assertSame('anna@example.be', $this->extractor->extract("Exp?diteur : anna@example.be"));
    }

    public function testTheAddressIsLowercased(): void
    {
        // The resolver matches on a blind index of the lowercased address;
        // handing it `Anna@Example.be` would find nobody.
        $this->assertSame('anna@example.be', $this->extractor->extract('De : Anna <Anna@Example.BE>'));
    }

    public function testTheFirstLabelledLineWins(): void
    {
        $body = "De : Anna <anna@example.be>\nÀ : finances@unite.be\nDe : Bruno <bruno@example.be>";

        $this->assertSame('anna@example.be', $this->extractor->extract($body));
    }

    // ── Ce qui ne doit PAS être lu comme un expéditeur ───────────────────

    public function testAnAddressInRunningTextIsNotASender(): void
    {
        // No label opens the line, so there is no forwarded header here —
        // only somebody's signature or a sentence.
        $this->assertNull($this->extractor->extract("Bonjour,\nÉcrivez-moi à anna@example.be si besoin."));
    }

    public function testALabelInTheMiddleOfALineIsNotAHeader(): void
    {
        // "…, de : anna@example.be" inside a sentence. A header opens its
        // line; anything else is prose, and prose is where an attacker
        // would hide one.
        $this->assertNull($this->extractor->extract('Le reçu vient de : anna@example.be selon Bruno.'));
    }

    public function testASenderFarDownALongThreadIsIgnored(): void
    {
        // A forwarded header sits at the top. Reading the whole body would
        // find the oldest sender in a long thread rather than the nearest,
        // and the supplier's own signature rather than the forwarder's.
        $body = str_repeat("Bonjour,\n", 60) . 'De : Anna <anna@example.be>';

        $this->assertNull($this->extractor->extract($body));
    }

    public function testAnEmptyBodyIsNull(): void
    {
        $this->assertNull($this->extractor->extract(''));
    }

    // ── La partie HTML, quand il n'y a pas de texte ──────────────────────

    public function testTheHtmlPartIsReadWhenThereIsNoTextPart(): void
    {
        // A phone forwarding a photo often sends HTML alone.
        $html = '<div>---------- Message transféré ----------</div>'
            . '<div>De&nbsp;: Anna Martin &lt;anna@example.be&gt;</div>'
            . '<div>Objet : Reçu</div>';

        $this->assertSame('anna@example.be', $this->extractor->extract('', $html));
    }

    public function testHtmlBlockTagsBecomeLineBreaksBeforeMatching(): void
    {
        // Stripping tags outright would run the whole header into one line,
        // where « De : » no longer opens anything and nothing matches.
        $html = '<p>Message transféré</p><p>De : anna@example.be</p>';

        $this->assertSame('anna@example.be', $this->extractor->extract('', $html));
    }

    public function testTheTextPartWinsWhenBothArePresent(): void
    {
        $this->assertSame(
            'anna@example.be',
            $this->extractor->extract('De : anna@example.be', '<div>De : bruno@example.be</div>')
        );
    }
}
