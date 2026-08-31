<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Service;

use Core\File\FileRepository;
use Core\Security\EncryptionService;
use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Api\MessageCandidate;
use Modules\InboundMail\Mailbox\ProviderType;
use Modules\InboundMail\Repository\InboundMailboxRepository;
use Modules\InboundMail\Repository\InboundMessageRepository;
use Modules\InboundMail\Service\GeneralMailboxService;
use Modules\InboundMail\Service\InboundMailAttentionProvider;
use Modules\InboundMail\Service\MessageConsumerRegistry;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\InboundMail\FakeMessageConsumer;
use Tests\Modules\InboundMail\InboundMailTestHelper;

/**
 * The Chef d'Unité's view of everything the unit received.
 *
 * What this file pins is the half that is not visual: the cursor really
 * pages (including through messages sharing a second, which a cursor on
 * the timestamp alone would silently skip), the filters mean what the
 * screen says they mean, and confirming a proposition records a HUMAN
 * decision rather than keeping the machine's guess.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class GeneralMailboxTest extends TestCase
{
    private \PDO $pdo;
    private InboundMessageRepository $messages;
    private GeneralMailboxService $mailbox;
    private int $mailboxId;
    private int $otherMailboxId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        InboundMailTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->messages = new InboundMessageRepository($this->pdo, $encryption);
        $mailboxes = new InboundMailboxRepository($this->pdo, $encryption);

        $registry = new MessageConsumerRegistry();
        $registry->register(new FakeMessageConsumer('rental'));
        $this->mailbox = new GeneralMailboxService($this->messages, $mailboxes, $registry);

        $this->mailboxId = $mailboxes->create('Unité', ProviderType::FAKE, 'h', 993, 'ssl', 'a@b.be', 's', [], true);
        $this->otherMailboxId = $mailboxes->create('Camps', ProviderType::FAKE, 'h', 993, 'ssl', 'c@b.be', 's', [], true);
    }

    // ── Cursor pagination (A13) ─────────────────────────────────────────

    public function testThePageIsNewestFirstAndBounded(): void
    {
        for ($i = 1; $i <= GeneralMailboxService::PAGE_SIZE + 5; $i++) {
            $this->store('m' . $i . '@x', $this->minutesApart($i));
        }

        $page = $this->mailbox->page(['association' => 'all'], null);

        $this->assertCount(GeneralMailboxService::PAGE_SIZE, $page['messages']);
        $this->assertNotNull($page['next_cursor']);
        $this->assertGreaterThan(
            $page['messages'][1]->sentAt,
            $page['messages'][0]->sentAt,
            'newest first'
        );
    }

    public function testTheSecondPageContinuesWhereTheFirstStoppedWithNoOverlapAndNoGap(): void
    {
        $ids = [];
        for ($i = 1; $i <= GeneralMailboxService::PAGE_SIZE + 5; $i++) {
            $ids[] = $this->store('m' . $i . '@x', $this->minutesApart($i));
        }

        $first = $this->mailbox->page(['association' => 'all'], null);
        $second = $this->mailbox->page(
            ['association' => 'all'],
            GeneralMailboxService::decodeCursor((string) $first['next_cursor'])
        );

        $seen = array_map(
            static fn(InboundMessage $m) => $m->id,
            array_merge($first['messages'], $second['messages'])
        );

        $this->assertSame(count($seen), count(array_unique($seen)), 'no message is shown twice');
        $this->assertSame(count($ids), count($seen), 'and none is skipped');
        $this->assertNull($second['next_cursor'], 'the last page says it is the last');
    }

    public function testMessagesSharingASecondAreNotSkipped(): void
    {
        // A mailing list delivering a batch stamps several messages with
        // the same second. A cursor on `sent_at` alone would show the first
        // of that second and lose the rest, permanently and invisibly —
        // which is why the cursor is the pair (sent_at, id).
        for ($i = 1; $i <= 5; $i++) {
            $this->store('batch' . $i . '@x', '2027-07-12 09:30:00');
        }

        $page = $this->mailbox->page(['association' => 'all'], null);
        $this->assertCount(5, $page['messages']);

        // Page size of one, walked by hand, is the shape that breaks.
        $seen = [];
        $cursor = null;
        for ($step = 0; $step < 6; $step++) {
            $rows = $this->messages->findPage(['association' => 'all'], $cursor, 1);
            if ($rows === []) {
                break;
            }
            $seen[] = $rows[0]->id;
            $cursor = GeneralMailboxService::decodeCursor(GeneralMailboxService::encodeCursor($rows[0]));
        }

        $this->assertCount(5, $seen);
        $this->assertSame($seen, array_unique($seen));
    }

    public function testAnUnreadableCursorIsIgnoredRatherThanFatal(): void
    {
        $this->assertNull(GeneralMailboxService::decodeCursor('bidon'));
        $this->assertNull(GeneralMailboxService::decodeCursor(''));
        $this->assertNull(GeneralMailboxService::decodeCursor('2027-07-12 09:30:00'));
    }

    // ── The filters mean what the screen says ───────────────────────────

    public function testSansAssociationMeansNoLinkAndNoStandingProposition(): void
    {
        $bare = $this->store('bare@x');
        $linked = $this->store('linked@x');
        $proposed = $this->store('proposed@x');

        $this->messages->addLink($linked, 'rental', 'LOC-1', LinkOrigin::REFERENCE);
        $this->addCandidate($proposed);

        $page = $this->mailbox->page(['association' => 'none'], null);

        $this->assertSame([$bare], array_map(static fn(InboundMessage $m) => $m->id, $page['messages']));
    }

    public function testAvecAssociationShowsOnlyWhatIsReallyAttached(): void
    {
        $bare = $this->store('bare@x');
        $linked = $this->store('linked@x');
        $this->messages->addLink($linked, 'rental', 'LOC-1', LinkOrigin::REFERENCE);

        $page = $this->mailbox->page(['association' => 'some'], null);

        $this->assertSame([$linked], array_map(static fn(InboundMessage $m) => $m->id, $page['messages']));
    }

    public function testAutomaticMailIsHiddenUntilItIsAskedFor(): void
    {
        // Hidden, never dropped: a bounce is how a unit finds out its own
        // message never arrived.
        $ordinary = $this->store('ordinary@x');
        $bulk = $this->store('news@x', '2027-07-12 09:30:00', true);

        $without = $this->mailbox->page(['association' => 'all'], null);
        $with = $this->mailbox->page(['association' => 'all', 'include_bulk' => true], null);

        $this->assertSame([$ordinary], array_map(static fn(InboundMessage $m) => $m->id, $without['messages']));
        $this->assertCount(2, $with['messages']);
        $this->assertSame(1, $this->mailbox->bulkCount());
        $this->assertContains($bulk, array_map(static fn(InboundMessage $m) => $m->id, $with['messages']));
    }

    public function testTheMailboxFilterNarrowsToOneBox(): void
    {
        $here = $this->store('here@x');
        $this->store('there@x', '2027-07-12 09:30:00', false, $this->otherMailboxId);

        $page = $this->mailbox->page(['association' => 'all', 'mailbox_id' => $this->mailboxId], null);

        $this->assertSame([$here], array_map(static fn(InboundMessage $m) => $m->id, $page['messages']));
    }

    // ── Confirming and dismissing a proposition ─────────────────────────

    public function testConfirmingAPropositionRecordsAHumanDecisionNotTheMachinesGuess(): void
    {
        // D20: the origin answers « comment ce rattachement a-t-il été
        // fait ». Once somebody has looked and said yes, keeping the
        // heuristic would present a human decision as a guess and make
        // every later reader trust it less than they should.
        $id = $this->store('m@x');
        $this->addCandidate($id);
        $candidate = $this->mailbox->candidatesFor($id)[0];

        $this->assertTrue($this->mailbox->confirmCandidate($id, $candidate->id, 7));

        $links = $this->messages->findLinksForMessage($id);
        $this->assertCount(1, $links);
        $this->assertSame(LinkOrigin::MANUAL, $links[0]->origin);
        $this->assertSame('rental', $links[0]->consumerId);
        $this->assertSame('LOC-1', $links[0]->businessReference);
    }

    public function testAConfirmedPropositionStopsBeingAsked(): void
    {
        $id = $this->store('m@x');
        $this->addCandidate($id);

        $this->mailbox->confirmCandidate($id, $this->mailbox->candidatesFor($id)[0]->id, null);

        $this->assertSame([], $this->mailbox->candidatesFor($id));
    }

    public function testDismissingAPropositionIsFinal(): void
    {
        $id = $this->store('m@x');
        $this->addCandidate($id);

        $this->assertTrue($this->mailbox->dismissCandidate($id, $this->mailbox->candidatesFor($id)[0]->id));
        $this->assertSame([], $this->mailbox->candidatesFor($id));
        $this->assertSame([], $this->messages->findLinksForMessage($id), 'dismissing associates nothing');
    }

    public function testAPropositionThatIsNoLongerThereIsRefusedRatherThanGuessedAt(): void
    {
        $id = $this->store('m@x');

        $this->assertFalse($this->mailbox->confirmCandidate($id, 4242, null));
        $this->assertFalse($this->mailbox->dismissCandidate($id, 4242));
    }

    public function testAPropositionOfAnotherMessageIsNeverConfirmedFromThisOne(): void
    {
        $mine = $this->store('mine@x');
        $other = $this->store('other@x');
        $this->addCandidate($other);
        $candidate = $this->mailbox->candidatesFor($other)[0];

        $this->assertFalse($this->mailbox->confirmCandidate($mine, $candidate->id, null));
        $this->assertSame([], $this->messages->findLinksForMessage($mine));
    }

    // ── The attention point ─────────────────────────────────────────────

    public function testTheAttentionPointCountsWhatNobodyHasOrientedYet(): void
    {
        $this->store('bare@x');
        $linked = $this->store('linked@x');
        $this->messages->addLink($linked, 'rental', 'LOC-1', LinkOrigin::REFERENCE);

        $points = (new InboundMailAttentionProvider($this->messages))->collect(1);

        $this->assertCount(1, $points);
        $this->assertStringContainsString('1 message', $points[0]->title);
        $this->assertSame('/courrier', $points[0]->actionUrl);
    }

    public function testTheAttentionPointIgnoresAutomaticMail(): void
    {
        // A chief told about one unattributed newsletter a week stops
        // reading the page, and then stops seeing the fifty that matter.
        $this->store('news@x', '2027-07-12 09:30:00', true);

        $this->assertSame([], (new InboundMailAttentionProvider($this->messages))->collect(1));
    }

    public function testTheAttentionPointSaysNothingWhenThereIsNothingToSay(): void
    {
        $linked = $this->store('linked@x');
        $this->messages->addLink($linked, 'rental', 'LOC-1', LinkOrigin::REFERENCE);

        $this->assertSame([], (new InboundMailAttentionProvider($this->messages))->collect(1));
    }

    public function testTheAttentionPointNamesNobodyAndQuotesNothing(): void
    {
        // §7.9: a summary is not an exception. The page is screenshotted
        // and exported.
        $this->store('bare@x');

        $point = (new InboundMailAttentionProvider($this->messages))->collect(1)[0];
        $text = $point->title . ' ' . $point->why;

        $this->assertStringNotContainsString('jeanne', strtolower($text));
        $this->assertStringNotContainsString('example.be', strtolower($text));
        $this->assertStringNotContainsString('sujet', strtolower($text));
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function store(
        string $messageId,
        string $sentAt = '2027-07-12 09:30:00',
        bool $isBulk = false,
        ?int $mailboxId = null
    ): int {
        static $uid = 100;

        return $this->messages->create(
            mailboxId: $mailboxId ?? $this->mailboxId,
            folder: 'INBOX',
            uidValidity: 1,
            imapUid: ++$uid,
            messageId: $messageId,
            inReplyTo: null,
            subject: 'Sujet',
            fromEmail: 'jeanne@example.be',
            fromName: 'Jeanne Martin',
            bodyText: 'Bonjour',
            bodyHtml: '',
            sentAt: new \DateTimeImmutable($sentAt),
            isBulk: $isBulk
        );
    }

    /** A distinct instant per message, without running off the end of a month. */
    private function minutesApart(int $step): string
    {
        return (new \DateTimeImmutable('2027-07-12 09:00:00'))
            ->modify('+' . $step . ' minutes')
            ->format('Y-m-d H:i:s');
    }

    private function addCandidate(int $messageId): void
    {
        $this->messages->addCandidate($messageId, 'rental', new MessageCandidate(
            businessReference: 'LOC-1',
            label: 'La Grange — 12 au 15 septembre',
            evidenceType: 'sender_window',
            explanation: "L'expéditeur est le locataire, et le message est arrivé pendant son séjour."
        ));
    }
}
