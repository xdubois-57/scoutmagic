<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Registration\Repository;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Security\EncryptionService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Modules\Registration\Repository\DecisionEmail;
use Modules\Registration\Repository\DecisionEmailRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Service\DecisionEmailService;
use Modules\Registration\Service\RegistrationException;
use Modules\Registration\Service\RequestEmailService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Registration\RegistrationTestHelper;
use Tests\UsesProductionEngine;

/**
 * The decision e-mail claim (issue #843, IT-05) on the engine production
 * runs, on the schema the real migration builds. Everything it rests on is
 * an engine's answer: an INSERT … SELECT that creates nothing when the
 * request's status moved, a UNIQUE index on a nullable column that admits
 * any number of NULLs but one value once, a UNIQUE violation reported as
 * SQLSTATE 23000, and rowCount() after a conditional UPDATE.
 *
 * The races are played across TWO connections to the same database — the
 * second, a separate session, standing for the other click or the other
 * scheduler pass.
 */
#[Group('database')]
class DecisionEmailRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private \PDO $otherSession;
    private EncryptionService $encryption;
    private DecisionEmailRepository $repository;
    private RegistrationRequestRepository $requests;
    private int $yearId;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->otherSession = self::productionEngineConnection(self::$productionEngineDatabase)->getPdo();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new DecisionEmailRepository($this->pdo);
        $this->requests = new RegistrationRequestRepository($this->pdo, $this->encryption, null, $this->repository);
        $this->yearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $this->now = new \DateTimeImmutable('2026-10-12 10:00:00');
    }

    private function request(string $status, string $firstName = 'Léa'): int
    {
        $id = $this->requests->create($this->yearId, [
            'parent_name' => 'Marie Dupont', 'child_last_name' => 'Dupont', 'child_first_name' => $firstName,
            'gender' => 'F', 'birth_date' => '2019-05-12', 'street' => 'Rue', 'number' => '1',
            'postal_code' => '1000', 'city' => 'Ville', 'email' => strtolower($firstName) . '@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, [])['id'];
        if ($status !== 'pending') {
            $this->requests->updateStatus($id, $status, $status === 'accepted' ? null : $this->now);
        }

        return $id;
    }

    /**
     * The premise of the claim: the column admits several NULLs — every
     * settled e-mail carries one — and refuses a value twice, as SQLSTATE
     * 23000, the one code the repository reads as "already in play".
     */
    public function testTheActiveColumnAdmitsManyNullsAndOneValueOnceAs23000(): void
    {
        $index = $this->pdo->query(
            "SELECT NON_UNIQUE, COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registration_decision_emails'
               AND INDEX_NAME = 'idx_rde_active_request'"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($index);
        $this->assertSame(0, (int) $index['NON_UNIQUE']);
        $this->assertSame('active_request_id', $index['COLUMN_NAME']);

        $requestId = $this->request('accepted');
        $insert = $this->pdo->prepare(
            "INSERT INTO registration_decision_emails (registration_request_id, decision, state, active_request_id,
                created_at, updated_at) VALUES (?, 'accepted', ?, ?, NOW(), NOW())"
        );
        $insert->execute([$requestId, 'sent', null]);
        $insert->execute([$requestId, 'failed', null]);
        $insert->execute([$requestId, 'sending', $requestId]);

        try {
            $insert->execute([$requestId, 'scheduled', $requestId]);
            $this->fail('A second row in play for one request must be refused.');
        } catch (\PDOException $e) {
            $this->assertSame('23000', $e->errorInfo[0] ?? null);
        }
    }

    /**
     * The ENUMs are the values the code writes, and no others — a value
     * spelled differently in schema.sql would pass every SQLite test.
     */
    public function testTheEnumsAreTheValuesTheCodeWrites(): void
    {
        $types = [];
        foreach ($this->pdo->query(
            "SELECT COLUMN_NAME, COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registration_decision_emails'
               AND DATA_TYPE = 'enum'"
        )->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $types[(string) $row['COLUMN_NAME']] = strtolower((string) $row['COLUMN_TYPE']);
        }

        $enum = static fn(array $values): string => "enum('" . implode("','", $values) . "')";
        $this->assertSame([
            'cancel_reason' => $enum([DecisionEmail::CANCEL_DECISION_CHANGED]),
            'decision' => $enum(DecisionEmail::DECISIONS),
            'failure_code' => $enum(DecisionEmail::FAILURE_CODES),
            'state' => $enum(['scheduled', 'sending', 'sent', 'failed', 'cancelled']),
        ], (static function (array $types): array {
            ksort($types);

            return $types;
        })($types));
    }

    public function testTheInsertIsGatedOnTheRequestsStatusInOneStatement(): void
    {
        $pending = $this->request('pending');
        $accepted = $this->request('accepted', 'Noa');
        $encoded = $this->request('encoded', 'Eli');

        $this->assertNull($this->repository->insert($pending, 'accepted', 'sending', null, 1, false, $this->now));
        $this->assertNull($this->repository->insert($accepted, 'refused', 'sending', null, 1, false, $this->now));
        $id = $this->repository->insert($accepted, 'accepted', 'scheduled', $this->now, 2, true, $this->now);
        $this->assertNotNull($id);
        $this->assertNotNull($this->repository->insert($encoded, 'accepted', 'sending', null, 1, false, $this->now));

        $row = $this->repository->findById($id);
        $this->assertSame(
            [$accepted, 'scheduled', 2, true],
            [$row?->requestId, $row?->state, $row?->attempt, $row?->isResend]
        );
        $this->assertSame([], $this->repository->findForRequest($pending));
    }

    /**
     * Two clicks: both found nothing in play, both insert. The UNIQUE
     * index lets exactly one through — from whichever session.
     */
    public function testTwoSessionsCreatingTheSameRequestsEmailGetOneRow(): void
    {
        $requestId = $this->request('accepted');
        $other = new DecisionEmailRepository($this->otherSession);

        $first = $this->repository->insert($requestId, 'accepted', 'sending', null, 1, false, $this->now);
        $second = $other->insert($requestId, 'accepted', 'sending', null, 1, false, $this->now);

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertCount(1, $this->repository->findForRequest($requestId));
    }

    /**
     * Two scheduler passes over the same due e-mail: one claim wins. The
     * UPDATE changes the state, so the changed-rows count MySQL reports is
     * the matched-rows count, and the loser reads 0.
     */
    public function testTwoSessionsClaimingTheSameEmailOneWins(): void
    {
        $id = (int) $this->repository->insert(
            $this->request('accepted'),
            'accepted',
            'scheduled',
            $this->now,
            1,
            false,
            $this->now
        );

        $this->assertTrue($this->repository->claim($id, $this->now));
        $this->assertFalse((new DecisionEmailRepository($this->otherSession))->claim($id, $this->now));
        $this->assertSame('sending', $this->repository->findById($id)?->state);
    }

    /**
     * The race the brief names, end to end through the real sender: a
     * first send holds the e-mail; a second, from another session, read
     * "nothing in play" a moment earlier (the read is blinded here, since a
     * test cannot slot a request between two statements). Its INSERT meets
     * the index: it sends nothing, and says so. One e-mail leaves.
     */
    public function testTwoSimultaneousSendsMakeOneEmail(): void
    {
        $requestId = $this->request('accepted');
        $journal = new JournalService(new JournalRepository($this->pdo));

        $first = (new DecisionEmailService($this->repository, $journal))
            ->begin($this->requests->findById($requestId), 'accepted');

        $blinded = new class ($this->otherSession) extends DecisionEmailRepository {
            public function findActiveForRequest(int $requestId): ?DecisionEmail
            {
                return null;
            }
        };
        $mail = $this->createMock(MailService::class);
        $mail->expects($this->never())->method('send');
        $secondSender = new RequestEmailService(
            new RegistrationRequestRepository($this->otherSession, $this->encryption, null, $blinded),
            $mail,
            new EditableContentService(new EditableContentRepository($this->otherSession)),
            new JournalService(new JournalRepository($this->otherSession)),
            'https://example.com',
            'Unité Test',
            new DecisionEmailService($blinded, new JournalService(new JournalRepository($this->otherSession)))
        );

        try {
            $secondSender->sendAccepted($this->requests->findById($requestId), '2026-2027');
            $this->fail('The second send must not go out.');
        } catch (RegistrationException $e) {
            $this->assertStringStartsWith("L'e-mail n'a pas pu être lancé", $e->getMessage());
        }

        $this->repository->markSent($first->id, $this->now);
        $this->assertSame(
            [[$first->id, 'sent']],
            array_map(static fn(DecisionEmail $e) => [$e->id, $e->state], $this->repository->findForRequest($requestId))
        );
    }

    /**
     * The status change and the supersede are one transaction, request
     * row first: a send creating its e-mail from another session after the
     * change sees the new status and creates nothing.
     */
    public function testAfterADecisionChangeNoSessionCanCreateTheOldDecisionsEmail(): void
    {
        $requestId = $this->request('accepted');
        $planned = (int) $this->repository->insert(
            $requestId,
            'accepted',
            'scheduled',
            $this->now,
            1,
            false,
            $this->now
        );

        $this->assertSame(1, $this->requests->updateStatus($requestId, 'refused', $this->now));

        $other = new DecisionEmailRepository($this->otherSession);
        $this->assertNull($other->insert($requestId, 'accepted', 'sending', null, 1, false, $this->now));
        $this->assertFalse($other->claim($planned, $this->now));
        $this->assertSame('cancelled', $this->repository->findById($planned)?->state);
        $this->assertNotNull($other->insert($requestId, 'refused', 'sending', null, 1, false, $this->now));
    }

    public function testTheFirstSendOfTheCurrentDecisionOnTheRealSchema(): void
    {
        $requestId = $this->request('accepted');
        $first = (int) $this->repository->insert($requestId, 'accepted', 'sending', null, 1, false, $this->now);
        $this->repository->markSent($first, new \DateTimeImmutable('2026-10-01 08:00:00'));
        $resend = (int) $this->repository->insert($requestId, 'accepted', 'sending', null, 2, true, $this->now);
        $this->repository->markSent($resend, new \DateTimeImmutable('2026-10-05 08:00:00'));

        $this->assertSame(
            '2026-10-01 08:00:00',
            $this->repository->firstSentAtByRequest([$requestId])[$requestId]['accepted']->format('Y-m-d H:i:s')
        );

        $this->requests->updateStatus($requestId, 'pending', null);
        $this->assertSame([], $this->repository->firstSentAtByRequest([$requestId]));
    }

    public function testDueEmailsUseTheStateAndDueDateOnTheRealSchema(): void
    {
        $due = (int) $this->repository->insert(
            $this->request('accepted'),
            'accepted',
            'scheduled',
            $this->now->modify('-1 hour'),
            1,
            false,
            $this->now
        );
        $undated = $this->request('accepted', 'Noa');
        $this->repository->insert($undated, 'accepted', 'scheduled', null, 1, false, $this->now);

        $this->assertSame(
            [$due],
            array_map(static fn(DecisionEmail $e) => $e->id, $this->repository->findDue($this->now, 5))
        );
    }

    /**
     * R7: the e-mails go with their request even when it is deleted by a
     * statement that does not name them — the schema's ON DELETE CASCADE,
     * which only the real engine enforces.
     */
    public function testDeletingTheRequestRowCascadesToItsEmails(): void
    {
        $requestId = $this->request('refused');
        RegistrationTestHelper::recordDecisionEmailSent($this->pdo, $requestId, 'refused');

        $this->pdo->prepare('DELETE FROM registration_requests WHERE id = ?')->execute([$requestId]);

        $this->assertSame([], $this->repository->findForRequest($requestId));
    }

    public function testThePurgeDeletesTheRequestAndItsEmails(): void
    {
        $requestId = $this->request('refused');
        RegistrationTestHelper::recordDecisionEmailSent($this->pdo, $requestId, 'refused');

        $this->requests->deleteById($requestId);

        $this->assertNull($this->requests->findById($requestId));
        $this->assertSame([], $this->repository->findForRequest($requestId));
    }
}
