<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Service;

use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Rental\Repository\RentalAssetManagerRepository;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Service\ManagerRecipientResolver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * Who hears about an asset (#708, IT-05): its managers with an account, and
 * the Staff d'U only when none of them can be reached — journaled, with no
 * personal data.
 */
#[Group('database')]
class ManagerRecipientResolverTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private RentalAssetManagerRepository $managerRepository;
    private int $scoutYearId;
    private int $assetId;
    /** @var list<int> */
    private array $staff = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->managerRepository = new RentalAssetManagerRepository($this->pdo);
        $this->scoutYearId = (new \Core\Config\ScoutYearService($this->pdo))->getCurrentYear()['id'];
        $this->assetId = (new RentalAssetRepository($this->pdo, $this->encryption))->create(
            'Local',
            'Local Saint-Georges',
            'local-saint-georges',
            60,
            1,
            '18:00',
            '11:00',
            null,
            true
        );
    }

    private function resolver(bool $withStaff = true): ManagerRecipientResolver
    {
        return new ManagerRecipientResolver(
            $this->managerRepository,
            new MemberYearRepository($this->pdo),
            new UserAccountRepository($this->pdo, $this->encryption),
            new JournalService(new JournalRepository($this->pdo)),
            $withStaff ? fn(): array => $this->staff : null
        );
    }

    private function member(string $email, bool $withAccount): int
    {
        $memberId = RentalTestHelper::insertMember($this->pdo, 'D-' . strtoupper(substr(md5($email), 0, 8)));
        RentalTestHelper::insertMemberYear($this->pdo, $this->encryption, $memberId, $this->scoutYearId, $email);

        if ($withAccount) {
            $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)')
                ->execute([
                    $this->encryption->encrypt($email, 'user_accounts.email'),
                    $this->encryption->blindIndex(strtolower($email), 'email'),
                ]);
        }

        return $memberId;
    }

    /** @return list<int> */
    private function memberIdsOf(array $recipients): array
    {
        return array_map(static fn(array $r): int => (int) $r['memberId'], $recipients);
    }

    private function journal(): string
    {
        return (string) json_encode($this->pdo->query('SELECT * FROM event_log')->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function testTheReachableManagersAreTheRecipientsAndNotTheStaff(): void
    {
        $manager = $this->member('gestion@unite.be', true);
        $this->managerRepository->grant($this->assetId, $manager, false);
        $this->staff = [$this->member('staffdu@unite.be', true)];

        $this->assertSame([$manager], $this->memberIdsOf($this->resolver()->recipientsFor($this->assetId, 'new_request')));
        $this->assertStringNotContainsString('rental_staff_fallback', $this->journal());
    }

    public function testWithNoReachableManagerTheStaffIsToldAndTheJournalSaysSo(): void
    {
        $manager = $this->member('sans.compte@unite.be', false);
        $this->managerRepository->grant($this->assetId, $manager, false);
        $staff = $this->member('staffdu@unite.be', true);
        $this->staff = [$staff, $this->member('staff.sans.compte@unite.be', false)];

        $this->assertSame([$staff], $this->memberIdsOf($this->resolver()->recipientsFor($this->assetId, 'new_request')));
        $journal = $this->journal();
        $this->assertStringContainsString('rental_staff_fallback', $journal);
        $this->assertStringNotContainsString('staffdu@unite.be', $journal);
    }

    /**
     * The production fallback reads the Staff d'U where the rest of the
     * site does — the active members' functions — so a chef d'unité synced
     * there is found, and a former one or another section's staff is not.
     */
    public function testTheProductionFallbackFindsTheStaffOfTheStaffDu(): void
    {
        $branch = $this->pdo->prepare('INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, ?)');
        $branch->execute(['STAFFDU', "Staff d'U", 99]);
        $branchId = (int) $this->pdo->lastInsertId();
        $section = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
        $section->execute(['STAFFDU', $branchId, "Staff d'U"]);
        $staffDu = (int) $this->pdo->lastInsertId();
        $section->execute(['LOUP', $branchId, 'Meute']);
        $otherSection = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO functions (desk_code, label, role) VALUES (?, ?, ?)')
            ->execute(['CU', "Chef d'unité", 'admin']);
        $function = (int) $this->pdo->lastInsertId();

        $assign = function (int $memberId, int $sectionId) use ($function): int {
            $memberYearId = (int) $this->pdo->query(
                'SELECT id FROM member_years WHERE member_id = ' . $memberId
            )->fetchColumn();
            $this->pdo->prepare(
                'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function)
                 VALUES (?, ?, ?, 1)'
            )->execute([$memberYearId, $function, $sectionId]);

            return $memberYearId;
        };
        $chief = $this->member('cu@unite.be', true);
        $assign($chief, $staffDu);
        $assign($this->member('animateur@unite.be', true), $otherSection);
        $former = $this->member('ancien.cu@unite.be', true);
        $this->pdo->prepare('UPDATE member_years SET is_active = 0 WHERE id = ?')
            ->execute([$assign($former, $staffDu)]);

        $staff = ManagerRecipientResolver::unitStaffOfTheCurrentYear(
            new \Core\Member\Repository\SectionRepository(\Core\Database\Connection::withPdo($this->pdo)),
            new MemberYearRepository($this->pdo),
            new \Core\Config\ScoutYearService($this->pdo)
        );

        $this->assertSame([$chief], $staff());
    }

    public function testWhenNobodyCanBeToldTheJournalSaysSo(): void
    {
        $this->assertSame([], $this->resolver()->recipientsFor($this->assetId, 'new_request'));
        $this->assertSame([], $this->resolver(false)->recipientsFor($this->assetId, 'new_request'));
        $this->assertStringContainsString('rental_nobody_reachable', $this->journal());
    }

    public function testEachManagerWhoCannotBeToldIsNamedWithTheReason(): void
    {
        $reachable = $this->member('gestion@unite.be', true);
        $noAccount = $this->member('sans.compte@unite.be', false);
        $inactive = $this->member('ancien@unite.be', true);
        foreach ([$reachable, $noAccount, $inactive] as $memberId) {
            $this->managerRepository->grant($this->assetId, $memberId, false);
        }
        $this->pdo->prepare('UPDATE rental_asset_managers SET is_active = 0 WHERE member_id = ?')->execute([$inactive]);

        $this->assertSame([
            $noAccount => ManagerRecipientResolver::REASON_NO_ACCOUNT,
            $inactive => ManagerRecipientResolver::REASON_INACTIVE,
        ], $this->resolver()->unreachableManagers($this->assetId));
        $this->assertTrue($this->resolver()->hasReachableManager($this->assetId));
    }
}
