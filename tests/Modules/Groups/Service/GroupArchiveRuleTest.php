<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Service\GroupArchiveRule;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * The one rule behind « Archivé » (issue #1350): a date, or a year that
 * is over — measured by periods against the PUBLIC year.
 */
#[Group('database')]
class GroupArchiveRuleTest extends TestCase
{
    private \PDO $pdo;
    private GroupRepository $groupRepo;
    private int $pastYearId;
    private int $currentYearId;
    private int $nextYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);
        $this->groupRepo = new GroupRepository($this->pdo);

        $this->pastYearId = GroupsTestHelper::createScoutYear($this->pdo, '2024-2025', false);
        $this->currentYearId = GroupsTestHelper::createScoutYear($this->pdo, '2025-2026', true);
        $this->nextYearId = GroupsTestHelper::createScoutYear($this->pdo, '2026-2027', false);
    }

    public function testAnActiveGroupOfTheCurrentYearIsNotArchived(): void
    {
        $group = $this->group($this->currentYearId);
        $rule = $this->rule();

        $this->assertFalse($rule->isArchived($group));
        $this->assertFalse($rule->isOfPastYear($group));
        $this->assertFalse($rule->canReopen($group));
    }

    public function testADateArchivesAGroupAndLeavesItReopenable(): void
    {
        $group = $this->group($this->currentYearId, '2026-02-01 10:00:00');
        $rule = $this->rule();

        $this->assertTrue($rule->isArchived($group));
        $this->assertTrue($rule->canReopen($group));
    }

    public function testAPastYearArchivesAGroupWithoutAnyDateAndForGood(): void
    {
        $group = $this->group($this->pastYearId);
        $rule = $this->rule();

        $this->assertTrue($rule->isOfPastYear($group));
        $this->assertTrue($rule->isArchived($group));
        $this->assertFalse($rule->canReopen($group));
        $this->assertFalse($rule->canReopen($this->group($this->pastYearId, '2025-03-01 00:00:00')));
    }

    public function testAFutureYearAndNoYearAreNeverPast(): void
    {
        $rule = $this->rule();

        $this->assertFalse($rule->isArchived($this->group($this->nextYearId)));
        $this->assertFalse($rule->isArchived($this->group(null)));
        $this->assertTrue($rule->canReopen($this->group($this->nextYearId, '2026-02-01 00:00:00')));
        $this->assertTrue($rule->canReopen($this->group(null, '2026-02-01 00:00:00')));
    }

    /**
     * Ids are insertion order: a year imported after the current one has
     * a higher id and may still be long over. Only its dates count.
     */
    public function testPastIsDecidedByPeriodsNotByIds(): void
    {
        $lateYearId = GroupsTestHelper::createScoutYear($this->pdo, '2019-2020', false);
        $this->assertGreaterThan($this->nextYearId, $lateYearId);

        $this->assertTrue($this->rule()->isOfPastYear($this->group($lateYearId)));
    }

    /**
     * The reference is the public year: moving it is what makes the
     * current year past, and what a session is on plays no part (the rule
     * does not even take one).
     */
    public function testMovingThePublicYearMakesTheCurrentYearPast(): void
    {
        $group = $this->group($this->currentYearId);
        GroupsTestHelper::setPublicYear($this->pdo, $this->nextYearId);

        $this->assertTrue($this->rule()->isOfPastYear($group));
        $this->assertSame('2026-09-01', $this->rule()->publicYearStartDate());
    }

    /** A year id that resolves to nothing is no evidence that a year is over. */
    public function testAYearThatNoLongerResolvesIsNotPast(): void
    {
        $this->assertFalse($this->rule()->isArchived($this->group(9999)));
    }

    /**
     * Read once per instance: asking about a whole list costs no query per
     * group. Proved by removing the rows after the first question — the
     * answers do not change.
     */
    public function testYearsAreReadOncePerInstance(): void
    {
        $rule = $this->rule();
        $this->assertTrue($rule->isOfPastYear($this->group($this->pastYearId)));

        $this->pdo->exec('DELETE FROM scout_years');
        $this->pdo->exec("DELETE FROM settings WHERE setting_key = 'current_scout_year_id'");

        $this->assertTrue($rule->isOfPastYear($this->group($this->pastYearId)));
        $this->assertFalse($rule->isOfPastYear($this->group($this->nextYearId)));
        $this->assertSame('2025-09-01', $rule->publicYearStartDate());
    }

    /** A year created after the first read is picked up rather than answered wrongly. */
    public function testAYearCreatedAfterTheFirstReadIsStillKnown(): void
    {
        $rule = $this->rule();
        $rule->isOfPastYear($this->group($this->currentYearId));

        $laterId = GroupsTestHelper::createScoutYear($this->pdo, '2018-2019', false);
        // createScoutYear(…, false) leaves the public year where it was.
        $this->assertTrue($rule->isOfPastYear($this->group($laterId)));
    }

    private function rule(): GroupArchiveRule
    {
        return GroupsTestHelper::archiveRule($this->pdo);
    }

    private function group(?int $scoutYearId, ?string $archivedAt = null): DiscussionGroup
    {
        $groupId = $this->groupRepo->create('Groupe', $scoutYearId, null, null);
        if ($archivedAt !== null) {
            $this->groupRepo->setArchived($groupId, $archivedAt);
        }
        $group = $this->groupRepo->findById($groupId);
        \assert($group !== null);

        return $group;
    }
}
