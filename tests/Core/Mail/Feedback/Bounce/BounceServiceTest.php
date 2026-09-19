<?php

declare(strict_types=1);

namespace Tests\Core\Mail\Feedback\Bounce;

use Core\Mail\Feedback\Bounce\BounceNotifier;
use Core\Mail\Feedback\Bounce\BounceService;
use Core\Mail\Feedback\Bounce\BounceState;
use Core\Mail\Feedback\Bounce\BounceStateRepository;
use Core\Mail\Feedback\Bounce\DeliveryStatusReport;
use Core\Security\EncryptionService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The policy: block or not, tell the member or not (roadmap IT-05).
 *
 * @group database
 */
#[Group('database')]
class BounceServiceTest extends TestCase
{
    private \PDO $pdo;
    private BounceStateRepository $states;
    private BounceService $bounces;
    /** @var list<array{email: string, blocking: bool}> */
    private array $told = [];
    private \DateTimeImmutable $clock;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->states = new BounceStateRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->told = [];
        $this->clock = new \DateTimeImmutable('2026-09-19 08:00:00');

        $this->bounces = new BounceService(
            $this->states,
            null,
            new class ($this->told) implements BounceNotifier {
                /** @param list<array{email: string, blocking: bool}> $told */
                public function __construct(private array &$told)
                {
                }

                public function notify(BounceState $state, bool $blocking): void
                {
                    $this->told[] = ['email' => $state->email, 'blocking' => $blocking];
                }
            }
        );
    }

    private function report(string $status, string $email = 'parent@exemple.be'): DeliveryStatusReport
    {
        $reports = DeliveryStatusReport::parseAll(
            "Final-Recipient: rfc822; {$email}\nAction: failed\nStatus: {$status}\n"
        );
        self::assertCount(1, $reports, 'the fixture must produce exactly one report.');

        return $reports[0];
    }

    /**
     * **One message out, one bounce back** — and the send is part of the
     * fixture rather than an afterthought.
     *
     * A report counts only when a message has gone out since the last
     * report that counted, so a test recording two bounces in a row with
     * nothing sent in between would be modelling a sequence the site
     * cannot produce: a bounce is an answer, and two answers need two
     * questions. Written the other way these tests went on passing while
     * `record()` counted one forged message as two strikes.
     *
     * The clock advances on every call, because the rule compares two
     * `DATETIME`s and a fixture crowded into a single second proves
     * nothing about their order.
     */
    private function bounce(string $status, string $email = 'parent@exemple.be'): ?BounceState
    {
        $this->clock = $this->clock->modify('+1 hour');
        $this->bounces->recordSend($email, $this->clock);

        $this->clock = $this->clock->modify('+1 minute');

        return $this->bounces->record($this->report($status, $email), $this->clock);
    }

    /**
     * The same send-then-bounce step for the two tests that need a
     * service of their own (a notifier that throws): stamps the send and
     * hands back the moment the bounce came in.
     */
    private function tick(string $email = 'parent@exemple.be'): \DateTimeImmutable
    {
        $this->clock = $this->clock->modify('+1 hour');
        $this->bounces->recordSend($email, $this->clock);

        return $this->clock = $this->clock->modify('+1 minute');
    }

    public function testOnePermanentFailureIsNotYetABlock(): void
    {
        $state = $this->bounce('5.1.1');

        $this->assertFalse($state->isBlocked());
        $this->assertFalse($this->bounces->isBlocked('parent@exemple.be'));
    }

    public function testTheAddressIsBlockedAtTheSecondPermanentFailure(): void
    {
        $this->bounce('5.1.1');
        $state = $this->bounce('5.1.1');

        $this->assertTrue($state->isBlocked());
        $this->assertTrue($this->bounces->isBlocked('parent@exemple.be'));
    }

    /**
     * **However often it repeats.** A mailbox that has been full for
     * months bounces at every mailing, and none of those is the far end
     * saying « stop », only « not now ».
     */
    public function testTransientFailuresNeverBlockTheAddress(): void
    {
        foreach (range(1, 10) as $ignored) {
            $state = $this->bounce('4.2.2');
        }

        $this->assertFalse($state->isBlocked());
    }

    /**
     * The rule that makes the transient notification bearable. Without
     * it, a full mailbox produces one notification per mailing — the
     * fastest way to teach somebody to ignore this site.
     */
    public function testARepeatedErrorIsToldOnce(): void
    {
        foreach (range(1, 5) as $ignored) {
            $this->bounce('4.2.2');
        }

        $this->assertCount(1, $this->told);
        $this->assertFalse($this->told[0]['blocking']);
    }

    /**
     * Indexed on the error, so a mailbox emptied and full again is news
     * again — which it is, to the person who has to empty it.
     */
    public function testADifferentErrorIsToldSeparately(): void
    {
        $this->bounce('4.2.2');
        $this->bounce('4.4.1');

        $this->assertCount(2, $this->told);
    }

    /**
     * A blocking bounce always tells, whatever was said before: the
     * address has just stopped receiving anything, and there is no
     * version of that which is not news.
     */
    public function testBlockingAlwaysTellsEvenWhenTheErrorIsOld(): void
    {
        $this->bounce('5.1.1');
        $this->told = [];

        $this->bounce('5.1.1');

        $this->assertCount(1, $this->told);
        $this->assertTrue($this->told[0]['blocking'], 'the blocking bounce is its own kind of news.');
    }

    public function testAnAddressIsBlockedOnlyOnce(): void
    {
        foreach (range(1, 4) as $ignored) {
            $this->bounce('5.1.1');
        }

        $blocking = array_filter($this->told, static fn(array $t): bool => $t['blocking']);
        $this->assertCount(1, $blocking, 'passing the threshold again is not a second event.');
    }

    /**
     * **The scenario that would have disabled every block on this site,
     * silently.** A relay accepting a message is not a delivery, and the
     * bounce for that very send arrives seconds later. A send path that
     * cleared the counter on acceptance would wipe it before every single
     * bounce — the count would never reach two, no address would ever be
     * blocked, and every test of the blocking rule would still pass
     * because none of them sends anything.
     *
     * So the send is recorded, not celebrated: it settles the PREVIOUS
     * send and nothing more.
     */
    public function testASendFollowedByItsOwnBounceStillCounts(): void
    {
        $send = new \DateTimeImmutable('2026-09-15 10:00:00');
        $bounce = $send->modify('+30 seconds');

        $this->bounces->recordSend('parent@exemple.be', $send);
        $this->bounces->record($this->report('5.1.1'), $bounce);

        $this->bounces->recordSend('parent@exemple.be', $send->modify('+7 days'));
        $this->bounces->record($this->report('5.1.1'), $bounce->modify('+7 days'));

        $this->assertTrue(
            $this->bounces->isBlocked('parent@exemple.be'),
            'two failing mailings must block, however many sends happened between them.'
        );
    }

    /**
     * And the other half: a send that produced nothing settles the
     * address. Judged one send later, because that is the first moment
     * anybody can know.
     */
    public function testASendThatBouncedNothingClearsTheAddressAtTheNextSend(): void
    {
        $start = new \DateTimeImmutable('2026-09-15 10:00:00');

        $this->bounces->recordSend('parent@exemple.be', $start);
        $this->bounces->record($this->report('5.1.1'), $start->modify('+1 minute'));
        $this->assertSame(1, $this->states->find('parent@exemple.be')?->failures);

        // The parent fixes their mailbox. This send produces no bounce —
        // which nobody can tell yet.
        $this->bounces->recordSend('parent@exemple.be', $start->modify('+7 days'));
        $this->assertNotNull($this->states->find('parent@exemple.be'), 'not knowable yet.');

        // The next one looks back and finds the previous send clean.
        $this->bounces->recordSend('parent@exemple.be', $start->modify('+14 days'));

        $this->assertNull($this->states->find('parent@exemple.be'));
    }

    /**
     * Two unrelated failures a year apart are not a pattern, and must not
     * add up to a block.
     */
    public function testFailuresSeparatedByWorkingSendsDoNotAccumulate(): void
    {
        $start = new \DateTimeImmutable('2026-01-10 10:00:00');

        $this->bounces->recordSend('parent@exemple.be', $start);
        $this->bounces->record($this->report('5.4.1'), $start->modify('+1 minute'));

        // A year of mailings that all work.
        foreach (range(1, 12) as $month) {
            $this->bounces->recordSend('parent@exemple.be', $start->modify("+{$month} months"));
        }

        $this->bounces->record($this->report('5.4.1'), $start->modify('+13 months'));

        $this->assertFalse(
            $this->bounces->isBlocked('parent@exemple.be'),
            'a failure last January says nothing about one this January.'
        );
    }

    public function testASendToAnAddressThatNeverBouncedWritesNothing(): void
    {
        $this->bounces->recordSend('jamais@exemple.be', new \DateTimeImmutable());

        $statement = $this->pdo->query('SELECT COUNT(*) FROM mail_bounce_states');
        $this->assertNotFalse($statement);
        $this->assertSame(0, (int) $statement->fetchColumn());
    }

    /**
     * After a member lifts the block it takes the full count again — the
     * gesture has to buy them more than one message.
     */
    public function testUnblockingGivesTheAddressItsFullAllowanceBack(): void
    {
        $this->bounce('5.1.1');
        $state = $this->bounce('5.1.1');
        $this->bounces->unblock($state->id, true);

        $this->bounce('5.1.1');
        $this->assertFalse($this->bounces->isBlocked('parent@exemple.be'), 'one failure must not re-block.');

        $this->bounce('5.1.1');
        $this->assertTrue($this->bounces->isBlocked('parent@exemple.be'));
    }

    public function testUnblockingSomethingThatIsGoneIsHarmless(): void
    {
        $this->bounces->unblock(4242, false);

        $this->assertSame(0, $this->states->countBlocked());
    }

    /**
     * A notifier that throws must not undo a bounce that was correctly
     * recorded: the counting is the durable part, the telling is best
     * effort.
     */
    public function testANotifierThatFailsDoesNotLoseTheBounce(): void
    {
        $bounces = new BounceService(
            $this->states,
            null,
            new class implements BounceNotifier {
                public function notify(BounceState $state, bool $blocking): void
                {
                    throw new \RuntimeException('le centre de notifications est indisponible');
                }
            }
        );

        $bounces->record($this->report('5.1.1'), $this->tick());
        $bounces->record($this->report('5.1.1'), $this->tick());

        $this->assertTrue($bounces->isBlocked('parent@exemple.be'));
    }

    /**
     * **And a failed telling is not recorded as told.** Marking the code
     * regardless would file an error as « déjà dit » that nobody was ever
     * told, and every later bounce carrying it would take the early
     * return. For a full mailbox that is the only notification the member
     * would ever have had — the address goes quiet and nothing retries.
     */
    public function testANotifierFailureLeavesTheErrorStillWorthTelling(): void
    {
        $failing = new BounceService(
            $this->states,
            null,
            new class implements BounceNotifier {
                public function notify(BounceState $state, bool $blocking): void
                {
                    throw new \RuntimeException('le centre de notifications est indisponible');
                }
            }
        );

        $failing->record($this->report('4.2.2'), $this->tick());

        $this->assertTrue(
            $this->states->find('parent@exemple.be')?->isNewError('4.2.2'),
            'an error nobody was told about must still be news.'
        );
    }

    /** Two addresses fail independently; one blocked is not both blocked. */
    public function testAddressesAreCountedApart(): void
    {
        $this->bounce('5.1.1', 'un@exemple.be');
        $this->bounce('5.1.1', 'un@exemple.be');
        $this->bounce('5.1.1', 'deux@exemple.be');

        $this->assertTrue($this->bounces->isBlocked('un@exemple.be'));
        $this->assertFalse($this->bounces->isBlocked('deux@exemple.be'));
    }
}
