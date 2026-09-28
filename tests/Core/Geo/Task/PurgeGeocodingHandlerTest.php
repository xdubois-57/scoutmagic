<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Geo\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Geo\AddressLocator;
use Core\Geo\GeocodingCacheRepository;
use Core\Geo\GeocodingLookupRepository;
use Core\Geo\GeoPoint;
use Core\Geo\Task\PurgeGeocodingHandler;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\CoreTaskHandlers;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

#[\PHPUnit\Framework\Attributes\Group('database')]
final class PurgeGeocodingHandlerTest extends TestCase
{
    private \PDO $pdo;
    private TaskContext $context;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->context = new TaskContext(
            Connection::withPdo($this->pdo),
            $encryption,
            $this->createStub(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $encryption),
            sys_get_temp_dir()
        );
    }

    public function testDropsQuotaRowsPastTheWindowAndKeepsTheRest(): void
    {
        $lookups = new GeocodingLookupRepository($this->pdo);
        $lookups->record(1, new \DateTimeImmutable('-' . (AddressLocator::QUOTA_WINDOW_MINUTES + 5) . ' minutes'));
        $lookups->record(1, new \DateTimeImmutable());

        (new PurgeGeocodingHandler())->handle([], $this->context);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM geocoding_lookups');
        $stmt->execute();
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testDropsCachedAnswersTooOldToBeServedAndKeepsTheRest(): void
    {
        $cache = new GeocodingCacheRepository($this->pdo);
        $cache->store(str_repeat('a', 64), new GeoPoint(50.0, 4.0), new \DateTimeImmutable(
            '-' . (AddressLocator::FOUND_TTL_DAYS + 1) . ' days'
        ));
        $cache->store(str_repeat('b', 64), new GeoPoint(50.0, 4.0), new \DateTimeImmutable('-1 day'));

        (new PurgeGeocodingHandler())->handle([], $this->context);

        $this->assertNull($cache->find(str_repeat('a', 64)));
        $this->assertNotNull($cache->find(str_repeat('b', 64)));
    }

    public function testNothingFoundRowsGoAfterHoursNotMonths(): void
    {
        $cache = new GeocodingCacheRepository($this->pdo);
        $cache->store(str_repeat('c', 64), null, new \DateTimeImmutable(
            '-' . (AddressLocator::NOT_FOUND_TTL_HOURS + 1) . ' hours'
        ));
        $cache->store(str_repeat('d', 64), null, new \DateTimeImmutable('-1 hour'));
        $cache->store(str_repeat('e', 64), new GeoPoint(50.0, 4.0), new \DateTimeImmutable('-2 days'));

        (new PurgeGeocodingHandler())->handle([], $this->context);

        $this->assertNull($cache->find(str_repeat('c', 64)));
        $this->assertNotNull($cache->find(str_repeat('d', 64)));
        $this->assertNotNull($cache->find(str_repeat('e', 64)));
    }

    public function testReschedulesItselfAndIsKnownToBothEntryPoints(): void
    {
        (new PurgeGeocodingHandler())->handle([], $this->context);

        $scheduled = (new SchedulerService(new SchedulerRepository($this->pdo)))
            ->find('core', PurgeGeocodingHandler::TASK_KEY, PurgeGeocodingHandler::REFERENCE);
        $this->assertNotNull($scheduled);
        $this->assertSame('pending', $scheduled['status']);

        $this->assertSame(
            PurgeGeocodingHandler::class,
            CoreTaskHandlers::all()[PurgeGeocodingHandler::TASK_KEY] ?? null,
            'registered in Core\Scheduler\CoreTaskHandlers, or public/cron.php never runs it'
        );
    }
}
