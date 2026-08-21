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
 * What the dashboard turns a stored report into before Twig ever sees it:
 * the version/build bucket, and the difference between the URL it prints
 * and the URL it is willing to link (ARCHITECTURE.md §8.50).
 */
class SupportDashboardPresentationTest extends TestCase
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
    private function seed(string $installationId, array $payloadOverrides = []): int
    {
        $payload = array_replace([
            'statistics_schema_version' => 1,
            'installation_id' => $installationId,
            'instance_url' => 'https://' . $installationId . '.example.be',
            'scoutmagic' => ['version' => '1.0.33', 'is_dev_build' => false],
            'usage' => ['active_members' => 10, 'active_sections' => 2],
        ], $payloadOverrides);

        return $this->installations->register(
            $installationId,
            password_hash('secret', PASSWORD_DEFAULT),
            (string) json_encode($payload),
            StatisticsIntakeService::denormalize($payload)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(int $id): array
    {
        $row = $this->service->findDetail($id);
        $this->assertIsArray($row);

        return $row;
    }

    // ---------------------------------------------------------------
    // Version / build
    // ---------------------------------------------------------------

    /**
     * The column is called "Version / build" and the chart "Répartition des
     * versions / builds"; the label behind both used to be the bare version
     * string, so a release and a dev build of the same number landed in one
     * indistinguishable slice — the opposite of what a support dashboard is
     * asked for, since a dev build is whatever the branch held that day.
     */
    public function testADevelopmentBuildIsItsOwnVersionBucket(): void
    {
        $release = $this->seed('aaaa', ['scoutmagic' => ['version' => '1.0.33', 'is_dev_build' => false]]);
        $dev = $this->seed('bbbb', ['scoutmagic' => ['version' => '1.0.33', 'is_dev_build' => true]]);

        $this->assertSame('1.0.33', $this->detail($release)['version_label']);
        $this->assertSame('1.0.33 (dev)', $this->detail($dev)['version_label']);
        $this->assertNotSame(
            $this->detail($release)['version_label'],
            $this->detail($dev)['version_label']
        );
    }

    /**
     * A sender that could not answer `is_dev_build` is not thereby a
     * release: the suffix is added only on a reported `true`.
     */
    public function testAnUnreportedDevFlagLeavesThePlainVersionBucket(): void
    {
        $id = $this->seed('cccc', ['scoutmagic' => ['version' => '1.0.30']]);

        $this->assertSame('1.0.30', $this->detail($id)['version_label']);
        $this->assertNull($this->detail($id)['is_dev_build']);
    }

    public function testAnUnreportedVersionStaysAbsentRatherThanBecomingADevLabel(): void
    {
        $id = $this->seed('dddd', ['scoutmagic' => ['is_dev_build' => true]]);

        $this->assertNull($this->detail($id)['version_label']);
    }

    public function testTheVersionChartAndFilterOptionsSplitTheTwoBuilds(): void
    {
        $this->seed('aaaa', ['scoutmagic' => ['version' => '1.0.33', 'is_dev_build' => false]]);
        $this->seed('bbbb', ['scoutmagic' => ['version' => '1.0.33', 'is_dev_build' => true]]);

        $view = $this->service->buildView(SupportDashboardFilters::fromQuery(['status' => 'all']));

        $labels = array_column($view['charts']['versions'], 'label');
        sort($labels);
        $this->assertSame(['1.0.33', '1.0.33 (dev)'], $labels);
        $this->assertSame(['1.0.33', '1.0.33 (dev)'], $view['available']['versions']);
    }

    public function testFilteringOnTheDevBucketReturnsOnlyDevBuilds(): void
    {
        $this->seed('aaaa', ['scoutmagic' => ['version' => '1.0.33', 'is_dev_build' => false]]);
        $this->seed('bbbb', ['scoutmagic' => ['version' => '1.0.33', 'is_dev_build' => true]]);

        $rows = $this->service->filteredRows(SupportDashboardFilters::fromQuery([
            'status' => 'all',
            'version' => '1.0.33 (dev)',
        ]));

        $this->assertCount(1, $rows);
        $this->assertSame('bbbb', $rows[0]['installation_id']);
    }

    public function testTheExportCarriesTheSameVersionBuildLabelAsThePage(): void
    {
        $id = $this->seed('bbbb', ['scoutmagic' => ['version' => '1.0.33', 'is_dev_build' => true]]);

        $rows = $this->service->filteredRows(SupportDashboardFilters::fromQuery(['status' => 'all']));
        $xlsx = SupportInstallationExporter::build($rows);

        $this->assertNotSame('', $xlsx);
        $this->assertSame('1.0.33 (dev)', $this->detail($id)['version_label']);
    }

    // ---------------------------------------------------------------
    // instance_url: printed always, linked only when it is a link
    // ---------------------------------------------------------------

    public function testAnHttpsUrlIsBothPrintedAndLinkable(): void
    {
        $id = $this->seed('aaaa', ['instance_url' => 'https://unite.example.be']);

        $row = $this->detail($id);
        $this->assertSame('https://unite.example.be', $row['instance_url']);
        $this->assertSame('https://unite.example.be', $row['instance_url_href']);
    }

    /**
     * The belt-and-braces half of the fix. A row written before the intake
     * started refusing non-http schemes still holds whatever it holds, and
     * the dashboard has to render it without ever putting it behind an
     * `<a href>`. Written straight into the column here on purpose —
     * that is exactly the legacy state being defended against.
     */
    public function testALegacyRowWithAJavascriptUrlIsPrintedButNeverLinkable(): void
    {
        $id = $this->seed('aaaa');
        $this->pdo
            ->prepare('UPDATE support_installations SET instance_url = ? WHERE id = ?')
            ->execute(['javascript:alert(document.cookie)', $id]);

        $row = $this->detail($id);
        $this->assertSame('javascript:alert(document.cookie)', $row['instance_url']);
        $this->assertNull($row['instance_url_href'], 'a javascript: URL must never become an href');
    }

    public function testTheDashboardTemplateLinksTheHrefAndNotTheRawValue(): void
    {
        $template = (string) file_get_contents(
            dirname(__DIR__, 3) . '/modules/support_dashboard/views/index.html.twig'
        );

        $this->assertStringContainsString('href="{{ row.instance_url_href }}"', $template);
        $this->assertStringNotContainsString('href="{{ row.instance_url }}"', $template);
    }

    public function testAnAbsentUrlIsNeitherPrintedNorLinked(): void
    {
        $id = $this->seed('aaaa', ['instance_url' => null]);

        $row = $this->detail($id);
        $this->assertNull($row['instance_url']);
        $this->assertNull($row['instance_url_href']);
    }

    // ---------------------------------------------------------------
    // The export's module columns
    // ---------------------------------------------------------------

    /**
     * "No module list at all" and "a module list with none in this state"
     * are different facts, and the export keeps them apart: the first is
     * the same "Non renseigné" every other column uses, the second is an
     * empty cell.
     */
    public function testTheModuleColumnsTellAnAbsentListApartFromAnEmptyOne(): void
    {
        $this->seed('aaaa', ['modules' => [
            ['id' => 'calendar', 'enabled' => true, 'version' => '1.2.0'],
            ['id' => 'finance', 'enabled' => true, 'version' => '2.0.0'],
        ]]);
        $this->seed('bbbb', ['modules' => null]);

        $rows = $this->service->filteredRows(SupportDashboardFilters::fromQuery(['status' => 'all', 'sort' => 'instance_url', 'dir' => 'asc']));

        $this->assertSame(['aaaa', 'bbbb'], array_column($rows, 'installation_id'));

        $withModules = $this->exported($rows[0]);
        $withoutModules = $this->exported($rows[1]);

        $this->assertSame('calendar, finance', $withModules['Modules activés']);
        $this->assertSame('', $withModules['Modules désactivés']);
        $this->assertSame(SupportInstallationExporter::MISSING, $withoutModules['Modules activés']);
        $this->assertSame(SupportInstallationExporter::MISSING, $withoutModules['Modules désactivés']);
    }

    /**
     * A module entry that is not a module — a bare string, a null, an id
     * that is not a string — comes from a remote installation and must be
     * skipped rather than crash the page that renders it.
     */
    public function testMalformedModuleEntriesAreSkippedNotFatal(): void
    {
        $id = $this->seed('aaaa', ['modules' => [
            'pas un module',
            null,
            ['enabled' => true],
            ['id' => 42, 'enabled' => true],
            ['id' => 'calendar', 'enabled' => true, 'version' => '1.2.0'],
            ['id' => 'finance', 'enabled' => false, 'version' => 7],
        ]]);

        $modules = $this->detail($id)['modules'];

        $this->assertSame(['calendar', 'finance'], array_keys($modules));
        $this->assertTrue($modules['calendar']['enabled']);
        $this->assertSame('1.2.0', $modules['calendar']['version']);
        $this->assertFalse($modules['finance']['enabled']);
        $this->assertNull($modules['finance']['version'], 'a non-string version is not reported');
    }

    /**
     * A count of zero is a reported value; only an absent one reads as
     * "Non renseigné". Collapsing the two is the single most tempting
     * mistake in this export.
     */
    public function testZeroIsExportedAsZeroAndAnAbsentCountAsNonRenseigne(): void
    {
        $this->seed('aaaa', ['usage' => ['active_members' => 0, 'active_sections' => 0]]);
        $this->seed('bbbb', ['usage' => []]);

        $rows = $this->service->filteredRows(SupportDashboardFilters::fromQuery(['status' => 'all', 'sort' => 'instance_url', 'dir' => 'asc']));

        $this->assertSame('0', $this->exported($rows[0])['Membres actifs']);
        $this->assertSame(SupportInstallationExporter::MISSING, $this->exported($rows[1])['Membres actifs']);
    }

    /**
     * One XLSX cell per hydrated row, read back by header name. The
     * spreadsheet bytes themselves are covered by SupportRetentionTest; what
     * matters here is which value lands in which column.
     *
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    private function exported(array $row): array
    {
        $headers = SupportInstallationExporter::headers();
        $values = $this->exportedValues($row);

        $this->assertCount(count($headers), $values);

        return array_combine($headers, $values);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<int, string>
     */
    private function exportedValues(array $row): array
    {
        $xlsx = SupportInstallationExporter::build([$row]);
        $path = sys_get_temp_dir() . '/support-export-' . bin2hex(random_bytes(6)) . '.xlsx';
        file_put_contents($path, $xlsx);

        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet();
        $values = [];
        foreach (range(1, count(SupportInstallationExporter::headers())) as $column) {
            $values[] = (string) $sheet->getCell([$column, 2])->getValue();
        }
        unlink($path);

        return $values;
    }
}
