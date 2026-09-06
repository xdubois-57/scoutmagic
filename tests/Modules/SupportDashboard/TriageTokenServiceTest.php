<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Modules\SupportDashboard\Service\TriageTokenService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The credential the GitHub triage presents (ARCHITECTURE.md §8.49sexies).
 *
 * What matters: the token is readable exactly once, nothing but its hash
 * is ever stored, and revoking or re-issuing makes the previous token
 * worthless at once.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class TriageTokenServiceTest extends TestCase
{
    private \PDO $pdo;
    private TriageTokenService $service;
    private SettingService $settings;

    protected function setUp(): void
    {
        SupportDashboardTestHelper::ensureAutoloadable();
        $this->pdo = DatabaseTestHelper::createTestDatabase();

        // The row module.json declares, as the module loader would create
        // it: `secret`, not editable.
        $repository = new SettingRepository($this->pdo);
        $repository->insert(
            TriageTokenService::MODULE_ID,
            TriageTokenService::SETTING_KEY,
            '',
            'secret',
            'Jeton de triage GitHub (empreinte)',
            'Empreinte du jeton.',
            null,
            null,
            false,
            0
        );

        $this->settings = new SettingService($repository);
        $this->service = new TriageTokenService(
            $this->settings,
            new JournalService(new JournalRepository($this->pdo))
        );
    }

    public function testNothingMatchesWhileNoTokenIsConfigured(): void
    {
        $this->assertFalse($this->service->isConfigured());
        $this->assertFalse($this->service->matches(''));
        $this->assertFalse($this->service->matches(str_repeat('a', 64)));
    }

    public function testIssuingReturnsTheTokenAndStoresOnlyItsHash(): void
    {
        $token = $this->service->issue();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertTrue($this->service->isConfigured());
        $this->assertTrue($this->service->matches($token));
        $this->assertFalse($this->service->matches(strrev($token)));

        $stored = (string) $this->settings->get(TriageTokenService::SETTING_KEY, TriageTokenService::MODULE_ID);
        $this->assertSame(hash('sha256', $token), $stored);
        $this->assertStringNotContainsString($token, $stored);

        // And nowhere else either: the journal records the act, never
        // the credential.
        $this->assertStringNotContainsString($token, $this->journalDump());
    }

    public function testIssuingAgainReplacesThePreviousToken(): void
    {
        $first = $this->service->issue();
        $second = $this->service->issue();

        $this->assertNotSame($first, $second);
        $this->assertFalse($this->service->matches($first));
        $this->assertTrue($this->service->matches($second));
    }

    public function testRevokingLeavesNothingToMatch(): void
    {
        $token = $this->service->issue();
        $this->service->revoke();

        $this->assertFalse($this->service->isConfigured());
        $this->assertFalse($this->service->matches($token));
    }

    public function testEveryChangeIsJournaledAtSecurityLevel(): void
    {
        $this->service->issue();
        $this->service->revoke();

        $rows = $this->pdo->query(
            "SELECT event_type, level FROM event_log WHERE category = 'support_dashboard' ORDER BY id"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertSame(
            [
                ['event_type' => 'support_triage_token_issued', 'level' => 'security'],
                ['event_type' => 'support_triage_token_revoked', 'level' => 'security'],
            ],
            $rows
        );
    }

    private function journalDump(): string
    {
        $rows = $this->pdo->query('SELECT description, context FROM event_log')->fetchAll(\PDO::FETCH_ASSOC);

        return (string) json_encode($rows);
    }
}
