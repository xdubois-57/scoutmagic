<?php

declare(strict_types=1);

namespace Tests\Core\Statistics;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Statistics\InstallationDateService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

class InstallationDateServiceTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        InstallationDateService::register($this->settings);
    }

    private function service(): InstallationDateService
    {
        return new InstallationDateService($this->settings, $this->pdo);
    }

    public function testRegisterCreatesANonEditableEmptySetting(): void
    {
        $row = (new SettingRepository($this->pdo))->findByModuleAndKey(null, InstallationDateService::SETTING_KEY);

        $this->assertNotNull($row);
        $this->assertSame('', $row['setting_value']);
        $this->assertSame(0, (int) $row['editable']);
    }

    public function testEmptyDatabaseFallsBackToNow(): void
    {
        $recorded = $this->service()->ensureRecorded();

        $this->assertNotNull($recorded);
        $elapsed = abs(time() - (int) (new \DateTimeImmutable($recorded))->format('U'));
        $this->assertLessThan(120, $elapsed);
    }

    public function testTheOldestJournalEntryIsUsedWhenTheJournalIsNotEmpty(): void
    {
        $this->insertJournalEntry('2019-09-01 08:00:00');
        $this->insertJournalEntry('2024-02-03 20:30:00');

        $recorded = $this->service()->ensureRecorded();

        $this->assertNotNull($recorded);
        $this->assertSame(
            '2019-09-01T08:00:00',
            (new \DateTimeImmutable($recorded))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d\TH:i:s')
        );
    }

    public function testTheRecordedValueIsIso8601Utc(): void
    {
        $this->insertJournalEntry('2019-09-01 08:00:00');

        $recorded = $this->service()->ensureRecorded();

        $this->assertNotNull($recorded);
        $this->assertStringEndsWith('+00:00', $recorded);
    }

    public function testASecondRunHasNoEffect(): void
    {
        $this->insertJournalEntry('2019-09-01 08:00:00');
        $first = $this->service()->ensureRecorded();

        $this->insertJournalEntry('2015-01-01 00:00:00');
        $second = $this->service()->ensureRecorded();

        $this->assertSame($first, $second);
    }

    public function testAnAlreadySetValueIsNeverOverwritten(): void
    {
        (new SettingRepository($this->pdo))->updateValue(null, InstallationDateService::SETTING_KEY, '2020-01-01T00:00:00+00:00');
        $this->settings->clearCache();

        $this->assertSame('2020-01-01T00:00:00+00:00', $this->service()->ensureRecorded());
    }

    private function insertJournalEntry(string $loggedAt): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO event_log (logged_at, category, event_type, level, description) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$loggedAt, 'core', 'test_event', 'info', 'Test']);
    }

    /**
     * The backfill reads the journal, which on an installation predating
     * this feature is the only birth certificate available. A journal that
     * cannot be read at all is not a reason to fail a boot — the honest
     * fallback is "now".
     */
    public function testAnUnreadableJournalFallsBackToNowRatherThanFailing(): void
    {
        $this->pdo->exec('DROP TABLE event_log');

        $recorded = $this->service()->ensureRecorded();

        $this->assertNotNull($recorded);
        $this->assertNotFalse(strtotime($recorded));
    }

    public function testAnUnparseableOldestEntryFallsBackToNow(): void
    {
        $this->pdo
            ->prepare('INSERT INTO event_log (category, event_type, level, description, logged_at) VALUES (?, ?, ?, ?, ?)')
            ->execute(['core', 'x', 'info', 'x', 'pas une date']);

        $recorded = $this->service()->ensureRecorded();

        $this->assertNotNull($recorded);
        $this->assertNotFalse(strtotime($recorded));
    }

    /**
     * The setting declares itself from this service precisely so
     * SetupController can write it before the composition root has ever
     * run. Called before that declaration, there is nowhere to record it —
     * null, not an exception, and certainly not a silent success.
     */
    public function testAnUnregisteredSettingRecordsNothingAndSaysSo(): void
    {
        $this->pdo->exec("DELETE FROM settings WHERE setting_key = '" . InstallationDateService::SETTING_KEY . "'");
        $this->settings->clearCache();

        $this->assertNull($this->service()->ensureRecorded());
    }

    public function testNowIsoIsAlwaysUtcAndIso8601(): void
    {
        $now = InstallationDateService::nowIso();

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $now);
    }
}
