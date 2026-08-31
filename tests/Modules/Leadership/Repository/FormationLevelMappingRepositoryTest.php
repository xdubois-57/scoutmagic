<?php

declare(strict_types=1);

namespace Tests\Modules\Leadership\Repository;

use Core\Database\Connection;
use Modules\Leadership\FormationStep;
use Modules\Leadership\Repository\FormationLevelMappingRepository;
use Modules\Leadership\Service\FormationLevelResolver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Leadership\LeadershipTestHelper;

#[Group('database')]
class FormationLevelMappingRepositoryTest extends TestCase
{
    private FormationLevelMappingRepository $repository;

    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        LeadershipTestHelper::createTables($pdo);

        $this->repository = new FormationLevelMappingRepository(Connection::withPdo($pdo));
    }

    public function testSaveThenFindAllReturnsTheFoldedKey(): void
    {
        $this->repository->save('Animateur Breveté', FormationStep::BREVET);

        $this->assertSame(
            [FormationLevelResolver::keyFor('Animateur Breveté') => 'brevet'],
            $this->repository->findAll()
        );
    }

    /**
     * The key is folded, so two spellings of the same wording are one
     * decision — not two rows able to contradict each other.
     */
    public function testSavingAVariantSpellingUpdatesTheSameRow(): void
    {
        $this->repository->save('Animateur Breveté', FormationStep::BREVET);
        $this->repository->save('ANIMATEUR BREVETE', FormationStep::T3);

        $rows = $this->repository->findAllRows();

        $this->assertCount(1, $rows);
        $this->assertSame('t3', $rows[0]['step']);
        // The verbatim value follows the most recent decision, so the page
        // shows the admin the string they actually just acted on.
        $this->assertSame('ANIMATEUR BREVETE', $rows[0]['raw_value']);
    }

    public function testFindAllRowsKeepsTheVerbatimValue(): void
    {
        $this->repository->save('Module transversal 4', FormationStep::T2);

        $this->assertSame(
            [['raw_value' => 'Module transversal 4', 'step' => 't2']],
            $this->repository->findAllRows()
        );
    }

    public function testDeleteRemovesTheDecision(): void
    {
        $this->repository->save('Zorglub', FormationStep::T1);
        $this->repository->delete('zorglub');

        $this->assertSame([], $this->repository->findAll());
    }

    public function testAnEmptyValueIsNeitherStoredNorDeleted(): void
    {
        $this->repository->save('   ', FormationStep::T1);
        $this->repository->delete('   ');

        $this->assertSame([], $this->repository->findAll());
    }

    public function testFindAllOnAnEmptyTable(): void
    {
        $this->assertSame([], $this->repository->findAll());
        $this->assertSame([], $this->repository->findAllRows());
    }

    /**
     * The IT-19 reclassification: what a unit had already decided was "a
     * brevet" is split into the two boxes the ONE ratio needs apart —
     * where, and only where, the wording says which one it was.
     */
    public function testTheReclassificationSplitsTheTwoNamedBrevets(): void
    {
        $this->repository->save('BACV 2024', FormationStep::BREVET);
        $this->repository->save('Woodbadge', FormationStep::BREVET);
        $this->repository->save('Animateur breveté', FormationStep::BREVET);
        $this->repository->save('Module transversal 4', FormationStep::T2);

        $this->assertSame(2, $this->repository->reclassifyLegacyBrevetRows());

        $this->assertSame(
            [
                ['raw_value' => 'Animateur breveté', 'step' => 'brevet'],
                ['raw_value' => 'BACV 2024', 'step' => 'bacv'],
                ['raw_value' => 'Module transversal 4', 'step' => 't2'],
                ['raw_value' => 'Woodbadge', 'step' => 'woodbadge'],
            ],
            $this->repository->findAllRows(),
            'a brevet whose kind nobody wrote down stays on the legacy box'
        );
    }

    /**
     * It runs on a live request and the flag guarding it can be lost — a
     * restored backup, a reset of the settings. Replaying it must be a
     * no-op, and must never undo a decision an admin has since made by
     * hand.
     */
    public function testTheReclassificationIsIdempotentAndNeverUndoesAHumanDecision(): void
    {
        $this->repository->save('BACV 2024', FormationStep::BREVET);
        $this->repository->reclassifyLegacyBrevetRows();

        // A chief decides this wording actually means something else.
        $this->repository->save('BACV 2024', FormationStep::T3);

        $this->assertSame(0, $this->repository->reclassifyLegacyBrevetRows());
        $this->assertSame('t3', $this->repository->findAllRows()[0]['step']);
    }

    /**
     * It matches on the folded key, so it sees exactly the string the
     * resolver sees: casing and surrounding punctuation do not hide a
     * BACV from it — and a wording the folding breaks into letters
     * ("B.A.C.V." folds to "b a c v") is not one it pretends to
     * recognise, which is the same silence the resolver keeps.
     */
    public function testTheReclassificationMatchesOnTheFoldedKey(): void
    {
        $this->repository->save('Brevet — BACV !', FormationStep::BREVET);
        $this->repository->save('Brevet — B.A.C.V.', FormationStep::BREVET);

        $this->assertSame(1, $this->repository->reclassifyLegacyBrevetRows());
        $this->assertSame(
            [
                ['raw_value' => 'Brevet — B.A.C.V.', 'step' => 'brevet'],
                ['raw_value' => 'Brevet — BACV !', 'step' => 'bacv'],
            ],
            $this->repository->findAllRows()
        );
    }

    public function testTheReclassificationOnAnEmptyTable(): void
    {
        $this->assertSame(0, $this->repository->reclassifyLegacyBrevetRows());
    }
}
