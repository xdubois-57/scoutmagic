<?php

declare(strict_types=1);

namespace Tests\Core\Security\HumanCheck;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Core\Security\HumanCheck\HumanCheckRateLimitRepository;
use Core\Security\HumanCheck\HumanCheckService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class HumanCheckServiceTest extends TestCase
{
    private const FORM_KEY = 'test_form';

    private EncryptionService $encryption;
    private HumanCheckService $service;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $this->pdo = $pdo;
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $settings = new SettingService(new SettingRepository($pdo));
        $settings->register('human_check_min_delay_seconds', '2', 'number', 'Délai minimum', 'Description de test.');
        $settings->register('human_check_form_validity_seconds', '100', 'number', 'Durée de validité', 'Description de test.');
        $settings->register('human_check_rate_limit_window_minutes', '10', 'number', 'Fenêtre', 'Description de test.');
        $settings->register('human_check_rate_limit_max_attempts', '3', 'number', 'Max tentatives', 'Description de test.');

        $this->service = new HumanCheckService(
            $this->encryption,
            new HumanCheckRateLimitRepository($pdo),
            $settings,
            new JournalService(new JournalRepository($pdo))
        );
    }

    /**
     * Reproduces exactly what a rendered partials/human_check_fields.
     * html.twig + HumanCheckService::generateChallenge() would submit, but
     * lets each test control the timestamp/signature/trap value directly
     * — the only way to exercise "expired" or "forged" without sleeping.
     *
     * @return array<string, string>
     */
    private function buildFormData(int $timestamp, string $trapValue = '', ?string $signatureOverride = null): array
    {
        $trapField = 'hc_test_trap';
        // Same construction as HumanCheckService::sign() (private) — 'v1'
        // is its TOKEN_VERSION constant, duplicated here deliberately
        // (same convention as CsrfGuard's '_csrf_token' literal).
        $signature = $signatureOverride ?? $this->encryption->blindIndex('v1:' . self::FORM_KEY . ':' . $trapField . ':' . $timestamp, 'human_check_sign');
        $token = base64_encode((string) json_encode(['f' => $trapField, 't' => $timestamp, 's' => $signature]));

        return ['human_check_token' => $token, $trapField => $trapValue];
    }

    public function testGeneratedChallengeIsAcceptedWhenSubmittedAfterTheMinimumDelay(): void
    {
        $formData = $this->buildFormData(time() - 10);

        $result = $this->service->verify(self::FORM_KEY, false, $formData, '203.0.113.1');

        $this->assertTrue($result->accepted);
    }

    public function testHoneypotFilledIsRejected(): void
    {
        $formData = $this->buildFormData(time() - 10, trapValue: 'I am a bot');

        $result = $this->service->verify(self::FORM_KEY, false, $formData, '203.0.113.2');

        $this->assertFalse($result->accepted);
        $this->assertSame('honeypot_filled', $result->reason);
    }

    public function testSubmittedBeforeTheMinimumDelayIsRejected(): void
    {
        $formData = $this->buildFormData(time());

        $result = $this->service->verify(self::FORM_KEY, false, $formData, '203.0.113.3');

        $this->assertFalse($result->accepted);
        $this->assertSame('submitted_too_fast', $result->reason);
    }

    public function testExpiredFormIsRejected(): void
    {
        $formData = $this->buildFormData(time() - 1000);

        $result = $this->service->verify(self::FORM_KEY, false, $formData, '203.0.113.4');

        $this->assertFalse($result->accepted);
        $this->assertSame('form_expired', $result->reason);
    }

    public function testForgedTimestampWithInvalidSignatureIsRejected(): void
    {
        $formData = $this->buildFormData(time() - 10, signatureOverride: str_repeat('0', 64));

        $result = $this->service->verify(self::FORM_KEY, false, $formData, '203.0.113.5');

        $this->assertFalse($result->accepted);
        $this->assertSame('invalid_signature', $result->reason);
    }

    public function testMissingTokenIsRejected(): void
    {
        $result = $this->service->verify(self::FORM_KEY, false, [], '203.0.113.6');

        $this->assertFalse($result->accepted);
        $this->assertSame('invalid_token', $result->reason);
    }

    public function testAcceptsUpToTheRateLimitThenRejectsBeyondIt(): void
    {
        $ip = '203.0.113.7';

        for ($i = 0; $i < 3; $i++) {
            $result = $this->service->verify(self::FORM_KEY, false, $this->buildFormData(time() - 10), $ip);
            $this->assertTrue($result->accepted, "Attempt {$i} should be accepted (under the limit)");
        }

        $result = $this->service->verify(self::FORM_KEY, false, $this->buildFormData(time() - 10), $ip);

        $this->assertFalse($result->accepted);
        $this->assertSame('rate_limited', $result->reason);
    }

    public function testRateLimitIsScopedPerIp(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->service->verify(self::FORM_KEY, false, $this->buildFormData(time() - 10), '203.0.113.8');
        }

        $result = $this->service->verify(self::FORM_KEY, false, $this->buildFormData(time() - 10), '203.0.113.9');

        $this->assertTrue($result->accepted);
    }

    /**
     * Issue #1128: an IPv6 subscriber owns a whole /64 and can use a fresh
     * address for every submission — the counter must still fill.
     */
    public function testRateLimitCountsAnIpv6SubscriberAcrossItsWholePrefix(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $result = $this->service->verify(
                self::FORM_KEY,
                false,
                $this->buildFormData(time() - 10),
                '2001:db8:aaaa:1::' . dechex($i)
            );
            $this->assertTrue($result->accepted);
        }

        $sameSubscriber = $this->service->verify(
            self::FORM_KEY,
            false,
            $this->buildFormData(time() - 10),
            '2001:db8:aaaa:1:ffff:ffff:ffff:ffff'
        );
        $otherSubscriber = $this->service->verify(
            self::FORM_KEY,
            false,
            $this->buildFormData(time() - 10),
            '2001:db8:aaaa:2::1'
        );

        $this->assertFalse($sameSubscriber->accepted);
        $this->assertSame('rate_limited', $sameSubscriber->reason);
        $this->assertTrue($otherSubscriber->accepted);
    }

    /** A form may bring its own ceiling and window, in place of the settings (issue #1267). */
    public function testAFormMayCarryItsOwnCeilingInsteadOfTheSetting(): void
    {
        $ip = '203.0.113.20';

        // The setting says 3; this form says 5.
        for ($i = 0; $i < 5; $i++) {
            $result = $this->service->verify(self::FORM_KEY, false, $this->buildFormData(time() - 10), $ip, true, 60, 5);
            $this->assertTrue($result->accepted, "Attempt {$i} is under this form's own ceiling");
        }

        $result = $this->service->verify(self::FORM_KEY, false, $this->buildFormData(time() - 10), $ip, true, 60, 5);

        $this->assertFalse($result->accepted);
        $this->assertSame('rate_limited', $result->reason);
    }

    /**
     * No override counts further back than the purge keeps (issue #1267): a
     * window of ten hours is held to one, so a row from two hours ago — which
     * the purge may already have taken — never counts against the source.
     */
    public function testAnOverrideIsHeldToTheLongestWindowThePurgeKeeps(): void
    {
        $ip = '203.0.113.30';
        $ipHash = $this->encryption->blindIndex(\Core\Security\RateLimitAddress::key($ip), 'human_check_ip');
        $this->pdo->prepare('INSERT INTO human_check_rate_limits (ip_hash, form_key, created_at) VALUES (?, ?, ?)')
            ->execute([$ipHash, self::FORM_KEY, date('Y-m-d H:i:s', time() - 2 * 3600)]);

        $result = $this->service->verify(self::FORM_KEY, false, $this->buildFormData(time() - 10), $ip, true, 600, 1);

        $this->assertSame(60, HumanCheckService::LONGEST_WINDOW_MINUTES);
        $this->assertTrue($result->accepted, 'a two-hour-old row is outside the longest window');
    }

    public function testWithoutAnOverrideTheSettingsStillApply(): void
    {
        $ip = '203.0.113.21';

        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($this->service->verify(self::FORM_KEY, false, $this->buildFormData(time() - 10), $ip)->accepted);
        }

        $this->assertFalse($this->service->verify(self::FORM_KEY, false, $this->buildFormData(time() - 10), $ip)->accepted);
    }

    public function testEnforceRateLimitFalseSkipsTheThirdBarrier(): void
    {
        $ip = '203.0.113.10';

        for ($i = 0; $i < 5; $i++) {
            $result = $this->service->verify(self::FORM_KEY, false, $this->buildFormData(time() - 10), $ip, false);
            $this->assertTrue($result->accepted, "Attempt {$i} should be accepted — rate limiting is disabled for this call site");
        }
    }

    /**
     * @dataProvider identifiedSessionCases
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('identifiedSessionCases')]
    public function testIdentifiedSessionAlwaysAcceptsRegardlessOfBarrier(array $formData, ?string $ip): void
    {
        $result = $this->service->verify(self::FORM_KEY, true, $formData, $ip);

        $this->assertTrue($result->accepted);
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: ?string}>
     */
    public static function identifiedSessionCases(): array
    {
        return [
            'honeypot filled' => [self::buildFormDataStatic(time() - 10, 'bot'), '198.51.100.1'],
            'submitted too fast' => [self::buildFormDataStatic(time()), '198.51.100.2'],
            'expired' => [self::buildFormDataStatic(time() - 1000), '198.51.100.3'],
            'forged signature' => [self::buildFormDataStatic(time() - 10, '', str_repeat('0', 64)), '198.51.100.4'],
            'no token at all' => [[], '198.51.100.5'],
        ];
    }

    /**
     * Data providers run before setUp() builds $this->encryption, so this
     * static twin of buildFormData() constructs its own EncryptionService
     * instance — same fixed test keys as setUp(), so the signatures still
     * verify against the real service under test.
     *
     * @return array<string, string>
     */
    private static function buildFormDataStatic(int $timestamp, string $trapValue = '', ?string $signatureOverride = null): array
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $trapField = 'hc_test_trap';
        $signature = $signatureOverride ?? $encryption->blindIndex('v1:' . self::FORM_KEY . ':' . $trapField . ':' . $timestamp, 'human_check_sign');
        $token = base64_encode((string) json_encode(['f' => $trapField, 't' => $timestamp, 's' => $signature]));

        return ['human_check_token' => $token, $trapField => $trapValue];
    }
}
