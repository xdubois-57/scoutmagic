<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\SupportDashboard\Repository\SupportInstallationRepository;
use Modules\SupportDashboard\Repository\SupportReportRateLimitRepository;
use Modules\SupportDashboard\Service\StatisticsIntakeResult;
use Modules\SupportDashboard\Service\StatisticsIntakeService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

class StatisticsIntakeServiceTest extends TestCase
{
    private \PDO $pdo;
    private StatisticsIntakeService $service;
    private SupportInstallationRepository $installations;
    private SupportReportRateLimitRepository $rateLimits;

    private const INSTALLATION_ID = '0a1b2c3d4e5f60718293a4b5c6d7e8f9';
    private const SECRET = 'f1e2d3c4b5a6978869504132231405f6f1e2d3c4b5a6978869504132231405f6';

    protected function setUp(): void
    {
        SupportDashboardTestHelper::ensureAutoloadable();
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SupportDashboardTestHelper::createTables($this->pdo);

        $this->installations = new SupportInstallationRepository($this->pdo);
        $this->rateLimits = new SupportReportRateLimitRepository($this->pdo);

        $this->service = new StatisticsIntakeService(
            $this->installations,
            $this->rateLimits,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)),
            new JournalService(new JournalRepository($this->pdo))
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function payload(array $overrides = []): string
    {
        return (string) json_encode(array_replace([
            'statistics_schema_version' => 1,
            'installation_id' => self::INSTALLATION_ID,
            'instance_url' => 'https://unite-exemple.be',
            'generated_at' => '2026-08-20T03:00:00+00:00',
            'scoutmagic' => ['version' => '1.0.33', 'is_dev_build' => false],
            'scout_year' => ['label' => '2026-2027'],
            'usage' => ['active_members' => 142, 'active_sections' => 7],
            'modules' => [['id' => 'calendar', 'enabled' => true, 'version' => '1.2.0']],
            'installation' => ['method' => 'layout_a'],
            'runtime' => ['php_version' => '8.4.3'],
            'database' => ['engine' => 'mysql', 'version' => '8.0.36'],
            'host' => ['os_family' => 'Linux', 'cpu_architecture' => 'x86_64'],
            'security' => ['https_enabled' => true],
            'email' => ['mode' => 'smtp', 'configured' => true],
            'scheduler' => ['mode' => 'real_cron'],
            'updates' => ['auto_update_enabled' => true, 'auto_update_level' => 'patch'],
            'lifecycle' => ['installed_at' => '2025-09-01T10:12:00+00:00', 'last_upgraded_at' => null],
            'storage' => ['free_disk_bytes' => 12884901888],
        ], $overrides));
    }

    private function receive(string $body, string $secret = self::SECRET, string $ip = '203.0.113.1'): StatisticsIntakeResult
    {
        return $this->service->receive($body, 'Bearer ' . $secret, $ip, true);
    }

    /**
     * @return array<int, array{type: string, level: string, context: ?string}>
     */
    private function journalEntries(): array
    {
        $stmt = $this->pdo->query('SELECT event_type, level, context FROM event_log ORDER BY id');
        $rows = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];

        return array_map(
            static fn(array $row): array => [
                'type' => (string) $row['event_type'],
                'level' => (string) $row['level'],
                'context' => $row['context'] !== null ? (string) $row['context'] : null,
            ],
            $rows
        );
    }

    // --- registration and authentication ---

    public function testAnUnknownInstallationIsAcceptedAsAFirstRegistration(): void
    {
        $result = $this->receive($this->payload());

        $this->assertTrue($result->accepted);
        $this->assertTrue($result->firstRegistration);
        $this->assertSame(204, $result->statusCode);

        $row = $this->installations->findByInstallationId(self::INSTALLATION_ID);
        $this->assertNotNull($row);
        $this->assertSame('https://unite-exemple.be', $row['instance_url']);
        $this->assertSame('1.0.33', $row['scoutmagic_version']);
        $this->assertSame(142, (int) $row['active_members']);
    }

    public function testASecondReportWithTheSameSecretIsAcceptedAndOverwrites(): void
    {
        $this->receive($this->payload());
        $result = $this->receive($this->payload(['usage' => ['active_members' => 150, 'active_sections' => 8]]));

        $this->assertTrue($result->accepted);
        $this->assertFalse($result->firstRegistration);

        $row = $this->installations->findByInstallationId(self::INSTALLATION_ID);
        $this->assertNotNull($row);
        $this->assertSame(150, (int) $row['active_members']);

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM support_installations');
        $this->assertSame(1, (int) ($stmt !== false ? $stmt->fetchColumn() : 0));
    }

    public function testAReportWithTheWrongSecretIsRejectedWith401(): void
    {
        $this->receive($this->payload());
        $result = $this->receive($this->payload(), str_repeat('9', 64));

        $this->assertFalse($result->accepted);
        $this->assertSame(401, $result->statusCode);
        $this->assertSame(StatisticsIntakeResult::REJECT_BAD_CREDENTIALS, $result->rejectionReason);
    }

    public function testAWrongSecretDoesNotOverwriteTheStoredState(): void
    {
        $this->receive($this->payload());
        $this->receive($this->payload(['usage' => ['active_members' => 999, 'active_sections' => 99]]), str_repeat('9', 64));

        $row = $this->installations->findByInstallationId(self::INSTALLATION_ID);
        $this->assertNotNull($row);
        $this->assertSame(142, (int) $row['active_members']);
    }

    public function testAMissingAuthorizationHeaderIsRejected(): void
    {
        $result = $this->service->receive($this->payload(), '', '203.0.113.1', true);

        $this->assertFalse($result->accepted);
        $this->assertSame(401, $result->statusCode);
        $this->assertSame(StatisticsIntakeResult::REJECT_MISSING_CREDENTIALS, $result->rejectionReason);
    }

    public function testTheSecretIsNeverStoredInClearAnywhere(): void
    {
        $this->receive($this->payload());

        $stmt = $this->pdo->query('SELECT * FROM support_installations');
        $rows = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        $this->assertStringNotContainsString(self::SECRET, (string) json_encode($rows));

        $this->assertStringNotContainsString(self::SECRET, (string) json_encode($this->journalEntries()));

        $row = $this->installations->findByInstallationId(self::INSTALLATION_ID);
        $this->assertNotNull($row);
        $this->assertTrue(password_verify(self::SECRET, (string) $row['secret_hash']));
    }

    public function testTwoInstallationsAreIndependent(): void
    {
        $this->receive($this->payload());
        $other = str_repeat('b', 32);
        $this->receive($this->payload(['installation_id' => $other]), str_repeat('c', 64));

        $this->assertNotNull($this->installations->findByInstallationId(self::INSTALLATION_ID));
        $this->assertNotNull($this->installations->findByInstallationId($other));
    }

    // --- transport and size ---

    public function testAnUnencryptedRequestIsRejected(): void
    {
        $result = $this->service->receive($this->payload(), 'Bearer ' . self::SECRET, '203.0.113.1', false);

        $this->assertFalse($result->accepted);
        $this->assertSame(StatisticsIntakeResult::REJECT_INSECURE_TRANSPORT, $result->rejectionReason);
        $this->assertNull($this->installations->findByInstallationId(self::INSTALLATION_ID));
    }

    public function testAOneMegabyteBodyIsRejectedWithoutBeingParsed(): void
    {
        $huge = str_repeat('x', 1024 * 1024);

        $result = $this->receive($huge);

        $this->assertFalse($result->accepted);
        $this->assertSame(413, $result->statusCode);
        $this->assertSame(StatisticsIntakeResult::REJECT_PAYLOAD_TOO_LARGE, $result->rejectionReason);

        // Refused before the rate-limit counter is even touched, so a flood
        // of oversized bodies costs a strlen() and nothing more.
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM support_report_rate_limits');
        $this->assertSame(0, (int) ($stmt !== false ? $stmt->fetchColumn() : 1));
    }

    public function testAMalformedBodyIsRejected(): void
    {
        $result = $this->receive('{not json');

        $this->assertFalse($result->accepted);
        $this->assertSame(400, $result->statusCode);
        $this->assertSame(StatisticsIntakeResult::REJECT_MALFORMED, $result->rejectionReason);
    }

    /**
     * @return array<int, array{0: array<string, mixed>}>
     */
    public static function invalidPayloadProvider(): array
    {
        return [
            [['installation_id' => null]],
            [['installation_id' => 'short']],
            [['installation_id' => 'contains spaces and a very long value that goes on']],
            [['statistics_schema_version' => 'one']],
            [['statistics_schema_version' => 99]],
            [['usage' => 'not-an-object']],
            [['instance_url' => 12345]],
            [['modules' => 'not-a-list']],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPayloadProvider')]
    public function testStructurallyInvalidPayloadsAreRejected(array $overrides): void
    {
        $result = $this->receive($this->payload($overrides));

        $this->assertFalse($result->accepted);
        $this->assertSame(StatisticsIntakeResult::REJECT_MALFORMED, $result->rejectionReason);
    }

    // --- unknown and missing fields ---

    public function testAnUnknownFieldIsAcceptedWarnedAboutAndKeptInTheRawJson(): void
    {
        $result = $this->receive($this->payload(['a_field_from_the_future' => ['x' => 1]]));

        $this->assertTrue($result->accepted);
        $this->assertSame(['a_field_from_the_future'], $result->unknownFields);

        $entries = $this->journalEntries();
        $this->assertSame('statistics_report_received', $entries[0]['type']);
        $this->assertSame('warning', $entries[0]['level']);
        $this->assertStringContainsString('a_field_from_the_future', (string) $entries[0]['context']);

        $row = $this->installations->findByInstallationId(self::INSTALLATION_ID);
        $this->assertNotNull($row);
        $stored = json_decode((string) $row['payload'], true);
        $this->assertIsArray($stored);
        $this->assertSame(['x' => 1], $stored['a_field_from_the_future']);
    }

    /**
     * `module_usage` is a field this receiver understands (roadmap IT-04):
     * a sender carrying it must not have every report warned about as
     * « somebody is ahead of us », which is what the unknown-field list is
     * for.
     */
    public function testTheModuleUsageFieldIsUnderstoodRatherThanWarnedAbout(): void
    {
        $result = $this->receive($this->payload([
            'module_usage' => [
                'window_months' => 12,
                'modules' => [['id' => 'calendar', 'views' => 412]],
            ],
        ]));

        $this->assertTrue($result->accepted);
        $this->assertSame([], $result->unknownFields);
    }

    public function testAMissingOptionalFieldBecomesNullNotZeroOrFalse(): void
    {
        $this->receive($this->payload([
            'usage' => [],
            'updates' => [],
            'scout_year' => [],
            'installation' => [],
            'instance_url' => null,
        ]));

        $row = $this->installations->findByInstallationId(self::INSTALLATION_ID);
        $this->assertNotNull($row);

        foreach (['active_members', 'active_sections', 'auto_update_enabled', 'auto_update_level', 'scout_year_label', 'installation_method', 'instance_url'] as $column) {
            $this->assertNull($row[$column], "{$column} must be NULL, not 0/false/''");
        }
    }

    public function testAReportedFalseIsStoredAsFalseAndNotConfusedWithNull(): void
    {
        $this->receive($this->payload([
            'scoutmagic' => ['version' => '1.0.33', 'is_dev_build' => false],
            'updates' => ['auto_update_enabled' => false, 'auto_update_level' => 'patch'],
        ]));

        $row = $this->installations->findByInstallationId(self::INSTALLATION_ID);
        $this->assertNotNull($row);
        $this->assertNotNull($row['is_dev_build']);
        $this->assertSame(0, (int) $row['is_dev_build']);
        $this->assertNotNull($row['auto_update_enabled']);
        $this->assertSame(0, (int) $row['auto_update_enabled']);
    }

    public function testAValueThatBecomesUnavailableStopsBeingReported(): void
    {
        $this->receive($this->payload());
        $this->receive($this->payload(['usage' => []]));

        $row = $this->installations->findByInstallationId(self::INSTALLATION_ID);
        $this->assertNotNull($row);
        $this->assertNull($row['active_members']);
    }

    // --- rate limiting ---

    public function testExceedingTheRateLimitIsRejectedAndJournaledWithTheSourceIp(): void
    {
        for ($i = 0; $i < StatisticsIntakeService::RATE_LIMIT_MAX_REQUESTS; $i++) {
            $this->receive($this->payload(['installation_id' => str_pad((string) $i, 32, 'a', STR_PAD_LEFT)]), str_repeat((string) $i, 64));
        }

        $result = $this->receive($this->payload());

        $this->assertFalse($result->accepted);
        $this->assertSame(429, $result->statusCode);
        $this->assertSame(StatisticsIntakeResult::REJECT_RATE_LIMITED, $result->rejectionReason);

        $rejections = array_values(array_filter(
            $this->journalEntries(),
            static fn(array $entry): bool => $entry['type'] === 'statistics_report_rejected'
        ));
        $this->assertNotSame([], $rejections);
        $this->assertSame('warning', $rejections[0]['level']);
        $this->assertStringContainsString('203.0.113.1', (string) $rejections[0]['context']);
        $this->assertStringContainsString('rate_limited', (string) $rejections[0]['context']);
    }

    public function testTheRateLimitIsPerSourceAddress(): void
    {
        for ($i = 0; $i < StatisticsIntakeService::RATE_LIMIT_MAX_REQUESTS; $i++) {
            $this->receive($this->payload(['installation_id' => str_pad((string) $i, 32, 'a', STR_PAD_LEFT)]), str_repeat((string) $i, 64));
        }

        $this->assertFalse($this->receive($this->payload())->accepted);
        $this->assertTrue($this->receive($this->payload(), self::SECRET, '198.51.100.7')->accepted);
    }

    public function testTheRawSourceAddressIsNeverStoredInTheRateLimitTable(): void
    {
        $this->receive($this->payload());

        $stmt = $this->pdo->query('SELECT ip_hash FROM support_report_rate_limits');
        $hashes = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_COLUMN) : [];
        $this->assertNotSame([], $hashes);
        foreach ($hashes as $hash) {
            $this->assertNotSame('203.0.113.1', $hash);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $hash);
        }
    }

    // --- journal ---

    public function testAnAcceptedReportIsJournaledAtInfoLevel(): void
    {
        $this->receive($this->payload());

        $entries = $this->journalEntries();
        $this->assertCount(1, $entries);
        $this->assertSame('statistics_report_received', $entries[0]['type']);
        $this->assertSame('info', $entries[0]['level']);
    }

    // --- bearer parsing ---

    /**
     * @return array<int, array{0: string, 1: ?string}>
     */
    public static function authorizationHeaderProvider(): array
    {
        return [
            ['Bearer abcdef', 'abcdef'],
            ['bearer abcdef', 'abcdef'],
            ['  Bearer   abcdef  ', 'abcdef'],
            ['Basic abcdef', null],
            ['Bearer', null],
            ['Bearer a b', null],
            ['', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('authorizationHeaderProvider')]
    public function testBearerTokenExtraction(string $header, ?string $expected): void
    {
        $this->assertSame($expected, StatisticsIntakeService::extractBearerToken($header));
    }
}
