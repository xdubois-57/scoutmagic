<?php

declare(strict_types=1);

namespace Tests\Core\Member\Movement;

use Core\Config\ScoutYearService;
use Core\Database\Connection;
use Core\Member\Export\MemberExportRowBuilder;
use Core\Member\MemberEmailRepository;
use Core\Member\Movement\MemberMovementClassifierService;
use Core\Member\Movement\MemberMovementRepository;
use Core\Member\Movement\MemberMovementStatus;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\MemberSectionTotemRepository;
use Core\Member\Repository\SectionRepository;
use Core\Member\SectionContactAccess;
use Core\Member\SectionRosterRepository;
use Core\Member\SectionRosterService;
use Core\Member\SectionService;
use Core\Security\EncryptionService;
use Core\Security\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * « Animés de la section », its Excel export and its PDF call sheet tell the
 * same story about a member (#1323): the rule lives once, in
 * `MemberMovementClassifierService`, and every consumer hands it the same
 * facts — a Twig-only fix would have left a wrong status in the export.
 *
 * The screen and the PDF read the rows `SectionRosterService` builds; the
 * export builds its own entries, which is why it is held here too.
 */
#[Group('database')]
final class MemberMovementConsumersAgreeTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private SectionRosterService $roster;
    private MemberExportRowBuilder $export;
    private int $previousYearId;
    private int $currentYearId;
    private int $louveteaux;
    private int $eclaireurs;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $scoutYears = new ScoutYearService($this->pdo);
        $classifier = new MemberMovementClassifierService(new MemberMovementRepository($this->pdo), $scoutYears);
        $emails = new MemberEmailRepository($this->pdo, $this->encryption);
        $rosterRepository = new SectionRosterRepository($this->pdo, $this->encryption);
        $connection = Connection::withPdo($this->pdo);

        $this->roster = new SectionRosterService(
            $rosterRepository,
            $emails,
            $classifier,
            new MemberSectionTotemRepository($this->pdo, $this->encryption)
        );
        $this->export = new MemberExportRowBuilder(
            $rosterRepository,
            new SectionService(
                new SectionRepository($connection),
                new MemberProfileRepository($connection, $this->encryption, new \Core\Badge\MemberBadgeRepository($this->pdo))
            ),
            $scoutYears,
            $emails,
            $classifier
        );

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2024-2025', '2024-09-01', '2025-08-31')");
        $this->previousYearId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->currentYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 20)");
        $louBranch = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('ECL', 'Éclaireurs', 30)");
        $eclBranch = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('LOU01', {$louBranch}, 'Meute')");
        $this->louveteaux = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('ECL01', {$eclBranch}, 'Troupe')");
        $this->eclaireurs = (int) $this->pdo->lastInsertId();
    }

    public function testAnAnimateurWhoChangesBranchIsASectionChangeEverywhere(): void
    {
        $this->member('Alice', $this->louveteaux, 'chief', $this->eclaireurs, 'chief');

        $this->assertAgree(MemberMovementStatus::SECTION_CHANGE, $this->eclaireurs, 'Alice');
    }

    public function testAnAnimeBecomingAnAnimateurInTheSameSectionIsABranchChangeEverywhere(): void
    {
        $this->member('Bob', $this->louveteaux, 'identified', $this->louveteaux, 'chief');

        $this->assertAgree(MemberMovementStatus::BRANCH_CHANGE, $this->louveteaux, 'Bob');
    }

    public function testAnAnimateurStayingPutIsContinuityEverywhere(): void
    {
        $this->member('Chloé', $this->louveteaux, 'chief', $this->louveteaux, 'chief');

        $this->assertAgree(MemberMovementStatus::CONTINUING, $this->louveteaux, 'Chloé');
    }

    public function testAnAnimeChangingBranchKeepsItsOldStatusEverywhere(): void
    {
        $this->member('Dan', $this->louveteaux, 'identified', $this->eclaireurs, 'identified');

        $this->assertAgree(MemberMovementStatus::BRANCH_CHANGE, $this->eclaireurs, 'Dan');
    }

    /** The PDF call sheet is drawn from the roster rows, not from a rule of its own. */
    public function testThePdfReadsTheStatusFromTheRosterRows(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4) . '/core/Member/SectionRosterPdfService.php');

        $this->assertStringContainsString('movement: $row->movement->status', $source);
        $this->assertStringNotContainsString('MemberMovementClassifierService', $source);
    }

    private function assertAgree(MemberMovementStatus $expected, int $sectionId, string $firstName): void
    {
        $roster = $this->roster->buildRoster([$sectionId], $this->currentYearId);
        $onScreen = null;
        foreach ($roster[$sectionId] as $rows) {
            foreach ($rows as $row) {
                if ($row->firstName === $firstName) {
                    $onScreen = $row->movement->status;
                }
            }
        }

        $exported = null;
        foreach ($this->export->buildForSections([$sectionId], $this->currentYearId, SectionContactAccess::forViewer(Role::CHIEF, [])) as $row) {
            if ($row->firstName === $firstName) {
                $exported = $row->movementStatusLabel;
            }
        }

        $this->assertSame($expected, $onScreen, 'on the page (and so on the PDF)');
        $this->assertSame($expected->label(), $exported, 'in the Excel export');
    }

    private function member(string $firstName, int $previousSection, string $previousRole, int $currentSection, string $currentRole): void
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $this->memberYear($memberId, $this->previousYearId, $firstName, $previousSection, $previousRole);
        $this->memberYear($memberId, $this->currentYearId, $firstName, $currentSection, $currentRole);
    }

    private function memberYear(int $memberId, int $yearId, string $firstName, int $sectionId, string $role): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, is_active)
             VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $memberId,
            $yearId,
            $this->encryption->encrypt($firstName, 'member_years.first_name'),
            $this->encryption->encrypt('Dupont', 'member_years.last_name'),
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $function = $this->pdo->prepare('SELECT id FROM functions WHERE desk_code = ?');
        $function->execute([$role]);
        $functionId = $function->fetchColumn();
        if ($functionId === false) {
            $this->pdo->prepare('INSERT INTO functions (desk_code, label, role) VALUES (?, ?, ?)')
                ->execute([$role, ucfirst($role), $role]);
            $functionId = (int) $this->pdo->lastInsertId();
        }
        $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, 1)'
        )->execute([$memberYearId, $functionId, $sectionId]);
    }
}
