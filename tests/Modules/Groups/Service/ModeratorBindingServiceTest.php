<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Modules\Groups\Repository\GroupMemberRepository;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Service\GroupRecipientResolver;
use Modules\Groups\Service\ModeratorBindingService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * Moderator rows granted before the flag named a login.
 *
 * The flag used to sit on the membership, so it belonged to a member and
 * therefore to every address that reaches them; it now names one account,
 * and a row that names none moderates nothing. This is what keeps that
 * change from silently stripping the moderators of every group that
 * already existed — and what refuses to guess when guessing is exactly
 * the thing the change was made to stop.
 *
 * @group database
 */
#[Group('database')]
class ModeratorBindingServiceTest extends TestCase
{
    private \PDO $pdo;
    private GroupMemberRepository $memberRepo;
    private int $groupId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);

        $this->memberRepo = new GroupMemberRepository($this->pdo);
        $this->groupId = (new GroupRepository($this->pdo))->create('Louveteaux', null, null, 1);
    }

    public function testAMemberWithExactlyOneLoginIsBoundToIt(): void
    {
        $this->memberRepo->add($this->groupId, 42, true);

        $bound = $this->service([42 => [7]])->run();

        $this->assertSame(1, $bound);
        $this->assertSame(7, $this->memberRepo->find($this->groupId, 42)->moderatorUserAccountId);
    }

    /**
     * The ambiguous case is precisely the one the per-login flag exists
     * for: two addresses reach this member, and picking one of them here
     * would be the site making the very choice it now asks a human to
     * make deliberately.
     */
    public function testAMemberWithSeveralLoginsIsLeftForAHumanToDecide(): void
    {
        $this->memberRepo->add($this->groupId, 42, true);

        $bound = $this->service([42 => [7, 8]])->run();

        $this->assertSame(0, $bound);
        $this->assertNull($this->memberRepo->find($this->groupId, 42)->moderatorUserAccountId);
    }

    public function testAMemberWithNoLoginAtAllIsLeftAlone(): void
    {
        $this->memberRepo->add($this->groupId, 42, true);

        $this->assertSame(0, $this->service([42 => []])->run());
        $this->assertNull($this->memberRepo->find($this->groupId, 42)->moderatorUserAccountId);
    }

    /**
     * A deliberate grant is never overwritten, whatever else resolves for
     * that member — the UPDATE is guarded on the column still being NULL.
     */
    public function testARowThatAlreadyNamesALoginIsNeverTouched(): void
    {
        $this->memberRepo->add($this->groupId, 42, true, null, 8);

        $this->assertSame(0, $this->service([42 => [7]])->run());
        $this->assertSame(8, $this->memberRepo->find($this->groupId, 42)->moderatorUserAccountId);
    }

    public function testAnOrdinaryMemberIsNotGivenTheFlagByThisPass(): void
    {
        $this->memberRepo->add($this->groupId, 42, false);

        $this->assertSame(0, $this->service([42 => [7]])->run());
        $this->assertFalse($this->memberRepo->find($this->groupId, 42)->isModerator);
    }

    /**
     * Idempotent: a second pass over an already-bound install is two
     * queries and no writes, which is what makes running it on a page
     * load acceptable.
     */
    public function testASecondPassFindsNothingLeftToDo(): void
    {
        $this->memberRepo->add($this->groupId, 42, true);
        $service = $this->service([42 => [7]]);

        $this->assertSame(1, $service->run());
        $this->assertSame(0, $service->run());
    }

    /**
     * @param array<int, int[]> $accountsByMember
     */
    private function service(array $accountsByMember): ModeratorBindingService
    {
        $resolver = $this->createStub(GroupRecipientResolver::class);
        $resolver->method('accountIdsForMember')->willReturnCallback(
            static fn(int $memberId): array => $accountsByMember[$memberId] ?? []
        );

        return new ModeratorBindingService($this->memberRepo, $resolver);
    }
}
