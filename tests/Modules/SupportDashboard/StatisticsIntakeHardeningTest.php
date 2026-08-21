<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use PHPUnit\Framework\Attributes\DataProvider;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\SupportDashboard\Repository\SupportInstallationRepository;
use Modules\SupportDashboard\Repository\SupportMonthlyAggregateRepository;
use Modules\SupportDashboard\Repository\SupportReportRateLimitRepository;
use Modules\SupportDashboard\Service\StatisticsIntakeService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * `POST /api/statistics` is `role_min: public` and takes a JSON document
 * from a stranger. Its contract is a status code and nothing else
 * (ARCHITECTURE.md §8.49), which means no shape of body may ever get it to
 * fault, and no value out of that body may ever reach a surface that treats
 * it as more than text.
 *
 * The companion file StatisticsIntakeServiceTest covers the happy path,
 * the guard order and trust-on-first-use; this one covers what a crafted
 * payload can and cannot do.
 */
class StatisticsIntakeHardeningTest extends TestCase
{
    private \PDO $pdo;
    private StatisticsIntakeService $service;
    private SupportInstallationRepository $installations;

    protected function setUp(): void
    {
        SupportDashboardTestHelper::ensureAutoloadable();

        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SupportDashboardTestHelper::createTables($this->pdo);

        $this->installations = new SupportInstallationRepository($this->pdo);
        $this->service = new StatisticsIntakeService(
            $this->installations,
            new SupportReportRateLimitRepository($this->pdo),
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)),
            new JournalService(new JournalRepository($this->pdo)),
            new SupportMonthlyAggregateRepository($this->pdo)
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'statistics_schema_version' => 1,
            'installation_id' => 'abcdef0123456789',
            'instance_url' => 'https://unite.example.be',
            'scoutmagic' => ['version' => '1.0.33', 'is_dev_build' => false],
            'usage' => ['active_members' => 42, 'active_sections' => 4],
            'lifecycle' => ['installed_at' => '2025-09-01T10:00:00+00:00', 'last_upgraded_at' => null],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function receive(array $payload, string $secret = 'a-shared-secret'): \Modules\SupportDashboard\Service\StatisticsIntakeResult
    {
        return $this->service->receive(
            (string) json_encode($payload),
            'Bearer ' . $secret,
            '198.51.100.7',
            true
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function storedRow(string $installationId = 'abcdef0123456789'): array
    {
        $row = $this->installations->findByInstallationId($installationId);
        $this->assertIsArray($row);

        return $row;
    }

    // ---------------------------------------------------------------
    // instance_url: the one field the dashboard turns into a link
    // ---------------------------------------------------------------

    /**
     * A `javascript:` URL is a perfectly valid string that Twig's HTML
     * escaping does nothing about. Stored, it would have appeared on
     * /support-dashboard as `<a href="javascript:…">` — a superadmin one
     * click from running a stranger's script in the receiver's own origin.
     *
     */
    #[DataProvider('nonHttpUrls')]
    public function testAnInstanceUrlThatIsNotHttpIsStoredAsNotReported(string $url): void
    {
        $result = $this->receive($this->payload(['instance_url' => $url]));

        $this->assertTrue($result->accepted, 'a bad URL is one optional field, never a reason to lose the report');
        $this->assertNull($this->storedRow()['instance_url']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonHttpUrls(): array
    {
        return [
            'javascript' => ['javascript:alert(document.cookie)'],
            'javascript uppercase' => ['JavaScript:alert(1)'],
            'data' => ['data:text/html,<script>alert(1)</script>'],
            'vbscript' => ['vbscript:msgbox(1)'],
            'file' => ['file:///etc/passwd'],
            'no scheme at all' => ['unite.example.be'],
        ];
    }

    public function testHttpAndHttpsInstanceUrlsAreKept(): void
    {
        $this->receive($this->payload(['instance_url' => 'https://unite.example.be/']));
        $this->assertSame('https://unite.example.be/', $this->storedRow()['instance_url']);

        $this->receive($this->payload([
            'installation_id' => 'fedcba9876543210',
            'instance_url' => 'http://ancienne.example.be',
        ]));
        $this->assertSame('http://ancienne.example.be', $this->storedRow('fedcba9876543210')['instance_url']);
    }

    /**
     * The verbatim document is kept whatever the denormalised columns end
     * up holding — the detail dialog shows it as plain text, and a receiver
     * that quietly dropped what it did not like would be lying about what
     * it received.
     */
    public function testTheRawPayloadStillCarriesTheValueThatWasRefusedAsAColumn(): void
    {
        $this->receive($this->payload(['instance_url' => 'javascript:alert(1)']));

        $this->assertStringContainsString('javascript:alert(1)', (string) $this->storedRow()['payload']);
    }

    // ---------------------------------------------------------------
    // Out-of-range values: an INT UNSIGNED / DATETIME column is narrower
    // than JSON is
    // ---------------------------------------------------------------

    /**
     */
    #[DataProvider('outOfRangeCounters')]
    public function testACounterOutsideTheColumnsRangeBecomesNotReported(int $value): void
    {
        $result = $this->receive($this->payload(['usage' => ['active_members' => $value, 'active_sections' => 1]]));

        $this->assertTrue($result->accepted);
        $this->assertNull($this->storedRow()['active_members']);
        $this->assertSame(1, (int) $this->storedRow()['active_sections']);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function outOfRangeCounters(): array
    {
        return [
            'negative' => [-1],
            'beyond INT UNSIGNED' => [4294967296],
            'PHP_INT_MAX' => [PHP_INT_MAX],
        ];
    }

    public function testZeroIsAReportedValueAndSurvives(): void
    {
        $this->receive($this->payload(['usage' => ['active_members' => 0, 'active_sections' => 0]]));

        $row = $this->storedRow();
        $this->assertNotNull($row['active_members']);
        $this->assertSame(0, (int) $row['active_members']);
    }

    /**
     */
    #[DataProvider('outOfRangeDates')]
    public function testADateOutsideTheDatetimeRangeBecomesNotReported(string $value): void
    {
        $result = $this->receive($this->payload([
            'lifecycle' => ['installed_at' => $value, 'last_upgraded_at' => null],
        ]));

        $this->assertTrue($result->accepted);
        $this->assertNull($this->storedRow()['installed_at']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function outOfRangeDates(): array
    {
        return [
            'year zero' => ['0000-01-01T00:00:00+00:00'],
            'negative year' => ['-5000-01-01T00:00:00+00:00'],
            'year 900' => ['0900-06-01T00:00:00+00:00'],
            'unparseable' => ['pas une date'],
        ];
    }

    public function testAnOrdinaryDateIsNormalisedToUtc(): void
    {
        $this->receive($this->payload([
            'lifecycle' => ['installed_at' => '2025-09-01T12:00:00+02:00', 'last_upgraded_at' => null],
        ]));

        $this->assertSame('2025-09-01 10:00:00', $this->storedRow()['installed_at']);
    }

    // ---------------------------------------------------------------
    // Unknown fields
    // ---------------------------------------------------------------

    /**
     * A sender one version ahead must keep working: unknown fields are
     * warned about, never a rejection.
     */
    public function testAnUnknownFieldIsWarnedAboutAndNeverRejected(): void
    {
        $result = $this->receive($this->payload(['something_from_v2' => ['a' => 1]]));

        $this->assertTrue($result->accepted);
        $this->assertSame(['something_from_v2'], $result->unknownFields);
    }

    /**
     * The names come verbatim out of a stranger's JSON and land in
     * `event_log`'s context column. A 64 KB body can carry thousands of
     * them; the warning exists to say "somebody is ahead of us", not to
     * mirror an arbitrary document into the journal.
     */
    public function testTheUnknownFieldListIsBoundedAndSaysHowMuchItLeftOut(): void
    {
        $extra = [];
        for ($i = 0; $i < 100; $i++) {
            $extra['field_' . $i] = $i;
        }

        $unknown = StatisticsIntakeService::unknownFieldsOf($this->payload($extra));

        $this->assertCount(21, $unknown, '20 names plus the overflow marker');
        $this->assertSame('… (+80)', end($unknown));
    }

    public function testAVeryLongUnknownFieldNameIsTruncated(): void
    {
        $unknown = StatisticsIntakeService::unknownFieldsOf(
            $this->payload([str_repeat('z', 500) => 1])
        );

        $this->assertCount(1, $unknown);
        $this->assertSame(64, mb_strlen($unknown[0]));
    }

    // ---------------------------------------------------------------
    // Whatever else a crafted body does, the endpoint must answer, not fault
    // ---------------------------------------------------------------

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('hostilePayloads')]
    public function testNoCraftedPayloadEverFaultsTheEndpoint(array $payload): void
    {
        $result = $this->receive($payload);

        // Accepted or rejected is the service's business; what matters here
        // is that a status came back at all rather than an exception
        // escaping a public route.
        $this->assertContains($result->statusCode, [204, 400, 401, 413, 429]);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function hostilePayloads(): array
    {
        return [
            'every counter hostile' => [[
                'statistics_schema_version' => 1,
                'installation_id' => 'aaaaaaaabbbbbbbb',
                'usage' => ['active_members' => -2147483648, 'active_sections' => PHP_INT_MIN],
                'lifecycle' => ['installed_at' => '-9999-01-01', 'last_upgraded_at' => '99999-01-01'],
            ]],
            'strings where numbers belong' => [[
                'statistics_schema_version' => 1,
                'installation_id' => 'aaaaaaaabbbbbbbb',
                'usage' => ['active_members' => '42', 'active_sections' => true],
            ]],
            'over-long denormalised strings' => [[
                'statistics_schema_version' => 1,
                'installation_id' => 'aaaaaaaabbbbbbbb',
                'scoutmagic' => ['version' => str_repeat('9', 400), 'is_dev_build' => 'yes'],
                'updates' => ['auto_update_enabled' => 1, 'auto_update_level' => str_repeat('p', 400)],
                'scout_year' => ['label' => str_repeat('a', 400)],
                'installation' => ['method' => str_repeat('m', 400)],
            ]],
            'modules that are not module shapes' => [[
                'statistics_schema_version' => 1,
                'installation_id' => 'aaaaaaaabbbbbbbb',
                'modules' => [['id' => 42], 'not an array', null, ['enabled' => true]],
            ]],
        ];
    }

    /**
     * An over-long version or label is cut to what the column holds rather
     * than refused: the point of the denormalised columns is filtering and
     * sorting, and the untouched value is in the raw payload either way.
     */
    public function testOverLongDenormalisedStringsAreTruncatedToTheirColumn(): void
    {
        $this->receive($this->payload([
            'scoutmagic' => ['version' => str_repeat('9', 400), 'is_dev_build' => false],
            'scout_year' => ['label' => str_repeat('a', 400)],
        ]));

        $row = $this->storedRow();
        $this->assertSame(50, mb_strlen((string) $row['scoutmagic_version']));
        $this->assertSame(50, mb_strlen((string) $row['scout_year_label']));
    }
}
