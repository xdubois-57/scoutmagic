<?php

declare(strict_types=1);

namespace Tests\Core\Member\Duplicate;

use Core\Member\Duplicate\DuplicateMemberDetector;
use Core\Member\Duplicate\DuplicateMemberRepository;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Detecting a member re-created in Desk instead of having their old
 * record reopened.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class DuplicateMemberDetectorTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private DuplicateMemberDetector $detector;
    private DuplicateMemberRepository $repository;
    private int $lastYear;
    private int $thisYear;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new DuplicateMemberRepository($this->pdo, $this->encryption);
        $this->detector = new DuplicateMemberDetector($this->repository, $this->encryption);

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2024-2025', '2024-09-01', '2025-08-31', 0)");
        $this->lastYear = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->thisYear = (int) $this->pdo->lastInsertId();
    }

    public function testAReturningMemberRecreatedUnderANewCodeIsProposed(): void
    {
        $old = $this->createMember('T001', $this->lastYear, 'Grosjean', 'Pierre', '2010-04-12');
        $new = $this->createMember('T900', $this->thisYear, 'Grosjean', 'Pierre', '2010-04-12');

        $this->assertSame(1, $this->detector->detect([$new], $this->thisYear));

        $pending = $this->repository->findPending();
        $this->assertCount(1, $pending);
        $this->assertSame($old, $pending[0]['kept_member_id']);
        $this->assertSame($new, $pending[0]['duplicate_member_id']);
    }

    public function testSpellingAndCaseDifferencesDoNotHideADuplicate(): void
    {
        $this->createMember('T001', $this->lastYear, 'Grosjean', 'Pierre', '2010-04-12');
        $new = $this->createMember('T900', $this->thisYear, '  GROSJEAN ', 'pierre', '2010-04-12');

        $this->assertSame(1, $this->detector->detect([$new], $this->thisYear));
    }

    public function testTwinsAreNotProposed(): void
    {
        // Same surname, same birth date, different first name — which is
        // exactly why the key is all three and never two of them.
        $this->createMember('T001', $this->lastYear, 'Grosjean', 'Pierre', '2010-04-12');
        $new = $this->createMember('T900', $this->thisYear, 'Grosjean', 'Paul', '2010-04-12');

        $this->assertSame(0, $this->detector->detect([$new], $this->thisYear));
    }

    public function testAMemberWithNoBirthDateIsNeverProposed(): void
    {
        $this->createMember('T001', $this->lastYear, 'Grosjean', 'Pierre', null);
        $new = $this->createMember('T900', $this->thisYear, 'Grosjean', 'Pierre', null);

        $this->assertSame(0, $this->detector->detect([$new], $this->thisYear));
    }

    public function testItNeverLooksInsideTheYearBeingImported(): void
    {
        // Desk guarantees one desk_id per person per export, so two rows
        // in the same year are two people. Only earlier years count.
        $this->createMember('T001', $this->thisYear, 'Grosjean', 'Pierre', '2010-04-12');
        $new = $this->createMember('T900', $this->thisYear, 'Grosjean', 'Pierre', '2010-04-12');

        $this->assertSame(0, $this->detector->detect([$new], $this->thisYear));
    }

    public function testOnlyTheMembersThisImportCreatedAreExamined(): void
    {
        $this->createMember('T001', $this->lastYear, 'Grosjean', 'Pierre', '2010-04-12');
        $new = $this->createMember('T900', $this->thisYear, 'Grosjean', 'Pierre', '2010-04-12');

        // The import created nobody: an ordinary re-import proposes nothing.
        $this->assertSame(0, $this->detector->detect([], $this->thisYear));
        // And with the member named, it does.
        $this->assertSame(1, $this->detector->detect([$new], $this->thisYear));
    }

    public function testAPairAlreadyDecidedIsNotProposedAgain(): void
    {
        $old = $this->createMember('T001', $this->lastYear, 'Grosjean', 'Pierre', '2010-04-12');
        $new = $this->createMember('T900', $this->thisYear, 'Grosjean', 'Pierre', '2010-04-12');

        $this->detector->detect([$new], $this->thisYear);
        $candidate = $this->repository->findPending()[0];
        $this->repository->decide($candidate['id'], 'distinct', 1);

        // A list that keeps re-asking a question already answered stops
        // being read.
        $this->assertSame(0, $this->detector->detect([$new], $this->thisYear));
        $this->assertSame(0, $this->repository->countPending());
        unset($old);
    }

    public function testASharedAddressIsRecordedAsAHintNotAsTheReason(): void
    {
        $old = $this->createMember('T001', $this->lastYear, 'Grosjean', 'Pierre', '2010-04-12');
        $new = $this->createMember('T900', $this->thisYear, 'Grosjean', 'Pierre', '2010-04-12');
        $this->giveAddress($old, $this->lastYear, 'rue-de-la-station-5-1000');
        $this->giveAddress($new, $this->thisYear, 'rue-de-la-station-5-1000');

        $this->detector->detect([$new], $this->thisYear);

        $this->assertTrue($this->repository->findPending()[0]['same_address']);
    }

    public function testAPairWithoutASharedAddressIsStillProposed(): void
    {
        $old = $this->createMember('T001', $this->lastYear, 'Grosjean', 'Pierre', '2010-04-12');
        $new = $this->createMember('T900', $this->thisYear, 'Grosjean', 'Pierre', '2010-04-12');
        $this->giveAddress($old, $this->lastYear, 'rue-a');
        $this->giveAddress($new, $this->thisYear, 'rue-b');

        $this->detector->detect([$new], $this->thisYear);

        $pending = $this->repository->findPending();
        $this->assertCount(1, $pending);
        $this->assertFalse($pending[0]['same_address']);
    }

    public function testAnAlreadyMergedIdentityIsNeverProposedAsTheKeptOne(): void
    {
        $old = $this->createMember('T001', $this->lastYear, 'Grosjean', 'Pierre', '2010-04-12');
        $stmt = $this->pdo->prepare('UPDATE members SET merged_into_member_id = ? WHERE id = ?');
        $stmt->execute([$old, $old]);

        $new = $this->createMember('T900', $this->thisYear, 'Grosjean', 'Pierre', '2010-04-12');

        $this->assertSame(0, $this->detector->detect([$new], $this->thisYear));
    }

    /* ------------------------------------------------------------------ */

    private function createMember(string $deskId, int $scoutYearId, string $lastName, string $firstName, ?string $birthDate): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM members WHERE desk_id = ?');
        $stmt->execute([$deskId]);
        $existing = $stmt->fetchColumn();

        if ($existing === false) {
            $stmt = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
            $stmt->execute([$deskId]);
            $memberId = (int) $this->pdo->lastInsertId();
        } else {
            $memberId = (int) $existing;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, birth_date_encrypted, is_active)
             VALUES (?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $memberId,
            $scoutYearId,
            $this->encryption->encrypt($firstName, 'member_years.first_name'),
            $this->encryption->encrypt($lastName, 'member_years.last_name'),
            $birthDate !== null ? $this->encryption->encrypt($birthDate, 'member_years.birth_date') : null,
        ]);

        return $memberId;
    }

    private function giveAddress(int $memberId, int $scoutYearId, string $normalized): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM member_years WHERE member_id = ? AND scout_year_id = ?');
        $stmt->execute([$memberId, $scoutYearId]);
        $memberYearId = (int) $stmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_addresses (member_year_id, address_type, address_normalized_blind_index) VALUES (?, ?, ?)'
        );
        $stmt->execute([$memberYearId, 'Domicile', $this->encryption->blindIndex($normalized, 'address')]);
    }
}
