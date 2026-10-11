<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Registration\Repository;

use Core\Security\EncryptionService;
use Modules\Registration\Repository\RegistrationRequest;
use Modules\Registration\Repository\RegistrationRequestRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Registration\RegistrationTestHelper;
use Tests\UsesProductionEngine;

/**
 * The refusal reason (issue #843, D6) and the two reads of the
 * recommendation queue, on the engine production runs and on the schema
 * the real migration builds — the SQLite fixture of the other tests is
 * written by hand, and a column missing from `schema.sql`, or an ENUM
 * spelled differently there, would pass every one of them.
 *
 * `refusal_reason` is an ENUM: the repository only ever writes one of its
 * two values or NULL, and the premise test checks the engine would not
 * keep anything else.
 */
#[Group('database')]
class RegistrationRequestRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private RegistrationRequestRepository $repository;
    private EncryptionService $encryption;
    private int $previousYearId;
    private int $yearId;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new RegistrationRequestRepository($this->pdo, $this->encryption);
        $this->previousYearId = RegistrationTestHelper::insertScoutYear(
            $this->pdo,
            '2025-2026',
            '2025-09-01',
            '2026-08-31'
        );
        $this->yearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
    }

    /**
     * The premise: the column holds the two reasons and NULL, nothing else.
     */
    public function testTheColumnIsAnEnumOfTheTwoReasons(): void
    {
        $type = $this->pdo->query(
            "SELECT COLUMN_TYPE, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registration_requests'
               AND COLUMN_NAME = 'refusal_reason'"
        )->fetch(\PDO::FETCH_ASSOC);

        $this->assertIsArray($type, 'registration_requests.refusal_reason exists');
        $this->assertSame("enum('no_room','other')", strtolower((string) $type['COLUMN_TYPE']));
        $this->assertSame('YES', $type['IS_NULLABLE']);
    }

    public function testTheReasonIsWrittenWithTheRefusalAndClearedAfterIt(): void
    {
        $id = $this->create($this->yearId, 'Léa');

        $this->repository->updateStatus($id, 'refused', new \DateTimeImmutable(), RegistrationRequest::REFUSAL_NO_ROOM);
        $this->assertSame(RegistrationRequest::REFUSAL_NO_ROOM, $this->repository->findById($id)?->refusalReason);

        $this->repository->updateStatus($id, 'pending', null);
        $this->assertNull($this->repository->findById($id)?->refusalReason);
    }

    public function testTheQueueReadsBothQueriesOnTheRealSchema(): void
    {
        $refused = $this->create($this->previousYearId, 'Lucas');
        $this->repository->updateStatus(
            $refused,
            'refused',
            new \DateTimeImmutable(),
            RegistrationRequest::REFUSAL_NO_ROOM
        );
        $pending = $this->create($this->yearId, 'Lucas');

        $candidates = $this->repository->findQueueCandidatesForYear($this->yearId);
        $this->assertSame([$pending], array_map(static fn($c) => $c->id, $candidates));

        $this->assertSame(
            [$candidates[0]->nameDobBlindIndex => '2025-2026'],
            $this->repository->findNoRoomRefusalYearLabels([$candidates[0]->nameDobBlindIndex], '2026-09-01')
        );
    }

    private function create(int $yearId, string $firstName): int
    {
        return $this->repository->create($yearId, [
            'parent_name' => 'Marie Dupont', 'child_last_name' => 'Dupont', 'child_first_name' => $firstName,
            'gender' => 'F', 'birth_date' => '2019-05-12', 'street' => 'Rue', 'number' => '1',
            'postal_code' => '1000', 'city' => 'Ville', 'email' => 'marie@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, [])['id'];
    }
}
