<?php

declare(strict_types=1);

namespace Tests\Modules\Attestations\Service;

use Core\Database\Connection;
use Modules\Attestations\Repository\BatchLineRepository;
use Modules\Attestations\Repository\BatchRepository;
use Modules\Attestations\Repository\MemberNameRepository;
use Modules\Attestations\Service\CoverageService;
use Modules\Attestations\Value\AttestationCategory;
use Modules\Attestations\Value\BatchStatus;
use Modules\Attestations\Value\MatchState;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Attestations\AttestationsTestHelper;

/**
 * Who is still missing a certificate.
 *
 * Three properties carry this screen, and each one is a way the answer
 * could be quietly wrong: the population is the roster of the season shown
 * (not today's, or the families who have left disappear from the question);
 * the comparison is on `members.id` (not the annual row, or the same person
 * counts as missing every year afterwards); and only published batches
 * count (a batch nobody validated gave nobody anything).
 */
#[Group('database')]
class CoverageServiceTest extends TestCase
{
    private \PDO $pdo;
    private BatchRepository $batches;
    private BatchLineRepository $lines;
    private CoverageService $service;
    private int $currentYearId;
    private int $pastYearId;

    /** @var array<string, int> */
    private array $memberIds = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        AttestationsTestHelper::createTables($this->pdo);

        $this->pastYearId = AttestationsTestHelper::createScoutYear($this->pdo, '2024-2025');
        $this->currentYearId = AttestationsTestHelper::createScoutYear($this->pdo, '2025-2026');

        $connection = Connection::withPdo($this->pdo);
        $encryption = AttestationsTestHelper::encryption();
        $members = new MemberNameRepository($connection, $encryption);

        $this->batches = new BatchRepository($connection);
        $this->lines = new BatchLineRepository($connection, $encryption);
        $this->service = new CoverageService($members, $this->lines);
    }

    /** A member present in the past year, and optionally in the current one. */
    private function createMember(string $key, string $first, string $last, bool $stillHere): void
    {
        $memberId = AttestationsTestHelper::createMember($this->pdo, $this->pastYearId, $first, $last);
        if ($stillHere) {
            AttestationsTestHelper::addMemberYear($this->pdo, $memberId, $this->currentYearId, $first, $last);
        }

        $this->memberIds[$key] = $memberId;
    }

    private function createFile(): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute(['a/' . bin2hex(random_bytes(6)), 'x.pdf', 'application/pdf', 10, 'identified']);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param list<string> $memberKeys
     */
    private function publishBatchFor(
        array $memberKeys,
        AttestationCategory $category = AttestationCategory::Tax,
        ?int $scoutYearId = null,
        bool $publish = true
    ): int {
        $scoutYearId ??= $this->pastYearId;
        $batchId = $this->batches->create(
            $scoutYearId, $category, 'Lot ' . bin2hex(random_bytes(3)),
            count($memberKeys) * 2, 2, count($memberKeys), null
        );

        $position = 0;
        foreach ($memberKeys as $key) {
            $position++;
            $this->lines->create(
                $batchId, $position, $position * 2 - 1, $position * 2, 'NOM Prenom',
                $this->memberIds[$key], MatchState::Matched, $this->createFile()
            );
        }

        if ($publish) {
            $stmt = $this->pdo->prepare(
                'UPDATE attestation_batches SET status = ?, published_at = ? WHERE id = ?'
            );
            $stmt->execute([BatchStatus::Published->value, '2026-02-11 09:00:00', $batchId]);
        }

        return $batchId;
    }

    private function coverage(?int $scoutYearId = null): \Modules\Attestations\Value\Coverage
    {
        return $this->service->forYear(AttestationCategory::Tax, $scoutYearId ?? $this->pastYearId);
    }

    private function names(array $summaries): array
    {
        return array_map(static fn($summary): string => $summary->fullName, $summaries);
    }

    public function testTheMissingAreTheRostersMembersWithNoPublishedCertificate(): void
    {
        $this->createMember('margaux', 'Margaux', 'Vandenbrande', true);
        $this->createMember('sacha', 'Sacha', 'Meunier', true);
        $this->publishBatchFor(['margaux']);

        $coverage = $this->coverage();

        $this->assertSame(['Margaux Vandenbrande'], $this->names($coverage->covered));
        $this->assertSame(['Sacha Meunier'], $this->names($coverage->missing));
        $this->assertSame(2, $coverage->total());
        $this->assertFalse($coverage->isComplete());
    }

    /**
     * The trap this screen exists to avoid. A member who has left the unit
     * was there when the certificate was earned, and their family has no
     * page on the site to notice for themselves that nothing arrived.
     */
    public function testAMemberWhoHasSinceLeftIsStillCounted(): void
    {
        $this->createMember('partie', 'Camille', 'Delacroix', false);

        $coverage = $this->coverage();

        $this->assertSame(['Camille Delacroix'], $this->names($coverage->missing));
    }

    /**
     * The comparison is on the person, not the annual row: a certificate
     * covers a member, and matching on `member_years.id` would report them
     * missing from the moment they renew.
     */
    public function testACertificateCoversThePersonWhateverTheirAnnualRows(): void
    {
        $this->createMember('margaux', 'Margaux', 'Vandenbrande', true);
        $this->publishBatchFor(['margaux']);

        $this->assertSame([], $this->names($this->coverage()->missing));
    }

    /** A batch nobody validated has given nobody anything. */
    public function testADraftBatchCoversNobody(): void
    {
        $this->createMember('margaux', 'Margaux', 'Vandenbrande', true);
        $this->publishBatchFor(['margaux'], publish: false);

        $this->assertSame(['Margaux Vandenbrande'], $this->names($this->coverage()->missing));
    }

    /**
     * The one job the category has: a tax certificate and an attendance
     * certificate are two legitimate documents for the same person in the
     * same season, and one must never answer for the other.
     */
    public function testAnAttendanceCertificateDoesNotCoverTheTaxQuestion(): void
    {
        $this->createMember('margaux', 'Margaux', 'Vandenbrande', true);
        $this->publishBatchFor(['margaux'], AttestationCategory::Attendance);

        $this->assertSame(['Margaux Vandenbrande'], $this->names($this->coverage()->missing));
    }

    /** And neither does the same category in another season. */
    public function testACertificateFromAnotherSeasonDoesNotCount(): void
    {
        $this->createMember('margaux', 'Margaux', 'Vandenbrande', true);
        $this->publishBatchFor(['margaux'], scoutYearId: $this->currentYearId);

        $this->assertSame(['Margaux Vandenbrande'], $this->names($this->coverage()->missing));
    }

    /**
     * Three partial files, which is the situation the screen is for: the
     * answer is their union, not the last one.
     */
    public function testSeveralPartialBatchesAddUp(): void
    {
        $this->createMember('a', 'Anne', 'Alpha', true);
        $this->createMember('b', 'Bruno', 'Beta', true);
        $this->createMember('c', 'Chloé', 'Gamma', true);
        $this->publishBatchFor(['a']);
        $this->publishBatchFor(['b']);

        $coverage = $this->coverage();

        $this->assertSame(['Anne Alpha', 'Bruno Beta'], $this->names($coverage->covered));
        $this->assertSame(['Chloé Gamma'], $this->names($coverage->missing));
    }

    public function testAYearNobodyWasRegisteredInIsEmptyRatherThanComplete(): void
    {
        $coverage = $this->coverage();

        $this->assertSame(0, $coverage->total());
        $this->assertSame(0, $coverage->percentage());
    }

    public function testEverybodyCoveredReadsAsComplete(): void
    {
        $this->createMember('margaux', 'Margaux', 'Vandenbrande', true);
        $this->publishBatchFor(['margaux']);

        $coverage = $this->coverage();

        $this->assertTrue($coverage->isComplete());
        $this->assertSame(100, $coverage->percentage());
    }

    /**
     * Alphabetical, because the list is read by a human looking for a name
     * — and on the folded form, so an accented first letter lands where a
     * reader expects it rather than wherever the process locale puts it.
     */
    public function testBothListsAreSortedByNameAccentsIncluded(): void
    {
        $this->createMember('z', 'Zoé', 'Zulu', true);
        $this->createMember('e', 'Émile', 'Echo', true);
        $this->createMember('a', 'Anne', 'Alpha', true);
        $this->createMember('m', 'Marc', 'Mike', true);

        $this->assertSame(
            ['Anne Alpha', 'Émile Echo', 'Marc Mike', 'Zoé Zulu'],
            $this->names($this->coverage()->missing)
        );
    }
}
