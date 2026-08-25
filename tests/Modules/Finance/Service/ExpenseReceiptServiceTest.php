<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Badge\BadgeRepository;
use Core\Badge\BadgeService;
use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Security\EncryptionService;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Repository\TransactionAttachmentRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\ExpenseReceiptService;
use Modules\Finance\Service\FinanceException;
use Modules\Finance\Service\ReceiptService;
use Modules\Finance\Service\TreasurerScopeService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * The door another module comes through (`Api\ExpenseReceiptInterface`,
 * ARCHITECTURE.md §7.5).
 *
 * What matters here is that the AUTHORIZATION is built on this side, from
 * the actor the caller names, and asked again at storage time: a filtered
 * picker is UI, never the boundary (SECURITY.md §3). A consumer able to
 * supply the decision could grant itself one.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ExpenseReceiptServiceTest extends TestCase
{
    private \PDO $pdo;
    private AccountRepository $accounts;
    private ExpenseReceiptService $service;
    private string $storagePath;
    private int $scoutYearId = 1;
    private int $louveteauxId = 1;
    private int $eclaireursId = 2;
    private int $badgeId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->accounts = new AccountRepository($this->pdo, $encryption);
        $this->storagePath = sys_get_temp_dir() . '/finance_expense_receipt_test_' . uniqid();

        $receiptService = new ReceiptService(
            new AttachmentRepository($this->pdo, $encryption),
            $this->accounts,
            new TransactionAttachmentRepository($this->pdo),
            new EncryptedFileStorageService(new FileRepository($this->pdo), $encryption, $this->storagePath),
            new TransactionRepository($this->pdo, $encryption)
        );

        $this->pdo->exec("INSERT INTO scout_years (id, label, start_date, end_date, is_current) VALUES (1, '2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->pdo->exec("INSERT INTO age_branches (id, desk_code, label, sort_order) VALUES (1, 'LOU', 'Louveteaux', 20), (2, 'ECL', 'Eclaireurs', 30)");
        $this->pdo->exec("INSERT INTO sections (id, age_branch_id, desk_code, name) VALUES (1, 1, 'LOU01', 'Louveteaux'), (2, 2, 'ECL01', 'Eclaireurs')");
        $this->pdo->exec("INSERT INTO functions (id, desk_code, label, role) VALUES (1, 'ANIM', 'Animateur', 'chief')");

        $stmt = $this->pdo->prepare('INSERT INTO badges (name, is_default, is_active) VALUES (?, 1, 1)');
        $stmt->execute([BadgeService::BADGE_TREASURER]);
        $this->badgeId = (int) $this->pdo->lastInsertId();

        $this->service = new ExpenseReceiptService(
            $this->accounts,
            new TreasurerScopeService(Connection::withPdo($this->pdo), new BadgeRepository($this->pdo), new MemberBadgeRepository($this->pdo)),
            $receiptService,
            $this->scoutYearId
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storagePath)) {
            $this->removeDirectory($this->storagePath);
        }
    }

    private function removeDirectory(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function createMember(int $sectionId, bool $withBadge): int
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('desk-" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, is_active)
             VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([$memberId, $this->scoutYearId, 'x', 'y']);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare('INSERT INTO member_functions (member_year_id, function_id, section_id) VALUES (?, 1, ?)');
        $stmt->execute([$memberYearId, $sectionId]);

        if ($withBadge) {
            $stmt = $this->pdo->prepare('INSERT INTO member_badges (member_year_id, badge_id) VALUES (?, ?)');
            $stmt->execute([$memberYearId, $this->badgeId]);
        }

        return $memberId;
    }

    private const PDF = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";

    /**
     * A freshly created account is a draft, and a draft is not somewhere a
     * receipt is filed — every fixture that expects to be offered has to
     * be activated the way the finance screens activate one.
     */
    private function activeAccount(string $name, string $type, ?int $sectionId, string $roleMinView = 'intendant'): int
    {
        $id = $this->accounts->create($name, $type, $sectionId, null, null, $roleMinView);
        $this->accounts->updateStatus($id, Account::STATUS_ACTIVE);

        return $id;
    }

    public function testTheChefDUniteIsOfferedEveryActiveAccount(): void
    {
        $unit = $this->activeAccount('Compte unité', Account::TYPE_BANK, null);
        $section = $this->activeAccount('Caisse louveteaux', Account::TYPE_CASH, $this->louveteauxId);

        $offered = $this->service->receiptAccounts('admin', []);

        // The finance module's own ordering, by name — not this service's.
        $this->assertSame([$section => 'Caisse louveteaux', $unit => 'Compte unité'], $offered);
    }

    /**
     * A draft or archived account is not somewhere a receipt is filed;
     * the picker is the finance module's own listing.
     */
    public function testAnInactiveAccountIsNotOffered(): void
    {
        $active = $this->activeAccount('Compte unité', Account::TYPE_BANK, null);
        $archived = $this->activeAccount('Ancien compte', Account::TYPE_BANK, null);
        $this->accounts->updateStatus($archived, Account::STATUS_INACTIVE);

        $this->assertSame([$active => 'Compte unité'], $this->service->receiptAccounts('admin', []));
    }

    /** The section rule narrows the picker exactly as it narrows the screens. */
    public function testATreasurerIsOfferedTheirOwnSectionsAccountOnly(): void
    {
        $mine = $this->activeAccount('Caisse louveteaux', Account::TYPE_CASH, $this->louveteauxId);
        $this->activeAccount('Caisse éclaireurs', Account::TYPE_CASH, $this->eclaireursId);
        $treasurer = $this->createMember($this->louveteauxId, withBadge: true);

        $this->assertSame([$mine => 'Caisse louveteaux'], $this->service->receiptAccounts('intendant', [$treasurer]));
    }

    public function testRoleMinViewStillRefusesTheAccountToALowerRole(): void
    {
        $this->activeAccount('Compte réservé', Account::TYPE_BANK, null, 'admin');

        $this->assertSame([], $this->service->receiptAccounts('intendant', []));
    }

    public function testAStoredReceiptComesBackWithItsId(): void
    {
        $accountId = $this->activeAccount('Compte unité', Account::TYPE_BANK, null);

        $receiptId = $this->service->storeReceipt(
            self::PDF, 'application/pdf', 'facture.pdf', $accountId, null, null, 'admin', [], 7
        );

        $this->assertGreaterThan(0, $receiptId);
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM finance_attachments WHERE id = ' . $receiptId)->fetchColumn()
        );
    }

    /**
     * The boundary is asked again at storage time, not only when the
     * picker was built — a caller that never opened one still cannot use
     * an account it may not see.
     */
    public function testAnAccountTheActorMayNotSeeIsRefusedEvenWhenNamedDirectly(): void
    {
        $theirs = $this->activeAccount('Caisse éclaireurs', Account::TYPE_CASH, $this->eclaireursId);
        $treasurer = $this->createMember($this->louveteauxId, withBadge: true);

        $this->expectException(FinanceException::class);
        $this->service->storeReceipt(
            self::PDF, 'application/pdf', 'facture.pdf', $theirs, null, null, 'intendant', [$treasurer], 7
        );
    }

    /**
     * "No such account" and "not yours" answer the same thing, so a
     * consumer never learns which accounts exist (SECURITY.md §3).
     */
    public function testAnUnknownAccountAnswersExactlyLikeAForbiddenOne(): void
    {
        $theirs = $this->activeAccount('Caisse éclaireurs', Account::TYPE_CASH, $this->eclaireursId);
        $treasurer = $this->createMember($this->louveteauxId, withBadge: true);

        $messages = [];
        foreach ([$theirs, 987654] as $accountId) {
            try {
                $this->service->storeReceipt(
                    self::PDF, 'application/pdf', 'facture.pdf', $accountId, null, null, 'intendant', [$treasurer], 7
                );
                $this->fail('Both should have been refused.');
            } catch (FinanceException $e) {
                $messages[] = $e->getMessage();
            }
        }

        $this->assertSame($messages[0], $messages[1]);
    }

    public function testATypeTheReceiptServiceRefusesIsRefusedHereToo(): void
    {
        $accountId = $this->activeAccount('Compte unité', Account::TYPE_BANK, null);

        $this->expectException(FinanceException::class);
        $this->service->storeReceipt(
            '<?php echo 1;', 'application/x-httpd-php', 'facture.php', $accountId, null, null, 'admin', [], 7
        );
    }
}
