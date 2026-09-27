<?php

declare(strict_types=1);

namespace Tests\Core\Badge;

use Core\Badge\BadgeHolders;
use Core\Badge\BadgeHolderService;
use Core\Badge\BadgeRepository;
use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;
use Core\Member\SectionService;
use Core\Security\EncryptionService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Who wore which badge in one scout year — the Badges holders pages of the
 * Espace chefs d'U (issue #621, docs/chantiers/CHANTIER-badges.md).
 */
#[Group('database')]
final class BadgeHolderServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private BadgeRepository $badges;
    private MemberBadgeRepository $memberBadges;
    private BadgeHolderService $service;
    private int $yearId;
    private int $otherYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);
        $this->badges = new BadgeRepository($this->pdo);
        $this->memberBadges = new MemberBadgeRepository($this->pdo);
        $sections = new SectionService(
            new SectionRepository($connection),
            new MemberProfileRepository($connection, $this->encryption, $this->memberBadges)
        );
        $this->service = new BadgeHolderService($this->badges, $this->memberBadges, $sections);

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 0)");
        $this->otherYearId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2026-2027', '2026-09-01', '2027-08-31', 1)");
        $this->yearId = (int) $this->pdo->lastInsertId();
    }

    public function testAYearWithoutAnyBadgeWornListsNothing(): void
    {
        $this->badge('Infirmier', default: true);

        $this->assertSame([], $this->service->holdersForYear($this->yearId));
    }

    public function testOnlyBadgesWornThatYearAreListedWithTheirHolders(): void
    {
        $section = $this->section('LUT', 'Lutins');
        $nurse = $this->badge('Infirmier', default: true);
        $this->badge('Trésorier', default: true);
        $claire = $this->memberYear('Claire', 'Renard', $this->yearId, $section, 'Animatrice');
        $this->memberBadges->assign($claire, $nurse, null);
        // Worn the year before: not this year's.
        $past = $this->memberYear('Paul', 'Ancien', $this->otherYearId, $section, 'Animateur');
        $this->memberBadges->assign($past, $this->badge('Communication'), null);

        $groups = $this->service->holdersForYear($this->yearId);

        $this->assertSame(['Infirmier'], $this->names($groups));
        $holder = $groups[0]->holders[0];
        $this->assertSame('Claire Renard', $holder->name);
        $this->assertSame('Lutins', $holder->sectionName);
        $this->assertSame('Animatrice', $holder->functionLabel);
    }

    /**
     * The link to the person's sheet carries the member_year id —
     * /admin/members/{id} reads a member_year and moves to the person's
     * latest year itself. The two ids are made to differ here, so a
     * members.id in its place would fail rather than pass by coincidence.
     */
    public function testTheLinkCarriesTheMemberYearOfTheYearReadNeverTheMembersId(): void
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('PADDING-1'), ('PADDING-2'), ('PADDING-3')");
        $section = $this->section('LUT', 'Lutins');
        $nurse = $this->badge('Infirmier', default: true);
        $memberYearId = $this->memberYear('Claire', 'Renard', $this->yearId, $section, 'Animatrice');
        $memberId = (int) $this->pdo->query("SELECT member_id FROM member_years WHERE id = {$memberYearId}")->fetchColumn();
        $this->assertNotSame($memberId, $memberYearId, 'The fixture must tell the two ids apart.');
        $this->memberBadges->assign($memberYearId, $nurse, null);

        $this->assertSame($memberYearId, $this->service->holdersForYear($this->yearId)[0]->holders[0]->memberYearId);
    }

    public function testADeactivatedBadgeStillWornStaysListed(): void
    {
        $section = $this->section('LUT', 'Lutins');
        $upkeep = $this->badge('Intendance');
        $this->badges->setActive($upkeep, false);
        $this->memberBadges->assign($this->memberYear('Ahmed', 'Bensalah', $this->yearId, $section, 'Intendant'), $upkeep, null);

        $groups = $this->service->holdersForYear($this->yearId);

        $this->assertSame(['Intendance'], $this->names($groups));
        $this->assertFalse($groups[0]->badge->isActive);
    }

    /**
     * Default badges, then the automatic ones in their sections' order,
     * then the others by name — never by holder count, which would
     * reshuffle the page from one week to the next.
     */
    public function testTheOrderIsStableDefaultThenAutomaticBySectionThenTheRestByName(): void
    {
        $pio = $this->section('PIO', 'Pionniers', branchOrder: 3);
        $lut = $this->section('LUT', 'Lutins', branchOrder: 1);
        $person = $this->memberYear('Marie', 'Dubois', $this->yearId, $lut, 'Chef d\'unité adjointe');
        $other = $this->memberYear('Julien', 'Peeters', $this->yearId, $pio, 'Animateur');

        $ids = [
            $this->badge('Zèbre'),
            $this->badge('Communication'),
            $this->badge('Référent Pionniers', default: true, referentSection: $pio),
            $this->badge('Référent Lutins', default: true, referentSection: $lut),
            $this->badge('Trésorier', default: true),
            $this->badge('Infirmier', default: true),
        ];
        foreach ($ids as $badgeId) {
            $this->memberBadges->assign($person, $badgeId, null);
        }
        // More holders never moves a badge up.
        $this->memberBadges->assign($other, $ids[0], null);

        $groups = $this->service->holdersForYear($this->yearId);

        $this->assertSame(
            ['Infirmier', 'Trésorier', 'Référent Lutins', 'Référent Pionniers', 'Communication', 'Zèbre'],
            $this->names($groups)
        );
        $this->assertTrue($groups[2]->isAutomatic());
        $this->assertFalse($groups[0]->isAutomatic(), 'A default badge is not an automatic one.');
    }

    public function testHoldersAreListedByName(): void
    {
        $section = $this->section('LUT', 'Lutins');
        $badge = $this->badge('Communication');
        foreach (['Zoé', 'Émile', 'Adèle'] as $firstName) {
            $this->memberBadges->assign($this->memberYear($firstName, 'X', $this->yearId, $section, 'Animateur'), $badge, null);
        }

        $holders = $this->service->holdersForYear($this->yearId)[0]->holders;

        $this->assertSame(['Adèle X', 'Émile X', 'Zoé X'], array_map(static fn($h) => $h->name, $holders));
    }

    /** @param list<BadgeHolders> $groups @return list<string> */
    private function names(array $groups): array
    {
        return array_map(static fn(BadgeHolders $g): string => $g->badge->name, $groups);
    }

    private function badge(string $name, bool $default = false, ?int $referentSection = null): int
    {
        return $this->badges->create($name, $default, $referentSection);
    }

    private function section(string $deskCode, string $name, int $branchOrder = 1): int
    {
        $this->pdo->prepare('INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, ?)')
            ->execute(['B' . $deskCode, 'Branche ' . $name, $branchOrder]);
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name, is_visible) VALUES (?, ?, ?, 1)')
            ->execute([$deskCode, $branchId, $name]);

        return (int) $this->pdo->lastInsertId();
    }

    private function memberYear(string $firstName, string $lastName, int $yearId, int $sectionId, string $function): int
    {
        $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)')->execute(['D-' . bin2hex(random_bytes(4))]);
        $memberId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted) VALUES (?, ?, ?, ?)'
        )->execute([
            $memberId,
            $yearId,
            $this->encryption->encrypt($firstName, 'member_years.first_name'),
            $this->encryption->encrypt($lastName, 'member_years.last_name'),
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT OR IGNORE INTO functions (desk_code, label, role) VALUES (?, ?, ?)')
            ->execute([$function, $function, 'chief']);
        $functionId = (int) $this->pdo->query(
            'SELECT id FROM functions WHERE desk_code = ' . $this->pdo->quote($function)
        )->fetchColumn();
        $branchId = (int) $this->pdo->query("SELECT age_branch_id FROM sections WHERE id = {$sectionId}")->fetchColumn();
        $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, age_branch_id, is_main_function) VALUES (?, ?, ?, ?, 1)'
        )->execute([$memberYearId, $functionId, $sectionId, $branchId]);

        return $memberYearId;
    }
}
