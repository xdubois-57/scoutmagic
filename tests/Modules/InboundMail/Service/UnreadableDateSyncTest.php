<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Service;

use Core\Security\EncryptionService;
use Core\Security\HtmlSanitizer;
use Modules\InboundMail\Client\ImapMailboxClient;
use Modules\InboundMail\Mailbox\Mailbox;
use Modules\InboundMail\Mailbox\ProviderType;
use Modules\InboundMail\Repository\InboundMailboxRepository;
use Modules\InboundMail\Repository\InboundMessageRepository;
use Modules\InboundMail\Service\AnalysisResultApplier;
use Modules\InboundMail\Service\AttachmentPolicy;
use Modules\InboundMail\Service\MailboxClientFactory;
use Modules\InboundMail\Service\MailboxErrorFormatter;
use Modules\InboundMail\Service\MailboxSyncService;
use Modules\InboundMail\Service\MessageConsumerRegistry;
use Modules\InboundMail\Service\MessageContentSanitizer;
use Modules\InboundMail\Service\SyncOutcome;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Modules\InboundMail\Client\ScriptedImapManager;
use Tests\Modules\InboundMail\Client\ScriptedImapServer;
use Tests\UsesProductionEngine;

/**
 * The denial of service, at the level where it was felt: one message with
 * a `Date:` the IMAP library cannot parse failed every synchronisation of
 * its box, for good, since the cursor never moved past it.
 *
 * The real sync, the real IMAP client and the real library, against a
 * scripted server and the production engine.
 */
#[Group('database')]
class UnreadableDateSyncTest extends TestCase
{
    use UsesProductionEngine;

    private const MESSAGE = "From: Jeanne Martin <jeanne@example.be>\r\n"
        . "To: locations@unite.be\r\n"
        . "Subject: Demande de location\r\n"
        . "Message-ID: <demande@example.be>\r\n"
        . "Date: Mon, 12 Jul 2027 09:30:00 +0200\r\n"
        . "\r\n"
        . "Bonjour, est-ce libre ?\r\n";

    private \PDO $pdo;
    private InboundMailboxRepository $mailboxRepository;
    private InboundMessageRepository $messageRepository;
    private ScriptedImapServer $server;
    private MailboxSyncService $service;
    private int $mailboxId;
    private string $log = '';
    private string|false $previousLog = false;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->mailboxRepository = new InboundMailboxRepository($this->pdo, $encryption);
        $this->messageRepository = new InboundMessageRepository($this->pdo, $encryption);
        $this->server = new ScriptedImapServer();

        $factory = new MailboxClientFactory();
        $factory->register(ProviderType::IMAP, new ImapMailboxClient(new ScriptedImapManager($this->server)));
        $this->service = new MailboxSyncService(
            $this->mailboxRepository,
            $this->messageRepository,
            new MessageConsumerRegistry(),
            new MessageContentSanitizer(new HtmlSanitizer()),
            new AttachmentPolicy(),
            new MailboxErrorFormatter(),
            $factory,
            new AnalysisResultApplier($this->messageRepository)
        );

        $this->mailboxId = $this->mailboxRepository->create(
            ProviderType::IMAP,
            'imap.example.be',
            993,
            'ssl',
            'locations@unite.be',
            'un-mot-de-passe',
            ['INBOX'],
            true
        );

        $this->log = (string) tempnam(sys_get_temp_dir(), 'imaplog');
        $this->previousLog = ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->previousLog);
        @unlink($this->log);
    }

    public function testAMessageWithAnUnreadableDateNoLongerStopsTheBox(): void
    {
        $this->server->folders['INBOX'] = [
            5 => self::MESSAGE,
            6 => str_replace(
                ['<demande@', 'Mon, 12 Jul 2027 09:30:00 +0200'],
                ['<illisible@', 'pas une date'],
                self::MESSAGE
            ),
        ];

        $first = $this->sync();

        $this->assertFalse($first->isFailure(), 'the pass succeeds: ' . (string) $first->reason);
        $this->assertSame(2, $first->messagesSeen);
        $this->assertSame(2, $first->messagesStored, 'the good message and the bad one are both kept');
        $this->assertSame(6, $this->mailboxRepository->findCursor($this->mailboxId, 'INBOX')->lastUid);
        $this->assertNull($this->mailbox()->lastError);
        $this->assertNotNull($this->messageRepository->findIdByMessageId($this->mailboxId, 'demande@example.be'));
        $this->assertNotNull($this->messageRepository->findIdByMessageId($this->mailboxId, 'illisible@example.be'));

        // The second pass is where the box used to stay stuck for ever.
        $this->server->folders['INBOX'][7] = str_replace('<demande@', '<suite@', self::MESSAGE);

        $second = $this->sync();

        $this->assertFalse($second->isFailure(), 'the next pass does not meet the message again');
        $this->assertSame(1, $second->messagesSeen, 'only the new message is read');
        $this->assertSame(7, $this->mailboxRepository->findCursor($this->mailboxId, 'INBOX')->lastUid);
        $this->assertNotNull($this->messageRepository->findIdByMessageId($this->mailboxId, 'suite@example.be'));
        $this->assertSame(
            1,
            substr_count((string) file_get_contents($this->log), 'message UID 6'),
            'traced once, by the pass that read it'
        );
    }

    /** Two `Date:` headers, the other shape of the same failure. */
    public function testAMessageWithTwoDatesNoLongerStopsTheBox(): void
    {
        $this->server->folders['INBOX'] = [
            5 => str_replace(
                "Date: Mon, 12 Jul 2027 09:30:00 +0200\r\n",
                "Date: Mon, 12 Jul 2027 09:30:00 +0200\r\nDate: Tue, 13 Jul 2027 10:00:00 +0200\r\n",
                self::MESSAGE
            ),
        ];

        $first = $this->sync();
        $second = $this->sync();

        $this->assertFalse($first->isFailure(), 'the pass succeeds: ' . (string) $first->reason);
        $this->assertSame(1, $first->messagesStored);
        $this->assertSame(5, $this->mailboxRepository->findCursor($this->mailboxId, 'INBOX')->lastUid);
        $this->assertFalse($second->isFailure());
        $this->assertSame(0, $second->messagesSeen, 'the message is not met again');
    }

    private function sync(): SyncOutcome
    {
        return $this->service->syncMailbox($this->mailbox(), new \DateTimeImmutable('now'));
    }

    private function mailbox(): Mailbox
    {
        $mailbox = $this->mailboxRepository->findById($this->mailboxId);
        $this->assertNotNull($mailbox);

        return $mailbox;
    }
}
