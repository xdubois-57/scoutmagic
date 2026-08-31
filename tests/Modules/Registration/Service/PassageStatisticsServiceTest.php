<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Member\SectionService;
use Core\Security\EncryptionService;
use Modules\Registration\Api\ProjectedPerson;
use Modules\Registration\Api\ProjectedPopulationProvider;
use Modules\Registration\Api\ProjectedRecipient;
use Modules\Registration\Api\ProjectedSectionTotals;
use Modules\Registration\Service\PassageStatisticsService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * The Passage page's statistics box (spec §8).
 *
 * The box is a grouping, not a computation: every figure comes from the
 * projection (`Api\ProjectedPopulationProvider`), which is the same
 * `ForecastService` the Prévisions page reads. What is worth pinning is
 * therefore what the grouping DECIDES:
 *
 * - the two scopes are different questions, and « Arrivées seules » really
 *   does drop the animés who simply stay — a switch that quietly showed
 *   the same numbers would be a lie in one label;
 * - a person nobody has placed is in no section AND in the dedicated
 *   counter, never silently absent from both;
 * - the third gender bucket is « non renseigné » and never an assertion
 *   about anybody;
 * - the total bar's scale is the BRANCH's biggest section, so two bars in
 *   one card are comparable to each other, which is the only comparison
 *   the page invites.
 *
 * @group database
 */
class PassageStatisticsServiceTest extends TestCase
{
    private \PDO $pdo;
    private SectionService $sectionService;
    private int $louveteauxA;
    private int $louveteauxB;
    private int $eclaireursA;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $louveteaux = RegistrationTestHelper::insertAgeBranch($this->pdo, 'LOUV', 'Louveteaux', 20);
        $eclaireurs = RegistrationTestHelper::insertAgeBranch($this->pdo, 'ECLA', 'Éclaireurs', 30);

        $this->louveteauxA = $this->createSection('LOUV1', $louveteaux, 'Louveteaux A');
        $this->louveteauxB = $this->createSection('LOUV2', $louveteaux, 'Louveteaux B');
        $this->eclaireursA = $this->createSection('ECLA1', $eclaireurs, 'Éclaireurs A');

        $this->sectionService = new SectionService(
            Connection::withPdo($this->pdo),
            $encryption,
            new MemberBadgeRepository($this->pdo)
        );
    }

    // ── the two scopes ────────────────────────────────────────────────

    public function testTheProjectedScopeHoldsEverybody(): void
    {
        $stats = $this->statistics($this->population());

        $this->assertSame(4, $this->sectionTotal($stats, $this->louveteauxA, 'projected'));
        $this->assertSame(1, $this->sectionTotal($stats, $this->louveteauxB, 'projected'));
        $this->assertSame(1, $this->sectionTotal($stats, $this->eclaireursA, 'projected'));
    }

    public function testArrivalsOnlyDropsTheAnimesWhoStay(): void
    {
        $stats = $this->statistics($this->population());

        // Louveteaux A holds two continuing animés, one accepted
        // registration and one already-encoded Desk row. Only the
        // registration is an arrival.
        $this->assertSame(
            1,
            $this->sectionTotal($stats, $this->louveteauxA, 'arrivals'),
            'A continuing animé is not an arrival, and neither is a row Desk already holds — '
                . 'nobody assigns either of them on this page.'
        );
        // Éclaireurs A holds one branch change: an arrival.
        $this->assertSame(1, $this->sectionTotal($stats, $this->eclaireursA, 'arrivals'));
        // Louveteaux B holds one continuing animé: nothing arrives.
        $this->assertSame(0, $this->sectionTotal($stats, $this->louveteauxB, 'arrivals'));
    }

    public function testTheTwoScopesAreNotTheSameNumbers(): void
    {
        // The point of the switch: if these ever agreed, one of the two
        // labels would be lying.
        $stats = $this->statistics($this->population());

        $this->assertNotSame(
            $this->branchTotal($stats, 'Louveteaux', 'projected'),
            $this->branchTotal($stats, 'Louveteaux', 'arrivals')
        );
    }

    // ── nobody falls between two counters ─────────────────────────────

    public function testSomebodyWithNoSectionIsCountedOnlyInTheDedicatedCounter(): void
    {
        $stats = $this->statistics([
            new ProjectedPerson(1, null, null, null, 'male', false, 'passage'),
            new ProjectedPerson(null, 7, null, 1, 'female', false, 'registration'),
        ]);

        $this->assertSame(2, $stats['unassigned']['projected']);
        $this->assertSame(2, $stats['unassigned']['arrivals']);
        $this->assertSame([], $stats['branches'], 'Nobody is in a section, so no branch has anything to show.');
    }

    public function testAPersonPointingAtASectionThatNoLongerExistsIsNotLost(): void
    {
        $stats = $this->statistics([
            new ProjectedPerson(1, null, 9999, 1, 'male', false, 'passage'),
        ]);

        $this->assertSame(
            1,
            $stats['unassigned']['projected'],
            'A deleted section is not a decision still to make, but it is not a reason to drop somebody either.'
        );
    }

    public function testEverybodyIsCountedExactlyOnceAcrossSectionsAndTheCounter(): void
    {
        $people = $this->population();
        $stats = $this->statistics($people);

        $inSections = 0;
        foreach ($stats['branches'] as $branch) {
            $inSections += $branch['scopes']['projected']['total'];
        }

        $this->assertSame(count($people), $inSections + $stats['unassigned']['projected']);
    }

    // ── what the box says about people ────────────────────────────────

    public function testAnUnknownGenderIsNotRenseigneAndNotAnAssertion(): void
    {
        $stats = $this->statistics([
            new ProjectedPerson(1, null, $this->louveteauxA, 1, 'other', false, 'registration'),
            new ProjectedPerson(2, null, $this->louveteauxA, 1, 'male', false, 'registration'),
        ]);

        $counts = $this->sectionCounts($stats, $this->louveteauxA, 'projected');
        $this->assertSame(1, $counts['unknown']);
        $this->assertSame(1, $counts['male']);
        $this->assertSame(0, $counts['female']);
        $this->assertSame(2, $counts['total']);
    }

    public function testCertainAndHypothesisAddUpToTheTotal(): void
    {
        $stats = $this->statistics($this->population());

        foreach ($stats['branches'] as $branch) {
            foreach ($branch['sections'] as $section) {
                foreach ($section['scopes'] as $counts) {
                    $this->assertSame($counts['total'], $counts['certain'] + $counts['hypothesis']);
                    $this->assertSame($counts['total'], $counts['male'] + $counts['female'] + $counts['unknown']);
                }
            }
        }
    }

    // ── how the bars are scaled ───────────────────────────────────────

    public function testTheBarScaleIsTheBranchsBiggestSectionNotTheUnits(): void
    {
        $stats = $this->statistics($this->population());

        $louveteaux = $this->branch($stats, 'Louveteaux');
        $eclaireurs = $this->branch($stats, 'Éclaireurs');

        $this->assertSame(4, $louveteaux['scopes']['projected']['max']);
        $this->assertSame(
            1,
            $eclaireurs['scopes']['projected']['max'],
            "A branch of small sections must not be flattened by another branch's big one — "
                . 'the comparison the page invites is between the sections of ONE branch.'
        );
    }

    public function testSectionsKeepTheUnitsOwnOrderAndColour(): void
    {
        $stats = $this->statistics($this->population());
        $louveteaux = $this->branch($stats, 'Louveteaux');

        $this->assertSame(
            ['Louveteaux A', 'Louveteaux B'],
            array_column($louveteaux['sections'], 'label')
        );
        foreach ($louveteaux['sections'] as $section) {
            $this->assertMatchesRegularExpression('/^#[0-9a-fA-F]{6}$/', $section['color']);
        }
    }

    public function testABranchNobodyIsProjectedIntoIsAbsentRatherThanEmpty(): void
    {
        $stats = $this->statistics([
            new ProjectedPerson(1, null, $this->louveteauxA, 1, 'male', false, 'continuing'),
        ]);

        $this->assertSame(['Louveteaux'], array_column($stats['branches'], 'label'));
    }

    // ── without the module that owns the projection ───────────────────

    public function testWithoutTheProjectionTheBoxSaysSoRatherThanShowingZeros(): void
    {
        $stats = (new PassageStatisticsService($this->sectionService, null))->forTargetYear(1);

        $this->assertFalse($stats['available']);
        $this->assertSame([], $stats['branches']);
    }

    // ── fixture ───────────────────────────────────────────────────────

    /**
     * Six people, chosen so every origin is represented and the two scopes
     * cannot come out equal: two continuing animés, one already-encoded
     * Desk row, one accepted registration, one branch change, and one
     * nobody has placed.
     *
     * @return array<int, ProjectedPerson>
     */
    private function population(): array
    {
        return [
            new ProjectedPerson(1, null, $this->louveteauxA, 2, 'male', false, 'continuing'),
            new ProjectedPerson(2, null, $this->louveteauxA, 3, 'female', false, 'continuing'),
            new ProjectedPerson(3, null, $this->louveteauxA, 4, 'female', true, 'desk'),
            new ProjectedPerson(null, 7, $this->louveteauxA, 1, 'other', false, 'registration'),
            new ProjectedPerson(4, null, $this->louveteauxB, 2, 'male', false, 'continuing'),
            new ProjectedPerson(5, null, $this->eclaireursA, 1, 'male', false, 'passage'),
            new ProjectedPerson(6, null, null, null, 'male', false, 'passage'),
        ];
    }

    /**
     * @param array<int, ProjectedPerson> $people
     * @return array{available: bool, branches: array<int, array<string, mixed>>, unassigned: array<string, int>}
     */
    private function statistics(array $people): array
    {
        return (new PassageStatisticsService($this->sectionService, $this->projection($people)))->forTargetYear(1);
    }

    /**
     * @param array<int, ProjectedPerson> $people
     */
    private function projection(array $people): ProjectedPopulationProvider
    {
        return new class ($people) implements ProjectedPopulationProvider {
            /** @param array<int, ProjectedPerson> $people */
            public function __construct(private array $people)
            {
            }

            /** @return array<int, ProjectedPerson> */
            public function projectedPopulation(int $targetScoutYearId): array
            {
                return $this->people;
            }

            /** @return array<int, ProjectedSectionTotals> */
            public function projectedSectionTotals(int $targetScoutYearId): array
            {
                return [];
            }

            /** @return array<int, ProjectedRecipient> */
            public function reachableRecipients(int $targetScoutYearId): array
            {
                return [];
            }
        };
    }

    /**
     * @param array{available: bool, branches: array<int, array<string, mixed>>, unassigned: array<string, int>} $stats
     * @return array<string, mixed>
     */
    private function branch(array $stats, string $label): array
    {
        foreach ($stats['branches'] as $branch) {
            if ($branch['label'] === $label) {
                return $branch;
            }
        }

        $this->fail("No branch labelled {$label} in the statistics.");
    }

    /**
     * @param array{available: bool, branches: array<int, array<string, mixed>>, unassigned: array<string, int>} $stats
     */
    private function branchTotal(array $stats, string $label, string $scope): int
    {
        return $this->branch($stats, $label)['scopes'][$scope]['total'];
    }

    /**
     * @param array{available: bool, branches: array<int, array<string, mixed>>, unassigned: array<string, int>} $stats
     */
    private function sectionTotal(array $stats, int $sectionId, string $scope): int
    {
        return $this->sectionCounts($stats, $sectionId, $scope)['total'];
    }

    /**
     * @param array{available: bool, branches: array<int, array<string, mixed>>, unassigned: array<string, int>} $stats
     * @return array{total: int, certain: int, hypothesis: int, male: int, female: int, unknown: int}
     */
    private function sectionCounts(array $stats, int $sectionId, string $scope): array
    {
        foreach ($stats['branches'] as $branch) {
            foreach ($branch['sections'] as $section) {
                if ($section['id'] === $sectionId) {
                    return $section['scopes'][$scope];
                }
            }
        }

        $this->fail("Section {$sectionId} is in none of the branches.");
    }

    private function createSection(string $deskCode, int $branchId, string $name): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name, is_visible) VALUES (?, ?, ?, 1)');
        $stmt->execute([$deskCode, $branchId, $name]);

        return (int) $this->pdo->lastInsertId();
    }
}
