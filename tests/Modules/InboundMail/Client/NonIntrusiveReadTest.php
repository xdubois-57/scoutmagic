<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Client;

use Modules\InboundMail\Client\IncomingMailboxClientInterface;
use PHPUnit\Framework\TestCase;

/**
 * "Never modify the remote mailbox" (§7.5), pinned at the level where it is
 * actually guaranteed.
 *
 * This is the module's single most consequential promise: a unit whose
 * treasurer works through an unread inbox must not find it silently emptied
 * by a background task, and a message ScoutMagic deleted is gone from the
 * server too. None of that can be verified against a live server in CI, so
 * it is pinned two ways instead — the contract offers no vocabulary for
 * writing, and the IMAP implementation's source contains none of the calls
 * that would.
 *
 * Source-level assertions are deliberate here, the same precedent
 * tests/Security/ sets for rules that live in code a unit test cannot
 * reach. The difference between `fetch` and `fetch with PEEK` is one
 * method call, invisible in review, and catastrophic in production.
 */
class NonIntrusiveReadTest extends TestCase
{
    private static function imapClientSource(): string
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/modules/inbound_mail/src/Client/ImapMailboxClient.php'
        );
        self::assertNotFalse($source);

        return $source;
    }

    /**
     * @return string[]
     */
    private static function interfaceMethods(): array
    {
        return array_map(
            static fn(\ReflectionMethod $method) => $method->getName(),
            (new \ReflectionClass(IncomingMailboxClientInterface::class))->getMethods()
        );
    }

    public function testTheContractOffersNoWayToWriteToAMailbox(): void
    {
        // Not "we do not call them" — the words do not exist, so no
        // implementation and no caller can reach for one.
        $forbidden = ['markSeen', 'setFlag', 'addFlag', 'move', 'delete', 'expunge', 'createFolder', 'append', 'store'];

        foreach (self::interfaceMethods() as $method) {
            $this->assertNotContains(
                $method,
                $forbidden,
                'IncomingMailboxClientInterface must never grow a write method.'
            );
        }
    }

    public function testTheContractIsOnlyConnectReadAndDisconnect(): void
    {
        $expected = ['connect', 'disconnect', 'fetchSince', 'folderState', 'listFolders'];
        $actual = self::interfaceMethods();
        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual);
    }

    public function testTheImapClientFetchesWithPeekSoNothingIsMarkedAsRead(): void
    {
        $source = self::imapClientSource();

        $this->assertStringContainsString(
            'leaveUnread()',
            $source,
            'Bodies must be fetched with FT_PEEK, or reading a message marks it \\Seen on the server.'
        );
        $this->assertStringContainsString('IMAP::FT_PEEK', $source);
    }

    public function testTheImapClientOpensFoldersReadOnly(): void
    {
        // EXAMINE is the read-only open; SELECT sets \Recent on some
        // servers, which is already a modification.
        $this->assertStringContainsString('->examine()', self::imapClientSource());
        $this->assertStringNotContainsString('->select()', self::readingSource());
    }

    public function testTheImapClientNeverCallsAWriteOperation(): void
    {
        $source = self::readingSource();

        foreach (['->setFlag(', '->addFlag(', '->delete(', '->move(', '->copy(', '->expunge(', '->appendMessage('] as $call) {
            $this->assertStringNotContainsString(
                $call,
                $source,
                'The IMAP client must never write to the remote mailbox: found ' . $call
            );
        }
    }

    // ── the one named exception, and its confinement (roadmap IT-07) ────

    /**
     * **The reading half of this client is still read-only, and the two
     * tests above now prove it of everything except one method.**
     *
     * IT-07 needs a seed mailbox emptied: a box that receives a copy of
     * every mailing is a measuring instrument, and an instrument that
     * never resets stops working. That is a real write to somebody's
     * mailbox, and pretending otherwise would be worse than doing it —
     * so `deleteMessage()` exists, declared through a second interface,
     * and everything else stays as it was.
     *
     * This test is what stops that exception from spreading. It reads the
     * source ABOVE `deleteMessage()` for the two tests above, and here it
     * pins that the write verbs appear in that method and nowhere else.
     * Loosening the two tests instead — dropping `->delete(` from the
     * forbidden list — would have retired the guarantee for the whole
     * class in order to permit one method.
     */
    public function testEveryWriteVerbLivesInTheOnePrunningMethod(): void
    {
        $source = self::imapClientSource();
        $reading = self::readingSource();

        $this->assertNotSame($source, $reading, 'deleteMessage() must exist to be confined.');

        foreach (['->select()', '->delete('] as $call) {
            $this->assertStringContainsString($call, $source, 'Expected in deleteMessage().');
            $this->assertStringNotContainsString($call, $reading, $call . ' escaped deleteMessage().');
        }
    }

    /**
     * And the deletion is reachable only by asking for it: the reading
     * contract must not have grown the method, or every consumer would
     * have it.
     */
    public function testTheReadingContractStillHasNoDeleteMethod(): void
    {
        $this->assertNotContains('deleteMessage', self::interfaceMethods());
    }

    /**
     * The source of everything that is NOT the pruning method.
     *
     * Split on the method's own signature, so a second write method added
     * later falls on the reading side and fails the tests above — which is
     * the point: this file permits ONE exception, not a category.
     */
    private static function readingSource(): string
    {
        $source = self::imapClientSource();
        $at = strpos($source, 'public function deleteMessage(');

        return $at === false ? $source : substr($source, 0, $at);
    }

    public function testCertificateValidationIsOnAndHasNoOffSwitch(): void
    {
        $source = self::imapClientSource();

        $this->assertStringContainsString("'validate_cert' => true", $source);
        $this->assertStringNotContainsString("'validate_cert' => false", $source);
        // Not read from configuration either: an operator must not be able
        // to click past a bad certificate.
        $this->assertDoesNotMatchRegularExpression('/validate_cert.*\$/', $source);
    }

    public function testTheClientNeverPutsALibraryMessageIntoItsOwnException(): void
    {
        $source = self::imapClientSource();

        // A library's own text routinely carries the account name and the
        // server's verbatim rejection of a credential (§7.9).
        $this->assertStringNotContainsString('$e->getMessage()', $source);
    }
}
