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
use Modules\InboundMail\Service\MailboxErrorFormatter;
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

    /**
     * What the server sees of a read (#1231): the folder opened with
     * EXAMINE, the body fetched with BODY.PEEK, and not one STORE. The
     * library alone SELECTed, fetched RFC822.TEXT — which sets \\Seen on a
     * real server — and then sent STORE -FLAGS \\Seen to put it back.
     */
    public function testAReadOpensReadOnlyPeeksAtTheBodyAndStoresNothing(): void
    {
        $fetched = $this->connected()->fetchSince('INBOX', 0, 10);

        $this->assertCount(1, $fetched);
        $this->assertSame('Bonjour, est-ce libre ?', $fetched[0]->bodyText, 'the body came through BODY.PEEK');
        $this->assertSame([], $this->server->stored, 'no flag was set or removed');
        $this->assertContains('EXAMINE INBOX', $this->server->commands);
        $this->assertContains('FETCH UID BODY.PEEK[TEXT]', $this->server->commands);
        foreach ($this->server->commands as $command) {
            $this->assertStringStartsNotWith('SELECT', $command);
            $this->assertNotSame('FETCH RFC822.TEXT', $command, 'RFC822.TEXT marks the message read');
            $this->assertNotSame('FETCH FLAGS', $command);
        }
    }

    /**
     * The sending time keeps its instant (#1248): `09:30 +0200` is 07:30
     * UTC, and the site, keeping wall clocks in its own zone, stores the
     * hour that instant is there — not 09:30 whatever the zone.
     */
    public function testTheSendingTimeIsMovedIntoTheSitesZoneBeforeItLosesItsOffset(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('UTC');

        try {
            $fetched = $this->connected()->fetchSince('INBOX', 0, 10);
            $this->assertSame('2027-07-12 07:30:00', $fetched[0]->sentAt->format('Y-m-d H:i:s'));

            date_default_timezone_set('America/New_York');
            $fetched = $this->connected()->fetchSince('INBOX', 0, 10);
            $this->assertSame('2027-07-12 03:30:00', $fetched[0]->sentAt->format('Y-m-d H:i:s'));
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testAFolderListingTheServerRefusesIsAConnectionProblem(): void
    {
        $this->server->refuse = ['list'];

        $this->assertConnectionProblem(
            fn() => $this->connected()->listFolders(),
            MailboxConnectionException::STEP_LIST
        );
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

        $this->assertConnectionProblem(
            fn() => $this->connected()->folderState('INBOX'),
            MailboxConnectionException::STEP_OPEN_FOLDER
        );
    }

    public function testAFolderStatusTheServerRefusesIsAConnectionProblem(): void
    {
        $this->server->refuse = ['examine'];

        $this->assertConnectionProblem(
            fn() => $this->connected()->folderState('INBOX'),
            MailboxConnectionException::STEP_OPEN_FOLDER
        );
    }

    /**
     * The case #1325 was opened for: the login and the LIST went through,
     * then the server refused to open the folder. That server answered,
     * and the sentence must not send the superadmin to check the host.
     */
    public function testAFolderRefusedAfterASuccessfulLoginIsNotCalledAServerThatDidNotAnswer(): void
    {
        $this->server->refuse = ['examine'];
        $client = $this->connected();
        $this->assertSame(['INBOX'], array_map(static fn($folder): string => $folder->path, $client->listFolders()));

        try {
            $client->folderState('INBOX');
            $this->fail('The EXAMINE refusal was expected.');
        } catch (MailboxConnectionException $e) {
            $sentence = (new MailboxErrorFormatter())->format($e);
        }

        $this->assertStringNotContainsString('n\'a pas répondu', $sentence);
        $this->assertStringContainsString('après une connexion réussie', $sentence);
        $this->assertStringContainsString('l\'ouverture d\'un dossier surveillé', $sentence);
        $this->assertStringContainsString('ResponseException', $sentence);
        $this->assertStringNotContainsString('chef@unite.be', $sentence);
    }

    public function testASearchTheServerRefusesIsAConnectionProblem(): void
    {
        $this->server->refuse = ['search'];

        $this->assertConnectionProblem(
            fn() => $this->connected()->fetchSince('INBOX', 0, 10),
            MailboxConnectionException::STEP_FETCH
        );
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
    private function assertConnectionProblem(\Closure $operation, string $step): void
    {
        try {
            $operation();
            $this->fail('A connection problem was expected.');
        } catch (MailboxConnectionException $e) {
            $this->assertSame($step, $e->step, 'the command that failed, for the formatter (#1325)');
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
