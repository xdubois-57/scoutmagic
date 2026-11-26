<?php

declare(strict_types=1);

namespace Tests\Modules\News\Service;

use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Import\MemberRepository;
use Core\Import\MemberYearRepository;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;
use Core\Member\SectionService;
use Core\Security\EncryptionService;
use Modules\News\Service\MemberAnswerResolver;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\News\NewsMemberFixtures;

/**
 * Who the Desk ids of a « Membres » answer designate now (issue #1381).
 * Fictitious people only.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class MemberAnswerResolverTest extends TestCase
{
    private \PDO $pdo;
    private NewsMemberFixtures $fixtures;
    private MemberAnswerResolver $resolver;
    private int $lastYear;
    private int $year;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->fixtures = new NewsMemberFixtures($this->pdo, $encryption);
        $connection = Connection::withPdo($this->pdo);
        $this->resolver = new MemberAnswerResolver(
            new MemberRepository($this->pdo),
            new MemberYearRepository($this->pdo),
            new SectionService(
                new SectionRepository($connection),
                new MemberProfileRepository($connection, $encryption, new MemberBadgeRepository($this->pdo))
            )
        );
        $this->lastYear = $this->fixtures->year('2025-2026', '2025-09-01', '2026-08-31', false);
        $this->year = $this->fixtures->year('2026-2027', '2026-09-01', '2027-08-31', true);
    }

    public function testEachCodeIsNamedFromTheMembersNewestRecord(): void
    {
        $zoe = $this->fixtures->member('D-1001', $this->lastYear, 'Zoé', 'Lambert', null, 'a@example.test');
        $this->fixtures->record($zoe['member_id'], $this->year, 'Zoé', 'Lambert', 'Castor', 'a@example.test');
        $hugo = $this->fixtures->member('D-2001', $this->lastYear, 'Hugo', 'Renard', null, 'b@example.test');

        $resolved = $this->resolver->resolve(['D-1001', 'D-2001']);

        $this->assertSame('Castor (Zoé Lambert)', $resolved['D-1001']->label);
        // Left the unit: still named, by the last record the unit has.
        $this->assertSame('Hugo Renard', $resolved['D-2001']->label);
        $this->assertSame($hugo['member_year_id'], $resolved['D-2001']->memberYearId);
    }

    public function testACodeNobodyHoldsAndAnIdentityWithoutRecordAreAbsent(): void
    {
        $this->fixtures->identity('D-3001');

        $this->assertSame([], $this->resolver->resolve(['D-3001', 'D-9999', '']));
        $this->assertSame([], $this->resolver->resolve([]));
    }

    public function testAnAliasLeadsToTheMemberKeptAndCarriesTheirCurrentCode(): void
    {
        $kept = $this->fixtures->member('D-0904', $this->year, 'Lou', 'Dumont', 'Hibou', 'a@example.test');
        $abandoned = $this->fixtures->identity('D-1004');
        $this->pdo->prepare('UPDATE members SET merged_into_member_id = ? WHERE id = ?')->execute([$kept['member_id'], $abandoned]);
        $this->pdo->prepare('INSERT INTO member_desk_id_aliases (member_id, desk_id) VALUES (?, ?)')
            ->execute([$kept['member_id'], 'D-1004']);

        $resolved = $this->resolver->resolve(['D-1004']);

        $this->assertSame('D-0904', $resolved['D-1004']->deskId);
        $this->assertSame('Hibou (Lou Dumont)', $resolved['D-1004']->label);
    }
}
