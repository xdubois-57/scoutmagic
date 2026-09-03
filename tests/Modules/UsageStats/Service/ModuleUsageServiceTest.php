<?php

declare(strict_types=1);

namespace Tests\Modules\UsageStats\Service;

use Modules\UsageStats\Api\ModuleUsageInterface;
use Modules\UsageStats\Repository\PageViewRepository;
use Modules\UsageStats\Service\ModuleUsageService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\UsageStats\UsageStatsTestHelper;

/**
 * The module's one published capability: the aggregate the daily usage
 * report carries. Everything this class refuses to answer is as much the
 * specification as what it answers.
 */
class ModuleUsageServiceTest extends TestCase
{
    private const NOW = '2026-08-14 10:00:00';

    private PageViewRepository $pageViews;
    private ModuleUsageService $service;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        UsageStatsTestHelper::createTables($pdo);
        $this->pageViews = new PageViewRepository($pdo);
        $this->service = new ModuleUsageService($this->pageViews, new \DateTimeImmutable(self::NOW));
    }

    public function testTheAggregateSumsEveryPageAndAudienceOfAModuleOverTheWindow(): void
    {
        $this->pageViews->increment('2026-08', '/calendar', 'calendar', 'identified');
        $this->pageViews->increment('2026-08', '/calendar', 'calendar', 'anonymous');
        $this->pageViews->increment('2026-01', '/calendar/event/{id}', 'calendar', 'identified');

        $usage = $this->service->aggregatedByModule();

        $this->assertCount(1, $usage);
        $this->assertSame('calendar', $usage[0]->moduleId);
        $this->assertSame(3, $usage[0]->views);
    }

    public function testTheWindowIsTwelveMonthsAndOlderCountersAreLeftOut(): void
    {
        $this->assertSame(12, ModuleUsageInterface::WINDOW_MONTHS);

        $this->pageViews->increment('2025-09', '/calendar', 'calendar', 'identified');
        $this->pageViews->increment('2025-08', '/news', 'news', 'identified');

        $this->assertSame(['calendar'], array_map(
            static fn (object $usage): string => $usage->moduleId,
            $this->service->aggregatedByModule()
        ));
    }

    /**
     * `core` is a module id in the counters — every route the application
     * itself declares is filed under it — but it is not a module, and a
     * receiver aggregating « adoption des modules » would have to
     * special-case it on every row.
     */
    public function testTheApplicationsOwnPagesAreNotAModule(): void
    {
        $this->pageViews->increment('2026-08', '/', 'core', 'anonymous');

        $this->assertSame([], $this->service->aggregatedByModule());
    }

    /**
     * A module nobody opened is ABSENT rather than present with a zero:
     * the report already lists every installed module and its enabled
     * state, so absence from this list is the zero, said once.
     */
    public function testAModuleWithNoOpeningIsAbsentRatherThanZero(): void
    {
        $this->pageViews->increment('2026-08', '/calendar', 'calendar', 'identified');

        $this->assertSame(['calendar'], array_map(
            static fn (object $usage): string => $usage->moduleId,
            $this->service->aggregatedByModule()
        ));
    }

    /**
     * Sorted by module id, so two reports of an unchanged installation
     * differ only by `generated_at` — the same determinism the module list
     * beside it already guarantees.
     */
    public function testTheAggregateIsDeterministicallyOrdered(): void
    {
        foreach (['trombinoscope', 'calendar', 'news'] as $moduleId) {
            $this->pageViews->increment('2026-08', '/' . $moduleId, $moduleId, 'identified');
        }

        $this->assertSame(
            ['calendar', 'news', 'trombinoscope'],
            array_map(static fn (object $usage): string => $usage->moduleId, $this->service->aggregatedByModule())
        );
    }
}
