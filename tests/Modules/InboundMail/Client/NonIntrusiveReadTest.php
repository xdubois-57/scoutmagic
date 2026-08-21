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
        $source = self::imapClientSource();

        // EXAMINE is the read-only open; SELECT sets \Recent on some
        // servers, which is already a modification.
        $this->assertStringContainsString('->examine()', $source);
        $this->assertStringNotContainsString('->select()', $source);
    }

    public function testTheImapClientNeverCallsAWriteOperation(): void
    {
        $source = self::imapClientSource();

        foreach (['->setFlag(', '->addFlag(', '->delete(', '->move(', '->copy(', '->expunge(', '->appendMessage('] as $call) {
            $this->assertStringNotContainsString(
                $call,
                $source,
                'The IMAP client must never write to the remote mailbox: found ' . $call
            );
        }
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
