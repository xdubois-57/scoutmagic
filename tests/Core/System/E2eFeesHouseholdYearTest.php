<?php

declare(strict_types=1);

namespace Tests\Core\System;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\ScoutYear\ScoutYearResolver;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

if (!defined('E2E_SUPPORT_TEST')) {
    define('E2E_SUPPORT_TEST', true);
}
require_once dirname(__DIR__, 3) . '/scripts/e2e-support.php';

#[\PHPUnit\Framework\Attributes\Group('database')]
final class E2eFeesHouseholdYearTest extends TestCase
{
    public function testTheFixtureKeepsThePublicYearEvenWhenTheCalendarHasMovedOn(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $years = new ScoutYearService($pdo);
        $calendar = $years->getCurrentYear();
        $pinned = $years->ensureYear(ScoutYearService::previousLabel($calendar['label']));
        $settings = new SettingService(new SettingRepository($pdo));
        $settings->register(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $pinned, 'number', 'Année', 'Test');

        self::assertSame($pinned, \e2eFeesHouseholdYear($pdo)['id']);
    }

    public function testAnUnpinnedInstanceStillUsesTheCalendarYear(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $current = (new ScoutYearService($pdo))->getCurrentYear();
        self::assertSame($current, \e2eFeesHouseholdYear($pdo));
    }
}
