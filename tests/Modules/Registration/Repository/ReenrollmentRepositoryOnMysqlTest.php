<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Registration\Repository;

use Core\Security\EncryptionService;
use Modules\Registration\Repository\FriendWish;
use Modules\Registration\Repository\ReenrollmentAnswer;
use Modules\Registration\Repository\ReenrollmentRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Registration\RegistrationTestHelper;
use Tests\UsesProductionEngine;

/**
 * The two statements of `ReenrollmentRepository` the engine decides, on
 * the engine production runs (issue #481 part D).
 *
 * - **`resolveWish()` reads `rowCount()`** after an UPDATE. SQLite, which
 *   every other test of this repository runs on, reports the rows the
 *   WHERE matched; MySQL and MariaDB report the rows whose value CHANGED,
 *   because the site's connection does not set `MYSQL_ATTR_FOUND_ROWS`. A
 *   chief picking the candidate the wish already names — a double click,
 *   a second tab — changes nothing, and only the `wishExists()` fallback
 *   keeps that from reading as « this wish does not exist » and a 404.
 * - **`saveAnswer()` is an insert-or-update** behind the UNIQUE index on
 *   (member, year): a family answering again must land on the same row,
 *   with the friend wishes rewritten and the `applied_leaving` the
 *   « Départs » link owns left alone — against the real table, foreign
 *   keys included, which the SQLite fixture declares without any.
 *
 * The tables are the migration's, through `Tests\UsesProductionEngine`:
 * never written out here, and never on a connection of this test's own.
 */
#[Group('database')]
class ReenrollmentRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private ReenrollmentRepository $repository;
    private int $yearId;
    private int $memberId;
    private int $friendId;
    private int $otherFriendId;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->repository = new ReenrollmentRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );

        // The rows the foreign keys of `registration_reenrollments` and
        // `registration_friend_wishes` point at. Plain inserts into the
        // real core tables, every id read back rather than assumed.
        $this->yearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $this->memberId = RegistrationTestHelper::insertMember($this->pdo, 'DESK-CHILD');
        $this->friendId = RegistrationTestHelper::insertMember($this->pdo, 'DESK-FRIEND');
        $this->otherFriendId = RegistrationTestHelper::insertMember($this->pdo, 'DESK-OTHER');
    }

    /**
     * First, the premise `resolveWish()`'s fallback exists for: on this
     * engine, an UPDATE that writes the value already there reports no
     * row. The day it reports one, the fallback stops being load-bearing
     * and this says so instead of the test below passing for free.
     */
    public function testThisEngineReportsChangedRowsRatherThanMatchedOnes(): void
    {
        $wishId = $this->wishIdOf($this->saveWithOneWish(FriendWish::MATCH_AMBIGUOUS, null));

        $update = $this->pdo->prepare('UPDATE registration_friend_wishes SET match_state = ? WHERE id = ?');
        $update->execute([FriendWish::MATCH_AMBIGUOUS, $wishId]);

        $this->assertSame(
            0,
            $update->rowCount(),
            'if this ever reports 1, FOUND_ROWS got turned on and the fallback in resolveWish() is no longer tested.'
        );
    }

    /**
     * The chief resolves a wish, then resolves it again to the same
     * member. The second UPDATE changes nothing and MariaDB reports zero
     * rows; the wish still exists, so the answer must still be « done ».
     */
    public function testResolvingAWishToTheMemberItAlreadyNamesStillSucceeds(): void
    {
        $wishId = $this->wishIdOf($this->saveWithOneWish(FriendWish::MATCH_AMBIGUOUS, null));

        $this->assertTrue($this->repository->resolveWish($wishId, $this->friendId), 'the first resolution');
        $this->assertTrue(
            $this->repository->resolveWish($wishId, $this->friendId),
            'resolving again to the same member changed no row, and was read as a missing wish.'
        );

        $wish = $this->repository->findWish($wishId);
        $this->assertNotNull($wish);
        $this->assertSame($this->friendId, $wish->matchedMemberId);
        $this->assertSame(FriendWish::MATCH_RESOLVED, $wish->matchState);
    }

    public function testResolvingAWishThatNoLongerExistsReportsFalse(): void
    {
        $answerId = $this->saveWithOneWish(FriendWish::MATCH_AMBIGUOUS, null);
        $wishId = $this->wishIdOf($answerId);

        // Gone through the real cascade: deleting the answer takes its
        // wishes with it, which leaves an id known to be absent without
        // guessing one.
        $this->pdo->prepare('DELETE FROM registration_reenrollments WHERE id = ?')->execute([$answerId]);

        $this->assertFalse($this->repository->resolveWish($wishId, $this->friendId));
    }

    /**
     * A family changing their mind: the same row, the new decision, and
     * the wishes replaced rather than appended.
     */
    public function testAnsweringAgainUpdatesTheSameRowAndRewritesTheWishes(): void
    {
        $firstId = $this->repository->saveAnswer(
            $this->memberId,
            $this->yearId,
            ReenrollmentAnswer::DECISION_REENROLLED,
            null,
            'Avec son cousin si possible',
            null,
            [
                $this->wish('Léa', $this->friendId, FriendWish::MATCH_UNIQUE),
                $this->wish('Tom', null, FriendWish::MATCH_NONE),
            ]
        );

        $secondId = $this->repository->saveAnswer(
            $this->memberId,
            $this->yearId,
            ReenrollmentAnswer::DECISION_LEAVING,
            null,
            null,
            null,
            [$this->wish('Noé', $this->otherFriendId, FriendWish::MATCH_UNIQUE)]
        );

        $this->assertSame($firstId, $secondId, 'a second answer is an update of the first, not a second row.');
        $this->assertSame(1, $this->countRows('registration_reenrollments'));

        $answer = $this->repository->findAnswer($this->memberId, $this->yearId);
        $this->assertNotNull($answer);
        $this->assertSame(ReenrollmentAnswer::DECISION_LEAVING, $answer->decision);
        $this->assertNull($answer->familyComment, 'an emptied comment is cleared, not kept from the first answer');
        $this->assertCount(1, $answer->friendWishes);
        $this->assertSame('Noé', $answer->friendWishes[0]->rawName);
        $this->assertSame(0, $answer->friendWishes[0]->position);
        $this->assertSame(1, $this->countRows('registration_friend_wishes'), 'the first answer\'s wishes are gone');
    }

    /**
     * The same answer submitted twice inside one second — a double click
     * on « Envoyer ». Every column of the UPDATE writes the value already
     * there, so MariaDB changes no row; the method must neither read that
     * as a missing row nor trip the UNIQUE index with a second INSERT.
     */
    public function testTheSameAnswerTwiceInOneSecondKeepsOneRow(): void
    {
        $wishes = [$this->wish('Léa', $this->friendId, FriendWish::MATCH_UNIQUE)];

        $firstId = $this->repository->saveAnswer(
            $this->memberId,
            $this->yearId,
            ReenrollmentAnswer::DECISION_REENROLLED,
            null,
            null,
            null,
            $wishes
        );
        $secondId = $this->repository->saveAnswer(
            $this->memberId,
            $this->yearId,
            ReenrollmentAnswer::DECISION_REENROLLED,
            null,
            null,
            null,
            $wishes
        );

        $this->assertSame($firstId, $secondId);
        $this->assertSame(1, $this->countRows('registration_reenrollments'));
        $this->assertSame(1, $this->countRows('registration_friend_wishes'));
    }

    /**
     * What the « Départs » link last wrote belongs to that link: a family
     * answering again goes through the UPDATE branch, which must not
     * touch it (the « staff has the last word » rule reads it).
     */
    public function testAnsweringAgainLeavesTheAppliedLeavingMarkerAlone(): void
    {
        $id = $this->repository->saveAnswer(
            $this->memberId,
            $this->yearId,
            ReenrollmentAnswer::DECISION_LEAVING,
            null,
            null,
            null,
            []
        );
        $this->repository->markAppliedLeaving($id, true);

        $this->repository->saveAnswer(
            $this->memberId,
            $this->yearId,
            ReenrollmentAnswer::DECISION_REENROLLED,
            null,
            null,
            null,
            []
        );

        $this->assertTrue($this->repository->findAnswer($this->memberId, $this->yearId)?->appliedLeaving);
    }

    /**
     * `answered_at` carries `DEFAULT CURRENT_TIMESTAMP` in the schema,
     * and the server's clock is not PHP's on a shared host. The column is
     * written from PHP on both branches; this pins that the stored moment
     * is PHP's, whether or not the server agrees.
     */
    public function testAnsweredAtIsPhpsClockOnBothBranches(): void
    {
        // Checked after each save: the update would otherwise overwrite a
        // wrong timestamp from the insert before anything looked at it.
        foreach ([ReenrollmentAnswer::DECISION_REENROLLED, ReenrollmentAnswer::DECISION_LEAVING] as $decision) {
            $before = new \DateTimeImmutable('-1 second');
            $this->repository->saveAnswer($this->memberId, $this->yearId, $decision, null, null, null, []);
            $after = new \DateTimeImmutable('+1 second');

            $answeredAt = $this->repository->findAnswer($this->memberId, $this->yearId)?->answeredAt;
            $this->assertNotNull($answeredAt, $decision);
            $this->assertGreaterThanOrEqual($before->getTimestamp(), $answeredAt->getTimestamp(), $decision);
            $this->assertLessThanOrEqual($after->getTimestamp(), $answeredAt->getTimestamp(), $decision);
        }
    }

    private function saveWithOneWish(string $matchState, ?int $matchedMemberId): int
    {
        return $this->repository->saveAnswer(
            $this->memberId,
            $this->yearId,
            ReenrollmentAnswer::DECISION_REENROLLED,
            null,
            null,
            null,
            [$this->wish('Léa', $matchedMemberId, $matchState)]
        );
    }

    /**
     * @return array{raw_name: string, matched_member_id: ?int, match_state: string}
     */
    private function wish(string $rawName, ?int $matchedMemberId, string $matchState): array
    {
        return ['raw_name' => $rawName, 'matched_member_id' => $matchedMemberId, 'match_state' => $matchState];
    }

    private function wishIdOf(int $answerId): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM registration_friend_wishes WHERE reenrollment_id = ?');
        $stmt->execute([$answerId]);
        $id = $stmt->fetchColumn();
        $this->assertNotFalse($id, 'the fixture wish was not stored');

        return (int) $id;
    }

    /** @param 'registration_reenrollments'|'registration_friend_wishes' $table */
    private function countRows(string $table): int
    {
        // A table name cannot be a bound parameter, so each table this test
        // counts has its own fixed statement rather than a concatenated one.
        $statement = $this->pdo->prepare(match ($table) {
            'registration_reenrollments' => 'SELECT COUNT(*) FROM registration_reenrollments',
            'registration_friend_wishes' => 'SELECT COUNT(*) FROM registration_friend_wishes',
        });
        $statement->execute();

        return (int) $statement->fetchColumn();
    }
}
