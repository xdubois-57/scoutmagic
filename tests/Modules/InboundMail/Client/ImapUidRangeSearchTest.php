<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Client;

use Modules\InboundMail\Client\FetchedMessage;
use Modules\InboundMail\Client\ImapMailboxClient;
use Modules\InboundMail\Client\MailboxConnectionException;
use Modules\InboundMail\Mailbox\Mailbox;
use Modules\InboundMail\Mailbox\MailboxCredentials;
use Modules\InboundMail\Mailbox\ProviderType;
use Modules\InboundMail\Mailbox\SyncState;
use PHPUnit\Framework\TestCase;

/**
 * Every read after the first asks for « everything above the cursor » with
 * `UID SEARCH UID <n+1>:*`. The library's query builder quoted that range
 * (`UID "6:*"`), which the IMAP grammar does not allow: Gmail answered
 * « BAD Could not parse command » and a Gmail box failed every sync after
 * its first read, while a Dovecot box let it through. `ScriptedImapServer`
 * refuses the quoted form as Gmail does.
 *
 * And the inbox those reads start from is found whatever case the server
 * lists it in.
 */
class ImapUidRangeSearchTest extends TestCase
{
    private const MESSAGE = "From: Jeanne Martin <jeanne@example.be>\r\n"
        . "Subject: Demande de location\r\n"
        . "Message-ID: <MSG@example.be>\r\n"
        . "Date: Mon, 12 Jul 2027 09:30:00 +0200\r\n"
        . "\r\n"
        . "Bonjour, est-ce libre ?\r\n";

    private ScriptedImapServer $server;

    protected function setUp(): void
    {
        $this->server = new ScriptedImapServer();
        $this->server->folders['INBOX'] = [
            5 => $this->message('cinq'),
            6 => $this->message('six'),
            7 => $this->message('sept'),
            9 => $this->message('neuf'),
        ];
    }

    public function testTheRangeAboveTheCursorIsSentAsTheGrammarWantsIt(): void
    {
        $fetched = $this->connected()->fetchSince('INBOX', 5, 10);

        $this->assertContains('SEARCH UID 6:*', $this->server->commands, 'an unquoted sequence set');
        $this->assertSame([6, 7, 9], $this->uids($fetched));
    }

    public function testTheLimitKeepsTheOldestAboveTheCursor(): void
    {
        $this->assertSame([6, 7], $this->uids($this->connected()->fetchSince('INBOX', 5, 2)));
    }

    /** `*` is the last message even below the range: never handed back as new. */
    public function testNothingAboveTheCursorFetchesNothing(): void
    {
        $this->assertSame([], $this->connected()->fetchSince('INBOX', 9, 10));
    }

    public function testAnEmptyFolderFetchesNothing(): void
    {
        $this->server->folders['INBOX'] = [];

        $this->assertSame([], $this->connected()->fetchSince('INBOX', 0, 10));
    }

    /**
     * INBOX is case-insensitive (RFC 3501 §5.1): a server that lists its
     * inbox as « Inbox » still has it read when the box asks for INBOX —
     * which every box now does.
     */
    public function testTheInboxIsFoundWhateverCaseTheServerListsItIn(): void
    {
        $this->server->folders = ['Inbox' => [3 => $this->message('trois')]];

        $this->assertSame([3], $this->uids($this->connected()->fetchSince('INBOX', 0, 10)));
    }

    /** Only the inbox: every other name is matched exactly, as the server listed it. */
    public function testAnyOtherFolderIsMatchedExactly(): void
    {
        $this->server->folders = ['INBOX' => [], 'Archives' => [3 => $this->message('trois')]];

        $this->expectException(MailboxConnectionException::class);
        $this->connected()->fetchSince('archives', 0, 10);
    }

    private function message(string $id): string
    {
        return str_replace('MSG', $id, self::MESSAGE);
    }

    /**
     * @param FetchedMessage[] $fetched
     * @return list<int>
     */
    private function uids(array $fetched): array
    {
        return array_map(static fn(FetchedMessage $message): int => $message->uid, array_values($fetched));
    }

    private function connected(): ImapMailboxClient
    {
        $client = new ImapMailboxClient(new ScriptedImapManager($this->server));
        $client->connect(
            new Mailbox(1, ProviderType::IMAP, 'imap.gmail.com', 993, 'ssl', 'chef@unite.be', ['INBOX'], true, SyncState::NEVER),
            new MailboxCredentials('chef@unite.be', 'chene2026')
        );

        return $client;
    }
}
