<?php

declare(strict_types=1);

namespace Tests\Core\ScoutYear;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\ScoutYear\AuthorizationYearService;
use Core\ScoutYear\ScoutYearResolver;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Every label below is derived from the date the test runs, never
 * written out. A fixture spelling « 2025-2026 » is correct for eleven
 * months and asserts something else entirely on the twelfth, which is
 * exactly the failure `Tests\DatabaseTestHelper::scoutYear()` exists for.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class AuthorizationYearServiceTest extends TestCase
{
    private \PDO $pdo;
    private ScoutYearService $scoutYearService;
    private SettingService $settingService;

    /** Two years back, one year back, this year, next year. */
    private int $yearMinus2;
    private int $yearMinus1;
    private int $yearNow;
    private int $yearPlus1;
    private int $yearPlus2;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->scoutYearService = new ScoutYearService($this->pdo);
        $this->settingService = new SettingService(new SettingRepository($this->pdo));
        $this->settingService->register(
            ScoutYearResolver::SETTING_PUBLIC_YEAR, '0', 'number', 'Public', 'Public year id',
            null, '^[0-9]+$', null, false
        );
        $this->settingService->register(
            ScoutYearResolver::SETTING_STAFF_YEAR, '0', 'number', 'Staff', 'Staff year id',
            null, '^[0-9]+$', null, false
        );

        $this->yearMinus2 = $this->createYear(-2);
        $this->yearMinus1 = $this->createYear(-1);
        $this->yearNow = $this->createYear(0);
        $this->yearPlus1 = $this->createYear(1);
        $this->yearPlus2 = $this->createYear(2);
    }

    private function createYear(int $offset): int
    {
        [$label] = DatabaseTestHelper::scoutYear($offset);

        return $this->scoutYearService->ensureYear($label);
    }

    private function setPublicYear(int $id): void
    {
        $this->settingService->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $id);
    }

    private function setStaffYear(int $id): void
    {
        $this->settingService->setInternal(ScoutYearResolver::SETTING_STAFF_YEAR, (string) $id);
    }

    private function service(?\DateTimeImmutable $now = null): AuthorizationYearService
    {
        return new AuthorizationYearService($this->scoutYearService, $this->settingService, $now);
    }

    private function countYearRows(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM scout_years')->fetchColumn();
    }

    /**
     * 31 August and 1 September of the calendar year the current scout
     * year starts in — the two sides of the only boundary
     * ScoutYearService::labelForDate() has. Derived, never written out.
     */
    private function onDate(string $monthDay): \DateTimeImmutable
    {
        $startYear = (int) explode('-', ScoutYearService::labelForDate(new \DateTimeImmutable()))[0];

        return new \DateTimeImmutable(sprintf('%d-%s', $startYear, $monthDay));
    }

    public function testPublicYearAloneWhenNothingElseIsConfigured(): void
    {
        $this->setPublicYear($this->yearNow);

        $years = $this->service()->resolve();

        $this->assertSame([$this->yearNow], $years->ids());
        $this->assertSame($this->yearNow, $years->publicYearId);
        $this->assertTrue($years->isPublicYear($this->yearNow));
    }

    public function testStaffYearIsIncludedWhenConfigured(): void
    {
        $this->setPublicYear($this->yearNow);
        $this->setStaffYear($this->yearPlus1);

        $years = $this->service()->resolve();

        $this->assertSame([$this->yearNow, $this->yearPlus1], $years->ids());
    }

    /**
     * The staff year is the SECOND entry, and it is the one that carries
     * the answer during a transition. An implementation reading `$ids[0]`
     * and calling it a day passes every other test in this file.
     */
    public function testTheStaffYearIsNotFirstInTheSet(): void
    {
        $this->setPublicYear($this->yearNow);
        $this->setStaffYear($this->yearPlus1);

        $ids = $this->service()->resolve()->ids();

        $this->assertSame($this->yearPlus1, $ids[1] ?? null);
        $this->assertNotSame($this->yearPlus1, $ids[0] ?? null);
        $this->assertFalse($this->service()->resolve()->isPublicYear($this->yearPlus1));
    }

    public function testDateComputedYearIsAbsentOnThirtyFirstOfAugust(): void
    {
        $this->setPublicYear($this->yearMinus1);

        $years = $this->service($this->onDate('08-31'))->resolve();

        $this->assertSame([$this->yearMinus1], $years->ids());
    }

    public function testDateComputedYearJoinsTheSetOnFirstOfSeptember(): void
    {
        $this->setPublicYear($this->yearMinus1);

        $years = $this->service($this->onDate('09-01'))->resolve();

        $this->assertSame([$this->yearMinus1, $this->yearNow], $years->ids());
        $this->assertSame($this->yearMinus1, $years->publicYearId);
    }

    public function testYearTwoAheadOfThePublicYearIsDiscarded(): void
    {
        $this->setPublicYear($this->yearNow);
        $this->setStaffYear($this->yearPlus2);

        $this->assertSame([$this->yearNow], $this->service()->resolve()->ids());
    }

    public function testYearTwoBehindThePublicYearIsDiscarded(): void
    {
        $this->setPublicYear($this->yearNow);
        $this->setStaffYear($this->yearMinus2);

        $this->assertSame([$this->yearNow], $this->service()->resolve()->ids());
    }

    /**
     * The forgotten instance of D2: nobody ever ran the transition, so
     * the public year is two seasons behind what the calendar says. The
     * date-computed year is dropped rather than quietly extending every
     * departed chief's access.
     */
    public function testDateComputedYearTwoAheadOfThePublicYearIsDiscarded(): void
    {
        $this->setPublicYear($this->yearMinus2);

        $this->assertSame([$this->yearMinus2], $this->service()->resolve()->ids());
    }

    public function testResolvingCreatesNoScoutYearRow(): void
    {
        $this->setPublicYear($this->yearMinus1);
        $this->setStaffYear($this->yearNow);

        // The year the calendar is in is deleted first, so the only way
        // to answer would be to create it — which is what must not happen.
        $this->pdo->prepare('DELETE FROM scout_years WHERE id = ?')->execute([$this->yearNow]);
        $before = $this->countYearRows();

        $years = (new AuthorizationYearService(new ScoutYearService($this->pdo), $this->settingService))->resolve();

        $this->assertSame($before, $this->countYearRows());
        $this->assertSame([$this->yearMinus1], $years->ids());
    }

    public function testStaffYearNamingADeletedRowIsIgnored(): void
    {
        $this->setPublicYear($this->yearNow);
        $this->setStaffYear($this->yearPlus1);
        $this->pdo->prepare('DELETE FROM scout_years WHERE id = ?')->execute([$this->yearPlus1]);

        $service = new AuthorizationYearService(new ScoutYearService($this->pdo), $this->settingService);

        $this->assertSame([$this->yearNow], $service->resolve()->ids());
    }

    /**
     * With the setting unset the public year IS the date-computed one —
     * the same fallback ScoutYearResolver::getCurrentPublicYear() applies,
     * minus its INSERT. The threshold in RoleResolver therefore means the
     * same thing on a site nobody has configured yet.
     */
    public function testUnsetPublicSettingFallsBackToTheDateComputedYear(): void
    {
        $this->setStaffYear($this->yearPlus1);

        $years = $this->service()->resolve();

        $this->assertSame($this->yearNow, $years->publicYearId);
        $this->assertSame([$this->yearNow, $this->yearPlus1], $years->ids());
    }

    public function testEmptySetWhenTheInstallationHasNoScoutYearAtAll(): void
    {
        $this->pdo->exec('DELETE FROM scout_years');

        $years = (new AuthorizationYearService(new ScoutYearService($this->pdo), $this->settingService))->resolve();

        $this->assertNull($years->publicYearId);
        $this->assertSame([], $years->ids());
        $this->assertTrue($years->isEmpty());
        $this->assertFalse($years->isPublicYear(0));
    }
}
