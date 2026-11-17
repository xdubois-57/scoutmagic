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

    // ── The first read (D11) ────────────────────────────────────────────

    /**
     * The selection is a SEARCH by reception date and, past the cap, the
     * reception dates themselves — read-only open, no body, no flag. The
     * most recent are kept by INTERNALDATE: UIDs 4 to 6 are an old import
     * with UIDs above this year's mail.
     */
    public function testAFirstReadSelectionKeepsTheMostRecentlyReceivedAndTouchesNothing(): void
    {
        $now = new \DateTimeImmutable('2027-07-12 10:00:00');
        $this->server->folders['INBOX'] = [];
        $ages = [1 => '-3 days', 2 => '-1 day', 3 => '-2 days', 4 => '-2 years', 5 => '-3 years', 6 => '-4 years'];
        foreach ($ages as $uid => $ago) {
            $this->server->folders['INBOX'][$uid] = str_replace('<demande@', '<demande-' . $uid . '@', self::MESSAGE);
            $this->server->internalDates['INBOX'][$uid] = $now->modify($ago);
        }
        // An old import, with UIDs above this year's mail.
        $this->server->folders['INBOX'][7] = self::MESSAGE;
        $this->server->internalDates['INBOX'][7] = $now->modify('-5 years');

        $selection = $this->connected()->firstReadSelection('INBOX', $now->modify('-12 months'), 2);

        $this->assertSame([2, 3], $selection->uids, 'the two received last, never the highest UIDs');
        $this->assertSame(7, $selection->highestUid);
        $this->assertSame(5, $selection->leftAside);
        $this->assertContains('EXAMINE INBOX', $this->server->commands);
        $this->assertContains('SEARCH SINCE 12-Jul-2026', $this->server->commands);
        $this->assertContains('FETCH INTERNALDATE', $this->server->commands);
        $this->assertSame([], $this->server->stored, 'no flag was set or removed');
        foreach ($this->server->commands as $command) {
            $this->assertStringStartsNotWith('SELECT', $command);
            $this->assertStringNotContainsString('BODY', $command, 'no message is read to choose');
            $this->assertStringNotContainsString('RFC822', $command);
        }
    }

    /**
     * A server that leaves UIDNEXT out of its status: the highest UID comes
     * from the folder's UIDs, not 0 — which would retain nothing and start
     * the cursor at the bottom, the whole history.
     */
    public function testAFirstReadWithoutUidNextStillFindsTheHighestUid(): void
    {
        $now = new \DateTimeImmutable('2027-07-12 10:00:00');
        $this->server->omitUidNext = true;
        $this->server->folders['INBOX'] = [];
        foreach ([3 => '-1 day', 8 => '-2 days', 12 => '-3 years'] as $uid => $ago) {
            $this->server->folders['INBOX'][$uid] = str_replace('<demande@', '<demande-' . $uid . '@', self::MESSAGE);
            $this->server->internalDates['INBOX'][$uid] = $now->modify($ago);
        }

        $selection = $this->connected()->firstReadSelection('INBOX', $now->modify('-12 months'), 10);

        $this->assertSame([3, 8], $selection->uids);
        $this->assertSame(12, $selection->highestUid, 'the cursor starts above the old import');
        $this->assertSame(1, $selection->leftAside);
        $this->assertContains('SEARCH ALL', $this->server->commands);
    }

    /**
     * Without UIDNEXT, the folder status asks for the highest UID too, and
     * agrees with the first read: a 0 here would hold new mail back behind
     * a first read's whole backlog.
     */
    public function testAFolderStatusWithoutUidNextGivesTheSameHighestUidAsTheFirstRead(): void
    {
        $this->server->omitUidNext = true;
        $this->server->folders['INBOX'] = [4 => self::MESSAGE, 9 => self::MESSAGE];

        $client = $this->connected();

        $this->assertSame(9, $client->folderState('INBOX')->highestUid);
        $this->assertSame(9, $client->firstReadSelection('INBOX', new \DateTimeImmutable('-12 months'), 10)->highestUid);
        $this->assertSame([], $this->server->stored, 'no flag was set or removed');

        $this->server->folders['INBOX'] = [];
        $this->assertSame(0, $client->folderState('INBOX')->highestUid, 'an empty folder');
    }

    /**
     * Another client changes a message's flags while its reception date is
     * being read: the server's unsolicited FLAGS answer must not erase the
     * date, which would sort the newest message last and set it aside.
     */
    public function testAnUnsolicitedFlagsAnswerDoesNotEraseAReceptionDate(): void
    {
        $now = new \DateTimeImmutable('2027-07-12 10:00:00');
        $this->server->folders['INBOX'] = [];
        foreach ([1 => '-3 days', 2 => '-2 days', 3 => '-1 hour'] as $uid => $ago) {
            $this->server->folders['INBOX'][$uid] = str_replace('<demande@', '<demande-' . $uid . '@', self::MESSAGE);
            $this->server->internalDates['INBOX'][$uid] = $now->modify($ago);
        }
        $this->server->unsolicitedFlags = [3];

        $selection = $this->connected()->firstReadSelection('INBOX', $now->modify('-12 months'), 2);

        $this->assertSame([2, 3], $selection->uids, 'the newest is kept');
    }

    public function testAFirstReadWithinTheCapDoesNotEvenFetchTheDates(): void
    {
        $selection = $this->connected()->firstReadSelection('INBOX', new \DateTimeImmutable('-12 months'), 10);

        $this->assertSame([5], $selection->uids);
        $this->assertSame(0, $selection->leftAside);
        $this->assertNotContains('FETCH INTERNALDATE', $this->server->commands);
    }

    /** The retained messages, read by name — as passively as any other read. */
    public function testRetainedMessagesAreReadByNameAndPassively(): void
    {
        $this->server->folders['INBOX'] = [];
        foreach ([3, 5, 8] as $uid) {
            $this->server->folders['INBOX'][$uid] = str_replace('<demande@', '<demande-' . $uid . '@', self::MESSAGE);
        }

        $fetched = $this->connected()->fetchUids('INBOX', [8, 3, 42]);

        $this->assertSame([3, 8], array_map(static fn($message): int => $message->uid, $fetched));
        $this->assertSame('Bonjour, est-ce libre ?', $fetched[0]->bodyText);
        $this->assertSame([], $this->server->stored, 'no flag was set or removed');
        $this->assertContains('EXAMINE INBOX', $this->server->commands);
        $this->assertContains('FETCH UID BODY.PEEK[TEXT]', $this->server->commands);
        foreach ($this->server->commands as $command) {
            $this->assertStringStartsNotWith('SELECT', $command);
            $this->assertNotSame('FETCH RFC822.TEXT', $command, 'RFC822.TEXT marks the message read');
            $this->assertNotSame('FETCH FLAGS', $command);
        }
    }

    public function testAFirstReadSearchTheServerRefusesIsAConnectionProblem(): void
    {
        $this->server->refuse = ['search'];

        $this->assertConnectionProblem(
            fn() => $this->connected()->firstReadSelection('INBOX', new \DateTimeImmutable('-12 months'), 10),
            MailboxConnectionException::STEP_FETCH
        );
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
