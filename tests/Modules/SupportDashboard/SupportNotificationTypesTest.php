<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Module\ModuleManifest;
use Core\Notification\NotificationPreferenceRepository;
use Core\Notification\NotificationRepository;
use Core\Notification\NotificationService;
use Core\Notification\PushSubscriptionRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\SupportDashboard\Service\TicketIntakeService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The ticket notification is a NORMAL notification: it appears on
 * `/notifications/preferences` with the same three channel switches as
 * every other, and each one can be turned off.
 *
 * Asserting on module.json alone would not prove that — a type can be
 * spelled correctly in a manifest and never reach the registry the
 * preferences page reads. So this registers the real manifest through the
 * real parser, exactly as Core\Module\ModuleManager::loadModule() does.
 *
 * @group database
 */
#[Group('database')]
class SupportNotificationTypesTest extends TestCase
{
    private \PDO $pdo;
    private NotificationService $service;

    protected function setUp(): void
    {
        SupportDashboardTestHelper::ensureAutoloadable();
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->service = new NotificationService(
            new NotificationRepository($this->pdo, $encryption),
            new PushSubscriptionRepository($this->pdo, $encryption),
            new NotificationPreferenceRepository($this->pdo),
            null,
            new SettingService(new SettingRepository($this->pdo)),
            new JournalService(new JournalRepository($this->pdo)),
            new SchedulerService(new SchedulerRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $encryption)
        );

        $manifest = ModuleManifest::fromFile(dirname(__DIR__, 3) . '/modules/support_dashboard/module.json');
        $this->service->registerModuleTypes('support_dashboard', $manifest->notifications);
    }

    public function testTheTicketNotificationReachesTheRegistryThePreferencesPageReads(): void
    {
        $type = $this->service->findType(TicketIntakeService::NOTIFICATION_TICKET_RECEIVED);

        $this->assertNotNull($type);
        $this->assertSame('Nouveau ticket de support', $type->label);
        $this->assertSame('Supervision', $type->group);
        $this->assertSame('superadmin', $type->roleMin);
    }

    /**
     * The three channels, resolved through the same `channelEnabled()`
     * the send pipeline uses — so what the page shows and what the
     * dispatch does cannot disagree.
     */
    public function testItStartsOnInTheCentreAndOnThePhoneAndOffByMail(): void
    {
        $type = $this->service->findType(TicketIntakeService::NOTIFICATION_TICKET_RECEIVED);
        \assert($type !== null);

        $this->assertTrue($this->service->channelEnabled(1, $type, 'in_app'));
        $this->assertTrue($this->service->channelEnabled(1, $type, 'push'));
        $this->assertFalse($this->service->channelEnabled(1, $type, 'email'));
    }

    /**
     * Configurable is the point: a superadmin who does not want to be
     * interrupted turns the channel off and the switch holds. `off` (as
     * opposed to `default_off`) would make the row unswitchable, which is
     * what a locked channel means — this one is not locked.
     */
    public function testASuperadminCanTurnAnyOfThemOffAndItHolds(): void
    {
        $type = $this->service->findType(TicketIntakeService::NOTIFICATION_TICKET_RECEIVED);
        \assert($type !== null);

        $preferences = new NotificationPreferenceRepository($this->pdo);
        foreach (['in_app', 'push'] as $channel) {
            $preferences->setChannel(1, $type->id, $channel, false);
            $this->assertFalse($this->service->channelEnabled(1, $type, $channel), $channel);
        }

        $preferences->setChannel(1, $type->id, 'email', true);
        $this->assertTrue($this->service->channelEnabled(1, $type, 'email'));
    }
}
