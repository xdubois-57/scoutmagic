<?php

declare(strict_types=1);

namespace Tests\Core\Mail\Feedback\Bounce;

use Core\Mail\Feedback\Bounce\BounceCategory;
use Core\Mail\Feedback\Bounce\BounceSeverity;
use Core\Mail\Feedback\Bounce\BounceState;
use Core\Mail\Feedback\Bounce\MemberBounceNotifier;
use Core\Member\MemberEmail;
use Core\Member\MemberEmailRepository;
use Core\Notification\NotificationRegistry;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Going from « cette boîte a refusé un message » to « ces comptes-là
 * doivent l'apprendre » (roadmap IT-05).
 *
 * @group database
 */
#[Group('database')]
class MemberBounceNotifierTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private MemberEmailRepository $memberEmails;
    private UserAccountRepository $accounts;
    /** @var list<array{type: string, accountIds: list<int>, body: string, title: string}> */
    private array $sent = [];
    private MemberBounceNotifier $notifier;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->memberEmails = new MemberEmailRepository($this->pdo, $this->encryption);
        $this->accounts = new UserAccountRepository($this->pdo, $this->encryption);
        $this->sent = [];

        $this->notifier = new MemberBounceNotifier(
            $this->capturingNotifications(),
            $this->memberEmails,
            $this->accounts,
            $this->encryption
        );
    }

    /**
     * A double for the dispatcher, and only for it: the recipient
     * resolution under test runs against real repositories on a real
     * database.
     *
     * The double does open one gap — the real `dispatch()` throws on an
     * undeclared type id, and a mock never would — so
     * {@see testBothTypesAreDeclaredWhereDispatchWillLookForThem} closes
     * it against the actual registry.
     */
    private function capturingNotifications(): \Core\Notification\NotificationService
    {
        $notifications = $this->createMock(\Core\Notification\NotificationService::class);
        $notifications->method('dispatch')->willReturnCallback(
            function (string $typeId, array $recipients, array $payload): void {
                $this->sent[] = [
                    'type' => $typeId,
                    'accountIds' => array_values(array_map(
                        static fn(array $r): int => $r['userAccountId'],
                        $recipients
                    )),
                    'title' => (string) $payload['title'],
                    'body' => (string) $payload['body'],
                ];
            }
        );

        return $notifications;
    }

    private function state(bool $blocked = false): BounceState
    {
        $now = new \DateTimeImmutable('2026-09-19 10:00:00');

        return new BounceState(
            1,
            'parent@exemple.be',
            BounceCategory::MailboxFull,
            BounceSeverity::Transient,
            '4.2.2',
            0,
            $now,
            $now,
            $blocked ? $now : null
        );
    }

    private function createAccount(string $email): int
    {
        return $this->accounts->create($email)->id;
    }

    private function createMemberWithAddress(string $email, string $status = MemberEmail::STATUS_VALID): int
    {
        $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)')
            ->execute(['DESK' . bin2hex(random_bytes(3))]);
        $memberId = (int) $this->pdo->lastInsertId();
        $this->memberEmails->create($memberId, $email, MemberEmail::SOURCE_MANUAL, $status, null, null);

        return $memberId;
    }

    /** The common case: the account's sign-in identity IS the address that failed. */
    public function testTheAccountBehindTheAddressIsTold(): void
    {
        $accountId = $this->createAccount('parent@exemple.be');

        $this->notifier->notify($this->state(), false);

        $this->assertCount(1, $this->sent);
        $this->assertSame([$accountId], $this->sent[0]['accountIds']);
        $this->assertSame('core.mail_bounce_temporary', $this->sent[0]['type']);
    }

    /**
     * A parent with two addresses, one of which starts failing: they are
     * reached through the other one's account.
     */
    public function testASiblingAddressOfTheSameMemberCarriesTheNews(): void
    {
        $memberId = $this->createMemberWithAddress('parent@exemple.be');
        $this->memberEmails->create(
            $memberId, 'autre@exemple.be', MemberEmail::SOURCE_MANUAL, MemberEmail::STATUS_VALID, null, null
        );
        $accountId = $this->createAccount('autre@exemple.be');

        $this->notifier->notify($this->state(), false);

        $this->assertSame([$accountId], $this->sent[0]['accountIds']);
    }

    public function testTheBlockingBounceIsItsOwnType(): void
    {
        $this->createAccount('parent@exemple.be');

        $this->notifier->notify($this->state(blocked: true), true);

        $this->assertSame('core.mail_bounce_blocked', $this->sent[0]['type']);
        $this->assertStringContainsString('cessé d’écrire', $this->sent[0]['body']);
    }

    /**
     * **Never the address, and never the server's sentence.** A
     * notification body is stored, pushed, and read on a lock screen —
     * SECURITY.md §11 applies to it exactly as it does to a log line.
     */
    public function testNeitherTheAddressNorTheDiagnosticTravelsInTheNotification(): void
    {
        $this->createAccount('parent@exemple.be');

        $this->notifier->notify($this->state(), false);

        $whole = $this->sent[0]['title'] . ' ' . $this->sent[0]['body'];
        $this->assertStringNotContainsString('parent@exemple.be', $whole);
        $this->assertStringNotContainsString('4.2.2', $whole);
    }

    /** What the member can actually do about it is the point of the message. */
    public function testTheMessageCarriesTheGestureThatGoesWithTheCategory(): void
    {
        $this->createAccount('parent@exemple.be');

        $this->notifier->notify($this->state(), false);

        $this->assertStringContainsString(BounceCategory::MailboxFull->guidance(), $this->sent[0]['body']);
    }

    /**
     * An address nobody on this site can be reached through — an external
     * mailing-list entry, say. Nothing is dispatched rather than
     * something addressed to nobody.
     */
    public function testAnAddressWithNoAccountBehindItNotifiesNobody(): void
    {
        $this->notifier->notify($this->state(), false);

        $this->assertSame([], $this->sent);
    }

    /**
     * **The gap the dispatcher double opens, closed here.**
     * `NotificationService::dispatch()` throws on a type nobody declared,
     * and a mock will happily accept anything — so the two ids this class
     * sends are checked against the real registry rather than against the
     * double's good manners.
     */
    public function testBothTypesAreDeclaredWhereDispatchWillLookForThem(): void
    {
        $declared = array_map(
            static fn(\Core\Notification\NotificationType $type): string => $type->id,
            NotificationRegistry::getCoreTypes()
        );

        $this->assertContains('core.mail_bounce_temporary', $declared);
        $this->assertContains('core.mail_bounce_blocked', $declared);
    }

    /**
     * A member who switched an address off is not told it bounced: they
     * asked to stop hearing through it, and `findMemberIdsByValid…` is
     * the same guard the rest of the site uses.
     */
    public function testAnInactiveAddressDoesNotReachItsFormerOwner(): void
    {
        $this->createMemberWithAddress('parent@exemple.be', MemberEmail::STATUS_INACTIVE);

        $this->notifier->notify($this->state(), false);

        $this->assertSame([], $this->sent);
    }
}
