<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\MassMail\Service;

use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Member\MemberEmailRepository;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;
use Core\Member\SectionService;
use Core\Security\EncryptionService;
use Modules\MassMail\Service\MassMailAccessService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\MassMail\MassMailAccessFactory;
use Tests\Modules\MassMail\MassMailTestHelper;

/**
 * Issue #1105: the sections an account may target a section list for, and
 * see the mailings of, are the ones it STAFFS — never every section where
 * a linked member holds some function. An animé's function carries their
 * section, and children often share their parent's Desk address.
 */
#[Group('database')]
final class MassMailAccessServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private MassMailAccessService $service;
    private int $year;
    private int $louveteaux;
    private int $pionniers;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);

        [$label, $start, $end] = DatabaseTestHelper::scoutYear();
        $this->pdo->exec(
            'INSERT INTO scout_years (label, start_date, end_date, is_current) '
                . "VALUES ('{$label}', '{$start}', '{$end}', 1)"
        );
        $this->year = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 1)");
        $branch = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('LOU01', {$branch}, 'Meute')");
        $this->louveteaux = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('PIO01', {$branch}, 'Poste')");
        $this->pionniers = (int) $this->pdo->lastInsertId();

        $this->service = MassMailAccessFactory::create(
            $this->pdo,
            $this->encryption,
            new SectionService(
                new SectionRepository($connection),
                new MemberProfileRepository($connection, $this->encryption, new MemberBadgeRepository($this->pdo))
            )
        );
    }

    public function testAChildAniméSharingTheAddressDoesNotAddItsSection(): void
    {
        $this->member('PARENT', 'parent@example.test', $this->pionniers, 'chief');
        $this->member('CHILD', 'parent@example.test', $this->louveteaux, 'identified');

        self::assertSame([$this->pionniers], $this->service->getUserSectionIds('parent@example.test', $this->year));
    }

    public function testALinkedMemberWhoReallyAnimatesAddsItsSection(): void
    {
        $this->member('PARENT', 'parent@example.test', $this->pionniers, 'chief');
        $this->member('OTHER', 'parent@example.test', $this->louveteaux, 'chief');

        $ids = $this->service->getUserSectionIds('parent@example.test', $this->year);
        sort($ids);

        self::assertSame([$this->louveteaux, $this->pionniers], $ids);
    }

    /**
     * The core's resolution reaches an animateur through a currently-valid
     * secondary address as well — the old « any function » loop did too,
     * and an animateur who signs in with it must not lose their section.
     */
    public function testAValidSecondaryAddressCounts(): void
    {
        $member = $this->member('ANIM', 'desk@example.test', $this->louveteaux, 'chief');
        (new MemberEmailRepository($this->pdo, $this->encryption))
            ->create($member, 'perso@example.test', 'manual', 'valid', null, null);

        self::assertSame(
            [$this->louveteaux],
            $this->service->getUserSectionIds('perso@example.test', $this->year)
        );
    }

    public function testTheAuthorizationYearIsTheYearTheSiteIsOn(): void
    {
        // No public-year setting: the date-computed year, which is the one
        // DatabaseTestHelper::scoutYear() labels.
        self::assertSame($this->year, $this->service->authorizationYearId());
    }

    private function member(string $deskId, string $email, int $sectionId, string $role): int
    {
        $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)')->execute([$deskId]);
        $memberId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO member_years
                (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
                 email_encrypted, email_blind_index)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $memberId,
            $this->year,
            $this->encryption->encrypt($deskId, 'member_years.first_name'),
            $this->encryption->encrypt('Famille', 'member_years.last_name'),
            $this->encryption->encrypt($email, 'member_years.email'),
            $this->encryption->blindIndex(EncryptionService::normalizeEmailForIndex($email), 'email'),
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO functions (desk_code, label, role, confirmed) VALUES (?, ?, ?, 1)')
            ->execute(['F-' . $deskId, 'Fonction ' . $deskId, $role]);
        $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function)
             VALUES (?, ?, ?, 1)'
        )->execute([$memberYearId, (int) $this->pdo->lastInsertId(), $sectionId]);

        return $memberId;
    }
}
