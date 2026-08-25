<?php

declare(strict_types=1);

namespace Tests\Core\Member\Duplicate;

use Core\Import\MemberRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\Duplicate\DuplicateMemberRepository;
use Core\Member\Duplicate\MemberMergeService;
use Core\Member\Duplicate\MergeException;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Merging two member records: nothing is deleted, everything is
 * repointed, and the abandoned Desk id becomes an alias so a later import
 * cannot re-open the split.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MemberMergeServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private MemberMergeService $service;
    private DuplicateMemberRepository $duplicates;
    private int $lastYear;
    private int $thisYear;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->duplicates = new DuplicateMemberRepository($this->pdo, $this->encryption);
        $this->service = new MemberMergeService(
            $this->pdo,
            $this->duplicates,
            new JournalService(new JournalRepository($this->pdo))
        );

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2024-2025', '2024-09-01', '2025-08-31', 0)");
        $this->lastYear = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->thisYear = (int) $this->pdo->lastInsertId();
    }

    public function testItRepointsTheHistoryAndDeletesNothing(): void
    {
        $kept = $this->createMember('T001', $this->lastYear);
        $duplicate = $this->createMember('T900', $this->thisYear);
        $this->givePhoto($duplicate);
        $this->giveOwnedFile($duplicate);

        $preview = $this->service->merge($kept, $duplicate, 1);

        $this->assertSame(1, $preview->scoutYears);
        $this->assertSame(1, $preview->photos);
        $this->assertSame(1, $preview->files);

        $this->assertSame(2, $this->countRows('member_years', 'member_id', $kept));
        $this->assertSame(0, $this->countRows('member_years', 'member_id', $duplicate));
        $this->assertSame(1, $this->countRows('member_photos', 'member_id', $kept));
        $this->assertSame(1, $this->countRows('files', 'owner_member_id', $kept));

        // The abandoned row is kept, marked — nothing here deletes a member.
        $stmt = $this->pdo->prepare('SELECT merged_into_member_id, merged_at FROM members WHERE id = ?');
        $stmt->execute([$duplicate]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame($kept, (int) $row['merged_into_member_id']);
        $this->assertNotNull($row['merged_at']);
    }

    public function testTheAbandonedDeskIdBecomesAnAliasSoTheSplitCannotReopen(): void
    {
        $kept = $this->createMember('T001', $this->lastYear);
        $duplicate = $this->createMember('T900', $this->thisYear);

        $this->service->merge($kept, $duplicate, 1);

        // The whole point: the next CSV still carries T900.
        $found = (new MemberRepository($this->pdo))->findByDeskId('T900');
        $this->assertNotNull($found);
        $this->assertSame($kept, $found['id']);
    }

    public function testAnImportCarryingTheOldCodeCreatesNothingNew(): void
    {
        $kept = $this->createMember('T001', $this->lastYear);
        $duplicate = $this->createMember('T900', $this->thisYear);
        $this->service->merge($kept, $duplicate, 1);

        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM members')->fetchColumn();
        $this->assertSame($kept, (new MemberRepository($this->pdo))->upsertByDeskId('T900'));
        $this->assertSame($before, (int) $this->pdo->query('SELECT COUNT(*) FROM members')->fetchColumn());
    }

    public function testMergingClosesItsCandidateRow(): void
    {
        $kept = $this->createMember('T001', $this->lastYear);
        $duplicate = $this->createMember('T900', $this->thisYear);
        $candidateId = $this->duplicates->recordCandidate($kept, $duplicate, false);

        $this->service->merge($kept, $duplicate, 1, $candidateId);

        $this->assertSame(0, $this->duplicates->countPending());
        $this->assertSame('merged', $this->duplicates->findById($candidateId)['status']);
    }

    public function testDecidingTheyAreTwoPeopleIsAlsoRemembered(): void
    {
        $kept = $this->createMember('T001', $this->lastYear);
        $duplicate = $this->createMember('T900', $this->thisYear);
        $candidateId = $this->duplicates->recordCandidate($kept, $duplicate, false);

        $this->service->markDistinct($candidateId, 1);

        $this->assertSame(0, $this->duplicates->countPending());
        $this->assertSame('distinct', $this->duplicates->findById($candidateId)['status']);
        // And nothing was moved.
        $this->assertSame(1, $this->countRows('member_years', 'member_id', $duplicate));
    }

    public function testTwoIdentitiesPresentInTheSameYearAreRefused(): void
    {
        $a = $this->createMember('T001', $this->thisYear);
        $b = $this->createMember('T900', $this->thisYear);

        $this->expectException(MergeException::class);
        $this->service->merge($a, $b, 1);
    }

    public function testTheSameYearRefusalMovesNothing(): void
    {
        $a = $this->createMember('T001', $this->thisYear);
        $b = $this->createMember('T900', $this->thisYear);

        try {
            $this->service->merge($a, $b, 1);
        } catch (MergeException) {
            // expected
        }

        $this->assertSame(1, $this->countRows('member_years', 'member_id', $b));
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM member_desk_id_aliases')->fetchColumn());
    }

    public function testAMemberCannotBeMergedIntoItself(): void
    {
        $member = $this->createMember('T001', $this->lastYear);

        $this->expectException(MergeException::class);
        $this->service->merge($member, $member, 1);
    }

    public function testTheMergeIsJournaledAtSecurityLevelWithIdentifiersOnly(): void
    {
        $kept = $this->createMember('T001', $this->lastYear);
        $duplicate = $this->createMember('T900', $this->thisYear);

        $this->service->merge($kept, $duplicate, 1);

        $row = $this->pdo->query("SELECT * FROM event_log WHERE event_type = 'member_merged'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame('security', $row['level']);
        $context = (string) $row['context'];
        $this->assertStringContainsString('"kept_member_id":' . $kept, $context);
        $this->assertStringNotContainsString('T900', $context);
        $this->assertStringNotContainsString('Grosjean', $context);
    }

    public function testThePreviewCountsWithoutMovingAnything(): void
    {
        $kept = $this->createMember('T001', $this->lastYear);
        $duplicate = $this->createMember('T900', $this->thisYear);
        $this->givePhoto($duplicate);

        $preview = $this->service->preview($kept, $duplicate);

        $this->assertSame(1, $preview->photos);
        $this->assertSame(1, $preview->scoutYears);
        $this->assertSame(2, $preview->total());
        // Untouched.
        $this->assertSame(1, $this->countRows('member_photos', 'member_id', $duplicate));
    }

    /* ------------------------------------------------------------------ */

    private function createMember(string $deskId, int $scoutYearId): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
        $stmt->execute([$deskId]);
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, is_active)
             VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $memberId,
            $scoutYearId,
            $this->encryption->encrypt('Pierre', 'member_years.first_name'),
            $this->encryption->encrypt('Grosjean', 'member_years.last_name'),
        ]);

        return $memberId;
    }

    private function givePhoto(int $memberId): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute(['photos/' . $memberId . '.jpg', 'p.jpg', 'image/jpeg', 10, 'chief']);
        $fileId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare('SELECT id FROM scout_years LIMIT 1');
        $stmt->execute();
        $scoutYearId = (int) $stmt->fetchColumn();

        $stmt = $this->pdo->prepare('INSERT INTO member_photos (member_id, scout_year_id, file_id) VALUES (?, ?, ?)');
        $stmt->execute([$memberId, $scoutYearId, $fileId]);
    }

    private function giveOwnedFile(int $memberId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min, owner_member_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(['docs/' . $memberId . '.pdf', 'attestation.pdf', 'application/pdf', 10, 'identified', $memberId]);
    }

    private function countRows(string $table, string $column, int $memberId): int
    {
        $stmt = $this->pdo->prepare(sprintf('SELECT COUNT(*) FROM %s WHERE %s = ?', $table, $column));
        $stmt->execute([$memberId]);

        return (int) $stmt->fetchColumn();
    }
}
