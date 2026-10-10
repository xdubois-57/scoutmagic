<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Security\HtmlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The server's half of the rich-text contract of issue #844.
 *
 * The editors rebuild every paste, and every text they send, in one
 * canonical grammar (public/assets/js/rich-text-link.js, « The canonical
 * form »). That only removes the « mise en forme qui change à
 * l'enregistrement » if the server keeps that grammar exactly: a canonical
 * text that came back different from the sanitiser would change on save
 * again, just more politely.
 *
 * So both sides read the same fixtures — tests/fixtures/rich-text/
 * canonical-paste.json. tests/js/rich-text-canonical.test.js holds that a
 * paste becomes `canonical`; this holds that `canonical` is a fixed point of
 * HtmlSanitizer, byte for byte.
 *
 * What it deliberately does not do is make the sanitiser translate styles
 * into tags itself. The sanitiser is a security filter applied to every
 * string the server receives, whatever sent it; it is not where an editing
 * convention lives, and it is not widened for one (the issue's own
 * criterion 5). Its half of the contract is to keep the canon and to keep
 * refusing what it always refused — the second test below.
 */
final class RichTextCanonicalContractTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures/rich-text/canonical-paste.json';

    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        $decoded = json_decode((string) file_get_contents(self::FIXTURES), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['cases'] ?? null);

        $cases = [];
        foreach ($decoded['cases'] as $case) {
            self::assertIsArray($case);
            $cases[(string) $case['name']] = [(string) $case['pasted'], (string) $case['canonical']];
        }

        return $cases;
    }

    #[DataProvider('cases')]
    public function testTheServerKeepsTheCanonicalFormByteForByte(string $pasted, string $canonical): void
    {
        // One serialisation difference, and only one: a browser writes the
        // non-breaking space as `&nbsp;`, DOMDocument as the U+00A0
        // character itself. Same character, same DOM once parsed — so it is
        // the one thing compared as a character rather than as bytes.
        $expected = str_replace('&nbsp;', "\u{00A0}", $canonical);

        $this->assertSame($expected, (new HtmlSanitizer())->sanitize($canonical));
    }

    /**
     * The `stored` form — what an editor sends back for a text it reopened —
     * keeps the tags the sanitiser accepts and no button makes. The server
     * must keep those as they are too, or saving an untouched text would
     * still change it. Mirrors tests/js/rich-text-canonical.test.js.
     */
    public function testTheServerKeepsWhatAReopenedTextKeeps(): void
    {
        $stored = '<h4>Petit titre</h4><blockquote><p>Cité</p><p><strong>Encore</strong></p></blockquote>'
            . '<p><img src="/files/2" alt="x"></p>'
            . '<p><a href="https://www.lesscouts.be" target="_blank" rel="noopener noreferrer" title="Les Scouts">'
            . '<strong>Les Scouts</strong></a></p>';

        $this->assertSame($stored, (new HtmlSanitizer())->sanitize($stored));
    }

    #[DataProvider('cases')]
    public function testWhatThePasteCarriedIsStillRefusedWhenItReachesTheServerUnnormalised(string $pasted, string $canonical): void
    {
        // A forged POST, or an editor without JavaScript, sends the raw
        // clipboard. The server must not need the editor to have run.
        $kept = (new HtmlSanitizer())->sanitize($pasted);

        $this->assertStringNotContainsStringIgnoringCase('<script', $kept);
        $this->assertStringNotContainsStringIgnoringCase('<iframe', $kept);
        $this->assertStringNotContainsStringIgnoringCase('style=', $kept);
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $kept);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $kept);
    }
}
