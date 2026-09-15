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

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->states = new BounceStateRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->told = [];

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

    public function testOnePermanentFailureIsNotYetABlock(): void
    {
        $state = $this->bounces->record($this->report('5.1.1'));

        $this->assertFalse($state->isBlocked());
        $this->assertFalse($this->bounces->isBlocked('parent@exemple.be'));
    }

    public function testTheAddressIsBlockedAtTheSecondPermanentFailure(): void
    {
        $this->bounces->record($this->report('5.1.1'));
        $state = $this->bounces->record($this->report('5.1.1'));

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
            $state = $this->bounces->record($this->report('4.2.2'));
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
            $this->bounces->record($this->report('4.2.2'));
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
        $this->bounces->record($this->report('4.2.2'));
        $this->bounces->record($this->report('4.4.1'));

        $this->assertCount(2, $this->told);
    }

    /**
     * A blocking bounce always tells, whatever was said before: the
     * address has just stopped receiving anything, and there is no
     * version of that which is not news.
     */
    public function testBlockingAlwaysTellsEvenWhenTheErrorIsOld(): void
    {
        $this->bounces->record($this->report('5.1.1'));
        $this->told = [];

        $this->bounces->record($this->report('5.1.1'));

        $this->assertCount(1, $this->told);
        $this->assertTrue($this->told[0]['blocking'], 'the blocking bounce is its own kind of news.');
    }

    public function testAnAddressIsBlockedOnlyOnce(): void
    {
        foreach (range(1, 4) as $ignored) {
            $this->bounces->record($this->report('5.1.1'));
        }

        $blocking = array_filter($this->told, static fn(array $t): bool => $t['blocking']);
        $this->assertCount(1, $blocking, 'passing the threshold again is not a second event.');
    }

    public function testASuccessfulSendForgetsEverything(): void
    {
        $this->bounces->record($this->report('5.1.1'));
        $this->bounces->record($this->report('5.1.1'));
        $this->assertTrue($this->bounces->isBlocked('parent@exemple.be'));

        $this->bounces->recordSuccess('parent@exemple.be');

        $this->assertFalse($this->bounces->isBlocked('parent@exemple.be'));
        $this->assertNull($this->states->find('parent@exemple.be'));
    }

    /**
     * After a member lifts the block it takes the full count again — the
     * gesture has to buy them more than one message.
     */
    public function testUnblockingGivesTheAddressItsFullAllowanceBack(): void
    {
        $this->bounces->record($this->report('5.1.1'));
        $state = $this->bounces->record($this->report('5.1.1'));
        $this->bounces->unblock($state->id, true);

        $this->bounces->record($this->report('5.1.1'));
        $this->assertFalse($this->bounces->isBlocked('parent@exemple.be'), 'one failure must not re-block.');

        $this->bounces->record($this->report('5.1.1'));
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

        $bounces->record($this->report('5.1.1'));
        $bounces->record($this->report('5.1.1'));

        $this->assertTrue($bounces->isBlocked('parent@exemple.be'));
    }

    /** Two addresses fail independently; one blocked is not both blocked. */
    public function testAddressesAreCountedApart(): void
    {
        $this->bounces->record($this->report('5.1.1', 'un@exemple.be'));
        $this->bounces->record($this->report('5.1.1', 'un@exemple.be'));
        $this->bounces->record($this->report('5.1.1', 'deux@exemple.be'));

        $this->assertTrue($this->bounces->isBlocked('un@exemple.be'));
        $this->assertFalse($this->bounces->isBlocked('deux@exemple.be'));
    }
}
