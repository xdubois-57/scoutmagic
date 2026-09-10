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

    /**
     * Stands in for the composition root's wiring: whether the account
     * behind the request reaches `intendant` resolved IN the staff year.
     * Tests that never call this exercise the unwired, fail-closed path.
     */
    private function eligibleForStaffYear(bool $eligible): void
    {
        $this->resolver->setStaffYearEligibility(static fn(int $yearId): bool => $eligible);
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

    public function testStaffYearHonoredForWhoeverReachesIntendantInThatYear(): void
    {
        $this->setPublicYear($this->year2024);
        $this->setStaffYear($this->year2025);
        $this->eligibleForStaffYear(true);

        $effective = $this->resolver->getEffectiveYear(null, Role::INTENDANT);

        $this->assertSame($this->year2025, $effective->id);
        $this->assertSame('staff', $effective->overrideType);
    }

    /**
     * **The animateur who is leaving.** They are a chief — a chef d'unité
     * even — by the public year, and have no row at all in the year being
     * prepared. Under the old rule their global role sent them into that
     * year, where they have neither section nor animés. The question is
     * asked in the staff year now, so they stay where their section is.
     */
    public function testStaffYearRefusedToAnAdminWhoIsNotIntendantInThatYear(): void
    {
        $this->setPublicYear($this->year2024);
        $this->setStaffYear($this->year2025);
        $this->eligibleForStaffYear(false);

        $effective = $this->resolver->getEffectiveYear(null, Role::ADMIN);

        $this->assertSame($this->year2024, $effective->id);
        $this->assertNull($effective->overrideType);
    }

    public function testStaffYearIgnoredForAnOrdinaryMember(): void
    {
        $this->setPublicYear($this->year2024);
        $this->setStaffYear($this->year2025);
        $this->eligibleForStaffYear(false);

        $effective = $this->resolver->getEffectiveYear(null, Role::IDENTIFIED);

        $this->assertSame($this->year2024, $effective->id);
        $this->assertNull($effective->overrideType);
    }

    /**
     * Unwired — a background root with no session to ask about — no staff
     * year is served at all. The public year is where a caller with no
     * identity belongs, so this fails in the closed direction.
     */
    public function testStaffYearIgnoredWhenNoEligibilityIsWired(): void
    {
        $this->setPublicYear($this->year2024);
        $this->setStaffYear($this->year2025);

        $effective = $this->resolver->getEffectiveYear(null, Role::ADMIN);

        $this->assertSame($this->year2024, $effective->id);
        $this->assertNull($effective->overrideType);
    }

    /** The question is asked about the staff year, not about some other one. */
    public function testEligibilityIsAskedAboutTheStaffYearItself(): void
    {
        $this->setPublicYear($this->year2024);
        $this->setStaffYear($this->year2025);

        $asked = [];
        $this->resolver->setStaffYearEligibility(function (int $yearId) use (&$asked): bool {
            $asked[] = $yearId;
            return true;
        });

        $this->resolver->getEffectiveYear(null, Role::CHIEF);

        $this->assertSame([$this->year2025], $asked);
    }

    /**
     * **The question is put every time, and this resolver caches nothing
     * about it.** The answer depends on WHO is asking as much as on the
     * year, and the identity changes inside a single request the moment a
     * login succeeds — a cache here, keyed on the year alone, would
     * answer the controller with the anonymous answer the front
     * controller got before routing. The cache belongs one layer up,
     * where the address is known: Core\ScoutYear\StaffYearEligibility,
     * and Tests\Core\ScoutYear\StaffYearEligibilityTest holds both
     * halves of that.
     */
    public function testEligibilityIsAskedAgainRatherThanCachedHere(): void
    {
        $this->setPublicYear($this->year2024);
        $this->setStaffYear($this->year2025);

        $answers = [false, true, true];
        $this->resolver->setStaffYearEligibility(function () use (&$answers): bool {
            return array_shift($answers) ?? false;
        });

        // The front controller, before routing: nobody is signed in yet.
        $this->assertSame($this->year2024, $this->resolver->getEffectiveYear(null, Role::CHIEF)->id);

        // The controller, after the login: the same instance must ask again.
        $this->assertSame($this->year2025, $this->resolver->getEffectiveYear(null, Role::CHIEF)->id);
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
        $this->eligibleForStaffYear(true);

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

    /**
     * **The authorization year is not the displayed year**, and the
     * difference is the preview. A chief previewing 2019-2020 sees that
     * year; the question « is this person a chef d'unité » is still asked
     * in the year they are served, or whoever was Staff d'U in 2019-2020
     * could preview their way back into every screen gated on it.
     */
    public function testTheAuthorizationYearIgnoresThePreviewTheDisplayedYearHonours(): void
    {
        $this->setPublicYear($this->year2024);

        $displayed = $this->resolver->getEffectiveYear($this->year2023, Role::ADMIN);

        $this->assertSame($this->year2023, $displayed->id);
        $this->assertSame('session', $displayed->overrideType);
        $this->assertSame($this->year2024, $this->resolver->getAuthorizationYear()->id);
        $this->assertNull($this->resolver->getAuthorizationYear()->overrideType);
    }

    /** It does honour the staff year, on the same rule as the displayed one. */
    public function testTheAuthorizationYearStillFollowsTheStaffYear(): void
    {
        $this->setPublicYear($this->year2024);
        $this->setStaffYear($this->year2025);
        $this->eligibleForStaffYear(true);

        $this->assertSame($this->year2025, $this->resolver->getAuthorizationYear()->id);
        $this->assertSame('staff', $this->resolver->getAuthorizationYear()->overrideType);
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
}
