<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Service;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Core\Security\HtmlSanitizer;
use Modules\InboundMail\Api\AnalysisResult;
use Modules\InboundMail\Api\CandidateMessage;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Client\FakeMailboxClient;
use Modules\InboundMail\Mailbox\ProviderType;
use Modules\InboundMail\Repository\InboundMailboxRepository;
use Modules\InboundMail\Repository\InboundMessageRepository;
use Modules\InboundMail\Service\AnalysisJournal;
use Modules\InboundMail\Service\AnalysisResultApplier;
use Modules\InboundMail\Service\AttachmentPolicy;
use Modules\InboundMail\Service\MailboxClientFactory;
use Modules\InboundMail\Service\MailboxErrorFormatter;
use Modules\InboundMail\Service\MailboxSyncService;
use Modules\InboundMail\Service\MessageConsumerRegistry;
use Modules\InboundMail\Service\MessageContentSanitizer;
use Modules\InboundMail\Service\SyncOutcome;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\InboundMail\FakeOutboundConsumer;
use Tests\Modules\InboundMail\InboundMailTestHelper;

/**
 * The first read of a folder is limited (D11): the last twelve months, at
 * most the 5 000 most recent **by the server's reception date** — never by
 * UID — and the cursor then neither skips a retained message nor ever
 * reaches one set aside.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class FirstReadLimitTest extends TestCase
{
    private const NOW = '2027-07-12 10:00:00';

    private \PDO $pdo;
    private InboundMailboxRepository $mailboxes;
    private InboundMessageRepository $messages;
    private MessageConsumerRegistry $registry;
    private JournalRepository $journal;
    private FakeMailboxClient $client;
    private MailboxSyncService $service;
    private int $mailboxId;

    protected function setUp(): void
    {
        $this->pdo = $this->database();
        if ($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            InboundMailTestHelper::createTables($this->pdo);
        }
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->mailboxes = new InboundMailboxRepository($this->pdo, $encryption);
        $this->messages = new InboundMessageRepository($this->pdo, $encryption);
        $this->registry = new MessageConsumerRegistry();
        $this->journal = new JournalRepository($this->pdo);
        $this->client = new FakeMailboxClient();

        $factory = new MailboxClientFactory();
        $factory->register(ProviderType::FAKE, $this->client);

        $this->service = new MailboxSyncService(
            $this->mailboxes,
            $this->messages,
            $this->registry,
            new MessageContentSanitizer(new HtmlSanitizer()),
            new AttachmentPolicy(),
            new MailboxErrorFormatter(),
            $factory,
            new AnalysisResultApplier($this->messages),
            analysisJournal: new AnalysisJournal(new JournalService($this->journal))
        );

        $this->mailboxId = $this->mailboxes->create(
            'Locations',
            ProviderType::FAKE,
            'imap.test',
            993,
            'ssl',
            'locations@unite.be',
            'un-mot-de-passe',
            ['INBOX'],
            true
        );
    }

    // ── The rule ─────────────────────────────────────────────────────────

    /**
     * Seven thousand messages over three years, six thousand of them in the
     * last twelve months, and UIDs that do not follow the reception dates.
     * The first read keeps exactly the 5 000 received last.
     */
    public function testAThreeYearBoxKeepsTheFiveThousandReceivedLastInTheLastTwelveMonths(): void
    {
        $expected = [];
        for ($uid = 1; $uid <= 7000; $uid++) {
            // A permutation of 0..6999 (2333 is prime to 7000): reception
            // order is not UID order. Rank 0 is the most recent.
            $rank = ($uid * 2333) % 7000;
            $receivedAt = $rank < 6000
                ? $this->now()->modify('-' . ($rank + 1) . ' hours')
                : $this->now()->modify('-400 days')->modify('-' . (($rank - 6000) * 16) . ' hours');
            $this->add('INBOX', $uid, $receivedAt);
            if ($rank < MailboxSyncService::FIRST_READ_LIMIT) {
                $expected[] = $uid;
            }
        }

        $outcome = $this->sync();

        $cursor = $this->mailboxes->findCursor($this->mailboxId, 'INBOX');
        $this->assertSame(7000, $cursor->lastUid, 'the cursor starts from the highest UID of the first read');
        $this->assertSame(MailboxSyncService::BATCH_SIZE, $outcome->messagesSeen, 'one pass stays bounded');
        $this->assertCount(5000 - MailboxSyncService::BATCH_SIZE, $cursor->pendingUids);

        $taken = array_merge($this->client->fetchedUids['INBOX'], $cursor->pendingUids);
        sort($taken);
        $this->assertSame($expected, $taken, 'the 5 000 most recent by reception date, and only those');

        $entry = $this->journalEntry();
        $this->assertSame(['left_aside' => 2000, 'mailbox_id' => $this->mailboxId, 'retained' => 5000], $entry);
    }

    public function testASmallRecentBoxIsReadWhole(): void
    {
        for ($uid = 1; $uid <= 200; $uid++) {
            $this->add('INBOX', $uid, $this->now()->modify('-' . $uid . ' days'));
        }

        $passes = $this->syncUntilDrained();

        $this->assertSame(200, $this->countMessages());
        $this->assertSame(200 / MailboxSyncService::BATCH_SIZE, $passes);
        $this->assertSame(range(1, 200), $this->client->fetchedUids['INBOX']);
        $this->assertNull($this->journalEntry(), 'nothing was left aside, so nothing is said');
    }

    /**
     * A server that leaves UIDVALIDITY out of its answer reports 0, the
     * value a cursor never saved starts at: the first read must apply all
     * the same, and not read the whole history from UID 0.
     */
    public function testAServerReportingNoUidValidityStillGetsTheFirstRead(): void
    {
        $this->client->setUidValidity('INBOX', 0);
        for ($uid = 1; $uid <= 30; $uid++) {
            $this->add('INBOX', $uid, $this->now()->modify('-' . $uid . ' days'));
        }
        for ($uid = 31; $uid <= 50; $uid++) {
            $this->add('INBOX', $uid, $this->now()->modify('-3 years')->modify('-' . $uid . ' days'));
        }

        $this->syncUntilDrained();

        $this->assertSame(range(1, 30), $this->client->fetchedUids['INBOX'], 'the old ones are left aside');
        $this->assertSame(50, $this->mailboxes->findCursor($this->mailboxId, 'INBOX')->lastUid);
        $this->assertSame(
            ['left_aside' => 20, 'mailbox_id' => $this->mailboxId, 'retained' => 30],
            $this->journalEntry()
        );

        // The cursor has moved: the next pass is an ordinary one.
        $this->client->calls = [];
        $this->assertSame(0, $this->sync()->messagesSeen);
        $this->assertContains('fetchSince:INBOX:50', $this->client->calls);
    }

    /**
     * The trap D11 names: an old mailbox imported into this one gets UIDs
     * above this year's mail. Judged by UID, the import would be kept and
     * the year lost.
     */
    public function testOldImportedMessagesWithHigherUidsAreLeftAsideAndNeverRead(): void
    {
        for ($uid = 1; $uid <= 60; $uid++) {
            $this->add('INBOX', $uid, $this->now()->modify('-' . $uid . ' days'));
        }
        for ($uid = 61; $uid <= 100; $uid++) {
            $this->add('INBOX', $uid, $this->now()->modify('-3 years')->modify('-' . $uid . ' days'));
        }

        $this->syncUntilDrained();

        $this->assertSame(range(1, 60), $this->client->fetchedUids['INBOX'], 'the recent ones, each read once');
        $this->assertSame(60, $this->countMessages());
        $this->assertSame(100, $this->mailboxes->findCursor($this->mailboxId, 'INBOX')->lastUid);
        $this->assertSame(
            ['left_aside' => 40, 'mailbox_id' => $this->mailboxId, 'retained' => 60],
            $this->journalEntry()
        );

        // And at the next pass, none of them — old or recent — is met again.
        $this->client->calls = [];
        $outcome = $this->sync();

        $this->assertSame(0, $outcome->messagesSeen);
        $this->assertContains('fetchSince:INBOX:100', $this->client->calls);
        $this->assertSame(range(1, 60), $this->client->fetchedUids['INBOX']);
    }

    public function testThePassAfterAFirstReadReadsOnlyNewArrivals(): void
    {
        for ($uid = 1; $uid <= 20; $uid++) {
            $this->add('INBOX', $uid, $this->now()->modify('-' . $uid . ' days'));
        }
        $this->sync();

        $this->add('INBOX', 21, $this->now());
        $this->add('INBOX', 22, $this->now());
        $this->client->calls = [];
        $outcome = $this->sync();

        $this->assertSame(2, $outcome->messagesSeen);
        $this->assertSame(array_merge(range(1, 20), [21, 22]), $this->client->fetchedUids['INBOX']);
        $this->assertNotContains('firstReadSelection:INBOX:2026-07-12:5000', $this->client->calls);
        $this->assertSame([], array_values(array_filter(
            $this->client->calls,
            static fn(string $call): bool => str_starts_with($call, 'fetchUids:')
        )));
    }

    /**
     * Mail that arrives while a long first read is still going is read at
     * once, ahead of the backlog, and within the same per-pass bound.
     */
    public function testMailArrivingDuringAFirstReadIsNotHeldBehindIt(): void
    {
        for ($uid = 1; $uid <= 120; $uid++) {
            $this->add('INBOX', $uid, $this->now()->modify('-' . $uid . ' hours'));
        }
        $this->sync();

        $this->add('INBOX', 121, $this->now());
        $second = $this->sync();

        $this->assertSame(MailboxSyncService::BATCH_SIZE, $second->messagesSeen);
        $this->assertSame(121, $this->client->fetchedUids['INBOX'][MailboxSyncService::BATCH_SIZE]);

        $this->syncUntilDrained();
        $read = $this->client->fetchedUids['INBOX'];
        sort($read);
        $this->assertSame(range(1, 121), $read, 'every message read, none twice');
        $this->assertSame(121, $this->countMessages());
    }

    public function testAUidValidityChangeAppliesTheRuleAgain(): void
    {
        for ($uid = 1; $uid <= 10; $uid++) {
            $this->add('INBOX', $uid, $this->now()->modify('-' . $uid . ' days'));
        }
        $this->sync();
        $this->assertNull($this->journalEntry());

        // Renumbered — a restore that brought old mail back with it.
        $this->client->setUidValidity('INBOX', 77);
        for ($uid = 11; $uid <= 15; $uid++) {
            $this->add('INBOX', $uid, $this->now()->modify('-2 years'));
        }
        $this->client->calls = [];
        $outcome = $this->sync();

        $this->assertContains('firstReadSelection:INBOX:2026-07-12:5000', $this->client->calls);
        $cursor = $this->mailboxes->findCursor($this->mailboxId, 'INBOX');
        $this->assertSame(77, $cursor->uidValidity);
        $this->assertSame(15, $cursor->lastUid);
        $this->assertSame([], $cursor->pendingUids);
        $this->assertSame(10, $outcome->messagesSeen, 'the recent ones read again…');
        $this->assertSame(0, $outcome->messagesStored, '…and recognised by their Message-ID');
        $this->assertSame(10, $this->countMessages());
        $this->assertNotContains(11, $this->client->fetchedUids['INBOX'], 'the old ones are left aside');
        $this->assertSame(
            ['left_aside' => 5, 'mailbox_id' => $this->mailboxId, 'retained' => 10],
            $this->journalEntry()
        );
    }

    public function testAFolderTickedLaterHasAFirstReadOfItsOwn(): void
    {
        $this->add('INBOX', 1, $this->now());
        $this->sync();

        $this->add('Archives', 1, $this->now()->modify('-1 month'), 'archive-recent');
        $this->add('Archives', 2, $this->now()->modify('-5 years'), 'archive-ancien');
        $this->mailboxes->update(
            $this->mailboxId,
            'Locations',
            'imap.test',
            993,
            'ssl',
            'locations@unite.be',
            ['INBOX', 'Archives'],
            true
        );
        $this->sync();

        $this->assertSame([1], $this->client->fetchedUids['Archives']);
        $this->assertSame(2, $this->mailboxes->findCursor($this->mailboxId, 'Archives')->lastUid);
        $this->assertNotNull($this->messages->findIdByMessageId($this->mailboxId, 'archive-recent-1@example.be'));
        $this->assertNull($this->messages->findIdByMessageId($this->mailboxId, 'archive-ancien-2@example.be'));
    }

    public function testTheSentFolderHasAFirstReadToo(): void
    {
        $this->client->markSent('Envoyés');
        $this->add('Envoyés', 1, $this->now()->modify('-2 years'), 'envoi-ancien');
        $this->add('Envoyés', 2, $this->now()->modify('-1 week'), 'envoi-recent');
        $this->registry->register(new FakeOutboundConsumer(
            'rental',
            static fn(CandidateMessage $m): AnalysisResult
                => AnalysisResult::linkedTo('rental', 'LOC-K7Q2MX', LinkOrigin::RECIPIENT)
        ));

        $this->sync();

        $this->assertContains('firstReadSelection:Envoyés:2026-07-12:5000', $this->client->calls);
        $this->assertSame([2], $this->client->fetchedUids['Envoyés']);
        $this->assertSame(2, $this->mailboxes->findCursor($this->mailboxId, 'Envoyés')->lastUid);
    }

    // ── Fixtures ────────────────────────────────────────────────────────

    protected function database(): \PDO
    {
        return DatabaseTestHelper::createTestDatabase();
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    private function add(string $folder, int $uid, \DateTimeImmutable $receivedAt, string $prefix = 'msg'): void
    {
        $this->client->addRawMessage($folder, $uid, InboundMailTestHelper::rawMessage([
            'From' => 'Jeanne Martin <jeanne@example.be>',
            'To' => 'locations@unite.be',
            'Subject' => 'Message ' . $uid,
            'Message-ID' => '<' . $prefix . '-' . $uid . '@example.be>',
            'Date' => $receivedAt->format(\DateTimeInterface::RFC2822),
            'Content-Type' => 'text/plain; charset=UTF-8',
        ], 'Bonjour.'), $receivedAt);
    }

    private function sync(): SyncOutcome
    {
        $mailbox = $this->mailboxes->findById($this->mailboxId);
        $this->assertNotNull($mailbox);

        $outcome = $this->service->syncMailbox($mailbox, $this->now());
        $this->assertFalse($outcome->isFailure(), (string) $outcome->reason);

        return $outcome;
    }

    /** The passes it took for the first read of INBOX to have nothing left. */
    private function syncUntilDrained(): int
    {
        $passes = 0;
        do {
            $this->sync();
            $passes++;
            $this->assertLessThan(200, $passes, 'a first read that never ends');
        } while ($this->mailboxes->findCursor($this->mailboxId, 'INBOX')->hasPending());

        return $passes;
    }

    private function countMessages(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM inbound_messages')->fetchColumn();
    }

    /**
     * The context of the latest « première relève limitée » entry, or null.
     *
     * @return array<string, mixed>|null
     */
    private function journalEntry(): ?array
    {
        // By its event type, not through search(): that is one page of 50,
        // newest first, and a pass journals about as many analysed messages
        // in the same second — which of them make the page is up to the
        // engine.
        $stmt = $this->pdo->prepare('SELECT * FROM event_log WHERE event_type = ?');
        $stmt->execute(['inbound_first_read_limited']);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $entry) {
            $this->assertStringNotContainsString('Message ', (string) $entry['description']);
            $context = json_decode((string) $entry['context'], true);
            $this->assertIsArray($context);
            // MySQL's JSON type stores keys in its own order, MariaDB
            // and SQLite keep the written one: compare by key.
            ksort($context);

            return $context;
        }

        return null;
    }
}
