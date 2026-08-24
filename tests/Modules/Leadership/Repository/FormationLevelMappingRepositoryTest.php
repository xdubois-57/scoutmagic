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
}
