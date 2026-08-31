<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Client;

use Modules\InboundMail\Client\ImapMailboxClient;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Decoder\HeaderDecoder;

/**
 * RFC 2047 encoded words on the **wire** path (§7.5).
 *
 * The regression this pins was live: every accented subject reached the
 * screen as `=?utf-8?Q?Voici_le_re=C3=A7u_de_mes_d=C3=A9penses?=`, and
 * every consumer looking for a reference in a subject was reading that
 * instead of the words.
 *
 * The cause is worth naming, because it is invisible in review:
 * `webklex/php-imap`'s `HeaderDecoder` delegates to
 * `imap_mime_header_decode()`, and its fallback for a host without
 * `ext-imap` returns the header unchanged **and reports success** — so the
 * `iconv_mime_decode()` branch below it is dead code there. `ext-imap` left
 * PHP's core in 8.4, which is precisely the host this module was written
 * for; the library therefore decodes nothing in production and decodes
 * correctly on a developer's machine that happens to have the extension.
 *
 * `Mime\MimeMessageParserTest` covers the decoder itself. What is pinned
 * here is that the IMAP client uses it at all, and that the library gap it
 * exists to close is still open.
 */
class ImapHeaderDecodingTest extends TestCase
{
    /** The real subject from the bug report, folded across two encoded words. */
    private const FOLDED_SUBJECT =
        '=?utf-8?Q?Voici_le_re=C3=A7u_de_mes_d=C3=A9penses_de_ce_jo?= =?utf-8?Q?ur?=';

    private static function decodeHeader(string $value): string
    {
        $method = new \ReflectionMethod(ImapMailboxClient::class, 'decodeHeader');

        return (string) $method->invoke(null, $value);
    }

    public function testTheLibraryLeavesAnEncodedSubjectUndecodedWithoutExtImap(): void
    {
        if (extension_loaded('imap')) {
            $this->markTestSkipped('ext-imap present: the library decodes, and the gap cannot be observed here.');
        }

        // Not a test of somebody else's code for its own sake: this is the
        // reason `decodeHeader()` exists, and the day it stops being true
        // is the day that method can go.
        $this->assertSame(
            self::FOLDED_SUBJECT,
            (new HeaderDecoder())->decode(self::FOLDED_SUBJECT),
            'webklex/php-imap now decodes encoded words on its own — reconsider ImapMailboxClient::decodeHeader().'
        );
    }

    public function testAFoldedAccentedSubjectIsDecodedToTheWordsTheSenderWrote(): void
    {
        $this->assertSame(
            'Voici le reçu de mes dépenses de ce jour',
            self::decodeHeader(self::FOLDED_SUBJECT)
        );
    }

    public function testASubjectThatIsAlreadyPlainTextIsUntouched(): void
    {
        // The same client runs on a host that does have ext-imap, where the
        // library already decoded. Decoding twice must be a no-op, or that
        // host gets a different subject from this one.
        $this->assertSame(
            'Voici le reçu de mes dépenses de ce jour',
            self::decodeHeader('Voici le reçu de mes dépenses de ce jour')
        );
    }

    public function testASenderNameIsDecodedToo(): void
    {
        $this->assertSame('Xavier Dubois', self::decodeHeader('=?UTF-8?B?WGF2aWVyIER1Ym9pcw==?='));
    }

    public function testTheSubjectSenderNameAndFilenameAllGoThroughIt(): void
    {
        // Source-level, on the precedent NonIntrusiveReadTest sets: the
        // three values are read from a `Webklex\PHPIMAP\Message` that no
        // unit test can build without a socket, and a decode dropped from
        // one of the three is invisible in a diff.
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/modules/inbound_mail/src/Client/ImapMailboxClient.php'
        );
        $this->assertNotFalse($source);

        $this->assertStringContainsString('subject: self::decodeHeader(', $source);
        $this->assertStringContainsString('personal', $source);
        $this->assertMatchesRegularExpression(
            '/fromName:\s*self::nonEmptyOrNull\(\s*self::decodeHeader\(/',
            $source,
            'The sender name must be decoded: it arrives as an encoded word exactly as often as the subject does.'
        );
        $this->assertStringContainsString('filename: self::decodeHeader(', $source);
    }
}
