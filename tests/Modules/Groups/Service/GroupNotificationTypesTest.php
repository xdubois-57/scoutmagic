<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Module\ModuleManifest;
use Core\Notification\NotificationPreferenceRepository;
use Core\Notification\NotificationRepository;
use Core\Notification\NotificationService;
use Core\Notification\NotificationType;
use Core\Notification\PushSubscriptionRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Groups\Service\GroupNotificationService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The module's declarations as CORE sees them once the manifest has been
 * registered — i.e. exactly what Core\Http\Controller\
 * NotificationPreferenceController reads to build the preferences page,
 * and exactly what dispatch() validates a type id against.
 *
 * Asserting on the manifest alone would not prove either: a type can be
 * spelled correctly in module.json and still never reach the registry.
 *
 * @group database
 */
#[Group('database')]
class GroupNotificationTypesTest extends TestCase
{
    private \PDO $pdo;
    private NotificationService $service;

    protected function setUp(): void
    {
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

        // Registered through the real manifest parser, the way
        // Core\Module\ModuleManager::loadModule() does it.
        $manifest = ModuleManifest::fromFile(dirname(__DIR__, 4) . '/modules/groups/module.json');
        $this->service->registerModuleTypes('groups', $manifest->notifications);
    }

    /**
     * They all appear on the preferences page — grouped under "Groupes",
     * with the French labels a member actually reads.
     */
    public function testEveryTypeReachesTheRegistryUnderTheGroupesHeading(): void
    {
        $types = $this->groupsTypes();

        $this->assertCount(5, $types);
        foreach ($types as $type) {
            $this->assertSame('Groupes', $type->group);
        }

        $this->assertSame(
            [
                'Nouveau message dans un groupe',
                'Réponse à votre message',
                'Réaction à votre message',
                'Vous êtes cité dans un groupe',
                'Contenu signalé dans un groupe',
            ],
            array_map(static fn(NotificationType $t): string => $t->label, array_values($types))
        );
    }

    /**
     * Every id this module dispatches under is declared. dispatch()
     * throws on an undeclared type, so a typo in a constant would
     * otherwise only surface the first time somebody posted.
     */
    public function testEveryTypeThisModuleDispatchesUnderIsDeclared(): void
    {
        foreach ([
            GroupNotificationService::TYPE_POST_PUBLISHED,
            GroupNotificationService::TYPE_REPLY_RECEIVED,
            GroupNotificationService::TYPE_REACTION_RECEIVED,
            GroupNotificationService::TYPE_MENTIONED,
            GroupNotificationService::TYPE_ITEM_REPORTED,
        ] as $typeId) {
            $this->assertNotNull($this->service->findType($typeId), "{$typeId} must be declared in module.json");
        }
    }

    /**
     * The defaults a member sees before touching anything, resolved
     * through the same channelEnabled() the send pipeline uses.
     */
    public function testTheDefaultsAMemberStartsWithMatchTheDeclaration(): void
    {
        $post = $this->service->findType(GroupNotificationService::TYPE_POST_PUBLISHED);
        \assert($post !== null);
        $this->assertTrue($this->service->channelEnabled(1, $post, 'in_app'));
        $this->assertTrue($this->service->channelEnabled(1, $post, 'push'));
        $this->assertFalse($this->service->channelEnabled(1, $post, 'email'));

        // A reaction is in the centre but does not interrupt anyone.
        $reaction = $this->service->findType(GroupNotificationService::TYPE_REACTION_RECEIVED);
        \assert($reaction !== null);
        $this->assertTrue($this->service->channelEnabled(1, $reaction, 'in_app'));
        $this->assertFalse($this->service->channelEnabled(1, $reaction, 'push'));
    }

    /**
     * The locked channel, proved the way it actually matters: a stored
     * "off" preference for the report notification's in-app channel is
     * ignored, because channelEnabled() short-circuits on a locked
     * channel before it ever reads a preference row.
     */
    public function testAModeratorCannotTurnOffTheReportNotificationEvenWithAStoredPreference(): void
    {
        $type = $this->service->findType(GroupNotificationService::TYPE_ITEM_REPORTED);
        \assert($type !== null);

        (new NotificationPreferenceRepository($this->pdo))
            ->setChannel(1, GroupNotificationService::TYPE_ITEM_REPORTED, 'in_app', false);

        $this->assertTrue($type->isChannelLocked('in_app'));
        $this->assertTrue($this->service->channelEnabled(1, $type, 'in_app'));
    }

    /**
     * Push, by contrast, is a normal default_on channel on that same
     * type — a moderator who does not want to be buzzed at midnight can
     * still say so, they just cannot make the signal disappear entirely.
     */
    public function testAModeratorMayStillSilenceTheReportPushSpecifically(): void
    {
        $type = $this->service->findType(GroupNotificationService::TYPE_ITEM_REPORTED);
        \assert($type !== null);

        (new NotificationPreferenceRepository($this->pdo))
            ->setChannel(1, GroupNotificationService::TYPE_ITEM_REPORTED, 'push', false);

        $this->assertFalse($this->service->channelEnabled(1, $type, 'push'));
        $this->assertTrue($this->service->channelEnabled(1, $type, 'in_app'));
    }

    public function testNoGroupsTypeEverSendsEmailByDefault(): void
    {
        foreach ($this->groupsTypes() as $type) {
            $this->assertFalse(
                $this->service->channelEnabled(1, $type, 'email'),
                "{$type->id} must not send email by default"
            );
        }
    }

    /**
     * @return array<string, NotificationType>
     */
    private function groupsTypes(): array
    {
        $types = [];
        foreach ($this->service->getAllDeclaredTypes() as $type) {
            if (str_starts_with($type->id, 'groups.')) {
                $types[$type->id] = $type;
            }
        }

        return $types;
    }
}
