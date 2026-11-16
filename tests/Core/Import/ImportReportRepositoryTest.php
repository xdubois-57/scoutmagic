<?php

declare(strict_types=1);

namespace Tests\Core\Import;

use Core\Import\ImportReportRepository;
use Core\Security\EncryptionService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The names an import report shows, read from the member years.
 */
#[Group('database')]
final class ImportReportRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private ImportReportRepository $repository;
    private int $yearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new ImportReportRepository($this->pdo, $this->encryption);

        $statement = $this->pdo->prepare(
            'INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES (?, ?, ?, 1)'
        );
        $statement->execute(DatabaseTestHelper::scoutYear());
        $this->yearId = (int) $this->pdo->lastInsertId();
    }

    public function testEachMemberIsNamedWithTheirTotem(): void
    {
        $memberId = $this->member('Jean', 'Dupont', 'Baloo', $this->encryption);

        $this->assertSame(
            [$memberId => ['totem' => 'Baloo', 'first_name' => 'Jean', 'last_name' => 'Dupont']],
            $this->repository->findMemberIdentities([$memberId], $this->yearId)
        );
    }

    /**
     * A name that no longer decrypts — written under another key, a row
     * restored from another installation — reads as empty, and the other
     * members of the report are still named.
     */
    public function testANameThatDoesNotDecryptReadsAsEmptyAndTheOthersStillRender(): void
    {
        $readable = $this->member('Jean', 'Dupont', null, $this->encryption);
        $foreign = new EncryptionService(str_repeat('z', 32), str_repeat('y', 32));
        $unreadable = $this->member('Marie', 'Martin', 'Bagheera', $foreign);

        $identities = $this->repository->findMemberIdentities([$readable, $unreadable], $this->yearId);

        $this->assertSame(['totem' => null, 'first_name' => 'Jean', 'last_name' => 'Dupont'], $identities[$readable]);
        $this->assertSame(['totem' => null, 'first_name' => '', 'last_name' => ''], $identities[$unreadable]);
    }

    private function member(string $firstName, string $lastName, ?string $totem, EncryptionService $encryption): int
    {
        $statement = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
        $statement->execute(['T' . bin2hex(random_bytes(4))]);
        $memberId = (int) $this->pdo->lastInsertId();

        $statement = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
                totem_encrypted) VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $memberId,
            $this->yearId,
            $encryption->encrypt($firstName, 'member_years.first_name'),
            $encryption->encrypt($lastName, 'member_years.last_name'),
            $totem === null ? null : $encryption->encrypt($totem, 'member_years.totem'),
        ]);

        return $memberId;
    }
}
