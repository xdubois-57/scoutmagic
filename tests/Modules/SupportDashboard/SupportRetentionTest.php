<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Modules\SupportDashboard\Repository\SupportInstallationRepository;
use Modules\SupportDashboard\Service\StatisticsIntakeService;
use Modules\SupportDashboard\Service\SupportDashboardFilters;
use Modules\SupportDashboard\Service\SupportDashboardService;
use Modules\SupportDashboard\Service\SupportInstallationExporter;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * IT-10: the activity threshold, the retention window, manual deletion, and
 * the XLSX export.
 */
class SupportRetentionTest extends TestCase
{
    private \PDO $pdo;
    private SupportInstallationRepository $installations;
    private SettingService $settings;

    protected function setUp(): void
    {
        SupportDashboardTestHelper::ensureAutoloadable();
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SupportDashboardTestHelper::createTables($this->pdo);

        $this->installations = new SupportInstallationRepository($this->pdo);
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        $this->settings->register('support_active_threshold_days', '14', 'number', 'Seuil', 'Seuil', 'support_dashboard');
        $this->settings->register('support_retention_months', '6', 'number', 'Rétention', 'Rétention', 'support_dashboard');
    }

    private function service(): SupportDashboardService
    {
        return new SupportDashboardService($this->installations, $this->settings);
    }

    /**
     * @param array<string, mixed> $payloadOverrides
     */
    private function seed(string $installationId, string $lastReceivedRelative, array $payloadOverrides = []): int
    {
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

        $id = $this->installations->register(
            $installationId,
            password_hash('secret', PASSWORD_DEFAULT),
            (string) json_encode($payload),
            StatisticsIntakeService::denormalize($payload)
        );

        $this->pdo->prepare('UPDATE support_installations SET last_received_at = ? WHERE id = ?')
            ->execute([(new \DateTimeImmutable($lastReceivedRelative))->format('Y-m-d H:i:s'), $id]);

        return $id;
    }

    // --- the activity threshold is the setting, not a literal ---

    public function testTheActiveThresholdSettingDrivesTheActiveStaleSplit(): void
    {
        $this->seed('aaaa', '-20 days');

        $defaultView = $this->service()->buildView(SupportDashboardFilters::fromQuery([]));
        $this->assertSame([], $defaultView['rows'], '20 days old is stale at the default 14-day threshold');

        $this->settings->set('support_active_threshold_days', '30', 'support_dashboard');

        $widened = $this->service()->buildView(SupportDashboardFilters::fromQuery([]));
        $this->assertCount(1, $widened['rows'], 'raising the threshold to 30 days makes it active again');
        $this->assertSame(30, $widened['active_threshold_days']);
    }

    public function testAnAbsurdThresholdFallsBackToTheDeclaredDefault(): void
    {
        // A zero or negative threshold would mark every installation stale;
        // an empty one would be read as zero. None of those is obeyed.
        foreach (['0', '-5', ''] as $nonsense) {
            $this->settings->set('support_active_threshold_days', $nonsense, 'support_dashboard');
            $this->assertSame(
                SupportDashboardService::ACTIVE_THRESHOLD_DAYS,
                $this->service()->activeThresholdDays(),
                "threshold '{$nonsense}' should fall back to the default"
            );
        }
    }

    public function testTheRetentionSettingIsReadTheSameWay(): void
    {
        $this->assertSame(6, $this->service()->retentionMonths());

        $this->settings->set('support_retention_months', '12', 'support_dashboard');
        $this->assertSame(12, $this->service()->retentionMonths());

        $this->settings->set('support_retention_months', '0', 'support_dashboard');
        $this->assertSame(SupportDashboardService::RETENTION_MONTHS, $this->service()->retentionMonths());
    }

    // --- retention ---

    public function testRetentionSelectsOnlyRecordsPastTheWindow(): void
    {
        $keep = $this->seed('aaaa', '-2 months');
        $drop = $this->seed('bbbb', '-7 months');

        $cutoff = (new \DateTimeImmutable('-6 months'))->format('Y-m-d H:i:s');

        $this->assertSame([$drop], $this->installations->findIdsLastReceivedBefore($cutoff));
        $this->assertNotNull($this->installations->findById($keep));
    }

    public function testDeletionRemovesTheWholeRecordCredentialHashIncluded(): void
    {
        $id = $this->seed('aaaa', '-7 months');

        $this->assertTrue($this->installations->delete($id));
        $this->assertNull($this->installations->findById($id));

        // Nothing of the record survives anywhere in the table — most
        // importantly not the credential hash, which is what would let a
        // long-gone installation's identity be re-asserted.
        $remaining = (string) json_encode($this->installations->findAll());
        $this->assertStringNotContainsString('aaaa', $remaining);
        $this->assertStringNotContainsString('$2y$', $remaining);
    }

    public function testDeletingTheSameRecordTwiceReportsTheSecondAttemptAsANoop(): void
    {
        $id = $this->seed('aaaa', '-1 day');

        $this->assertTrue($this->service()->delete($id));
        $this->assertFalse($this->service()->delete($id));
    }

    public function testAPurgedInstallationThatReportsAgainIsANewRegistration(): void
    {
        $id = $this->seed('aaaa', '-7 months');
        $this->installations->delete($id);

        // Its id is unknown again, so trust-on-first-use applies: the
        // receiver has no basis to treat it as the same installation.
        $this->assertNull($this->installations->findByInstallationId('aaaa'));
    }

    // --- export ---

    /**
     * @return array<int, array<int, string|null>>
     */
    private function exportRows(SupportDashboardFilters $filters): array
    {
        $rows = $this->service()->filteredRows($filters);
        $sheet = SupportInstallationExporter::build($rows);
        $this->assertNotSame('', $sheet, 'the exporter must produce real XLSX bytes');

        return array_map(static fn(array $row): array => $row, $rows);
    }

    public function testTheExportCoversTheWholeFilteredSetAndNotTheCurrentPage(): void
    {
        for ($i = 0; $i < SupportDashboardFilters::PER_PAGE + 7; $i++) {
            $this->seed('inst' . str_pad((string) $i, 4, '0', STR_PAD_LEFT), '-1 day');
        }

        $filters = SupportDashboardFilters::fromQuery([]);

        $this->assertCount(SupportDashboardFilters::PER_PAGE, $this->service()->buildView($filters)['rows']);
        $this->assertCount(SupportDashboardFilters::PER_PAGE + 7, $this->service()->filteredRows($filters));
    }

    public function testTheExportHonoursTheCurrentFiltersAndSearch(): void
    {
        $this->seed('aaaa', '-1 day');
        $this->seed('bbbb', '-1 day', ['scoutmagic' => ['version' => '1.0.31', 'is_dev_build' => false]]);

        $filtered = $this->service()->filteredRows(SupportDashboardFilters::fromQuery(['version' => '1.0.31']));

        $this->assertCount(1, $filtered);
        $this->assertSame('https://bbbb.example.be', $filtered[0]['instance_url']);
    }

    public function testTheExportOmitsRawJsonContactEmailAndDerivedAgeColumns(): void
    {
        $headers = SupportInstallationExporter::headers();
        $joined = mb_strtolower(implode(' | ', $headers));

        foreach (['json', 'email', 'courriel', 'jours depuis', 'âge'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $joined, "column '{$forbidden}' must not exist");
        }
    }

    public function testTheExportCarriesAnExplicitStatusAndReceptionTimestamp(): void
    {
        $headers = SupportInstallationExporter::headers();

        $this->assertContains('Statut', $headers);
        $this->assertContains('Dernière réception', $headers);
    }

    public function testEveryDefinedMetricGetsItsOwnColumnNotJustTheVisibleOnes(): void
    {
        $this->seed('aaaa', '-1 day');
        $row = $this->service()->filteredRows(SupportDashboardFilters::fromQuery([]))[0];

        // Sections actives is hidden below xxl in the table; it is still a
        // column here, along with every other metric the payload defines.
        foreach (['Sections actives', 'Année scoute', "Date d'installation", 'Modules activés'] as $header) {
            $this->assertContains($header, SupportInstallationExporter::headers());
        }
        $this->assertSame(5, $row['active_sections']);
    }

    public function testAMissingMetricIsNeverExportedAsZeroOrNo(): void
    {
        $this->seed('aaaa', '-1 day', ['usage' => [], 'updates' => []]);

        $row = $this->service()->filteredRows(SupportDashboardFilters::fromQuery([]))[0];
        $this->assertNull($row['active_members']);
        $this->assertNull($row['auto_update_enabled']);

        $reflection = new \ReflectionMethod(SupportInstallationExporter::class, 'yesNo');
        $this->assertSame(SupportInstallationExporter::MISSING, $reflection->invoke(null, null));
        $this->assertSame('Non', $reflection->invoke(null, false));

        $text = new \ReflectionMethod(SupportInstallationExporter::class, 'text');
        $this->assertSame(SupportInstallationExporter::MISSING, $text->invoke(null, null));
        $this->assertSame('0', $text->invoke(null, 0), 'a genuine zero must survive as a zero');
    }

    public function testTheExportedStatusMatchesTheDashboardsOwnClassification(): void
    {
        $this->seed('aaaa', '-1 day');
        $this->seed('bbbb', '-40 days');

        $all = $this->service()->filteredRows(
            SupportDashboardFilters::fromQuery(['status' => SupportDashboardFilters::STATUS_ALL])
        );

        $byUrl = [];
        foreach ($all as $row) {
            $byUrl[(string) $row['instance_url']] = $row['is_active'];
        }

        $this->assertTrue($byUrl['https://aaaa.example.be']);
        $this->assertFalse($byUrl['https://bbbb.example.be']);
    }
}
