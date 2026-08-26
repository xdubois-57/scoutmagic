<?php

declare(strict_types=1);

namespace Tests\Core\Member\Service;

use Core\Database\Connection;
use Core\Member\Repository\MemberSearchRepository;
use Core\Member\Service\MemberSearchService;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MemberSearchServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $enc;
    private MemberSearchService $service;
    private int $yearId;
    private int $otherYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->enc = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $repo = new MemberSearchRepository(Connection::withPdo($this->pdo), $this->enc);
        $this->service = new MemberSearchService($repo, new \Core\Config\ScoutYearService($this->pdo));

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $this->yearId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2024-2025', '2024-09-01', '2025-08-31')");
        $this->otherYearId = (int) $this->pdo->lastInsertId();
    }

    private function insertMember(
        string $first,
        string $last,
        ?string $totem = null,
        ?string $email = null,
        ?string $mobile = null,
        bool $active = true,
        ?int $yearId = null
    ): int {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, totem_encrypted, email_encrypted, mobile_encrypted, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId,
            $yearId ?? $this->yearId,
            $this->enc->encrypt($first, 'member_years.first_name'),
            $this->enc->encrypt($last, 'member_years.last_name'),
            $totem !== null ? $this->enc->encrypt($totem, 'member_years.totem') : null,
            $email !== null ? $this->enc->encrypt($email, 'member_years.email') : null,
            $mobile !== null ? $this->enc->encrypt($mobile, 'member_years.mobile') : null,
            $active ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * The PERSISTENT identity behind a member_year row — what results are
     * grouped on, and what a second annual row has to be attached to.
     */
    private function memberIdOf(int $memberYearId): int
    {
        $stmt = $this->pdo->prepare('SELECT member_id FROM member_years WHERE id = ?');
        $stmt->execute([$memberYearId]);

        return (int) $stmt->fetchColumn();
    }

    public function testSearchByLastName(): void
    {
        $this->insertMember('Jean', 'Dupont');
        $this->insertMember('Marie', 'Martin');

        $results = $this->service->search($this->yearId, 'dupont');

        $this->assertCount(1, $results);
        $this->assertSame('Dupont', $results[0]->lastName);
    }

    public function testSearchByFirstName(): void
    {
        $this->insertMember('Alexandre', 'Dupont');
        $this->insertMember('Marie', 'Martin');

        $results = $this->service->search($this->yearId, 'alex');

        $this->assertCount(1, $results);
        $this->assertSame('Alexandre', $results[0]->firstName);
    }

    public function testSearchByEmail(): void
    {
        $this->insertMember('Jean', 'Dupont', email: 'jean.dupont@example.be');
        $this->insertMember('Marie', 'Martin', email: 'marie@example.be');

        $results = $this->service->search($this->yearId, 'jean.dupont@');

        $this->assertCount(1, $results);
        $this->assertSame('Dupont', $results[0]->lastName);
    }

    public function testSearchByPhone(): void
    {
        $this->insertMember('Jean', 'Dupont', mobile: '0476123456');
        $this->insertMember('Marie', 'Martin', mobile: '0498765432');

        $results = $this->service->search($this->yearId, '0476');

        $this->assertCount(1, $results);
        $this->assertSame('Dupont', $results[0]->lastName);
    }

    public function testSearchIsAccentInsensitive(): void
    {
        $this->insertMember('Jean', 'Dupont', totem: 'Renard Espiègle');

        $results = $this->service->search($this->yearId, 'espiegle');

        $this->assertCount(1, $results);
    }

    public function testEmptyQueryReturnsNothing(): void
    {
        $this->insertMember('Jean', 'Dupont');

        $this->assertSame([], $this->service->search($this->yearId, ''));
        $this->assertSame([], $this->service->search($this->yearId, '   '));
    }

    public function testResultsSortedByLastNameThenFirstName(): void
    {
        $this->insertMember('Bob', 'Zorro', email: 'x@a.be');
        $this->insertMember('Alice', 'Alpha', email: 'x@a.be');
        $this->insertMember('Bea', 'Alpha', email: 'x@a.be');

        $results = $this->service->search($this->yearId, 'x@a.be');

        $this->assertCount(3, $results);
        $this->assertSame('Alpha', $results[0]->lastName);
        $this->assertSame('Alice', $results[0]->firstName);
        $this->assertSame('Alpha', $results[1]->lastName);
        $this->assertSame('Bea', $results[1]->firstName);
        $this->assertSame('Zorro', $results[2]->lastName);
    }

    /**
     * The repository never filtered on `is_active`, and the result has
     * always carried the flag — what was missing was a way to narrow.
     * The default is now « actifs », which is what is wanted nine times
     * out of ten; the other two scopes are one tap away.
     */
    public function testInactiveMembersAreExcludedByDefaultAndReachableOnDemand(): void
    {
        $this->insertMember('Jean', 'Dupont', active: false);

        $this->assertSame([], $this->service->search($this->yearId, 'dupont'));

        $inactive = $this->service->search($this->yearId, 'dupont', MemberSearchService::SCOPE_INACTIVE);
        $this->assertCount(1, $inactive);
        $this->assertFalse($inactive[0]->isActive);

        $this->assertCount(1, $this->service->search($this->yearId, 'dupont', MemberSearchService::SCOPE_ALL));
    }

    public function testTheActiveScopeExcludesTheInactiveAndKeepsTheActive(): void
    {
        $this->insertMember('Jean', 'Dupont', active: true);
        $this->insertMember('Marie', 'Dupont', active: false);

        $active = $this->service->search($this->yearId, 'dupont', MemberSearchService::SCOPE_ACTIVE);
        $all = $this->service->search($this->yearId, 'dupont', MemberSearchService::SCOPE_ALL);

        $this->assertSame(['Jean'], array_map(fn($r) => $r->firstName, $active));
        $this->assertCount(2, $all);
    }

    /**
     * A typo or a stale bookmark must never quietly widen the list, so an
     * unknown scope falls back to the default rather than to « tous ».
     */
    public function testAnUnknownScopeFallsBackToActiveRatherThanToAll(): void
    {
        $this->insertMember('Marie', 'Dupont', active: false);

        $this->assertSame(MemberSearchService::SCOPE_ACTIVE, MemberSearchService::normalizeScope('everything'));
        $this->assertSame(MemberSearchService::SCOPE_ACTIVE, MemberSearchService::normalizeScope(null));
        $this->assertSame([], $this->service->search($this->yearId, 'dupont', 'everything'));
    }

    /**
     * Grouping is on members.id — the persistent identity — never on a
     * name: two children can share one, and Desk re-imports the same
     * person's name every year.
     */
    public function testTheWidenedSearchReturnsOneRowPerPersonNotOnePerYear(): void
    {
        $memberId = $this->memberIdOf($this->insertMember('Camille', 'Vandenbrande', yearId: $this->yearId));
        $this->addYearFor($memberId, $this->otherYearId, 'Camille', 'Vandenbrande');

        $grouped = $this->service->searchAllYears('vandenbrande', MemberSearchService::SCOPE_ALL, $this->yearId);

        $this->assertCount(1, $grouped);
        $this->assertSame($memberId, $grouped[0]->memberId);
        // Most recent year first.
        $this->assertSame(['2025-2026', '2024-2025'], $grouped[0]->scoutYearLabels);
    }

    public function testTwoPeopleSharingANameStayTwoRows(): void
    {
        $this->insertMember('Camille', 'Dupont');
        $this->insertMember('Camille', 'Dupont');

        $grouped = $this->service->searchAllYears('dupont', MemberSearchService::SCOPE_ALL, $this->yearId);

        $this->assertCount(2, $grouped);
    }

    /**
     * The row shows the LATEST year found, since somebody looking up a
     * former member wants their last known section and status.
     */
    public function testAGroupedRowShowsTheMostRecentYearFound(): void
    {
        $memberId = $this->memberIdOf($this->insertMember('Camille', 'Ancienne', yearId: $this->otherYearId));
        $this->addYearFor($memberId, $this->yearId, 'Camille', 'Ancienne', totem: 'Loutre');

        $grouped = $this->service->searchAllYears('ancienne', MemberSearchService::SCOPE_ALL, $this->yearId);

        $this->assertSame('Loutre', $grouped[0]->latest->totem);
        $this->assertFalse($grouped[0]->isFormerMember);
    }

    public function testSomeoneFoundOnlyInAPastYearIsMarkedAsAFormerMember(): void
    {
        $this->insertMember('Camille', 'Partie', yearId: $this->otherYearId);

        $grouped = $this->service->searchAllYears('partie', MemberSearchService::SCOPE_ALL, $this->yearId);

        $this->assertCount(1, $grouped);
        $this->assertTrue($grouped[0]->isFormerMember);
        $this->assertSame('2024-2025', $grouped[0]->yearsSummary());
    }

    public function testTheYearsSummaryReadsAsASpanRatherThanAListOfLabels(): void
    {
        $memberId = $this->memberIdOf($this->insertMember('Camille', 'Longue', yearId: $this->otherYearId));
        $this->addYearFor($memberId, $this->yearId, 'Camille', 'Longue');

        $grouped = $this->service->searchAllYears('longue', MemberSearchService::SCOPE_ALL, $this->yearId);

        $this->assertSame('2024-2025 → 2025-2026', $grouped[0]->yearsSummary());
    }

    /**
     * The ordinary, non-widened search groups too, so the page renders
     * one kind of row either way — and a year carrying two rows for one
     * person still reads as one person.
     */
    public function testTheOrdinarySearchIsGroupedTheSameWay(): void
    {
        $this->insertMember('Jean', 'Dupont');

        $grouped = $this->service->searchGrouped($this->yearId, 'dupont', MemberSearchService::SCOPE_ACTIVE, $this->yearId);

        $this->assertCount(1, $grouped);
        $this->assertFalse($grouped[0]->isFormerMember);
        $this->assertSame('2025-2026', $grouped[0]->yearsSummary());
    }

    private function addYearFor(int $memberId, int $yearId, string $first, string $last, ?string $totem = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, totem_encrypted, is_active)
             VALUES (?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $memberId,
            $yearId,
            $this->enc->encrypt($first, 'member_years.first_name'),
            $this->enc->encrypt($last, 'member_years.last_name'),
            $totem !== null ? $this->enc->encrypt($totem, 'member_years.totem') : null,
        ]);
    }

    public function testSearchScopedToYear(): void
    {
        $this->insertMember('Jean', 'Dupont', yearId: $this->otherYearId);

        $this->assertSame([], $this->service->search($this->yearId, 'dupont'));
    }

    public function testFindByIdOnlyReturnsMembersOfTheYear(): void
    {
        $id = $this->insertMember('Jean', 'Dupont', yearId: $this->otherYearId);

        $this->assertNull($this->service->findById($this->yearId, $id));
        $this->assertNotNull($this->service->findById($this->otherYearId, $id));
    }
}
