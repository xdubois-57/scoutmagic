<?php

declare(strict_types=1);

namespace Tests\Modules\Attestations\Repository;

use Core\Database\Connection;
use Modules\Attestations\Repository\MemberNameRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Attestations\AttestationsTestHelper;

#[Group('database')]
class MemberNameRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private MemberNameRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repository = new MemberNameRepository(
            Connection::withPdo($this->pdo),
            AttestationsTestHelper::encryption()
        );
    }

    /**
     * The decision this repository exists to make. A tax certificate covers
     * the year just gone and routinely names somebody who has since left —
     * and that family, having no page on the site, is the one the document
     * matters most to. Restricting the query to the effective year would
     * leave their line unmatched and undistributable, which is exactly
     * backwards.
     */
    public function testAMemberFromAPastYearIsStillFound(): void
    {
        $lastYear = AttestationsTestHelper::createScoutYear($this->pdo, '2024-2025');
        $thisYear = AttestationsTestHelper::createScoutYear($this->pdo, '2025-2026');

        $stayed = AttestationsTestHelper::createMember($this->pdo, $thisYear, 'Margaux', 'Vandenbrande');
        $left = AttestationsTestHelper::createMember($this->pdo, $lastYear, 'Antonin', 'Grandjean');

        $directory = $this->repository->buildDirectory();

        $this->assertSame([$stayed], $directory->lookup('Vandenbrande Margaux'));
        $this->assertSame([$left], $directory->lookup('Grandjean Antonin'));
    }

    public function testAMemberPresentInSeveralYearsIsOneCandidate(): void
    {
        $lastYear = AttestationsTestHelper::createScoutYear($this->pdo, '2024-2025');
        $thisYear = AttestationsTestHelper::createScoutYear($this->pdo, '2025-2026');

        $memberId = AttestationsTestHelper::createMember($this->pdo, $lastYear, 'Sacha', 'Meunier');
        AttestationsTestHelper::addMemberYear($this->pdo, $memberId, $thisYear, 'Sacha', 'Meunier');

        $this->assertSame([$memberId], $this->repository->buildDirectory()->lookup('Meunier Sacha'));
    }

    /**
     * A married name, or a correction Desk pushed. The certificate printed
     * last year carries last year's spelling, so both have to resolve — to
     * the one person.
     */
    public function testAMemberWhoChangedNameIsReachableUnderBothSpellings(): void
    {
        $lastYear = AttestationsTestHelper::createScoutYear($this->pdo, '2024-2025');
        $thisYear = AttestationsTestHelper::createScoutYear($this->pdo, '2025-2026');

        $memberId = AttestationsTestHelper::createMember($this->pdo, $lastYear, 'Carine', 'Lagard');
        AttestationsTestHelper::addMemberYear($this->pdo, $memberId, $thisYear, 'Carine', 'Lagard-Meunier');

        $directory = $this->repository->buildDirectory();

        $this->assertSame([$memberId], $directory->lookup('Lagard Carine'));
        $this->assertSame([$memberId], $directory->lookup('Lagard-Meunier Carine'));
    }

    public function testTwoMembersOfOneNameBothCome(): void
    {
        $year = AttestationsTestHelper::createScoutYear($this->pdo);

        $first = AttestationsTestHelper::createMember($this->pdo, $year, 'Zoé', 'Herremans');
        $second = AttestationsTestHelper::createMember($this->pdo, $year, 'Zoé', 'Herremans');

        $candidates = $this->repository->buildDirectory()->lookup('Herremans Zoé');
        sort($candidates);

        $this->assertSame([$first, $second], $candidates);
    }

    /**
     * A row whose name cannot be decrypted (a key rotated, a value written
     * by something else) indexes nothing rather than indexing an empty
     * key: half a name would match half the unit, and on a nominative
     * document an over-broad key is a wrong family, not a missing match.
     */
    public function testARowWhoseNameCannotBeReadIndexesNothing(): void
    {
        $year = AttestationsTestHelper::createScoutYear($this->pdo);
        AttestationsTestHelper::createMember($this->pdo, $year, 'Margaux', 'Vandenbrande');

        $stmt = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
        $stmt->execute(['BROKEN']);
        $brokenMemberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$brokenMemberId, $year, 'pas du chiffre', 'pas du chiffre non plus']);

        $directory = $this->repository->buildDirectory();

        // The readable member is still there; the broken row added nothing.
        $this->assertSame(2, $directory->size());
        $this->assertSame([], $directory->lookup('pas du chiffre'));
    }

    public function testASiteWithNoRosterYieldsAnEmptyDirectory(): void
    {
        $this->assertTrue($this->repository->buildDirectory()->isEmpty());
    }
}
