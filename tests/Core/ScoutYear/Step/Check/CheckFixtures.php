<?php

declare(strict_types=1);

namespace Tests\Core\ScoutYear\Step\Check;

use Core\Import\ImportRecord;
use Core\Member\MemberProfile;
use Core\Member\SectionYearHeadcount;
use Core\ScoutYear\Step\StepContext;

/**
 * What the step-check unit tests build over and over: the context of a
 * 2025-2026 → 2026-2027 transition, with or without a successful import
 * of the target, and the core values the checks read.
 */
final class CheckFixtures
{
    public const TARGET_ID = 12;
    public const PUBLIC_ID = 11;

    public static function context(bool $imported = true, ?\DateTimeImmutable $now = null): StepContext
    {
        return new StepContext(
            ['id' => self::TARGET_ID, 'label' => '2026-2027', 'start_date' => '2026-09-01', 'end_date' => '2027-08-31'],
            ['id' => self::PUBLIC_ID, 'label' => '2025-2026', 'start_date' => '2025-09-01', 'end_date' => '2026-08-31'],
            null,
            null,
            $now ?? new \DateTimeImmutable('2026-07-20 10:00:00'),
            $imported
                ? new ImportRecord(5, self::TARGET_ID, 1, 80, 72, 0, null, new \DateTimeImmutable('2026-07-18 21:14:00'))
                : null
        );
    }

    public static function section(
        int $id,
        string $label,
        int $chiefs = 2,
        int $animes = 10,
        bool $unitStaff = false
    ): SectionYearHeadcount {
        return new SectionYearHeadcount($id, 'S' . $id, $label, $id, $unitStaff, $chiefs, $animes);
    }

    /**
     * @return array{id: int, desk_code: string, name: ?string, email: ?string, age_branch_id: int,
     *     branch_name: string, branch_sort_order: int, color: ?string}
     */
    public static function sectionRow(int $id, ?string $name): array
    {
        return [
            'id' => $id, 'desk_code' => 'S' . $id, 'name' => $name, 'email' => null, 'age_branch_id' => 1,
            'branch_name' => 'Branche', 'branch_sort_order' => 1, 'color' => null,
        ];
    }

    public static function profile(int $memberYearId, int $memberId, string $firstName, string $lastName): MemberProfile
    {
        return new MemberProfile(
            $memberYearId, $memberId, 'D' . $memberId, $firstName, $lastName, null, null, null, null, null, null,
            null, null, null, false, false, [], [], '2026-2027'
        );
    }
}
