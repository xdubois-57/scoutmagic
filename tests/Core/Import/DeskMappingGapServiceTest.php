<?php

declare(strict_types=1);

namespace Tests\Core\Import;

use Core\Config\ScoutYearService;
use Core\Import\AgeBranchRepository;
use Core\Import\DeskMappingGapKind;
use Core\Import\DeskMappingGapService;
use Core\Import\FeeCategoryRepository;
use Core\Import\FunctionRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * What this installation currently fails to recognise (issue #356).
 *
 * The list is DERIVED, so every test here is really the same test asked
 * four ways: resolve the thing, and it leaves by itself.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class DeskMappingGapServiceTest extends TestCase
{
    private \PDO $pdo;
    private FunctionRepository $functions;
    private AgeBranchRepository $branches;
    private FeeCategoryRepository $feeCategories;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->functions = new FunctionRepository($this->pdo);
        $this->branches = new AgeBranchRepository($this->pdo);
        $this->feeCategories = new FeeCategoryRepository($this->pdo);
    }

    public function testAFunctionNobodyQualifiedIsAGap(): void
    {
        $this->functions->create('Animateur Nutons', 'Animateur Nutons', 'identified', false);

        $gaps = $this->service()->gaps();

        $this->assertCount(1, $gaps);
        $this->assertSame(DeskMappingGapKind::FUNCTION, $gaps[0]->kind);
        $this->assertSame('Animateur Nutons', $gaps[0]->rawValue);
    }

    public function testQualifyingTheFunctionRemovesItWithNothingToCleanUp(): void
    {
        $id = $this->functions->create('Animateur Nutons', 'Animateur Nutons', 'identified', false);
        $this->assertCount(1, $this->service()->gaps());

        $this->functions->updateRole($id, 'chief', true);

        $this->assertSame([], $this->service()->gaps());
    }

    public function testABranchTheCodeSortsLastIsAGap(): void
    {
        $this->branches->create('Nutons', 'Nutons');

        $gaps = $this->service()->gaps();

        $this->assertCount(1, $gaps);
        $this->assertSame(DeskMappingGapKind::BRANCH, $gaps[0]->kind);
    }

    public function testABranchTheCodeRecognisesIsNot(): void
    {
        $this->branches->create('Baladins', 'Baladins');

        $this->assertSame([], $this->service()->gaps());
    }

    /**
     * A Desk tariff is not a kind at all, and this is where that decision
     * is checked rather than only written down: one outside the three
     * household ones is an ordinary state, ARCHITECTURE.md §8.75 says
     * reporting it « would be a false positive on every unit », and the
     * site cannot tell it from one of the three spelled unusually.
     */
    public function testATariffOutsideTheThreeIsNeverAGap(): void
    {
        $this->feeCategories->create('Cotisation invités', 'Cotisation invités');
        $this->feeCategories->create('reduit_fratrie', 'reduit_fratrie');

        $this->assertSame([], $this->service()->gaps());
    }

    /**
     * The order is the reading order a chief needs: whatever touches most
     * of the unit first, a single typo last.
     */
    public function testTheMostCarriedValueIsReadFirst(): void
    {
        $scoutYearId = $this->seedScoutYear();
        $widespread = $this->functions->create('Animateur Nutons', 'Animateur Nutons', 'identified', false);
        $this->functions->create('Animateur Baladinss', 'Animateur Baladinss', 'identified', false);

        foreach ([1, 2, 3] as $memberNumber) {
            $this->giveMemberTheFunction($memberNumber, $widespread, $scoutYearId);
        }

        $gaps = $this->service()->gaps();

        $this->assertSame('Animateur Nutons', $gaps[0]->rawValue);
        $this->assertSame(3, $gaps[0]->affectedCount);
        $this->assertSame('Animateur Baladinss', $gaps[1]->rawValue);
        $this->assertSame(0, $gaps[1]->affectedCount);
    }

    /**
     * Zero is a real answer, not an empty one: a function nobody holds any
     * more is still a function this code has no role for, and the next
     * import will hand it to somebody.
     */
    public function testAValueNobodyCarriesIsStillReported(): void
    {
        $this->functions->create('Animateur Nutons', 'Animateur Nutons', 'identified', false);

        $gaps = $this->service()->gaps();

        $this->assertCount(1, $gaps);
        $this->assertSame(0, $gaps[0]->affectedCount);
    }

    private function service(): DeskMappingGapService
    {
        return new DeskMappingGapService($this->pdo, new ScoutYearService($this->pdo));
    }

    private function seedScoutYear(): int
    {
        $label = ScoutYearService::labelForDate(new \DateTimeImmutable());

        return (new ScoutYearService($this->pdo))->ensureYear($label);
    }

    private function giveMemberTheFunction(int $memberNumber, int $functionId, int $scoutYearId): void
    {
        // `members` holds an identity and nothing else — a name is
        // encrypted on `member_years`, and this count never reads one.
        $stmt = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
        $stmt->execute(["T{$memberNumber}"]);
        $memberId = (int) $this->pdo->lastInsertId();

        // The two encrypted name columns are NOT NULL; their content is
        // irrelevant here, and deliberately never read back — this count
        // is a COUNT, and the gap it feeds carries no name at all.
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, is_active)
             VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([$memberId, $scoutYearId, 'x', 'x']);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare('INSERT INTO member_functions (member_year_id, function_id) VALUES (?, ?)');
        $stmt->execute([$memberYearId, $functionId]);
    }
}
