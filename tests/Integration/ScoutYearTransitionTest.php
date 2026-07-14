<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Import\MemberYearRepository;
use Core\ScoutYear\ScoutYearAdminService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\Role;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * End-to-end scout-year transition workflow: prepare next year with the staff,
 * verify visibility per role, then transition the whole site.
 *
 * @group database
 */
class ScoutYearTransitionTest extends TestCase
{
    private \PDO $pdo;
    private ScoutYearResolver $resolver;
    private ScoutYearAdminService $admin;
    private SettingService $settingService;
    private int $current;
    private int $next;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $scoutYearService = new ScoutYearService($this->pdo);
        $this->settingService = new SettingService(new SettingRepository($this->pdo));
        $this->settingService->register(ScoutYearResolver::SETTING_PUBLIC_YEAR, '0', 'number', 'Public', 'Public year id', null, '^[0-9]+$', null, false);
        $this->settingService->register(ScoutYearResolver::SETTING_STAFF_YEAR, '0', 'number', 'Staff', 'Staff year id', null, '^[0-9]+$', null, false);

        $this->resolver = new ScoutYearResolver($scoutYearService, $this->settingService, new MemberYearRepository($this->pdo));
        $this->admin = new ScoutYearAdminService($this->settingService);

        $this->current = $scoutYearService->ensureYear('2024-2025');
        $this->next = $scoutYearService->ensureYear('2025-2026');

        // Public site currently shows the current year.
        $this->admin->activatePublicYear($this->current);

        $this->seedMemberYearData();
    }

    public function testFullTransitionWorkflow(): void
    {
        // --- Step 1: baseline — everyone sees the current public year.
        $this->assertSame($this->current, $this->resolver->getEffectiveYear(null, Role::IDENTIFIED)->id);
        $this->assertSame($this->current, $this->resolver->getEffectiveYear(null, Role::CHIEF)->id);
        $this->assertSame($this->current, $this->resolver->getCurrentPublicYear()['id']);

        // --- Step 2: staff starts preparing next year.
        $this->admin->activateStaffYear($this->next);

        // Chiefs/intendants now see next year; the public (identified) still sees current.
        $staffView = $this->resolver->getEffectiveYear(null, Role::INTENDANT);
        $this->assertSame($this->next, $staffView->id);
        $this->assertSame('staff', $staffView->overrideType);

        $this->assertSame($this->current, $this->resolver->getEffectiveYear(null, Role::IDENTIFIED)->id);

        // Login role resolution must still use the public year (never the staff year).
        $this->assertSame($this->current, $this->resolver->getCurrentPublicYear()['id']);

        // --- Step 3: a chief previews an even older/other year for their session only.
        $preview = $this->resolver->getEffectiveYear($this->current, Role::CHIEF);
        $this->assertSame($this->current, $preview->id);
        $this->assertSame('session', $preview->overrideType);

        // The same preview id has no effect for a plain identified user.
        $this->assertSame($this->current, $this->resolver->getEffectiveYear($this->current, Role::IDENTIFIED)->id);

        // --- Step 4: transition the whole site to next year.
        $this->admin->activatePublicYear($this->next);

        // Everyone now sees next year; staff override cleared.
        $this->assertSame($this->next, $this->resolver->getEffectiveYear(null, Role::IDENTIFIED)->id);
        $this->assertSame($this->next, $this->resolver->getCurrentPublicYear()['id']);
        $this->assertNull($this->resolver->getStaffYearId());
    }

    public function testCountsReflectEffectiveYear(): void
    {
        // Two active members in the current year, one in the next.
        $this->assertSame(2, $this->resolver->countMembers($this->current));
        $this->assertSame(1, $this->resolver->countMembers($this->next));

        // One configured section in the current year.
        $this->assertSame(1, $this->resolver->countSections($this->current));
    }

    private function seedMemberYearData(): void
    {
        $this->pdo->exec("INSERT INTO age_branches (id, desk_code, label, sort_order) VALUES (1, 'BAL', 'Baladins', 10)");
        $this->pdo->exec("INSERT INTO sections (id, age_branch_id, desk_code, name) VALUES (1, 1, 'BAL1', 'Baladins')");
        $this->pdo->exec("INSERT INTO functions (id, desk_code, label, role, confirmed) VALUES (1, 'ANIM', 'Animateur', 'chief', 1)");
        $this->pdo->exec("INSERT INTO members (id, desk_id) VALUES (1, 'D1'), (2, 'D2')");

        // Two active member_years in the current year, one in the next.
        $this->insertMemberYear(1, 1, $this->current);
        $this->insertMemberYear(2, 2, $this->current);
        $this->insertMemberYear(3, 1, $this->next);

        // A function tied to a section for member_year 1 (current year).
        $this->pdo->exec("INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (1, 1, 1, 1)");
    }

    private function insertMemberYear(int $id, int $memberId, int $yearId): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO member_years (id, member_id, scout_year_id, first_name_encrypted, last_name_encrypted, is_active)
             VALUES (?, ?, ?, ?, ?, 1)"
        );
        $stmt->execute([$id, $memberId, $yearId, 'x', 'y']);
    }
}
