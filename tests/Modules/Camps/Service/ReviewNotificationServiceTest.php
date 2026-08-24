<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Service;

use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Member\SectionService;
use Core\Notification\NotificationService;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Service\ReviewNotificationService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Camps\CampsTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ReviewNotificationServiceTest extends TestCase
{
    private const TODAY = '2026-08-24';

    private \PDO $pdo;
    private EncryptionService $encryption;
    private CampRepository $camps;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CampsTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->camps = new CampRepository($this->pdo, $this->encryption);

        $this->pdo->exec("INSERT INTO camp_places (name) VALUES ('Domaine de Mozet')");
        $this->pdo->exec(
            "INSERT INTO scout_years (label, start_date, end_date, is_current)
             VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)"
        );
    }

    // ── Which stays are due ─────────────────────────────────────────

    public function testAStayThatEndedYesterdayIsDue(): void
    {
        $this->camp('2026-08-23');

        $this->assertCount(1, $this->camps->findAwaitingReviewNotification($this->today()));
    }

    public function testAStayThatEndedTodayIsNotDueYet(): void
    {
        $this->camp(self::TODAY);

        $this->assertSame([], $this->camps->findAwaitingReviewNotification($this->today()));
    }

    public function testAStayThatEndedLongAgoIsStillDueIfNeverNotified(): void
    {
        $this->camp('2026-07-19');

        // "The end date is past", not "the end date was yesterday": on an
        // installation whose cron did not run that day, the second form
        // loses the notification for ever. Late is the right failure.
        $this->assertCount(1, $this->camps->findAwaitingReviewNotification($this->today()));
    }

    public function testACancelledStayIsNeverDue(): void
    {
        $this->camp('2026-07-19', Camp::STATUS_CANCELLED);

        $this->assertSame([], $this->camps->findAwaitingReviewNotification($this->today()));
    }

    public function testAYearOnlyStayIsNeverDue(): void
    {
        $this->camps->create(1, Camp::STAY_GRAND_CAMP, null, null, 2024, Camp::STATUS_CONFIRMED, null, null, null, null, []);

        // There is no day after a year.
        $this->assertSame([], $this->camps->findAwaitingReviewNotification($this->today()));
    }

    public function testAnAlreadyNotifiedStayIsNeverDueAgain(): void
    {
        $campId = $this->camp('2026-07-19');
        $this->camps->markReviewNotified($campId, $this->today());

        $this->assertSame([], $this->camps->findAwaitingReviewNotification($this->today()));
    }

    // ── Sending, exactly once ───────────────────────────────────────

    public function testASecondRunSendsNothingMore(): void
    {
        $this->camp('2026-07-19');
        $this->linkAnimatorAccount();
        $notifications = $this->createMock(NotificationService::class);
        // NotificationService::dispatch() deduplicates nothing — it always
        // creates the rows it is asked for. Once is enforced here.
        $notifications->expects($this->once())->method('dispatch');

        $service = $this->service($notifications);
        $service->dispatchDue($this->today());
        $service->dispatchDue($this->today());
        $service->dispatchDue($this->today());
    }

    public function testAStayWithNobodyToTellIsStillMarkedAsHandled(): void
    {
        $campId = $this->camp('2026-07-19');
        $notifications = $this->createMock(NotificationService::class);
        $notifications->expects($this->never())->method('dispatch');

        $this->assertSame(0, $this->service($notifications)->dispatchDue($this->today()));

        // Otherwise the task rebuilds that empty recipient list on every
        // single run, for ever.
        $this->assertSame([], $this->camps->findAwaitingReviewNotification($this->today()));
        $this->assertNotNull(
            $this->pdo->query("SELECT review_notified_at FROM camp_camps WHERE id = {$campId}")->fetchColumn() ?: null
        );
    }

    public function testWithoutANotificationServiceNothingIsSentAndNothingIsMarked(): void
    {
        $this->camp('2026-07-19');

        // A site with notifications switched off must not silently burn
        // the one chance every stay gets.
        $this->assertSame(0, $this->service(null)->dispatchDue($this->today()));
        $this->assertCount(1, $this->camps->findAwaitingReviewNotification($this->today()));
    }

    public function testTheNotificationCarriesTheStaysLabelAndItsOwnLink(): void
    {
        $campId = $this->camp('2026-07-12', Camp::STATUS_CONFIRMED, '2026-07-05');
        $this->linkAnimatorAccount();

        $notifications = $this->createMock(NotificationService::class);
        $notifications->expects($this->once())->method('dispatch')->with(
            ReviewNotificationService::TYPE_ID,
            $this->anything(),
            $this->callback(static function (array $payload) use ($campId): bool {
                return str_contains($payload['body'], '5–12 juillet 2026')
                    && str_ends_with((string) $payload['url'], '/chefs/camps/sejours/' . $campId);
            })
        );

        $this->service($notifications)->dispatchDue($this->today(), 'https://unite.example/');
    }

    // ── Who hears about it ──────────────────────────────────────────

    public function testWithNoSectionSetNobodyOutsideTheUnitStaffIsTold(): void
    {
        $campId = $this->camp('2026-07-19');
        $camp = $this->camps->findById($campId);
        $this->assertNotNull($camp);

        // No STAFFDU section exists in this fixture, so there is nobody —
        // which is the point: a missing section must never fall back to
        // "everyone", or the notification becomes noise nobody reads.
        $this->assertSame([], $this->service(null)->recipientsFor($camp));
    }

    public function testAStayWhoseEndDatePredatesEveryScoutYearTellsNobody(): void
    {
        $campId = $this->camp('2014-07-19');
        $camp = $this->camps->findById($campId);
        $this->assertNotNull($camp);

        // A camp from 2014 on an installation created in 2025 has no
        // scout year row; inventing one would be worse than sending
        // nothing.
        $this->assertSame([], $this->service(null)->recipientsFor($camp));
    }

    private function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::TODAY);
    }

    private function service(?NotificationService $notifications): ReviewNotificationService
    {
        $connection = Connection::withPdo($this->pdo);

        return new ReviewNotificationService(
            $this->camps,
            new SectionService($connection, $this->encryption, new MemberBadgeRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $this->encryption),
            $this->encryption,
            $this->pdo,
            $notifications
        );
    }

    private function camp(string $endDate, string $status = Camp::STATUS_CONFIRMED, ?string $startDate = null): int
    {
        return $this->camps->create(
            1, Camp::STAY_GRAND_CAMP, $startDate ?? $endDate, $endDate, null, $status,
            null, null, null, null, []
        );
    }

    /**
     * A unit staff ("STAFFDU") with one animator who also has an account
     * here — the fallback recipient set for a stay with no section.
     *
     * Built by hand rather than through a fixture helper so every link in
     * the chain the service walks is visible in one place: the function's
     * role gates who counts as staff, the member's e-mail is what maps to
     * an account, and the scout year is resolved from the stay's own end
     * date.
     */
    private function linkAnimatorAccount(): void
    {
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('STAFFDU', 'Staff d''U', 50)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (age_branch_id, desk_code, name) VALUES ({$branchId}, 'STAFFDU', 'Staff d''unité')");
        $sectionId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('CU', 'Chef d''unité', 'admin')");
        $functionId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('M-1')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
                                       email_encrypted, is_active)
             VALUES (?, 1, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $memberId,
            $this->encryption->encrypt('Camille', 'member_years.first_name'),
            $this->encryption->encrypt('Wauters', 'member_years.last_name'),
            $this->encryption->encrypt('anim@example.org', 'member_years.email'),
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec(
            "INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function)
             VALUES ({$memberYearId}, {$functionId}, {$sectionId}, 1)"
        );

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute([
            $this->encryption->encrypt('anim@example.org', 'user_accounts.email'),
            $this->encryption->blindIndex('anim@example.org', 'user_accounts.email'),
        ]);
    }
}
