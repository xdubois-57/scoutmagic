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
