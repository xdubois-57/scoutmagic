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
 *
 * The siblings still in the unit (« Fratrie déjà dans l'unité », after
 * IT-03) are a correlated EXISTS over core's `member_years`, reading the
 * request's own year from the outer row — judged here on the engine and
 * the schema an installed site has.
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

    /**
     * « E-mails de décision à envoyer » (issue #843, IT-01 then IT-05): a
     * correlated NOT EXISTS over registration_decision_emails, choosing the
     * decision with a CASE on the request's status — on the engine that
     * runs it. Sent for the current decision: not counted. Sent for an
     * earlier decision, planned, or never planned: counted.
     */
    public function testTheDecisionEmailCountReadsTheEmailTable(): void
    {
        $toldAccepted = $this->create($this->yearId, 'Ana');
        $this->repository->updateStatus($toldAccepted, 'accepted', null);
        RegistrationTestHelper::recordDecisionEmailSent($this->pdo, $toldAccepted, 'accepted');

        $toldThenRefused = $this->create($this->yearId, 'Eli');
        $this->repository->updateStatus($toldThenRefused, 'accepted', null);
        RegistrationTestHelper::recordDecisionEmailSent($this->pdo, $toldThenRefused, 'accepted');
        $this->repository->updateStatus($toldThenRefused, 'refused', new \DateTimeImmutable());

        $planned = $this->create($this->yearId, 'Noa');
        $this->repository->updateStatus($planned, 'refused', new \DateTimeImmutable());
        (new \Modules\Registration\Repository\DecisionEmailRepository($this->pdo))->insert(
            $planned,
            'refused',
            'scheduled',
            new \DateTimeImmutable('+1 day'),
            1,
            false,
            new \DateTimeImmutable()
        );

        $neverPlanned = $this->create($this->yearId, 'Tom');
        $this->repository->updateStatus($neverPlanned, 'encoded', new \DateTimeImmutable());
        $this->create($this->yearId, 'Zoé');

        $this->assertSame(3, $this->repository->countDecisionEmailsToSendForYears([$this->yearId]));
        $this->assertSame(0, $this->repository->countDecisionEmailsToSendForYears([$this->previousYearId]));

        RegistrationTestHelper::recordDecisionEmailSent($this->pdo, $toldThenRefused, 'refused');
        $this->assertSame(2, $this->repository->countDecisionEmailsToSendForYears([$this->yearId]));
    }

    /**
     * The premise of the siblings query: `member_years.is_active` is the
     * engine's boolean, an active enrolment reads back as 1 and is the
     * column's default — what `my.is_active = 1` compares against.
     */
    public function testAnActiveEnrolmentReadsBackAsOne(): void
    {
        $column = $this->pdo->query(
            "SELECT DATA_TYPE, COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'member_years' AND COLUMN_NAME = 'is_active'"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($column, 'member_years.is_active exists');
        $this->assertSame('tinyint', strtolower((string) $column['DATA_TYPE']));

        $memberId = RegistrationTestHelper::insertMember($this->pdo, 'PREMISE');
        $this->pdo->prepare('INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, '
            . 'last_name_encrypted) VALUES (?, ?, ?, ?)')->execute([$memberId, $this->yearId, 'x', 'x']);
        $this->assertSame(
            1,
            (int) $this->pdo->query("SELECT is_active FROM member_years WHERE member_id = {$memberId}")->fetchColumn()
        );
    }

    /**
     * Declared siblings still in the unit: an active enrolment in the
     * current year or in the request's own year — read per request from
     * the outer row — each sibling once, however many years they are
     * enrolled in, a merged one under the identity it was folded into.
     */
    public function testPresentSiblingsReadTheRequestsOwnYearOnTheRealSchema(): void
    {
        $targetYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');

        $bothYears = $this->member('BOTH', [$this->yearId => true, $targetYearId => true]);
        $reEnrolled = $this->member('NEXT', [$this->yearId => false, $targetYearId => true]);
        $left = $this->member('LEFT', [$this->previousYearId => true, $this->yearId => false]);
        $merged = $this->member('MERGED', []);
        $this->pdo->prepare('UPDATE members SET merged_into_member_id = ? WHERE id = ?')
            ->execute([$this->member('KEPT', [$this->yearId => true]), $merged]);

        $forTarget = $this->create($targetYearId, 'Léa', [$bothYears, $reEnrolled, $left, $merged]);
        $inYear = $this->create($this->yearId, 'Noa', [$reEnrolled, $left]);

        $this->assertSame(
            [$forTarget => [$bothYears, $reEnrolled, $merged]],
            $this->repository->findPresentSiblingMemberIdsForRequests([$forTarget, $inYear], $this->yearId)
        );
        // The screens that show siblings still read all four.
        $this->assertSame([$forTarget => 4], $this->repository->countSiblingsForRequests([$forTarget]));
    }

    /**
     * @param array<int, bool> $enrolments scout year id => is_active
     */
    private function member(string $deskId, array $enrolments): int
    {
        $memberId = RegistrationTestHelper::insertMember($this->pdo, $deskId);
        $insert = $this->pdo->prepare('INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, '
            . 'last_name_encrypted, is_active) VALUES (?, ?, ?, ?, ?)');
        foreach ($enrolments as $yearId => $active) {
            $insert->execute([
                $memberId,
                $yearId,
                $this->encryption->encrypt('Jules', 'member_years.first_name'),
                $this->encryption->encrypt('Dupont', 'member_years.last_name'),
                $active ? 1 : 0,
            ]);
        }

        return $memberId;
    }

    /**
     * @param array<int> $siblingMemberIds
     */
    private function create(int $yearId, string $firstName, array $siblingMemberIds = []): int
    {
        return $this->repository->create($yearId, [
            'parent_name' => 'Marie Dupont', 'child_last_name' => 'Dupont', 'child_first_name' => $firstName,
            'gender' => 'F', 'birth_date' => '2019-05-12', 'street' => 'Rue', 'number' => '1',
            'postal_code' => '1000', 'city' => 'Ville', 'email' => 'marie@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, $siblingMemberIds)['id'];
    }
}
