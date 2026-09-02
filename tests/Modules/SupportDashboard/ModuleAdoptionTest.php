<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Modules\SupportDashboard\Repository\SupportInstallationRepository;
use Modules\SupportDashboard\Service\StatisticsIntakeService;
use Modules\SupportDashboard\Service\SupportDashboardFilters;
use Modules\SupportDashboard\Service\SupportDashboardService;
use Modules\SupportDashboard\Service\SupportInstallationExporter;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * « Activé dans N installations, réellement ouvert dans N » — the reading
 * no single unit can produce, and the whole reason the per-module aggregate
 * travels (ARCHITECTURE.md §8.51bis).
 *
 * Every test here is really about one distinction: **« zéro ouverture » and
 * « ne participe pas » are different facts.** An installation whose
 * `usage_stats` module is off cannot say whether anybody opened its
 * calendar, and reading its silence as a zero is the one mistake that would
 * make a maintainer retire a module people use every week.
 */
class ModuleAdoptionTest extends TestCase
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

    public function testAModuleEnabledEverywhereAndOpenedNowhereIsTheHeadline(): void
    {
        $this->seed('a', ['calendar' => true, 'retro' => true], ['calendar' => 120]);
        $this->seed('b', ['calendar' => true, 'retro' => true], ['calendar' => 80]);

        $adoption = $this->adoption();

        $this->assertSame(['calendar', 'retro'], array_column($adoption['modules'], 'id'));
        $this->assertSame(['retro'], $adoption['unused_ids']);

        $retro = $this->moduleRow($adoption, 'retro');
        $this->assertSame(2, $retro['enabled']);
        $this->assertSame(2, $retro['measured']);
        $this->assertSame(0, $retro['used']);
        $this->assertSame(0, $retro['silent']);
    }

    /**
     * The trap this block exists to avoid. An installation that reports but
     * does not measure is counted as enabled and as SILENT — never as a
     * zero opening — so the module does not enter `unused_ids` on the
     * strength of a silence.
     */
    public function testAnInstallationThatDoesNotMeasureIsSilentRatherThanZero(): void
    {
        $this->seed('measuring', ['calendar' => true], ['calendar' => 40]);
        $this->seed('silent', ['calendar' => true, 'gallery' => true], null);

        $adoption = $this->adoption();

        $calendar = $this->moduleRow($adoption, 'calendar');
        $this->assertSame(2, $calendar['enabled']);
        $this->assertSame(1, $calendar['measured']);
        $this->assertSame(1, $calendar['used']);
        $this->assertSame(1, $calendar['silent']);

        // Gallery is enabled on the silent installation only: nothing can
        // be said about it, so it is not an « ouvert nulle part » finding.
        $gallery = $this->moduleRow($adoption, 'gallery');
        $this->assertSame(1, $gallery['enabled']);
        $this->assertSame(0, $gallery['measured']);
        $this->assertSame(0, $gallery['used']);
        $this->assertNotContains('gallery', $adoption['unused_ids']);
    }

    public function testAModuleNobodyHasEnabledIsNotListedAtAll(): void
    {
        $this->seed('a', ['calendar' => true, 'rental' => false], ['calendar' => 3]);

        $this->assertSame(['calendar'], array_column($this->adoption()['modules'], 'id'));
    }

    /**
     * A module enabled but opened zero times at an installation that DOES
     * measure is a real zero, and it is exactly what the block is for.
     */
    public function testAMeasuredZeroCountsAsAZero(): void
    {
        $this->seed('a', ['camps' => true], ['calendar' => 10]);

        $camps = $this->moduleRow($this->adoption(), 'camps');
        $this->assertSame(1, $camps['measured']);
        $this->assertSame(0, $camps['used']);
        $this->assertSame(['camps'], $this->adoption()['unused_ids']);
    }

    public function testTheCountOfMeasuringInstallationsIsReported(): void
    {
        $this->seed('a', ['calendar' => true], ['calendar' => 1]);
        $this->seed('b', ['calendar' => true], null);
        $this->seed('c', ['calendar' => true], []);

        // 'c' reports an EMPTY usage list, which is a measurement saying
        // « rien d'ouvert » — it measures. Only 'b' does not.
        $this->assertSame(2, $this->adoption()['measuring_installations']);
    }

    /**
     * Like the indicator cards and both charts, and for the same reason: a
     * block that ignored the filter would contradict the table under it on
     * the same screen.
     */
    public function testAdoptionFollowsTheFilters(): void
    {
        $this->seed('a', ['calendar' => true], ['calendar' => 5], '1.0.33');
        $this->seed('b', ['retro' => true], ['retro' => 5], '1.0.40');

        $filtered = $this->service->buildView(SupportDashboardFilters::fromQuery(['version' => '1.0.33']));

        $this->assertSame(['calendar'], array_column($filtered['module_adoption']['modules'], 'id'));
    }

    /**
     * The same distinction, carried into the spreadsheet: « Non renseigné »
     * for an installation that does not measure, an EMPTY cell for one that
     * measures and opened nothing. Written identically, a reader could sort
     * the column and conclude half the fleet uses nothing.
     */
    public function testTheExportKeepsSilenceApartFromZero(): void
    {
        $this->seed('measuring', ['calendar' => true], ['calendar' => 7]);
        $this->seed('nothing-opened', ['calendar' => true], []);
        $this->seed('silent', ['calendar' => true], null);

        $rows = $this->service->filteredRows(SupportDashboardFilters::fromQuery([]));
        $byId = [];
        foreach ($rows as $row) {
            $byId[(string) $row['installation_id']] = $row;
        }

        $this->assertSame(['calendar' => 7], $byId['measuring']['module_usage']);
        $this->assertSame([], $byId['nothing-opened']['module_usage']);
        $this->assertNull($byId['silent']['module_usage']);

        $this->assertStringContainsString('Modules réellement ouverts', implode('|', SupportInstallationExporter::headers()));
    }

    /**
     * The column the mockup adds to the existing installations block. It is
     * read straight from the payload, and an installation that never
     * reported it stays absent rather than becoming a zero.
     */
    public function testActiveAccountsAreReadFromThePayloadAndStayAbsentWhenUnreported(): void
    {
        $this->seed('with', ['calendar' => true], ['calendar' => 1], '1.0.33', 62);
        $this->seed('without', ['calendar' => true], ['calendar' => 1], '1.0.33', null);

        $byId = [];
        foreach ($this->service->filteredRows(SupportDashboardFilters::fromQuery([])) as $row) {
            $byId[(string) $row['installation_id']] = $row;
        }

        $this->assertSame(62, $byId['with']['active_accounts_30d']);
        $this->assertNull($byId['without']['active_accounts_30d']);
    }

    /**
     * @return array{modules: list<array<string, int|string>>, measuring_installations: int, unused_ids: list<string>}
     */
    private function adoption(): array
    {
        /** @var array{modules: list<array<string, int|string>>, measuring_installations: int, unused_ids: list<string>} $adoption */
        $adoption = $this->service->buildView(SupportDashboardFilters::fromQuery([]))['module_adoption'];

        return $adoption;
    }

    /**
     * @param array{modules: list<array<string, int|string>>, measuring_installations: int, unused_ids: list<string>} $adoption
     * @return array<string, int|string>
     */
    private function moduleRow(array $adoption, string $moduleId): array
    {
        foreach ($adoption['modules'] as $module) {
            if ($module['id'] === $moduleId) {
                return $module;
            }
        }

        $this->fail('No adoption row for ' . $moduleId);
    }

    /**
     * @param array<string, bool> $modules module id => enabled
     * @param ?array<string, int> $usage null = this installation does not measure
     */
    private function seed(
        string $installationId,
        array $modules,
        ?array $usage,
        string $version = '1.0.33',
        ?int $activeAccounts = 40
    ): void {
        $declaredModules = [];
        foreach ($modules as $id => $enabled) {
            $declaredModules[] = ['id' => $id, 'enabled' => $enabled, 'version' => '1.0.0'];
        }

        $declaredUsage = null;
        if ($usage !== null) {
            $entries = [];
            foreach ($usage as $id => $views) {
                $entries[] = ['id' => $id, 'views' => $views];
            }
            $declaredUsage = ['window_months' => 12, 'modules' => $entries];
        }

        $payload = [
            'statistics_schema_version' => 1,
            'installation_id' => $installationId,
            'instance_url' => 'https://' . $installationId . '.example.be',
            'scoutmagic' => ['version' => $version, 'is_dev_build' => false],
            'scout_year' => ['label' => '2026-2027'],
            'usage' => [
                'active_members' => 100,
                'active_sections' => 5,
                'active_accounts_30d' => $activeAccounts,
            ],
            'modules' => $declaredModules,
            'module_usage' => $declaredUsage,
            'installation' => ['method' => 'layout_a'],
            'updates' => ['auto_update_enabled' => true, 'auto_update_level' => 'patch'],
            'lifecycle' => ['installed_at' => '2025-09-01T10:12:00+00:00', 'last_upgraded_at' => null],
        ];

        $this->installations->register(
            $installationId,
            password_hash('secret', PASSWORD_DEFAULT),
            (string) json_encode($payload),
            StatisticsIntakeService::denormalize($payload)
        );
    }
}
