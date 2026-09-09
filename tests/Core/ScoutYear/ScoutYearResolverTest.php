<?php

declare(strict_types=1);

namespace Tests\Core\ScoutYear;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Import\MemberYearRepository;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\Role;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ScoutYearResolverTest extends TestCase
{
    private \PDO $pdo;
    private ScoutYearService $scoutYearService;
    private SettingService $settingService;
    private ScoutYearResolver $resolver;
    private int $year2023;
    private int $year2024;
    private int $year2025;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->scoutYearService = new ScoutYearService($this->pdo);
        $this->settingService = new SettingService(new SettingRepository($this->pdo));
        $this->settingService->register(ScoutYearResolver::SETTING_PUBLIC_YEAR, '0', 'number', 'Public', 'Public year id', null, '^[0-9]+$', null, false);
        $this->settingService->register(ScoutYearResolver::SETTING_STAFF_YEAR, '0', 'number', 'Staff', 'Staff year id', null, '^[0-9]+$', null, false);

        $this->resolver = new ScoutYearResolver(
            $this->scoutYearService,
            $this->settingService,
            new MemberYearRepository($this->pdo)
        );

        $this->year2023 = $this->scoutYearService->ensureYear('2023-2024');
        $this->year2024 = $this->scoutYearService->ensureYear('2024-2025');
        $this->year2025 = $this->scoutYearService->ensureYear('2025-2026');
    }

    private function setPublicYear(int $id): void
    {
        $this->settingService->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $id);
    }

    private function setStaffYear(int $id): void
    {
        $this->settingService->setInternal(ScoutYearResolver::SETTING_STAFF_YEAR, (string) $id);
    }

    public function testPublicSettingUsedWhenSet(): void
    {
        $this->setPublicYear($this->year2024);

        $effective = $this->resolver->getEffectiveYear(null, Role::PUBLIC);

        $this->assertSame($this->year2024, $effective->id);
        $this->assertNull($effective->overrideType);
        $this->assertFalse($effective->isOverridden());
    }

    public function testFallsBackToDateComputedWhenPublicUnset(): void
    {
        // No public setting → getCurrentPublicYear falls back to the date-computed year.
        $public = $this->resolver->getCurrentPublicYear();
        $effective = $this->resolver->getEffectiveYear(null, Role::PUBLIC);

        $this->assertNotEmpty($public['label']);
        $this->assertSame($public['id'], $effective->id);
        $this->assertNull($effective->overrideType);
    }

    public function testPublicSettingPointingToMissingYearFallsBackToDate(): void
    {
        $this->setPublicYear(99999);

        $effective = $this->resolver->getEffectiveYear(null, Role::PUBLIC);

        $this->assertNotSame(99999, $effective->id);
        $this->assertNull($effective->overrideType);
    }

    public function testStaffYearHonoredForIntendant(): void
    {
        $this->setPublicYear($this->year2024);
        $this->setStaffYear($this->year2025);

        $effective = $this->resolver->getEffectiveYear(null, Role::INTENDANT);

        $this->assertSame($this->year2025, $effective->id);
        $this->assertSame('staff', $effective->overrideType);
    }

    public function testStaffYearIgnoredBelowIntendant(): void
    {
        $this->setPublicYear($this->year2024);
        $this->setStaffYear($this->year2025);

        $effective = $this->resolver->getEffectiveYear(null, Role::IDENTIFIED);

        $this->assertSame($this->year2024, $effective->id);
        $this->assertNull($effective->overrideType);
    }

    public function testSessionPreviewHonoredForChief(): void
    {
        $this->setPublicYear($this->year2024);
        $this->setStaffYear($this->year2025);

        $effective = $this->resolver->getEffectiveYear($this->year2023, Role::CHIEF);

        $this->assertSame($this->year2023, $effective->id);
        $this->assertSame('session', $effective->overrideType);
    }

    public function testSessionPreviewIgnoredForIntendantFallsToStaff(): void
    {
        $this->setPublicYear($this->year2024);
        $this->setStaffYear($this->year2025);

        // Intendant is below chief: the preview must be ignored, staff year applies.
        $effective = $this->resolver->getEffectiveYear($this->year2023, Role::INTENDANT);

        $this->assertSame($this->year2025, $effective->id);
        $this->assertSame('staff', $effective->overrideType);
    }

    public function testSessionPreviewIgnoredWhenYearMissing(): void
    {
        $this->setPublicYear($this->year2024);

        $effective = $this->resolver->getEffectiveYear(99999, Role::CHIEF);

        $this->assertSame($this->year2024, $effective->id);
        $this->assertNull($effective->overrideType);
    }

    public function testSessionPreviewTakesPriorityOverStaff(): void
    {
        $this->setPublicYear($this->year2024);
        $this->setStaffYear($this->year2025);

        $effective = $this->resolver->getEffectiveYear($this->year2023, Role::CHIEF);

        $this->assertSame($this->year2023, $effective->id);
        $this->assertSame('session', $effective->overrideType);
    }

    public function testGetPublicAndStaffYearIdReturnNullWhenUnset(): void
    {
        $this->assertNull($this->resolver->getPublicYearId());
        $this->assertNull($this->resolver->getStaffYearId());

        $this->setStaffYear($this->year2025);
        $this->assertSame($this->year2025, $this->resolver->getStaffYearId());
    }

    public function testListYearsEnsuresTheNextYearExists(): void
    {
        $this->setPublicYear($this->year2025); // 2025-2026

        $labels = array_column($this->resolver->listYears(), 'label');

        // The year after the public year is created so it can be previewed/imported.
        $this->assertContains('2026-2027', $labels);
    }

    // ---------------------------------------------------------------
    // getAccessYearIds() — the transition allowance.
    //
    // These tests are written against the CALENDAR, because the method is:
    // the second year it may return is the one today's date falls in, so a
    // fixture that hardcoded '2025-2026' would assert something different
    // every September. calendarLabel() below derives the labels the same
    // way ScoutYearService::labelForDate() does, offset by whole years, so
    // each case says "one year before today's", "two after", and stays
    // true whenever it is run.
    // ---------------------------------------------------------------

    /**
     * Today's scout-year label, shifted by $yearOffset whole years.
     */
    private function calendarLabel(int $yearOffset = 0): string
    {
        $today = ScoutYearService::labelForDate(new \DateTimeImmutable());
        $start = (int) explode('-', $today)[0] + $yearOffset;

        return sprintf('%d-%d', $start, $start + 1);
    }

    public function testAccessYearsAreTheEffectiveYearAloneWhenTheCalendarYearHasNoRow(): void
    {
        $this->setPublicYear($this->year2023);

        // Nothing has created today's year, and asking must not.
        $this->assertSame([$this->year2023], $this->resolver->getAccessYearIds(null, Role::PUBLIC));
        $this->assertNull($this->scoutYearService->findByLabel($this->calendarLabel()));
    }

    public function testAccessYearsAddTheCalendarYearWhenItFollowsTheEffectiveOne(): void
    {
        $calendar = $this->scoutYearService->ensureYear($this->calendarLabel());
        $previous = $this->scoutYearService->ensureYear($this->calendarLabel(-1));
        $this->setPublicYear($previous);

        // The 1st-of-September case: the calendar has moved on, the public
        // year has not, and a membership in either still counts.
        $this->assertSame([$previous, $calendar], $this->resolver->getAccessYearIds(null, Role::PUBLIC));
    }

    public function testAccessYearsAddTheCalendarYearWhenItPRECEDESTheEffectiveOne(): void
    {
        $calendar = $this->scoutYearService->ensureYear($this->calendarLabel());
        $next = $this->scoutYearService->ensureYear($this->calendarLabel(1));
        $this->setStaffYear($next);

        // The other half of the same fortnight: step 9 of the transition
        // activates next year for the staffs BEFORE the calendar turns
        // over, so the effective year runs ahead of the date.
        $this->assertSame([$next, $calendar], $this->resolver->getAccessYearIds(null, Role::CHIEF));
    }

    public function testAccessYearsRefuseAYearThatIsTwoApart(): void
    {
        $this->scoutYearService->ensureYear($this->calendarLabel());
        $stale = $this->scoutYearService->ensureYear($this->calendarLabel(-2));
        $this->setPublicYear($stale);

        // A public year nobody has advanced in two seasons is an oversight,
        // not a transition — and the widening has to stop somewhere or a
        // long-departed chief keeps their access for ever.
        $this->assertSame([$stale], $this->resolver->getAccessYearIds(null, Role::PUBLIC));
    }

    public function testAccessYearsNeverRepeatTheSameYearTwice(): void
    {
        $calendar = $this->scoutYearService->ensureYear($this->calendarLabel());
        $this->setPublicYear($calendar);

        $this->assertSame([$calendar], $this->resolver->getAccessYearIds(null, Role::PUBLIC));
    }
}
