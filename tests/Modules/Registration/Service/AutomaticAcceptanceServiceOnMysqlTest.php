<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\TaskContext;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Registration\Repository\RegistrationRequest;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\SlotCapacityRepository;
use Modules\Registration\Service\AutomaticAcceptanceService;
use Modules\Registration\Task\AutoAcceptRequestsHandler;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Registration\RegistrationTestHelper;
use Tests\UsesProductionEngine;

/**
 * The automatic acceptance of the « Oui » requests (issue #843, IT-10) on
 * the engine production runs, on the schema the real migration builds:
 * the lock per slot is a named GET_LOCK, which SQLite has not got, and the
 * status re-read under `FOR UPDATE`, which SQLite ignores. Two sessions
 * stand for two scheduler passes, or for a pass and a chief.
 *
 * What it pins is the promise: **two passes close together, or
 * overlapping, accept a request once**, and a decision taken by hand is
 * never overridden.
 */
#[Group('database')]
class AutomaticAcceptanceServiceOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private const LOU1_BIRTH = '2019-05-04';
    private const LOU2_BIRTH = '2018-03-02';

    private \PDO $pdo;
    private \PDO $otherSession;
    private EncryptionService $encryption;
    private RegistrationRequestRepository $requests;
    private int $targetYearId;
    private int $louveteauxId;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->otherSession = self::productionEngineConnection(self::$productionEngineDatabase)->getPdo();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->requests = new RegistrationRequestRepository($this->pdo, $this->encryption);

        $settings = $this->settings($this->pdo);
        $settings->register(
            ScoutYearResolver::SETTING_PUBLIC_YEAR, '0', 'number', 'Public', 'desc', null, '^[0-9]+$', null, false
        );
        $publicYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $this->targetYearId = RegistrationTestHelper::insertScoutYear(
            $this->pdo,
            '2027-2028',
            '2027-09-01',
            '2028-08-31'
        );
        $settings->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $publicYearId);

        $this->louveteauxId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'LOUV', 'Louveteaux', 20);
        $capacities = new SlotCapacityRepository($this->pdo);
        $capacities->upsert($this->louveteauxId, 1, 15);
        $capacities->upsert($this->louveteauxId, 2, 15);

        // Written straight into the table: on this schema `modified_by`
        // references a real account, which nothing here needs.
        $content = $this->pdo->prepare(
            "INSERT INTO editable_contents (content_key, content_type, content_value) VALUES (?, 'rich_text', ?)"
        );
        $content->execute(['registration_email_accepted_body', '<p>Bienvenue</p>']);
        $content->execute(['registration_email_refused_body', '<p>Désolés.</p>']);
        RegistrationTestHelper::enableAutomaticDecisionEmails($settings, 24);
        RegistrationTestHelper::enableAutomaticAcceptance($settings, '2026-09-01');
    }

    protected function tearDown(): void
    {
        foreach ([1, 2] as $yearInBranch) {
            $this->requests->releaseAutoAcceptLock($this->targetYearId, $this->louveteauxId, $yearInBranch);
            (new RegistrationRequestRepository($this->otherSession, $this->encryption))
                ->releaseAutoAcceptLock($this->targetYearId, $this->louveteauxId, $yearInBranch);
        }
    }

    /**
     * The premise of the lock on this engine: a slot locked by one session
     * is refused to another at once (timeout 0), and free again once
     * released — while another slot is never refused.
     */
    public function testASlotLockedByOneSessionIsRefusedToAnotherOnThisEngine(): void
    {
        $other = new RegistrationRequestRepository($this->otherSession, $this->encryption);

        $this->assertTrue($other->acquireAutoAcceptLock($this->targetYearId, $this->louveteauxId, 1));
        $this->assertFalse($this->requests->acquireAutoAcceptLock($this->targetYearId, $this->louveteauxId, 1));
        $this->assertTrue($this->requests->acquireAutoAcceptLock($this->targetYearId, $this->louveteauxId, 2));

        $other->releaseAutoAcceptLock($this->targetYearId, $this->louveteauxId, 1);
        $this->assertTrue($this->requests->acquireAutoAcceptLock($this->targetYearId, $this->louveteauxId, 1));
    }

    /**
     * The premise of the status re-read: inside the transaction of the
     * decision, the row is locked on this engine — another session cannot
     * lock it too until that transaction ends.
     */
    public function testTheStatusIsReadUnderARowLockOnThisEngine(): void
    {
        $id = $this->file('Alix', self::LOU1_BIRTH, '2026-10-05 10:00:00');
        $lockWithoutWaiting = "SELECT status FROM registration_requests WHERE id = {$id} FOR UPDATE NOWAIT";

        $this->pdo->beginTransaction();
        try {
            $this->assertSame(RegistrationRequest::STATUS_PENDING, $this->requests->lockStatusForDecision($id));
            try {
                $this->otherSession->query($lockWithoutWaiting);
                $this->fail('the row is locked by the first session');
            } catch (\PDOException $e) {
                $this->assertSame('HY000', $e->getCode());
            }
        } finally {
            $this->pdo->rollBack();
        }

        $this->assertNotFalse($this->otherSession->query($lockWithoutWaiting));
    }

    /**
     * A slot another pass is working on is left to it — this pass takes
     * the other slot — and taken by the next pass once free.
     */
    public function testASlotAnotherPassIsWorkingOnIsLeftToIt(): void
    {
        $first = $this->file('Alix', self::LOU1_BIRTH, '2026-10-05 10:00:00');
        $second = $this->file('Basile', self::LOU2_BIRTH, '2026-10-06 10:00:00');
        $other = new RegistrationRequestRepository($this->otherSession, $this->encryption);
        $this->assertTrue($other->acquireAutoAcceptLock($this->targetYearId, $this->louveteauxId, 1));

        $this->assertSame(['accepted' => 1, 'busy' => 1, 'more' => false], $this->service($this->pdo)->run());
        $this->assertSame(RegistrationRequest::STATUS_PENDING, $this->statusOf($first));
        $this->assertSame(RegistrationRequest::STATUS_ACCEPTED, $this->statusOf($second));

        $other->releaseAutoAcceptLock($this->targetYearId, $this->louveteauxId, 1);
        $this->assertSame(['accepted' => 1, 'busy' => 0, 'more' => false], $this->service($this->pdo)->run());
        $this->assertSame(RegistrationRequest::STATUS_ACCEPTED, $this->statusOf($first));
    }

    /**
     * Two passes overlapping: while the first, holding the slot's lock, has
     * just recomputed its queue, a second pass runs from start to finish on
     * another session. It is kept out of the slot, the first accepts the
     * two requests one after the other, and each is accepted once — one
     * status change, one planned e-mail.
     */
    public function testTwoOverlappingPassesAcceptEachRequestOnce(): void
    {
        $ids = [
            $this->file('Alix', self::LOU1_BIRTH, '2026-10-05 10:00:00'),
            $this->file('Basile', self::LOU1_BIRTH, '2026-10-06 10:00:00'),
        ];
        $secondPass = null;
        $hook = function (int $call, int $scoutYearId) use (&$secondPass): void {
            if ($scoutYearId === $this->targetYearId && $call === 2) {
                $secondPass = $this->service($this->otherSession)->run();
            }
        };

        $firstPass = $this->service($this->pdo, $hook)->run();

        $this->assertSame(['accepted' => 0, 'busy' => 1, 'more' => false], $secondPass);
        $this->assertSame(['accepted' => 2, 'busy' => 0, 'more' => false], $firstPass);
        foreach ($ids as $id) {
            $this->assertSame(RegistrationRequest::STATUS_ACCEPTED, $this->statusOf($id));
            $this->assertSame(1, $this->countRows('event_log', "event_type = 'registration_request_auto_accepted' "
                . "AND JSON_EXTRACT(context, '$.request_id') = {$id}"));
            $this->assertSame(1, $this->countRows('registration_decision_emails', "registration_request_id = {$id}"));
        }
    }

    /**
     * Two passes one after the other, on two sessions: the second finds
     * nothing left to accept.
     */
    public function testTwoPassesCloseTogetherOnTwoSessionsAcceptOnce(): void
    {
        $id = $this->file('Alix', self::LOU1_BIRTH, '2026-10-05 10:00:00');

        $this->assertSame(1, $this->service($this->pdo)->run()['accepted']);
        $this->assertSame(0, $this->service($this->otherSession)->run()['accepted']);

        $this->assertSame(RegistrationRequest::STATUS_ACCEPTED, $this->statusOf($id));
        $this->assertSame(1, $this->countRows('registration_decision_emails', "registration_request_id = {$id}"));
    }

    /**
     * A chief refuses the request the pass has just picked, on another
     * session, committed before the pass locks the row: the status read
     * under the row lock is the chief's, and the site does not override it.
     */
    public function testADecisionTakenByHandOnAnotherSessionIsNeverOverridden(): void
    {
        $refused = $this->file('Alix', self::LOU1_BIRTH, '2026-10-05 10:00:00');
        $next = $this->file('Basile', self::LOU1_BIRTH, '2026-10-06 10:00:00');
        $chief = new RegistrationRequestRepository($this->otherSession, $this->encryption);
        $hook = function (int $call, int $scoutYearId) use ($chief, $refused): void {
            if ($scoutYearId === $this->targetYearId && $call === 2) {
                $chief->updateStatus($refused, RegistrationRequest::STATUS_REFUSED, new \DateTimeImmutable(), 'other');
            }
        };

        $this->assertSame(1, $this->service($this->pdo, $hook)->run()['accepted']);

        $this->assertSame(RegistrationRequest::STATUS_REFUSED, $this->statusOf($refused));
        $this->assertSame(RegistrationRequest::STATUS_ACCEPTED, $this->statusOf($next));
        $this->assertSame(0, $this->countRows('registration_decision_emails', "registration_request_id = {$refused}"));
    }

    /**
     * The whole pass as the scheduler runs it — the handler building its
     * own services from the task context — on the production schema.
     */
    public function testThePassRunsThroughTheHandlerOnTheProductionSchema(): void
    {
        $id = $this->file('Alix', self::LOU1_BIRTH, '2026-10-05 10:00:00');
        $context = new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $this->createStub(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            $this->settings($this->pdo),
            new UserAccountRepository($this->pdo, $this->encryption),
            sys_get_temp_dir()
        );

        (new AutoAcceptRequestsHandler())->handle([], $context);

        $this->assertSame(RegistrationRequest::STATUS_ACCEPTED, $this->statusOf($id));
        $this->assertSame(1, $this->countRows(
            'registration_decision_emails',
            "registration_request_id = {$id} AND state = 'scheduled' AND decision = 'accepted'"
        ));
        $this->assertSame(1, $this->countRows(
            'scheduled_actions',
            "module_id = 'registration' AND task_key = 'auto_accept_requests' AND status = 'pending'"
        ));
    }

    /**
     * @param (\Closure(int, int): void)|null $hook
     */
    private function service(\PDO $session, ?\Closure $hook = null): AutomaticAcceptanceService
    {
        return RegistrationTestHelper::automaticAcceptanceService(
            $session,
            $this->encryption,
            $this->settings($session),
            $this->createStub(MailService::class),
            $hook
        );
    }

    private function settings(\PDO $session): SettingService
    {
        $settings = new SettingService(new SettingRepository($session));
        RegistrationTestHelper::registerManifestSettings($settings);

        return $settings;
    }

    private function file(string $firstName, string $birthDate, string $receivedAt): int
    {
        $id = $this->requests->create($this->targetYearId, [
            'parent_name' => 'Parent ' . $firstName, 'child_last_name' => 'Dupont', 'child_first_name' => $firstName,
            'gender' => 'F', 'birth_date' => $birthDate, 'street' => 'Rue', 'number' => '1',
            'postal_code' => '1000', 'city' => 'Ville', 'email' => strtolower($firstName) . '@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, [])['id'];
        $filed = $this->pdo->prepare('UPDATE registration_requests SET received_at = ? WHERE id = ?');
        $filed->execute([$receivedAt, $id]);

        return $id;
    }

    private function statusOf(int $id): ?string
    {
        return $this->requests->findById($id)?->status;
    }

    private function countRows(string $table, string $where): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$table} WHERE {$where}")->fetchColumn();
    }
}
