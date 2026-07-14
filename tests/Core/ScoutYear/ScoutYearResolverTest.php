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
}
