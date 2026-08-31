<?php

declare(strict_types=1);

namespace Tests\Modules\Leadership\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Modules\Leadership\FormationStep;
use Modules\Leadership\Repository\FormationLevelMappingRepository;
use Modules\Leadership\Service\FormationStepMigration;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Leadership\LeadershipTestHelper;

/**
 * Roadmap IT-19's one-off reclassification, from the call site's point of
 * view: it runs once, on a page load, and everything that follows must be
 * cheap and silent.
 *
 * @group database
 */
#[Group('database')]
class FormationStepMigrationTest extends TestCase
{
    private \PDO $pdo;
    private FormationLevelMappingRepository $repository;
    private SettingService $settingService;
    /** @var array<int, array{type: string, description: string, context: ?string}> */
    private array $journalEntries = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        LeadershipTestHelper::createTables($this->pdo);

        $this->repository = new FormationLevelMappingRepository(Connection::withPdo($this->pdo));
        $this->settingService = new SettingService(new SettingRepository($this->pdo));
    }

    public function testItReclassifiesOnceAndThenCostsNothing(): void
    {
        $this->repository->save('BACV 2024', FormationStep::BREVET);
        $this->repository->save('Woodbadge', FormationStep::BREVET);

        $this->assertSame(2, $this->migration()->runOnce());
        $this->assertSame('1', (string) $this->settingService->get(FormationStepMigration::SETTING, 'leadership'));

        // A row put back on the legacy box afterwards is NOT re-migrated:
        // the flag is set, and a chief's later decision is theirs.
        $this->repository->save('BACV 2024', FormationStep::BREVET);
        $this->assertSame(0, $this->migration()->runOnce());
        $this->assertSame('brevet', $this->repository->findAll()[
            \Modules\Leadership\Service\FormationLevelResolver::keyFor('BACV 2024')
        ]);
    }

    public function testItJournalsACountAndNothingElse(): void
    {
        $this->repository->save('BACV 2024', FormationStep::BREVET);

        $this->migration()->runOnce();

        $this->assertCount(1, $this->journalEntries);
        $this->assertSame('leadership_formation_steps_migrated', $this->journalEntries[0]['type']);
        $this->assertSame('{"count":1}', (string) $this->journalEntries[0]['context']);
        $this->assertStringNotContainsString('BACV 2024', (string) $this->journalEntries[0]['description']);
    }

    /**
     * An installation with nothing to reclassify — a fresh one, or one
     * whose vocabulary was always precise — gets no journal entry: the
     * flag is set and that is all.
     */
    public function testAnInstallationWithNothingToDoStaysSilent(): void
    {
        $this->repository->save('T2', FormationStep::T2);

        $this->assertSame(0, $this->migration()->runOnce());
        $this->assertSame([], $this->journalEntries);
        $this->assertSame('1', (string) $this->settingService->get(FormationStepMigration::SETTING, 'leadership'));
    }

    /**
     * The flag is registered `editable: false`: it is bookkeeping, not a
     * knob, and Configuration > Réglages must not offer it.
     */
    public function testTheFlagIsNotSomethingAnAdminCanEdit(): void
    {
        $this->migration()->runOnce();

        $row = (new SettingRepository($this->pdo))->findByModuleAndKey('leadership', FormationStepMigration::SETTING);

        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row['editable']);
    }

    private function migration(): FormationStepMigration
    {
        $entries = &$this->journalEntries;
        $journalRepository = new class ($entries) extends JournalRepository {
            /** @param array<int, array{type: string, description: string, context: ?string}> $entries */
            public function __construct(private array &$entries)
            {
            }

            public function insert(
                string $category,
                string $type,
                string $level,
                string $description,
                ?string $context = null,
                ?int $userId = null,
                ?string $ipAddress = null
            ): void {
                $this->entries[] = compact('type', 'description', 'context');
            }
        };

        return new FormationStepMigration(
            $this->repository,
            $this->settingService,
            new JournalService($journalRepository)
        );
    }
}
