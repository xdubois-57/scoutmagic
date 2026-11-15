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
use Modules\InboundMail\Client\TolerantMessageQuery;
use Modules\InboundMail\Mailbox\Mailbox;
use Modules\InboundMail\Mailbox\MailboxCredentials;
use Modules\InboundMail\Mailbox\ProviderType;
use Modules\InboundMail\Mailbox\SyncState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * One message anybody can send must not stop a mailbox (a denial of
 * service): a `Date:` header `webklex/php-imap` cannot parse, or two of
 * them.
 *
 * The library parses every message inside `get()`, and such a message used
 * to fail the WHOLE batch there — as a connection problem, with the good
 * messages beside it, and with a cursor that never moved, so every later
 * pass met it again. These drive the real library through
 * `ScriptedImapServer`, since the failure lives in code nobody here wrote.
 */
class ImapUnreadableMessageTest extends TestCase
{
    private const MESSAGE = "From: Jeanne Martin <jeanne@example.be>\r\n"
        . "Subject: Demande de location\r\n"
        . "Message-ID: <demande@example.be>\r\n"
        . "Date: Mon, 12 Jul 2027 09:30:00 +0200\r\n"
        . "\r\n"
        . "Bonjour, est-ce libre ?\r\n";

    private const DATE_LINE = "Date: Mon, 12 Jul 2027 09:30:00 +0200\r\n";

    private ScriptedImapServer $server;

    private string $log = '';

    private string|false $previousLog = false;

    protected function setUp(): void
    {
        $this->server = new ScriptedImapServer();
        $this->log = (string) tempnam(sys_get_temp_dir(), 'imaplog');
        $this->previousLog = ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->previousLog);
        @unlink($this->log);
    }

    /**
     * The second value says whether the PHP date parser may read the lines
     * after all: it refuses two dates on some releases and not on others.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function unreadableDates(): array
    {
        return [
            'a Date the library cannot parse' => ['Date: pas une date' . "\r\n", false],
            'two Date headers' => [self::DATE_LINE . "Date: Tue, 13 Jul 2027 10:00:00 +0200\r\n", true],
        ];
    }

    /**
     * The good messages on either side come back as they always did, and
     * the bad one is kept rather than lost: without a date, which the
     * client stores at its reception time, and with its header block as
     * the server wrote it.
     */
    #[DataProvider('unreadableDates')]
    public function testAMessageWithAnUnreadableDateCostsTheBatchNothing(string $dateLines, bool $mayParse): void
    {
        $bad = str_replace(
            ['<demande@', 'Subject: Demande de location', self::DATE_LINE],
            ['<illisible@', 'Subject: Date illisible', $dateLines],
            self::MESSAGE
        );
        $this->server->folders['INBOX'] = [
            5 => self::MESSAGE,
            6 => $bad,
            7 => str_replace('<demande@', '<suite@', self::MESSAGE),
        ];
        $before = new \DateTimeImmutable('-1 minute');

        $fetched = $this->connected()->fetchSince('INBOX', 0, 10);

        $this->assertSame([5, 6, 7], array_map(static fn(FetchedMessage $m): int => $m->uid, $fetched));
        $this->assertSame('2027-07-12', $fetched[0]->sentAt->format('Y-m-d'));
        $this->assertSame('2027-07-12', $fetched[2]->sentAt->format('Y-m-d'));

        $undated = $fetched[1];
        $this->assertSame('illisible@example.be', $undated->messageId);
        $this->assertSame('Date illisible', $undated->subject);
        $this->assertSame('jeanne@example.be', $undated->fromEmail);
        $this->assertStringContainsString('est-ce libre', (string) $undated->bodyText);
        $this->assertStringContainsString($dateLines, $undated->rawHeaders, 'the header block as received');

        $written = (string) file_get_contents($this->log);
        $traced = str_contains($written, 'message UID 6 in folder "INBOX" has an unreadable Date header');
        if ($mayParse && !$traced) {
            // Whether two `Date:` lines parse is the PHP date parser's
            // call, and it is not the same on every PHP release: when it
            // reads them, the message keeps the first date, untraced.
            $this->assertSame('2027-07-12', $undated->sentAt->format('Y-m-d'));
        } else {
            $this->assertTrue($traced, 'the unreadable Date is traced');
            $this->assertGreaterThanOrEqual($before, $undated->sentAt, 'stored at its reception time');
            $this->assertLessThan(new \DateTimeImmutable('+1 minute'), $undated->sentAt, 'not a header date');
        }
        $this->assertStringNotContainsString('jeanne', $written, 'no sender in the server log');
        $this->assertStringNotContainsString('illisible@', $written, 'no Message-ID in the server log');
    }

    /** Reading the bad message marks nothing, as for every other message. */
    #[DataProvider('unreadableDates')]
    public function testReadingTheUnreadableMessageStillMarksNothing(string $dateLines, bool $mayParse): void
    {
        $this->server->folders['INBOX'] = [6 => str_replace(self::DATE_LINE, $dateLines, self::MESSAGE)];

        $this->connected()->fetchSince('INBOX', 0, 10);

        $added = array_filter($this->server->stored, static fn(string $store): bool => $store[0] === '+');
        $this->assertSame([], array_values($added), 'no flag added to the remote message');
    }

    /** What still fails the batch is the server, exactly as before. */
    public function testASearchTheServerRefusesIsStillAConnectionProblem(): void
    {
        $this->server->folders['INBOX'] = [5 => self::MESSAGE];
        $this->server->refuse = ['search'];

        try {
            $this->connected()->fetchSince('INBOX', 0, 10);
            $this->fail('A connection problem was expected.');
        } catch (MailboxConnectionException $e) {
            $this->assertSame('ResponseException', $e->getMessage(), 'the class, and nothing the server said');
        }
    }

    /**
     * Only the `Date:` field goes, folded lines included; a field whose
     * name merely contains « Date » stays.
     */
    public function testOnlyTheDateFieldIsRemovedFromTheHeaderBlock(): void
    {
        $header = "From: a@example.be\r\n"
            . "date: Mon,\r\n"
            . "\t12 Jul 2027 09:30:00 +0200\r\n"
            . "Resent-Date: Tue, 13 Jul 2027 10:00:00 +0200\r\n"
            . "Date-Sent: Tue, 13 Jul 2027 10:00:00 +0200\r\n"
            . "Subject: Un sujet\r\n"
            . " sur deux lignes\r\n"
            . "DATE : encore une\r\n"
            . "\r\n";

        $this->assertSame(
            "From: a@example.be\r\n"
            . "Resent-Date: Tue, 13 Jul 2027 10:00:00 +0200\r\n"
            . "Date-Sent: Tue, 13 Jul 2027 10:00:00 +0200\r\n"
            . "Subject: Un sujet\r\n"
            . " sur deux lignes\r\n"
            . "\r\n",
            TolerantMessageQuery::withoutDate($header)
        );
    }

    private function connected(): ImapMailboxClient
    {
        $client = new ImapMailboxClient(new ScriptedImapManager($this->server));
        $client->connect(
            new Mailbox(
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
            ),
            new MailboxCredentials('chef@unite.be', 'chene2026')
        );

        return $client;
    }
}
