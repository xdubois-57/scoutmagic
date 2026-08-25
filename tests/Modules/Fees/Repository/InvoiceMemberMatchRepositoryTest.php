<?php

declare(strict_types=1);

namespace Tests\Modules\Fees\Repository;

use Core\Security\EncryptionService;
use Modules\Fees\Invoice\PersonMatchKey;
use Modules\Fees\Repository\InvoiceMemberMatchRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Fees\FeesTestHelper;

/**
 * The index an invoice's names are matched against — the module's one
 * decryption of member_years, and the only place a name exists at import
 * time (SECURITY.md §5). What leaves it is keys and members.id.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class InvoiceMemberMatchRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private InvoiceMemberMatchRepository $repository;
    private int $scoutYearId;
    private int $otherYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FeesTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new InvoiceMemberMatchRepository($this->pdo, $this->encryption);

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2024-2025', '2024-09-01', '2025-08-31')");
        $this->otherYearId = (int) $this->pdo->lastInsertId();
    }

    private function createMember(string $first, string $last, ?string $birthDate, ?int $scoutYearId = null): int
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('" . uniqid('', true) . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, birth_date_encrypted)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId,
            $scoutYearId ?? $this->scoutYearId,
            $this->encryption->encrypt($first, 'member_years.first_name'),
            $this->encryption->encrypt($last, 'member_years.last_name'),
            $birthDate === null ? null : $this->encryption->encrypt($birthDate, 'member_years.birth_date'),
        ]);

        return $memberId;
    }

    public function testAMemberIsKeyedBySurnameFirstNameAndBirthDate(): void
    {
        $memberId = $this->createMember('Basile', 'Dubois', '15/03/2012');

        $index = $this->repository->buildIndex($this->scoutYearId);

        $this->assertSame([PersonMatchKey::for('Dubois', 'Basile', '2012-03-15') => $memberId], $index);
    }

    /** Desk exports both `15/03/2012` and `2019-05-22`; both are the same key. */
    public function testAnIsoBirthDateFromDeskProducesTheSameKeyAsADayFirstOne(): void
    {
        $memberId = $this->createMember('Zoé', 'Pissoort', '2019-05-22');

        $index = $this->repository->buildIndex($this->scoutYearId);

        $this->assertArrayHasKey(PersonMatchKey::for('PISSOORT', 'ZOE', '2019-05-22'), $index);
        $this->assertSame($memberId, $index[PersonMatchKey::for('PISSOORT', 'ZOE', '2019-05-22')]);
    }

    /**
     * Twins are registered together and appear on the same invoice line.
     * A key without the first name would merge them and make the count
     * wrong exactly where it matters.
     */
    public function testTwinsAreTwoDistinctKeys(): void
    {
        $one = $this->createMember('Léa', 'Pissoort', '01/09/2013');
        $two = $this->createMember('Lucas', 'Pissoort', '01/09/2013');

        $index = $this->repository->buildIndex($this->scoutYearId);

        $this->assertCount(2, $index);
        $this->assertSame($one, $index[PersonMatchKey::for('Pissoort', 'Léa', '2013-09-01')]);
        $this->assertSame($two, $index[PersonMatchKey::for('Pissoort', 'Lucas', '2013-09-01')]);
    }

    /** Without a birth date there is no key: two namesakes would collide. */
    public function testAMemberWithoutABirthDateIsNotInTheIndexAtAll(): void
    {
        $this->createMember('Basile', 'Dubois', null);

        $this->assertSame([], $this->repository->buildIndex($this->scoutYearId));
    }

    /**
     * Two members the site cannot tell apart: neither is matched, rather
     * than one of them being picked. A wrong match is worse than none.
     */
    public function testTwoMembersSharingAKeyDisqualifyBoth(): void
    {
        $this->createMember('Basile', 'Dubois', '15/03/2012');
        $this->createMember('Basile', 'Dubois', '15/03/2012');

        $this->assertSame([], $this->repository->buildIndex($this->scoutYearId));
    }

    public function testAnotherScoutYearIsNotInTheIndex(): void
    {
        $this->createMember('Basile', 'Dubois', '15/03/2012', $this->otherYearId);

        $this->assertSame([], $this->repository->buildIndex($this->scoutYearId));
    }
}
