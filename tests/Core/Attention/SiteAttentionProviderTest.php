<?php

declare(strict_types=1);

namespace Tests\Core\Attention;

use Core\Attention\AttentionPoint;
use Core\Attention\SiteAttentionProvider;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Config\UnitAddresses;
use Core\Import\ImportJournalRepository;
use Core\Import\MemberYearRepository;
use Core\ScoutYear\ScoutYearResolver;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The site's own attention points (issue #718 § C points 2, 4 and 13): one
 * test per point and state — present, absent — and for the stale-import
 * setting, its default and a changed value.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SiteAttentionProviderTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;
    private ScoutYearService $scoutYears;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        UnitAddresses::register($this->settings);
        SiteAttentionProvider::register($this->settings);
        $this->settings->register(ScoutYearResolver::SETTING_PUBLIC_YEAR, '0', 'number', 'Année publique', '', null, null, null, false);
        $this->scoutYears = new ScoutYearService($this->pdo);
        $this->scoutYearId = $this->scoutYears->getCurrentYear()['id'];
    }

    private function provider(): SiteAttentionProvider
    {
        return new SiteAttentionProvider(
            new ScoutYearResolver($this->scoutYears, $this->settings, new MemberYearRepository($this->pdo)),
            new ImportJournalRepository($this->pdo),
            $this->settings
        );
    }

    /** A configured site, recently imported, on the year in progress. */
    private function configureHealthySite(): void
    {
        $this->settings->set(UnitAddresses::POSTAL_ADDRESS, "Rue du Camp 1\n1000 Bruxelles");
        $this->importDaysAgo(3);
    }

    private function importDaysAgo(int $days): void
    {
        $at = \Core\Config\AppClock::now()->modify("-{$days} days")->format('Y-m-d H:i:s');
        $this->pdo->prepare(
            'INSERT INTO import_journal (scout_year_id, line_count, member_count, imported_at) VALUES (?, 10, 10, ?)'
        )->execute([$this->scoutYearId, $at]);
    }

    /** @return AttentionPoint[] */
    private function pointsTitled(string $needle): array
    {
        return array_values(array_filter(
            $this->provider()->collect($this->scoutYearId),
            static fn(AttentionPoint $p): bool => str_contains($p->title, $needle)
        ));
    }

    public function testAConfiguredRecentlyImportedSiteRaisesNothing(): void
    {
        $this->configureHealthySite();

        $this->assertSame([], $this->provider()->collect($this->scoutYearId));
    }

    public function testAPublicYearThatHasEndedIsUrgentAndLeadsToTheSwitch(): void
    {
        $this->configureHealthySite();
        $ended = $this->scoutYears->ensureYear('2020-2021');
        $this->settings->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $ended);

        $points = $this->pointsTitled('année publique');

        $this->assertCount(1, $points);
        $this->assertStringContainsString('2020-2021', $points[0]->title);
        $this->assertStringContainsString('31/08/2021', $points[0]->why);
        $this->assertSame(AttentionPoint::SEVERITY_URGENT, $points[0]->severity);
        $this->assertSame('/admin/scout-year', $points[0]->actionUrl);
    }

    public function testANewInstallationWithNoImportIsUrgent(): void
    {
        $this->settings->set(UnitAddresses::POSTAL_ADDRESS, 'Rue du Camp 1');

        $points = $this->pointsTitled('import Desk');

        $this->assertCount(1, $points);
        $this->assertSame("Aucun import Desk n'est enregistré", $points[0]->title);
        $this->assertSame(AttentionPoint::SEVERITY_URGENT, $points[0]->severity);
        $this->assertSame('/admin/import', $points[0]->actionUrl);
    }

    public function testAnImportOlderThanTheDefaultSixtyDaysIsAnAttentionPoint(): void
    {
        $this->assertSame('60', $this->settings->get(SiteAttentionProvider::STALE_IMPORT_SETTING_KEY));
        $this->settings->set(UnitAddresses::POSTAL_ADDRESS, 'Rue du Camp 1');

        $this->importDaysAgo(59);
        $this->assertSame([], $this->pointsTitled('import Desk'), '59 days is still within the default');

        $this->importDaysAgo(61);
        // The most recent import is what counts, not the oldest.
        $this->assertSame([], $this->pointsTitled('import Desk'));

        $this->pdo->exec('DELETE FROM import_journal');
        $this->importDaysAgo(61);
        $points = $this->pointsTitled('import Desk');
        $this->assertCount(1, $points);
        $this->assertSame('Le dernier import Desk date de 61 jours', $points[0]->title);
        $this->assertSame(AttentionPoint::SEVERITY_ATTENTION, $points[0]->severity);
    }

    public function testTheStaleImportDelayIsTheSetting(): void
    {
        $this->settings->set(UnitAddresses::POSTAL_ADDRESS, 'Rue du Camp 1');
        $this->importDaysAgo(20);
        $this->assertSame([], $this->pointsTitled('import Desk'));

        $this->settings->set(SiteAttentionProvider::STALE_IMPORT_SETTING_KEY, '14');

        $this->assertCount(1, $this->pointsTitled('import Desk'));
    }

    public function testTheStaleImportDelayRefusesZeroAndText(): void
    {
        foreach (['0', '-5', 'abc'] as $value) {
            try {
                $this->settings->set(SiteAttentionProvider::STALE_IMPORT_SETTING_KEY, $value);
                $this->fail("« {$value} » was accepted");
            } catch (\Core\Config\SettingException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** SettingService accepts an emptied field; it reads as the default. */
    public function testAnEmptiedDelayFallsBackToTheDefault(): void
    {
        $this->settings->set(UnitAddresses::POSTAL_ADDRESS, 'Rue du Camp 1');
        $this->settings->set(SiteAttentionProvider::STALE_IMPORT_SETTING_KEY, '');

        $this->importDaysAgo(59);
        $this->assertSame([], $this->pointsTitled('import Desk'));

        $this->pdo->exec('DELETE FROM import_journal');
        $this->importDaysAgo(60);
        $this->assertCount(1, $this->pointsTitled('import Desk'));
    }

    public function testAnEmptyPostalAddressIsAnAttentionPoint(): void
    {
        $this->importDaysAgo(1);
        $this->settings->set(UnitAddresses::POSTAL_ADDRESS, "  \n ");

        $points = $this->pointsTitled('adresse postale');

        $this->assertCount(1, $points);
        $this->assertSame(AttentionPoint::SEVERITY_ATTENTION, $points[0]->severity);
        $this->assertSame('/config/settings', $points[0]->actionUrl);
    }
}
