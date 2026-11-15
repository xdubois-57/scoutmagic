<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Client;

use Modules\InboundMail\Client\ImapMailboxClient;
use Modules\InboundMail\Client\MailboxConnectionException;
use Modules\InboundMail\Mailbox\Mailbox;
use Modules\InboundMail\Mailbox\MailboxCredentials;
use Modules\InboundMail\Mailbox\ProviderType;
use Modules\InboundMail\Mailbox\SyncState;
use PHPUnit\Framework\TestCase;

/**
 * The IMAP client against a server that answers, with `webklex/php-imap`
 * running for real between the two (`ScriptedImapServer`).
 *
 * Every refusal here is the server's own tagged `NO`, and what reaches the
 * sync is whatever the library makes of it — so these pin the half of the
 * behaviour nobody in this repository wrote. Each one checks the two
 * things a failure may carry out of the client: that it is a connection
 * problem the sync records, and that it names the exception's class and
 * nothing the server said, since a server's refusal quotes the account.
 */
class ImapClientAgainstAServerTest extends TestCase
{
    private const MESSAGE = "From: Jeanne Martin <jeanne@example.be>\r\n"
        . "Subject: Demande de location\r\n"
        . "Message-ID: <demande@example.be>\r\n"
        . "Date: Mon, 12 Jul 2027 09:30:00 +0200\r\n"
        . "\r\n"
        . "Bonjour, est-ce libre ?\r\n";

    private ScriptedImapServer $server;

    protected function setUp(): void
    {
        $this->server = new ScriptedImapServer();
        $this->server->folders['INBOX'] = [5 => self::MESSAGE];
    }

    public function testTheScriptedServerIsReadThroughTheRealLibrary(): void
    {
        // The premise: without a refusal, the same harness reads the box.
        $client = $this->connected();

        $fetched = $client->fetchSince('INBOX', 0, 10);

        $this->assertSame(['INBOX'], array_map(static fn($folder): string => $folder->path, $client->listFolders()));
        $this->assertSame(5, $client->folderState('INBOX')->highestUid);
        $this->assertCount(1, $fetched);
        $this->assertSame('Demande de location', $fetched[0]->subject);
    }

    public function testAFolderListingTheServerRefusesIsAConnectionProblem(): void
    {
        $this->server->refuse = ['list'];

        $this->assertConnectionProblem(fn() => $this->connected()->listFolders());
    }

    /**
     * The folder is looked up by listing the box first, so a refused LIST
     * fails the folder's status too — as a connection problem, not as
     * « dossier introuvable », which would send the superadmin looking for
     * a folder that is there.
     */
    public function testAFolderTheServerWillNotListIsAConnectionProblemNotAMissingFolder(): void
    {
        $this->server->refuse = ['list'];

        $this->assertConnectionProblem(fn() => $this->connected()->folderState('INBOX'));
    }

    public function testAFolderStatusTheServerRefusesIsAConnectionProblem(): void
    {
        $this->server->refuse = ['examine'];

        $this->assertConnectionProblem(fn() => $this->connected()->folderState('INBOX'));
    }

    public function testASearchTheServerRefusesIsAConnectionProblem(): void
    {
        $this->server->refuse = ['search'];

        $this->assertConnectionProblem(fn() => $this->connected()->fetchSince('INBOX', 0, 10));
    }

    public function testADeletionTheServerRefusesAnswersFalseAndRemovesNothing(): void
    {
        $this->server->refuse = ['select'];

        $this->assertFalse($this->connected()->deleteMessage('INBOX', 5));

        $this->assertSame([], $this->server->deleted);
        $this->assertArrayHasKey(5, $this->server->folders['INBOX'], 'the message is still in the box');
    }

    public function testADeletionTheServerAcceptsRemovesTheMessage(): void
    {
        $this->assertTrue($this->connected()->deleteMessage('INBOX', 5));

        $this->assertSame([['folder' => 'INBOX', 'uid' => 5]], $this->server->deleted);
    }

    /**
     * @param \Closure(): mixed $operation
     */
    private function assertConnectionProblem(\Closure $operation): void
    {
        try {
            $operation();
            $this->fail('A connection problem was expected.');
        } catch (MailboxConnectionException $e) {
            $this->assertSame('ResponseException', $e->getMessage(), 'the class, and nothing the server said');
            $this->assertStringNotContainsString('chef@unite.be', $e->getMessage());
            $this->assertInstanceOf(\Webklex\PHPIMAP\Exceptions\ResponseException::class, $e->getPrevious());
        }
    }

    private function connected(): ImapMailboxClient
    {
        $client = new ImapMailboxClient(new ScriptedImapManager($this->server));
        $client->connect($this->mailbox(), $this->credentials());

        return $client;
    }

    private function mailbox(): Mailbox
    {
        return new Mailbox(
            1,
            'Boîte de l\'unité',
            ProviderType::IMAP,
            'imap.example.be',
            993,
            'ssl',
            'chef@unite.be',
            ['INBOX'],
            true,
            SyncState::NEVER
        );
    }

    private function credentials(): MailboxCredentials
    {
        return new MailboxCredentials('chef@unite.be', 'chene2026');
    }
}
