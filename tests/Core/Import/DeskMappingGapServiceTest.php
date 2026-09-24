<?php

declare(strict_types=1);

namespace Tests\Core\Import;

use Core\Config\ScoutYearService;
use Core\Import\AgeBranchRepository;
use Core\Import\DeskMappingGapKind;
use Core\Import\DeskMappingGapService;
use Core\Import\FeeCategoryRepository;
use Core\Import\FunctionRepository;
use Modules\Fees\Api\HouseholdTariffRecognitionInterface;
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
     * The one the `Api\` contract decides (ARCHITECTURE.md §7.5): core
     * cannot tell a recognised tariff from an unrecognised one, so it does
     * not guess — no cotisations module, no fee gap.
     */
    public function testWithoutTheCotisationsModuleNoTariffIsEverAGap(): void
    {
        $this->feeCategories->create('reduit_fratrie', 'reduit_fratrie');

        $this->assertSame([], $this->service()->gaps());
    }

    public function testATariffTheCotisationsModuleClaimsNobodyMapsIsAGap(): void
    {
        $id = $this->feeCategories->create('reduit_fratrie', 'reduit_fratrie');

        $gaps = $this->service(new FakeRecognition([$id]))->gaps();

        $this->assertCount(1, $gaps);
        $this->assertSame(DeskMappingGapKind::FEE_CATEGORY, $gaps[0]->kind);
        $this->assertSame('reduit_fratrie', $gaps[0]->rawValue);
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

    private function service(?HouseholdTariffRecognitionInterface $recognition = null): DeskMappingGapService
    {
        return new DeskMappingGapService($this->pdo, new ScoutYearService($this->pdo), $recognition);
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

/**
 * The cotisations module's answer, stubbed. The real one is
 * `Modules\Fees\Service\HouseholdTariffRecognition`, tested against the
 * real barème in its own suite; what matters here is that core asks rather
 * than guesses.
 */
final class FakeRecognition implements HouseholdTariffRecognitionInterface
{
    /** @param list<int> $unmapped */
    public function __construct(private array $unmapped)
    {
    }

    public function recognisesWording(string $deskCode, string $label): bool
    {
        return true;
    }

    /** @return list<int> */
    public function unmappedFeeCategoryIds(): array
    {
        return $this->unmapped;
    }
}
