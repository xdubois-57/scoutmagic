<?php

declare(strict_types=1);

namespace Tests\Core\Statistics;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\SecretManager;
use Core\Statistics\InstallationIdentityService;
use Core\Statistics\StatisticsPayloadBuilder;
use Core\Statistics\StatisticsSender;
use Core\Statistics\StatisticsStateSettings;
use Core\Statistics\StatisticsTransportInterface;
use Core\Statistics\StatisticsTransportResponse;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * A transport that never touches the network and records exactly what it
 * was handed — the only way to assert "the secret travels in a header and
 * never in the body".
 */
final class RecordingTransport implements StatisticsTransportInterface
{
    /** @var array<int, array{url: string, body: string, token: string, userAgent: string}> */
    public array $calls = [];

    public function __construct(private ?StatisticsTransportResponse $response = null, private ?\Throwable $throwable = null)
    {
    }

    public function post(string $url, string $jsonBody, string $bearerToken, string $userAgent): StatisticsTransportResponse
    {
        $this->calls[] = ['url' => $url, 'body' => $jsonBody, 'token' => $bearerToken, 'userAgent' => $userAgent];

        if ($this->throwable !== null) {
            throw $this->throwable;
        }

        return $this->response ?? StatisticsTransportResponse::response(200, '');
    }
}

class StatisticsSenderTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;
    private SettingRepository $settingRepository;
    private string $projectRoot;
    private SecretManager $secretManager;
    private InstallationIdentityService $identityService;
    private JournalService $journal;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settingRepository = new SettingRepository($this->pdo);
        $this->settings = new SettingService($this->settingRepository);
        $this->journal = new JournalService(new JournalRepository($this->pdo));

        $this->projectRoot = sys_get_temp_dir() . '/scoutmagic-sender-' . bin2hex(random_bytes(6));
        mkdir($this->projectRoot . '/storage/keys', 0700, true);
        mkdir($this->projectRoot . '/storage/config', 0700, true);
        file_put_contents($this->projectRoot . '/VERSION', "1.0.33\n");

        $this->secretManager = new SecretManager(
            $this->projectRoot . '/storage/keys/master.key',
            $this->projectRoot . '/storage/config/secrets.enc'
        );
        $this->secretManager->generateMasterKey();
        $this->secretManager->writeSecrets([]);

        $this->settings->register('base_url', 'https://unite-exemple.be', 'url', 'L', 'D');
        $this->settings->register('statistics_enabled', '1', 'boolean', 'L', 'D');
        $this->settings->register('statistics_destination', 'https://www.scoutmagic.be', 'url', 'L', 'D');
        $this->settings->register('auto_update_enabled', '1', 'boolean', 'L', 'D');
        $this->settings->register('auto_update_level', 'minor', 'text', 'L', 'D');
        $this->settings->register(InstallationIdentityService::INSTALLATION_ID_SETTING, '', 'text', 'L', 'D', null, null, null, false);
        $this->settings->register(StatisticsStateSettings::LAST_SUCCESS_AT, '', 'text', 'L', 'D', null, null, null, false);
        $this->settings->register(StatisticsStateSettings::LAST_FAILURE_AT, '', 'text', 'L', 'D', null, null, null, false);
        $this->settings->register(StatisticsStateSettings::LAST_FAILURE_REASON, '', 'text', 'L', 'D', null, null, null, false);
        $this->settings->clearCache();

        $this->identityService = new InstallationIdentityService($this->settings, $this->secretManager);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->projectRoot);
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                unlink($path);
            }
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }

    private function sender(RecordingTransport $transport): StatisticsSender
    {
        return new StatisticsSender(
            $this->settings,
            new StatisticsPayloadBuilder($this->settings, $this->pdo, $this->identityService, $this->projectRoot),
            $this->identityService,
            $transport,
            $this->journal,
            $this->pdo,
            '1.0.33'
        );
    }

    private function set(string $key, string $value): void
    {
        $this->settingRepository->updateValue(null, $key, $value);
        $this->settings->clearCache();
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

    // --- guards ---

    public function testDisabledReportingSkipsSilentlyWithoutAJournalEntry(): void
    {
        $this->set('statistics_enabled', '0');
        $transport = new RecordingTransport();

        $result = $this->sender($transport)->send();

        $this->assertTrue($result->isSkipped());
        $this->assertSame('disabled', $result->reason);
        $this->assertSame([], $transport->calls);
        $this->assertSame([], $this->journalEntries());
        $this->settings->clearCache();
        $this->assertSame('', $this->settings->get(StatisticsStateSettings::LAST_FAILURE_REASON));
    }

    /**
     * An installation on the dev channel reports like any other. It used to
     * be skipped outright, which meant the one build most likely to break
     * was also the one nobody had a single fact about when its bug report
     * arrived. Nothing about the dev channel is hidden by reporting: the
     * payload carries `scoutmagic.is_dev_build` and
     * `updates.auto_update_level`, and the receiver buckets a dev build
     * separately from the release of the same number (ARCHITECTURE.md
     * §8.50).
     */
    public function testADevelopmentChannelInstallationReportsLikeAnyOther(): void
    {
        $this->set('auto_update_level', 'dev');
        $this->set('auto_update_enabled', '1');
        $transport = new RecordingTransport();

        $result = $this->sender($transport)->send();

        $this->assertTrue($result->isSent());
        $this->assertCount(1, $transport->calls);
    }

    public function testTheDevChannelIsReportedInThePayloadRatherThanHidden(): void
    {
        $this->set('auto_update_level', 'dev');
        $this->set('auto_update_enabled', '1');
        $transport = new RecordingTransport();

        $this->sender($transport)->send();

        $payload = json_decode($transport->calls[0]['body'], true);
        $this->assertSame('dev', $payload['updates']['auto_update_level']);
        $this->assertTrue($payload['updates']['auto_update_enabled']);
    }

    public function testADevBuildVersionIsReportedAsSuch(): void
    {
        file_put_contents($this->projectRoot . '/VERSION', "dev-abc1234\n");
        $transport = new RecordingTransport();

        $this->sender($transport)->send();

        $payload = json_decode($transport->calls[0]['body'], true);
        $this->assertTrue($payload['scoutmagic']['is_dev_build']);
    }

    public function testDevelopmentModeWithAutomaticUpdatesOffAlsoReports(): void
    {
        $this->set('auto_update_level', 'dev');
        $this->set('auto_update_enabled', '0');
        $transport = new RecordingTransport();

        $result = $this->sender($transport)->send();

        $this->assertTrue($result->isSent());
    }

    /**
     * The guard that actually keeps a developer's working copy out of the
     * receiver — and the reason removing the dev-mode one costs nothing. A
     * checkout lives on localhost, an IP, or a `.test`/`.local` name.
     */
    public function testADevChannelInstallationOnALocalHostIsStillSkipped(): void
    {
        $this->set('auto_update_level', 'dev');
        $this->set('base_url', 'https://scoutmagic.test');
        $transport = new RecordingTransport();

        $result = $this->sender($transport)->send();

        $this->assertTrue($result->isSkipped());
        $this->assertSame('non_public_host', $result->reason);
        $this->assertSame([], $transport->calls);
    }

    /**
     * @return array<int, array{0: string}>
     */
    public static function nonPublicHostProvider(): array
    {
        return [
            ['http://localhost'],
            ['https://localhost:8080'],
            ['https://127.0.0.1'],
            ['https://192.168.1.10'],
            ['https://[::1]'],
            ['https://scoutmagic.local'],
            ['https://scoutmagic.test'],
            ['https://scoutmagic.localhost'],
            ['https://scoutmagic.invalid'],
            ['https://scoutmagic.internal'],
            ['https://intranet'],
            [''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonPublicHostProvider')]
    public function testANonPublicHostSkips(string $baseUrl): void
    {
        $this->set('base_url', $baseUrl);
        $transport = new RecordingTransport();

        $result = $this->sender($transport)->send();

        $this->assertTrue($result->isSkipped());
        $this->assertSame('non_public_host', $result->reason);
        $this->assertSame([], $transport->calls);
    }

    public function testTheReceiverItselfDoesNotReportToItself(): void
    {
        $this->set('base_url', 'https://www.scoutmagic.be');
        $transport = new RecordingTransport();

        $result = $this->sender($transport)->send();

        $this->assertTrue($result->isSkipped());
        $this->assertSame('self_destination', $result->reason);
        $this->assertSame([], $transport->calls);
    }

    public function testAnUpdateInProgressSkips(): void
    {
        $this->pdo->prepare('INSERT INTO update_history (version_from, version_to, status) VALUES (?, ?, ?)')
            ->execute(['1.0.32', '1.0.33', 'installing']);
        $transport = new RecordingTransport();

        $result = $this->sender($transport)->send();

        $this->assertTrue($result->isSkipped());
        $this->assertSame('maintenance_in_progress', $result->reason);
        $this->assertSame([], $transport->calls);
    }

    /**
     * A row a crashed install left behind ("installing", never closed) is
     * debris, not maintenance: it used to block every report forever,
     * daily task and Support-page test button alike.
     */
    public function testAnAbandonedInProgressUpdateDoesNotBlockReporting(): void
    {
        $this->pdo->prepare('INSERT INTO update_history (version_from, version_to, status, started_at) VALUES (?, ?, ?, ?)')
            ->execute(['1.0.32', '1.0.33', 'installing', (new \DateTimeImmutable('-2 days'))->format('Y-m-d H:i:s')]);

        $this->assertTrue($this->sender(new RecordingTransport())->send()->isSent());
    }

    public function testACompletedUpdateDoesNotBlockReporting(): void
    {
        $this->pdo->prepare('INSERT INTO update_history (version_from, version_to, status) VALUES (?, ?, ?)')
            ->execute(['1.0.32', '1.0.33', 'completed']);

        $this->assertTrue($this->sender(new RecordingTransport())->send()->isSent());
    }

    /**
     * @return array<int, array{0: string}>
     */
    public static function maintenanceTaskProvider(): array
    {
        return [['install_update'], ['restore_backup'], ['full_reset']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('maintenanceTaskProvider')]
    public function testAPendingMaintenanceTaskSkips(string $taskKey): void
    {
        $this->pdo->prepare('INSERT INTO scheduled_actions (module_id, task_key, run_at, status) VALUES (?, ?, ?, ?)')
            ->execute(['core', $taskKey, '2026-08-20 03:00:00', 'pending']);
        $transport = new RecordingTransport();

        $result = $this->sender($transport)->send();

        $this->assertTrue($result->isSkipped());
        $this->assertSame('maintenance_in_progress', $result->reason);
        $this->assertSame([], $transport->calls);
    }

    /**
     * An automatic update waiting for its weekly slot is pending for up to
     * a week. Counting that as "maintenance in progress" silenced
     * reporting for the whole week on every installation with automatic
     * updates enabled — only a task already claimed or already due is
     * actually running.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('maintenanceTaskProvider')]
    public function testAMaintenanceTaskScheduledForLaterDoesNotBlockReporting(string $taskKey): void
    {
        $this->pdo->prepare('INSERT INTO scheduled_actions (module_id, task_key, run_at, status) VALUES (?, ?, ?, ?)')
            ->execute(['core', $taskKey, (new \DateTimeImmutable('+3 days'))->format('Y-m-d H:i:s'), 'pending']);

        $this->assertTrue($this->sender(new RecordingTransport())->send()->isSent());
    }

    public function testAMaintenanceTaskAlreadyRunningSkips(): void
    {
        $this->pdo->prepare('INSERT INTO scheduled_actions (module_id, task_key, run_at, status) VALUES (?, ?, ?, ?)')
            ->execute(['core', 'install_update', (new \DateTimeImmutable('+3 days'))->format('Y-m-d H:i:s'), 'processing']);
        $transport = new RecordingTransport();

        $result = $this->sender($transport)->send();

        $this->assertTrue($result->isSkipped());
        $this->assertSame('maintenance_in_progress', $result->reason);
        $this->assertSame([], $transport->calls);
    }

    public function testAnUnrelatedPendingTaskDoesNotBlockReporting(): void
    {
        $this->pdo->prepare('INSERT INTO scheduled_actions (module_id, task_key, run_at, status) VALUES (?, ?, ?, ?)')
            ->execute(['core', 'auto_backup', '2026-08-20 03:00:00', 'pending']);

        $this->assertTrue($this->sender(new RecordingTransport())->send()->isSent());
    }

    public function testASuccessfulSendLessThanADayAgoSkips(): void
    {
        $this->set(StatisticsStateSettings::LAST_SUCCESS_AT, (new \DateTimeImmutable('-3 hours'))->format(\DateTimeInterface::ATOM));
        $transport = new RecordingTransport();

        $result = $this->sender($transport)->send();

        $this->assertTrue($result->isSkipped());
        $this->assertSame('already_sent_today', $result->reason);
        $this->assertSame([], $transport->calls);
    }

    public function testASuccessfulSendMoreThanADayAgoDoesNotSkip(): void
    {
        $this->set(StatisticsStateSettings::LAST_SUCCESS_AT, (new \DateTimeImmutable('-30 hours'))->format(\DateTimeInterface::ATOM));

        $this->assertTrue($this->sender(new RecordingTransport())->send()->isSent());
    }

    public function testBenignSkipsAreNotSurfacedAsTheLatestProblem(): void
    {
        $this->set(StatisticsStateSettings::LAST_SUCCESS_AT, (new \DateTimeImmutable('-3 hours'))->format(\DateTimeInterface::ATOM));

        $this->sender(new RecordingTransport())->send();

        $this->settings->clearCache();
        $this->assertSame('', $this->settings->get(StatisticsStateSettings::LAST_FAILURE_REASON));
    }

    public function testABlockingSkipIsSurfacedOnTheSupportPage(): void
    {
        $this->set('base_url', 'https://scoutmagic.test');

        $this->sender(new RecordingTransport())->send();

        $this->settings->clearCache();
        $this->assertSame('non_public_host', $this->settings->get(StatisticsStateSettings::LAST_FAILURE_REASON));
        $this->assertNotSame('', $this->settings->get(StatisticsStateSettings::LAST_FAILURE_AT));
    }

    // --- transport ---

    public function testAnHttpDestinationFailsWithoutSending(): void
    {
        $this->set('statistics_destination', 'http://www.scoutmagic.be');
        $transport = new RecordingTransport();

        $result = $this->sender($transport)->send();

        $this->assertTrue($result->isFailed());
        $this->assertSame('insecure_destination', $result->reason);
        $this->assertSame([], $transport->calls);
    }

    public function testTheSecretTravelsInTheHeaderAndNeverInTheBody(): void
    {
        $transport = new RecordingTransport();
        $secret = $this->identityService->getSecret();
        $this->assertNotNull($secret);

        $this->sender($transport)->send();

        $this->assertCount(1, $transport->calls);
        $this->assertSame($secret, $transport->calls[0]['token']);
        $this->assertStringNotContainsString($secret, $transport->calls[0]['body']);
        $this->assertStringNotContainsString('statistics_secret', $transport->calls[0]['body']);
    }

    public function testTheRequestTargetsTheStatisticsEndpointWithAnIdentifyingUserAgent(): void
    {
        $transport = new RecordingTransport();

        $this->sender($transport)->send();

        $this->assertSame('https://www.scoutmagic.be/api/statistics', $transport->calls[0]['url']);
        $this->assertStringContainsString('ScoutMagic/1.0.33', $transport->calls[0]['userAgent']);
    }

    public function testATrailingSlashOnTheDestinationDoesNotDoubleUp(): void
    {
        $this->set('statistics_destination', 'https://www.scoutmagic.be/');
        $transport = new RecordingTransport();

        $this->sender($transport)->send();

        $this->assertSame('https://www.scoutmagic.be/api/statistics', $transport->calls[0]['url']);
    }

    public function testASuccessfulSendRecordsTheTimestampAndJournalsStatusAndDuration(): void
    {
        $result = $this->sender(new RecordingTransport(StatisticsTransportResponse::response(204, '')))->send();

        $this->assertTrue($result->isSent());
        $this->assertSame(204, $result->statusCode);

        $this->settings->clearCache();
        $this->assertStringEndsWith('+00:00', (string) $this->settings->get(StatisticsStateSettings::LAST_SUCCESS_AT));

        $entries = $this->journalEntries();
        $this->assertCount(1, $entries);
        $this->assertSame('statistics_sent', $entries[0]['type']);
        $this->assertSame('info', $entries[0]['level']);
        $context = json_decode((string) $entries[0]['context'], true);
        $this->assertSame([204], [$context['status']]);
        $this->assertArrayHasKey('duration_ms', $context);
    }

    public function testANonSuccessResponseIsRecordedAsAFailureAndNotRetried(): void
    {
        $transport = new RecordingTransport(StatisticsTransportResponse::response(500, 'Internal error'));

        $result = $this->sender($transport)->send();

        $this->assertTrue($result->isFailed());
        $this->assertStringStartsWith('http_500', (string) $result->reason);
        $this->assertCount(1, $transport->calls);

        $entries = $this->journalEntries();
        $this->assertSame('statistics_send_failed', $entries[0]['type']);
        $this->assertSame('warning', $entries[0]['level']);
    }

    public function testATransportLevelErrorIsRecordedAsAFailure(): void
    {
        $transport = new RecordingTransport(StatisticsTransportResponse::transportError('unreachable'));

        $result = $this->sender($transport)->send();

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('transport_error', (string) $result->reason);
    }

    public function testAThrowingTransportIsRecordedAsAFailureRatherThanCrashing(): void
    {
        $transport = new RecordingTransport(null, new \RuntimeException('boom'));

        $result = $this->sender($transport)->send();

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('boom', (string) $result->reason);
    }

    public function testTheStoredFailureReasonNeverContainsTheSecretEvenWhenTheServerEchoesIt(): void
    {
        $secret = $this->identityService->getSecret();
        $this->assertNotNull($secret);

        $transport = new RecordingTransport(
            StatisticsTransportResponse::response(401, 'Bearer token ' . $secret . ' is not recognised')
        );

        $result = $this->sender($transport)->send();

        $this->assertTrue($result->isFailed());
        $this->assertStringNotContainsString($secret, (string) $result->reason);
        $this->assertStringContainsString('[REDACTED]', (string) $result->reason);

        $this->settings->clearCache();
        $stored = (string) $this->settings->get(StatisticsStateSettings::LAST_FAILURE_REASON);
        $this->assertStringNotContainsString($secret, $stored);

        $journal = json_encode($this->journalEntries()) ?: '';
        $this->assertStringNotContainsString($secret, $journal);
    }

    public function testTheStoredFailureReasonIsBoundedAndFreeOfControlCharacters(): void
    {
        $transport = new RecordingTransport(
            StatisticsTransportResponse::response(500, "line one\nline two\t" . str_repeat('x', 500))
        );

        $result = $this->sender($transport)->send();

        $reason = (string) $result->reason;
        $this->assertLessThanOrEqual(200, mb_strlen($reason));
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $reason);
    }

    // --- destination guards (audit M4/M5/M6 applied to where the bearer
    // secret actually goes) ---

    /**
     * The sender already refuses to *report from* a non-public host. It had
     * nothing to say about the destination it reports *to* — which is where
     * the bearer secret is carried, in a header, from inside the hosting
     * network. Every other configured outbound endpoint in this codebase
     * (web push, LLM, S3) goes through a public-address check; this one now
     * does too.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('nonPublicDestinations')]
    public function testANonPublicDestinationIsAFailureAndNothingIsTransmitted(string $destination): void
    {
        $this->set('statistics_destination', $destination);

        $transport = new RecordingTransport();
        $result = $this->sender($transport)->send();

        $this->assertTrue($result->isFailed());
        $this->assertSame('non_public_destination', $result->reason);
        $this->assertSame([], $transport->calls, 'nothing may leave for a non-public destination');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonPublicDestinations(): array
    {
        return [
            'localhost' => ['https://localhost/'],
            'IPv4 literal' => ['https://192.168.1.10/'],
            'public IPv4 literal' => ['https://93.184.216.34/'],
            'IPv6 literal' => ['https://[::1]/'],
            'cloud metadata' => ['https://169.254.169.254/'],
            'single label' => ['https://intranet/'],
            'dot-local' => ['https://receveur.local/'],
            'dot-internal' => ['https://receveur.internal/'],
        ];
    }

    public function testAnOrdinaryPublicDestinationStillSends(): void
    {
        $this->set('statistics_destination', 'https://stats.example.be');

        $transport = new RecordingTransport();
        $result = $this->sender($transport)->send();

        $this->assertTrue($result->isSent());
        $this->assertSame('https://stats.example.be/api/statistics', $transport->calls[0]['url']);
    }

    /**
     * Order matters for the message the administrator gets: cleartext is
     * its own diagnosis with its own fix, and reporting it as "not public"
     * would send them looking at DNS instead of at the scheme.
     */
    public function testCleartextIsStillReportedAsItsOwnFailureNotAsANonPublicHost(): void
    {
        $this->set('statistics_destination', 'http://localhost/');

        $result = $this->sender(new RecordingTransport())->send();

        $this->assertSame('insecure_destination', $result->reason);
    }

    // --- failure reasons that are not valid UTF-8 ---

    /**
     * A remote error body is very often not valid UTF-8. `preg_replace()`
     * with the `/u` flag returns null on such a subject, and the cast that
     * followed turned that into an empty string — so the Support page
     * showed a failure with a blank "Motif", having thrown away even the
     * HTTP status the reason was built around.
     */
    public function testANonUtf8ResponseBodyStillYieldsAReadableReason(): void
    {
        $transport = new RecordingTransport(
            StatisticsTransportResponse::response(502, "\xFF\xFE Bad Gateway \x80\x81")
        );

        $result = $this->sender($transport)->send();

        $this->assertTrue($result->isFailed());
        $reason = (string) $result->reason;
        $this->assertNotSame('', $reason);
        $this->assertStringStartsWith('http_502', $reason);
        $this->assertStringContainsString('Bad Gateway', $reason);
    }

    public function testANonUtf8ResponseBodyIsStillScrubbedOfTheSecret(): void
    {
        $secret = $this->identityService->getSecret();
        $this->assertNotNull($secret);

        $transport = new RecordingTransport(
            StatisticsTransportResponse::response(401, "\xFF token " . $secret . " rejected")
        );

        $result = $this->sender($transport)->send();

        $this->assertStringNotContainsString($secret, (string) $result->reason);
        $this->assertStringContainsString('[REDACTED]', (string) $result->reason);
    }

    public function testANonUtf8TransportExceptionMessageStillYieldsAReason(): void
    {
        $transport = new RecordingTransport(null, new \RuntimeException("\xFF\xFE connexion perdue"));

        $result = $this->sender($transport)->send();

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('transport_error', (string) $result->reason);
        $this->assertStringContainsString('connexion perdue', (string) $result->reason);
    }

    /**
     * A transport that never got an answer it could read reports no status
     * at all — StreamStatisticsTransport answers that way for an
     * unreachable host and for a response with no parseable status line,
     * rather than letting a status of 0 through as the nonsense reason
     * "http_0".
     */
    public function testATransportErrorResponseIsReportedWithItsMessage(): void
    {
        $transport = new RecordingTransport(StatisticsTransportResponse::transportError('no_status_line'));

        $result = $this->sender($transport)->send();

        $this->assertTrue($result->isFailed());
        $this->assertSame('transport_error: no_status_line', $result->reason);
    }

    public function testATransportErrorMessageIsAlsoScrubbedOfTheSecret(): void
    {
        $secret = $this->identityService->getSecret();
        $this->assertNotNull($secret);

        $transport = new RecordingTransport(StatisticsTransportResponse::transportError('refused with ' . $secret));

        $result = $this->sender($transport)->send();

        $this->assertStringNotContainsString($secret, (string) $result->reason);
    }

    /**
     * A 2xx with an empty body is the receiver's documented answer (a bare
     * 204), and must not be mistaken for a transport failure.
     */
    public function testABare204IsASuccess(): void
    {
        $transport = new RecordingTransport(StatisticsTransportResponse::response(204, ''));

        $result = $this->sender($transport)->send();

        $this->assertTrue($result->isSent());
        $this->assertSame(204, $result->statusCode);
    }

    // --- sendTest(): the button on Configuration > Support ---

    /**
     * The reason the manual send exists at all: on the installation that IS
     * the receiver, the daily task skips itself, so nothing ever exercises
     * the payload, the bearer header and the intake endpoint together.
     */
    public function testATestSendMayTargetThisInstallationItself(): void
    {
        $this->set('base_url', 'https://www.scoutmagic.be');
        $transport = new RecordingTransport();

        $result = $this->sender($transport)->sendTest();

        $this->assertTrue($result->isSent());
        $this->assertSame('https://www.scoutmagic.be/api/statistics', $transport->calls[0]['url']);
    }

    public function testTheDailySendStillSkipsItselfWhenTheTestSendDoesNot(): void
    {
        $this->set('base_url', 'https://www.scoutmagic.be');

        $this->assertSame('self_destination', $this->sender(new RecordingTransport())->send()->reason);
    }

    /** A test that cannot be repeated is not a test. */
    public function testATestSendIgnoresTheOncePerDayInterval(): void
    {
        $this->set(StatisticsStateSettings::LAST_SUCCESS_AT, (new \DateTimeImmutable('-3 hours'))->format(\DateTimeInterface::ATOM));
        $transport = new RecordingTransport();

        $this->assertTrue($this->sender($transport)->sendTest()->isSent());
        $this->assertCount(1, $transport->calls);
    }

    public function testATestSendStillRespectsTheOptOut(): void
    {
        $this->set('statistics_enabled', '0');
        $transport = new RecordingTransport();

        $result = $this->sender($transport)->sendTest();

        $this->assertTrue($result->isSkipped());
        $this->assertSame('disabled', $result->reason);
        $this->assertSame([], $transport->calls);
    }

    /**
     * A staging clone pressing the button must no more register under the
     * production installation's identity than the daily task may.
     */
    public function testATestSendStillRefusesANonPublicBaseUrl(): void
    {
        $this->set('base_url', 'https://unite.test');
        $transport = new RecordingTransport();

        $result = $this->sender($transport)->sendTest();

        $this->assertTrue($result->isSkipped());
        $this->assertSame('non_public_host', $result->reason);
        $this->assertSame([], $transport->calls);
    }

    /**
     * The two halves of "I want to check reporting works from the
     * installation that is itself the receiver": `self_destination` is
     * already lifted for a manual send, and a scheduled-but-not-yet-due
     * install no longer counts as maintenance — together, the button now
     * actually sends instead of always answering « Une opération de
     * maintenance est en cours ».
     */
    public function testATestSendReachesTheReceiverWithAnUpdateMerelyScheduled(): void
    {
        $this->set('base_url', 'https://www.scoutmagic.be');
        $this->pdo->prepare('INSERT INTO scheduled_actions (module_id, task_key, run_at, status) VALUES (?, ?, ?, ?)')
            ->execute(['core', 'install_update', (new \DateTimeImmutable('+3 days'))->format('Y-m-d H:i:s'), 'pending']);
        $transport = new RecordingTransport();

        $this->assertTrue($this->sender($transport)->sendTest()->isSent());
        $this->assertCount(1, $transport->calls);
    }

    public function testATestSendStillRefusesDuringMaintenance(): void
    {
        $this->pdo->prepare('INSERT INTO update_history (version_from, version_to, status) VALUES (?, ?, ?)')
            ->execute(['1.0.32', '1.0.33', 'installing']);
        $transport = new RecordingTransport();

        $this->assertSame('maintenance_in_progress', $this->sender($transport)->sendTest()->reason);
        $this->assertSame([], $transport->calls);
    }

    /**
     * "État des envois" answers "is the *automatic* reporting healthy?".
     * A manual send — successful or not — must not be able to answer it,
     * and must not delay the next scheduled report by 24 h either.
     */
    public function testATestSendNeverWritesTheDailySendState(): void
    {
        $this->assertTrue($this->sender(new RecordingTransport())->sendTest()->isSent());
        $this->settings->clearCache();
        $this->assertSame('', (string) $this->settings->get(StatisticsStateSettings::LAST_SUCCESS_AT));

        $failing = new RecordingTransport(StatisticsTransportResponse::response(500, 'boom'));
        $this->assertTrue($this->sender($failing)->sendTest()->isFailed());
        $this->settings->clearCache();
        $this->assertSame('', (string) $this->settings->get(StatisticsStateSettings::LAST_FAILURE_AT));
        $this->assertSame('', (string) $this->settings->get(StatisticsStateSettings::LAST_FAILURE_REASON));
    }

    /** What leaves the site is recorded, whoever asked for it. */
    public function testATestSendIsJournaledUnderItsOwnEventType(): void
    {
        $this->sender(new RecordingTransport())->sendTest();
        $this->sender(new RecordingTransport(StatisticsTransportResponse::response(500, 'boom')))->sendTest();

        $this->assertSame(
            ['statistics_test_sent', 'statistics_test_send_failed'],
            array_column($this->journalEntries(), 'type')
        );
    }

    /**
     * A skipped test sent nothing, and the superadmin who pressed the button
     * is reading the answer on the next page — journaling it would only fill
     * the journal with somebody's clicks.
     */
    public function testASkippedTestSendIsNotJournaled(): void
    {
        $this->set('statistics_enabled', '0');

        $this->sender(new RecordingTransport())->sendTest();

        $this->assertSame([], $this->journalEntries());
    }

    /** The secret rides in the header on a test send too, never in the body. */
    public function testATestSendCarriesTheSecretInTheHeaderOnly(): void
    {
        $secret = $this->identityService->getSecret();
        $this->assertNotNull($secret);
        $transport = new RecordingTransport();

        $this->sender($transport)->sendTest();

        $this->assertSame($secret, $transport->calls[0]['token']);
        $this->assertStringNotContainsString($secret, $transport->calls[0]['body']);
    }
}
