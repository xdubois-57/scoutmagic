<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Modules\SupportDashboard\Repository\SupportInstallationRepository;
use Modules\SupportDashboard\Service\SupportDashboardFilters;
use Modules\SupportDashboard\Service\SupportDashboardService;
use Modules\SupportDashboard\Service\StatisticsIntakeService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

class SupportDashboardServiceTest extends TestCase
{
    private \PDO $pdo;
    private SupportInstallationRepository $installations;
    private SupportDashboardService $service;

    protected function setUp(): void
    {
        SupportDashboardTestHelper::ensureAutoloadable();
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SupportDashboardTestHelper::createTables($this->pdo);

        $this->installations = new SupportInstallationRepository($this->pdo);
        $this->service = new SupportDashboardService($this->installations);
    }

    /**
     * @param array<string, mixed> $payloadOverrides
     */
    private function seed(
        string $installationId,
        string $lastReceivedRelative = '-1 day',
        array $payloadOverrides = []
    ): int {
        $payload = array_replace([
            'statistics_schema_version' => 1,
            'installation_id' => $installationId,
            'instance_url' => 'https://' . $installationId . '.example.be',
            'scoutmagic' => ['version' => '1.0.33', 'is_dev_build' => false],
            'scout_year' => ['label' => '2026-2027'],
            'usage' => ['active_members' => 100, 'active_sections' => 5],
            'modules' => [
                ['id' => 'calendar', 'enabled' => true, 'version' => '1.2.0'],
                ['id' => 'finance', 'enabled' => false, 'version' => '2.0.0'],
            ],
            'installation' => ['method' => 'layout_a'],
            'updates' => ['auto_update_enabled' => true, 'auto_update_level' => 'patch'],
            'lifecycle' => ['installed_at' => '2025-09-01T10:12:00+00:00', 'last_upgraded_at' => null],
        ], $payloadOverrides);

        $raw = (string) json_encode($payload);
        $id = $this->installations->register(
            $installationId,
            password_hash('secret', PASSWORD_DEFAULT),
            $raw,
            StatisticsIntakeService::denormalize($payload)
        );

        $this->pdo->prepare('UPDATE support_installations SET last_received_at = ? WHERE id = ?')
            ->execute([(new \DateTimeImmutable($lastReceivedRelative))->format('Y-m-d H:i:s'), $id]);

        return $id;
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function view(array $query = []): array
    {
        return $this->service->buildView(SupportDashboardFilters::fromQuery($query));
    }

    /**
     * @param array<string, mixed> $view
     * @return array<int, string>
     */
    private static function urlsOf(array $view): array
    {
        return array_map(static fn(array $row): string => (string) $row['instance_url'], $view['rows']);
    }

    /**
     * Roadmap IT-24: a row created because an installation opened a ticket
     * without ever agreeing to report is not a silent installation — it
     * never spoke. It gets a state of its own rather than reading as
     * « Obsolète », which would send a maintainer chasing a silence that
     * is a decision.
     */
    public function testAnInstallationWithoutTelemetryIsNeitherActiveNorStale(): void
    {
        $this->installations->register(
            'sans-telemetrie',
            password_hash('secret', PASSWORD_DEFAULT),
            '',
            [],
            false
        );

        $rows = $this->service->buildView(SupportDashboardFilters::fromQuery(['status' => SupportDashboardFilters::STATUS_ALL]))['rows'];
        $row = array_values(array_filter(
            $rows,
            static fn(array $r): bool => $r['installation_id'] === 'sans-telemetrie'
        ))[0];

        $this->assertFalse($row['telemetry_enabled']);
        $this->assertNull($row['is_active'], 'neither « Active » nor « Obsolète »');

        // And it stays out of both filtered readings, since it answers
        // neither question.
        $this->assertNotContains(
            'sans-telemetrie',
            array_column($this->service->buildView(SupportDashboardFilters::fromQuery([]))['rows'], 'installation_id')
        );
        $this->assertNotContains(
            'sans-telemetrie',
            array_column(
                $this->service->buildView(SupportDashboardFilters::fromQuery(['status' => SupportDashboardFilters::STATUS_STALE]))['rows'],
                'installation_id'
            )
        );
    }

    /**
     * The day a real report arrives the mark goes: the reason for it has
     * gone, and a row that kept saying « sans télémétrie » from the second
     * report onwards would be lying.
     */
    public function testAReportClearsTheMark(): void
    {
        $id = $this->installations->register(
            'sans-telemetrie',
            password_hash('secret', PASSWORD_DEFAULT),
            '',
            [],
            false
        );

        $this->installations->recordReport($id, '{}', []);

        $row = $this->installations->findById($id);
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['telemetry_enabled']);
    }

    // --- default view ---

    public function testTheDefaultViewShowsActiveInstallationsOnly(): void
    {
        $this->seed('aaaa', '-1 day');
        $this->seed('bbbb', '-40 days');

        $view = $this->view();

        $this->assertSame(['https://aaaa.example.be'], self::urlsOf($view));
        $this->assertSame(2, $view['total_count']);
        $this->assertSame(1, $view['filtered_count']);
    }

    public function testStaleInstallationsRemainReachableThroughAFilter(): void
    {
        $this->seed('aaaa', '-1 day');
        $this->seed('bbbb', '-40 days');

        $this->assertSame(['https://bbbb.example.be'], self::urlsOf($this->view(['status' => 'stale'])));
        $this->assertCount(2, $this->view(['status' => 'all'])['rows']);
    }

    public function testAnUnknownQueryParameterFallsBackToTheDefault(): void
    {
        $this->seed('aaaa', '-1 day');
        $this->seed('bbbb', '-40 days');

        $view = $this->view(['status' => 'nonsense', 'sort' => 'nonsense', 'page' => '-3']);

        $this->assertSame(1, $view['filtered_count']);
        $this->assertSame(1, $view['page']);
    }

    // --- filters ---

    public function testFilteringByVersion(): void
    {
        $this->seed('aaaa');
        $this->seed('bbbb', '-1 day', ['scoutmagic' => ['version' => '1.0.30', 'is_dev_build' => false]]);

        $this->assertSame(['https://bbbb.example.be'], self::urlsOf($this->view(['version' => '1.0.30'])));
    }

    public function testFilteringByInstallationMethod(): void
    {
        $this->seed('aaaa');
        $this->seed('bbbb', '-1 day', ['installation' => ['method' => 'layout_b']]);

        $this->assertSame(['https://bbbb.example.be'], self::urlsOf($this->view(['installation_method' => 'layout_b'])));
    }

    public function testFilteringByAutoUpdateMode(): void
    {
        $this->seed('aaaa');
        $this->seed('bbbb', '-1 day', ['updates' => ['auto_update_enabled' => false, 'auto_update_level' => 'patch']]);

        // The filter travels as a technical key, never as the French label
        // the page renders — a URL is not a translation artefact.
        $this->assertSame(['https://bbbb.example.be'], self::urlsOf($this->view(['auto_update' => 'disabled'])));
        $this->assertSame(['https://aaaa.example.be'], self::urlsOf($this->view(['auto_update' => 'patch'])));

        // And a reported `false` is a reported value: it must survive the
        // round-trip through the database as false, never as "unknown".
        $stale = $this->view(['auto_update' => 'disabled'])['rows'][0];
        $this->assertFalse($stale['auto_update_enabled']);
        $this->assertSame('Désactivées', $stale['auto_update_label']);
    }

    public function testFilteringByModuleActivationState(): void
    {
        $this->seed('aaaa');
        $this->seed('bbbb', '-1 day', ['modules' => [['id' => 'calendar', 'enabled' => false, 'version' => '1.2.0']]]);

        $this->assertSame(['https://aaaa.example.be'], self::urlsOf($this->view(['module' => 'calendar', 'module_state' => 'enabled'])));
        $this->assertSame(['https://bbbb.example.be'], self::urlsOf($this->view(['module' => 'calendar', 'module_state' => 'disabled'])));
        $this->assertCount(2, $this->view(['module' => 'calendar'])['rows']);
        $this->assertCount(1, $this->view(['module' => 'finance'])['rows']);
    }

    // --- search ---

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    public static function searchProvider(): array
    {
        return [
            ['bbbb.example', 'https://bbbb.example.be'],
            ['BBBB', 'https://bbbb.example.be'],
            ['1.0.30', 'https://bbbb.example.be'],
            ['2025-2026', 'https://bbbb.example.be'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('searchProvider')]
    public function testSearchCoversUrlIdentifierVersionAndScoutYear(string $needle, string $expectedUrl): void
    {
        $this->seed('aaaa');
        $this->seed('bbbb', '-1 day', [
            'scoutmagic' => ['version' => '1.0.30', 'is_dev_build' => false],
            'scout_year' => ['label' => '2025-2026'],
        ]);

        $this->assertSame([$expectedUrl], self::urlsOf($this->view(['q' => $needle])));
    }

    public function testSearchNarrowsTheCountersIndicatorsAndCharts(): void
    {
        $this->seed('aaaa');
        $this->seed('bbbb', '-1 day', ['scoutmagic' => ['version' => '1.0.30', 'is_dev_build' => false]]);

        $view = $this->view(['q' => 'bbbb']);

        $this->assertSame(1, $view['filtered_count']);
        $this->assertSame(1, $view['indicators']['active_installations']);
        $this->assertSame(100, $view['indicators']['total_active_members']);
        $this->assertSame('1.0.30', $view['indicators']['most_common_version']);
        $this->assertSame([['label' => '1.0.30', 'count' => 1]], $view['charts']['versions']);
    }

    // --- sorting and pagination ---

    public function testSortingByActiveMembers(): void
    {
        $this->seed('aaaa', '-1 day', ['usage' => ['active_members' => 10, 'active_sections' => 1]]);
        $this->seed('bbbb', '-1 day', ['usage' => ['active_members' => 200, 'active_sections' => 9]]);

        $this->assertSame(
            ['https://bbbb.example.be', 'https://aaaa.example.be'],
            self::urlsOf($this->view(['sort' => 'active_members']))
        );
        $this->assertSame(
            ['https://aaaa.example.be', 'https://bbbb.example.be'],
            self::urlsOf($this->view(['sort' => 'active_members', 'dir' => 'asc']))
        );
    }

    public function testAMissingValueAlwaysSortsLastInBothDirections(): void
    {
        $this->seed('aaaa', '-1 day', ['usage' => ['active_members' => 10, 'active_sections' => 1]]);
        $this->seed('zzzz', '-1 day', ['usage' => []]);

        $descending = self::urlsOf($this->view(['sort' => 'active_members']));
        $ascending = self::urlsOf($this->view(['sort' => 'active_members', 'dir' => 'asc']));

        $this->assertSame('https://zzzz.example.be', end($descending));
        $this->assertSame('https://zzzz.example.be', end($ascending));
    }

    public function testEverySortableFieldIsReachableAndOrdersTheRows(): void
    {
        $this->seed('aaaa', '-1 day', ['lifecycle' => ['installed_at' => '2024-01-01T00:00:00+00:00', 'last_upgraded_at' => null]]);
        $this->seed('bbbb', '-2 days', ['lifecycle' => ['installed_at' => '2026-01-01T00:00:00+00:00', 'last_upgraded_at' => null]]);

        foreach (SupportDashboardFilters::SORTABLE as $sort) {
            $this->assertCount(2, $this->view(['sort' => $sort])['rows'], "sort={$sort} lost rows");
        }

        $this->assertSame(
            ['https://bbbb.example.be', 'https://aaaa.example.be'],
            self::urlsOf($this->view(['sort' => 'installed_at']))
        );
        $this->assertSame(
            ['https://aaaa.example.be', 'https://bbbb.example.be'],
            self::urlsOf($this->view(['sort' => 'installed_at', 'dir' => 'asc']))
        );
    }

    public function testPagination(): void
    {
        for ($i = 0; $i < SupportDashboardFilters::PER_PAGE + 3; $i++) {
            $this->seed('inst' . str_pad((string) $i, 4, '0', STR_PAD_LEFT));
        }

        $first = $this->view();
        $this->assertCount(SupportDashboardFilters::PER_PAGE, $first['rows']);
        $this->assertSame(2, $first['page_count']);

        $second = $this->view(['page' => 2]);
        $this->assertCount(3, $second['rows']);
        $this->assertSame(2, $second['page']);
    }

    // --- indicators ---

    public function testIndicatorsAreComputedOnTheFilteredSet(): void
    {
        $this->seed('aaaa', '-1 day', ['usage' => ['active_members' => 10, 'active_sections' => 2]]);
        $this->seed('bbbb', '-1 day', ['usage' => ['active_members' => 30, 'active_sections' => 3]]);
        $this->seed('cccc', '-40 days', ['usage' => ['active_members' => 1000, 'active_sections' => 99]]);

        $view = $this->view();

        $this->assertSame(2, $view['indicators']['active_installations']);
        $this->assertSame(40, $view['indicators']['total_active_members']);
        $this->assertSame(5, $view['indicators']['total_active_sections']);
        $this->assertSame(100, $view['indicators']['auto_update_percentage']);
    }

    public function testATotalOverEntirelyUnreportedValuesIsNullNotZero(): void
    {
        $this->seed('aaaa', '-1 day', ['usage' => []]);

        $view = $this->view();

        $this->assertNull($view['indicators']['total_active_members']);
        $this->assertNull($view['indicators']['total_active_sections']);
    }

    public function testAnUnreportedAutoUpdateStateIsExcludedFromThePercentageRatherThanCountedAsOff(): void
    {
        $this->seed('aaaa', '-1 day', ['updates' => ['auto_update_enabled' => true, 'auto_update_level' => 'patch']]);
        $this->seed('bbbb', '-1 day', ['updates' => []]);

        $view = $this->view();

        $this->assertSame(100, $view['indicators']['auto_update_percentage']);
        $this->assertSame(1, $view['indicators']['auto_update_known_count']);
    }

    public function testThereAreExactlyFiveIndicatorsAndTwoCharts(): void
    {
        $this->seed('aaaa');

        $view = $this->view();

        $this->assertSame(
            ['active_installations', 'total_active_members', 'total_active_sections', 'most_common_version', 'most_common_version_count', 'auto_update_percentage', 'auto_update_known_count'],
            array_keys($view['indicators'])
        );
        $this->assertSame(['versions', 'auto_update'], array_keys($view['charts']));
    }

    // --- detail ---

    public function testTheDetailCarriesEveryMetricAndTheExactRawJson(): void
    {
        $id = $this->seed('aaaa', '-1 day', ['a_future_field' => ['x' => 1]]);

        $detail = $this->service->findDetail($id);

        $this->assertNotNull($detail);
        $this->assertSame('https://aaaa.example.be', $detail['instance_url']);
        $this->assertSame('2026-2027', $detail['scout_year_label']);
        $this->assertSame(['calendar', 'finance'], array_keys($detail['modules']));

        // Unknown fields stay visible in the raw JSON.
        $this->assertStringContainsString('a_future_field', $detail['raw_json']);
        // Pretty-printed, so a maintainer can actually read it.
        $this->assertStringContainsString("\n", $detail['raw_json']);
    }

    public function testTheDetailIsNullForAnUnknownInstallation(): void
    {
        $this->assertNull($this->service->findDetail(999));
    }

    public function testAValueThatWasNeverReportedStaysNullInTheDetail(): void
    {
        $id = $this->seed('aaaa', '-1 day', ['usage' => [], 'updates' => []]);

        $detail = $this->service->findDetail($id);

        $this->assertNotNull($detail);
        $this->assertNull($detail['active_members']);
        $this->assertNull($detail['active_sections']);
        $this->assertNull($detail['auto_update_enabled']);
    }

    // --- no persisted state ---

    public function testNoViewStateSurvivesARequestWithoutAQueryString(): void
    {
        $this->seed('aaaa', '-40 days');

        // A filtered request, then a bare one: the bare one must be back to
        // the default (active only), with nothing carried over.
        $this->assertCount(1, $this->view(['status' => 'stale'])['rows']);
        $this->assertCount(0, $this->view()['rows']);
    }

    public function testTheFilterQueryStringRoundTripsWithoutLosingOtherFilters(): void
    {
        $filters = SupportDashboardFilters::fromQuery(['q' => 'abc', 'status' => 'all', 'version' => '1.0.33']);

        $queryString = $filters->queryString(['sort' => 'active_members', 'page' => null]);

        $this->assertStringContainsString('q=abc', $queryString);
        $this->assertStringContainsString('status=all', $queryString);
        $this->assertStringContainsString('version=1.0.33', $queryString);
        $this->assertStringContainsString('sort=active_members', $queryString);
    }

    public function testTheDefaultFilterProducesAnEmptyQueryString(): void
    {
        $this->assertSame('', SupportDashboardFilters::defaults()->queryString());
    }
}
