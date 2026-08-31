<?php

declare(strict_types=1);

namespace Tests\Modules\Leadership\Service;

use Core\Database\Connection;
use Core\Import\AgeBranchRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\Leadership\FormationStep;
use Modules\Leadership\Repository\FormationLevelMappingRepository;
use Modules\Leadership\Repository\LeadershipRepository;
use Modules\Leadership\Service\FormationLevelResolver;
use Modules\Leadership\Service\LeadershipDeskImportListener;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Leadership\LeadershipTestHelper;

/**
 * Roadmap IT-19: every Desk import leaves ONE journal line naming the
 * formation wordings the site did not understand — or no line at all.
 *
 * The two properties worth pinning are both about restraint: the entry is
 * per import rather than per member, and it carries vocabulary rather than
 * people.
 *
 * @group database
 */
#[Group('database')]
class LeadershipDeskImportListenerTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private FormationLevelMappingRepository $mappingRepository;
    private LeadershipDeskImportListener $listener;
    /** @var array<int, array{category: string, type: string, level: string, description: string, context: ?string}> */
    private array $journalEntries = [];

    private int $yearId;
    private int $sectionId;
    private int $functionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        LeadershipTestHelper::createTables($this->pdo);
        $connection = Connection::withPdo($this->pdo);

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $this->yearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec(
            "INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('Louveteaux', 'Louveteaux', "
            . AgeBranchRepository::canonicalSortOrder('Louveteaux') . ')'
        );
        $branchId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO sections (age_branch_id, desk_code, name) VALUES (?, ?, ?)');
        $stmt->execute([$branchId, 'LOUV', 'Louveteaux']);
        $this->sectionId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('ANIM', 'Animateur', 'chief')");
        $this->functionId = (int) $this->pdo->lastInsertId();

        $this->mappingRepository = new FormationLevelMappingRepository($connection);

        $entries = &$this->journalEntries;
        $journalRepository = new class ($entries) extends JournalRepository {
            /** @param array<int, array{category: string, type: string, level: string, description: string, context: ?string}> $entries */
            public function __construct(private array &$entries)
            {
                // No parent::__construct(): records calls instead of writing.
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
                $this->entries[] = compact('category', 'type', 'level', 'description', 'context');
            }
        };

        $this->listener = new LeadershipDeskImportListener(
            new LeadershipRepository($connection, $this->encryption),
            $this->mappingRepository,
            new FormationLevelResolver(),
            new JournalService($journalRepository)
        );
    }

    public function testAnImportWhereEverythingIsRecognisedWritesNothing(): void
    {
        $this->staffMember('D1', 'T1');
        $this->staffMember('D2', 'BACV');
        $this->staffMember('D3', 'Woodbadge');
        $this->staffMember('D4', null);

        $this->listener->onDeskImportCompleted($this->yearId, [1, 2, 3, 4]);

        $this->assertSame([], $this->journalEntries, 'an import that went well leaves nothing to read past');
    }

    /**
     * The whole point of the entry: a unit of two hundred with three
     * unrecognised wordings gets ONE line, with the counts.
     */
    public function testThreeUnknownWordingsOnTwoHundredMembersWriteOneEntry(): void
    {
        for ($i = 1; $i <= 200; $i++) {
            $level = match (true) {
                $i <= 4 => 'Formation en cours',
                $i <= 6 => 'Module transversal 4',
                $i === 7 => 'Zorglub',
                default => 'T2',
            };
            $this->staffMember('D' . $i, $level);
        }

        $this->listener->onDeskImportCompleted($this->yearId, range(1, 200));

        $this->assertCount(1, $this->journalEntries);
        $entry = $this->journalEntries[0];
        $this->assertSame('leadership', $entry['category']);
        $this->assertSame('leadership_formation_levels_unrecognised', $entry['type']);
        $this->assertSame('info', $entry['level']);
        $this->assertStringContainsString('3 niveaux de formation non reconnus', $entry['description']);

        $context = json_decode((string) $entry['context'], true);
        $this->assertSame(
            ['Formation en cours' => 4, 'Module transversal 4' => 2, 'Zorglub' => 1],
            $context['levels'],
            'the vocabulary and how many people carry it, sorted so two identical imports read identically'
        );
        $this->assertSame($this->yearId, $context['scout_year_id']);
    }

    /**
     * Support needs the words, not who said them. Nothing in the entry
     * names or numbers a member.
     */
    public function testTheEntryCarriesNoMemberReference(): void
    {
        $memberId = $this->staffMember('D1', 'Zorglub', 'Camille', 'Dupont');

        $this->listener->onDeskImportCompleted($this->yearId, [$memberId]);

        $entry = $this->journalEntries[0];
        $context = json_decode((string) $entry['context'], true);

        $this->assertSame(['scout_year_id', 'levels'], array_keys($context));
        $this->assertStringNotContainsString('member', (string) $entry['context']);
        $this->assertStringNotContainsString('Camille', (string) $entry['context']);
        $this->assertStringNotContainsString('Dupont', (string) $entry['context']);
    }

    /**
     * A wording a chief has mapped by hand is understood, so it must not
     * be reported as unrecognised at the next import — otherwise the entry
     * would keep asking for a decision already made.
     */
    public function testAMappedWordingIsNoLongerUnrecognised(): void
    {
        $this->staffMember('D1', 'Zorglub');

        $this->listener->onDeskImportCompleted($this->yearId, [1]);
        $this->assertCount(1, $this->journalEntries);

        $this->mappingRepository->save('Zorglub', FormationStep::T2);
        $this->journalEntries = [];

        $this->listener->onDeskImportCompleted($this->yearId, [1]);
        $this->assertSame([], $this->journalEntries);
    }

    public function testASingleUnknownWordingIsSaidInTheSingular(): void
    {
        $this->staffMember('D1', 'Zorglub');

        $this->listener->onDeskImportCompleted($this->yearId, [1]);

        $this->assertSame(
            'Import Desk : 1 niveau de formation non reconnu',
            $this->journalEntries[0]['description']
        );
    }

    private function staffMember(
        string $deskId,
        ?string $formationLevel,
        string $firstName = 'Prénom',
        string $lastName = 'Nom'
    ): int {
        $stmt = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
        $stmt->execute([$deskId]);
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years
                (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, formation_level, is_active)
             VALUES (?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $memberId,
            $this->yearId,
            $this->encryption->encrypt($firstName, 'member_years.first_name'),
            $this->encryption->encrypt($lastName, 'member_years.last_name'),
            $formationLevel,
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function)
             VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $this->functionId, $this->sectionId]);

        return $memberId;
    }
}
