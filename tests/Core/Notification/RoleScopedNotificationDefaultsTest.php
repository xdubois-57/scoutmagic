<?php

declare(strict_types=1);

namespace Tests\Core\Notification;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Notification\NotificationPreferenceRepository;
use Core\Notification\NotificationRegistry;
use Core\Notification\NotificationRepository;
use Core\Notification\NotificationService;
use Core\Notification\NotificationType;
use Core\Notification\PushSubscriptionRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\EncryptionService;
use Core\Security\Role;
use Core\Security\RoleResolver;
use Core\Security\UserAccountRepository;
use Minishlink\WebPush\WebPush;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 *
 * A type declaring `default_on_role_min` is offered to everybody from its
 * own `role_min` up, but only starts switched on from `default_on_role_min`
 * up — the "on for the superadmin, available and off for the admin" shape
 * the two automatic-update types need (Core\Notification\
 * NotificationRegistry). These cover both halves: the declaration rule
 * itself, and the send pipeline actually honouring it.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RoleScopedNotificationDefaultsTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private NotificationRepository $notificationRepository;
    private NotificationPreferenceRepository $preferenceRepository;
    private SchedulerRepository $schedulerRepository;
    private NotificationService $service;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        [$label, $yearStart, $yearEnd] = DatabaseTestHelper::scoutYear();
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('{$label}', '{$yearStart}', '{$yearEnd}', 1)");

        $this->notificationRepository = new NotificationRepository($this->pdo, $this->encryption);
        $this->preferenceRepository = new NotificationPreferenceRepository($this->pdo);
        $this->schedulerRepository = new SchedulerRepository($this->pdo);

        $this->service = new NotificationService(
            $this->notificationRepository,
            new PushSubscriptionRepository($this->pdo, $this->encryption),
            $this->preferenceRepository,
            $this->createMock(WebPush::class),
            new SettingService(new SettingRepository($this->pdo)),
            new JournalService(new JournalRepository($this->pdo)),
            new SchedulerService($this->schedulerRepository),
            new UserAccountRepository($this->pdo, $this->encryption),
            new RoleResolver(new MemberYearRepository($this->pdo), $this->encryption, $this->pdo),
            new ScoutYearService($this->pdo)
        );
    }

    private function createAccount(string $email, bool $superAdmin = false): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_accounts (email_encrypted, email_blind_index, is_super_admin) VALUES (?, ?, ?)'
        );
        $stmt->execute([
            $this->encryption->encrypt($email, 'user_accounts.email'),
            $this->encryption->blindIndex($email, 'email'),
            $superAdmin ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * An account whose member_year carries a function mapped to $role —
     * how RoleResolver arrives at anything between "identified" and
     * "admin" (superadmin comes from the user_accounts flag instead).
     */
    private function giveRole(string $email, string $role): void
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role, confirmed) VALUES ('F_" . uniqid() . "', 'Fonction', '{$role}', 1)");
        $functionId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, email_blind_index)
             VALUES (?, 1, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId,
            $this->encryption->encrypt('Prénom', 'member_years.first_name'),
            $this->encryption->encrypt('Nom', 'member_years.last_name'),
            $this->encryption->encrypt($email, 'member_years.email'),
            $this->encryption->blindIndex($email, 'email'),
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare('INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, NULL, 1)');
        $stmt->execute([$memberYearId, $functionId]);
    }

    public function testDefaultOnRoleMinLeavesEveryDeclaredDefaultAloneWhenAbsent(): void
    {
        $type = new NotificationType(
            id: 'test.plain',
            label: 'L',
            description: 'D',
            group: 'G',
            roleMin: 'identified',
            channels: ['in_app' => 'default_on', 'push' => 'default_on', 'email' => 'default_off']
        );

        $this->assertTrue($type->defaultsOnForRole(Role::IDENTIFIED));
        $this->assertTrue($type->defaultEnabled('in_app', $type->defaultsOnForRole(Role::IDENTIFIED)));
    }

    public function testDefaultOnRoleMinDowngradesDefaultOnToOffBelowTheGivenRole(): void
    {
        $type = new NotificationType(
            id: 'test.scoped',
            label: 'L',
            description: 'D',
            group: 'G',
            roleMin: 'admin',
            channels: ['in_app' => 'default_on', 'push' => 'default_on', 'email' => 'default_off'],
            defaultOnRoleMin: 'superadmin'
        );

        $this->assertTrue($type->defaultsOnForRole(Role::SUPERADMIN));
        $this->assertTrue($type->defaultEnabled('in_app', true));
        $this->assertTrue($type->defaultEnabled('push', true));

        $this->assertFalse($type->defaultsOnForRole(Role::ADMIN));
        $this->assertFalse($type->defaultEnabled('in_app', false));
        $this->assertFalse($type->defaultEnabled('push', false));
        // Already off — a per-role default can only ever take something
        // away, never hand a lower role a channel the type never offered.
        $this->assertFalse($type->defaultEnabled('email', false));
    }

    /**
     * Locking is a statement that the member has no say at all; a per-role
     * default must not quietly reopen it in either direction.
     */
    public function testALockedChannelIsNeverTouchedByTheRoleScopedDefault(): void
    {
        $type = new NotificationType(
            id: 'test.locked',
            label: 'L',
            description: 'D',
            group: 'G',
            roleMin: 'identified',
            channels: ['in_app' => 'on', 'push' => 'off', 'email' => 'default_off'],
            defaultOnRoleMin: 'superadmin'
        );

        $this->assertTrue($type->defaultEnabled('in_app', false));
        $this->assertFalse($type->defaultEnabled('push', true));
    }

    public function testTheTwoAutomaticUpdateTypesAreDeclaredForAdminsAndOnForSuperadmins(): void
    {
        $types = [];
        foreach (NotificationRegistry::getCoreTypes() as $type) {
            $types[$type->id] = $type;
        }

        foreach (['core.update_installed', 'core.update_failed'] as $id) {
            $this->assertArrayHasKey($id, $types);
            $this->assertSame('admin', $types[$id]->roleMin);
            $this->assertSame('superadmin', $types[$id]->defaultOnRoleMin);
            $this->assertTrue($types[$id]->defaultsOnForRole(Role::SUPERADMIN));
            $this->assertFalse($types[$id]->defaultsOnForRole(Role::ADMIN));
        }
    }

    public function testRecipientsForTypeHoldsSuperadminsAndLeavesOutEverybodyBelowRoleMin(): void
    {
        $superadminId = $this->createAccount('super@test.example', true);
        $chiefEmail = 'chief@test.example';
        $chiefId = $this->createAccount($chiefEmail);
        $this->giveRole($chiefEmail, 'chief');
        $identifiedId = $this->createAccount('member@test.example');

        $recipients = array_column($this->service->recipientsForType('core.update_installed'), 'userAccountId');

        $this->assertSame([$superadminId], $recipients);
        $this->assertNotContains($chiefId, $recipients);
        $this->assertNotContains($identifiedId, $recipients);
    }

    public function testAnAdminIsOfferedTheTypeButOnlyReceivesItAfterSwitchingItOn(): void
    {
        $adminEmail = 'admin@test.example';
        $adminId = $this->createAccount($adminEmail);
        $this->giveRole($adminEmail, 'admin');

        $this->assertSame([], $this->service->recipientsForType('core.update_installed'));

        $this->preferenceRepository->setChannel($adminId, 'core.update_installed', 'in_app', true);

        $this->assertSame(
            [$adminId],
            array_column($this->service->recipientsForType('core.update_installed'), 'userAccountId')
        );
    }

    public function testASuperadminWhoSwitchedTheTypeOffIsLeftOutOfTheBroadcast(): void
    {
        $superadminId = $this->createAccount('super@test.example', true);
        $this->preferenceRepository->setChannel($superadminId, 'core.update_installed', 'in_app', false);

        $this->assertSame([], $this->service->recipientsForType('core.update_installed'));
    }

    public function testRecipientsForTypeIsEmptyForAnUndeclaredType(): void
    {
        $this->createAccount('super@test.example', true);

        $this->assertSame([], $this->service->recipientsForType('core.nothing_declared'));
    }

    /**
     * The whole point of the per-role default: an admin who deliberately
     * switched the in-app channel on must not silently get push with it,
     * because push is "default_on" for a role the type never defaults on
     * for.
     */
    public function testAnAdminWhoSwitchedInAppOnGetsTheRowButNoPush(): void
    {
        $adminEmail = 'admin@test.example';
        $adminId = $this->createAccount($adminEmail);
        $this->giveRole($adminEmail, 'admin');
        $this->preferenceRepository->setChannel($adminId, 'core.update_installed', 'in_app', true);

        $this->service->dispatch(
            'core.update_installed',
            $this->service->recipientsForType('core.update_installed'),
            ['title' => 'Mise à jour terminée', 'body' => 'B', 'url' => '/config/maintenance']
        );

        $this->assertCount(1, $this->notificationRepository->findByUserAccountId($adminId));
        $this->assertSame(0, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM scheduled_actions WHERE task_key = 'send_notifications'"
        )->fetchColumn());
    }

    public function testASuperadminGetsBothTheRowAndAScheduledPush(): void
    {
        $superadminId = $this->createAccount('super@test.example', true);

        $this->service->dispatch(
            'core.update_installed',
            $this->service->recipientsForType('core.update_installed'),
            ['title' => 'Mise à jour terminée', 'body' => 'B', 'url' => '/config/maintenance']
        );

        $this->assertCount(1, $this->notificationRepository->findByUserAccountId($superadminId));
        $this->assertSame(1, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM scheduled_actions WHERE task_key = 'send_notifications'"
        )->fetchColumn());
    }
}
