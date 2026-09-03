<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Mail;

use Core\Badge\BadgeRepository;
use Core\Badge\BadgeService;
use Core\Badge\MemberBadgeRepository;
use Core\Notification\NotificationService;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Finance\Mail\FinanceMailNotifier;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * The treasurers — badge holders this year with an account here — are
 * told of a receipt waiting for them, and told nothing personal.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class FinanceMailNotifierTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private UserAccountRepository $accounts;
    private int $badgeId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->accounts = new UserAccountRepository($this->pdo, $this->encryption);

        $this->pdo->exec("INSERT INTO scout_years (id, label, start_date, end_date, is_current) VALUES (1, '2026-2027', '2026-09-01', '2027-08-31', 1), (2, '2025-2026', '2025-09-01', '2026-08-31', 0)");
        $stmt = $this->pdo->prepare('INSERT INTO badges (name, is_default, is_active) VALUES (?, 1, 1)');
        $stmt->execute([BadgeService::BADGE_TREASURER]);
        $this->badgeId = (int) $this->pdo->lastInsertId();
    }

    /** A member holding the treasurer badge in $scoutYearId, with or without an account. */
    private function treasurer(string $email, bool $withAccount = true, int $scoutYearId = 1): ?int
    {
        static $memberId = 0;
        $memberId++;

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_blind_index, is_active)
             VALUES (?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([$memberId, $scoutYearId, 'x', 'y', $this->encryption->blindIndex(strtolower($email), 'email')]);
        $memberYearId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO member_badges (member_year_id, badge_id) VALUES (?, ?)');
        $stmt->execute([$memberYearId, $this->badgeId]);

        return $withAccount ? $this->accounts->create($email)->id : null;
    }

    private function notifier(NotificationService $notifications): FinanceMailNotifier
    {
        return new FinanceMailNotifier(
            $notifications,
            $this->pdo,
            new BadgeRepository($this->pdo),
            new MemberBadgeRepository($this->pdo),
            $this->accounts,
            1
        );
    }

    public function testThisYearsTreasurersWithAnAccountAreTold(): void
    {
        $told = $this->treasurer('tresorier@unite.be');
        $this->treasurer('sans-compte@unite.be', withAccount: false);
        $this->treasurer('ancien@unite.be', scoutYearId: 2);

        $notifications = $this->createMock(NotificationService::class);
        $notifications->expects($this->once())->method('dispatch')->with(
            FinanceMailNotifier::TYPE_PROPOSITION,
            $this->callback(static fn(array $recipients): bool => array_column($recipients, 'userAccountId') === [$told]),
            $this->callback(static fn(array $payload): bool
                => str_contains($payload['body'], 'compte « Compte courant »') && $payload['url'] === '/finance/receipts')
        );

        $this->notifier($notifications)->proposed(['Compte courant']);
    }

    public function testSeveralAccountsAreAllNamed(): void
    {
        $this->treasurer('tresorier@unite.be');
        $notifications = $this->createMock(NotificationService::class);
        $notifications->expects($this->once())->method('dispatch')->with(
            $this->anything(),
            $this->anything(),
            $this->callback(static fn(array $payload): bool => str_contains($payload['body'], '« Louveteaux », « Compte inconnu »'))
        );

        $this->notifier($notifications)->proposed(['Louveteaux', 'Compte inconnu']);
    }

    public function testNoTreasurerOnTheSiteMeansNobodyIsTold(): void
    {
        $this->treasurer('sans-compte@unite.be', withAccount: false);
        $notifications = $this->createMock(NotificationService::class);
        $notifications->expects($this->never())->method('dispatch');

        $this->notifier($notifications)->proposed(['Compte courant']);
    }
}
