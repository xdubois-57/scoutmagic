<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Database\Connection;
use Core\Import\MemberYearRepository;
use Core\Member\AddressNormalizer;
use Core\Member\Household\HouseholdRepository;
use Core\Member\Household\HouseholdService;
use Core\Member\MemberService;
use Core\Security\EncryptionService;
use Core\Security\Role;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\ReceivableAllocationRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\AccountVisibility;
use Modules\Finance\Api\FinanceException;
use Modules\Finance\Service\ReceivableSettlement;
use Modules\Finance\Service\ReconciliationService;
use Modules\Finance\Service\TreasurerScope;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ReconciliationServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private ReconciliationService $service;
    private ExpectedReceivableRepository $receivables;
    private TransactionRepository $transactions;
    private \Modules\Finance\Service\ReceivableAllocationService $allocations;
    private int $accountId;
    private int $otherAccountId;
    private int $scoutYearId;
    /** @var array<string, int> first name => members.id */
    private array $memberIds = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->receivables = new ExpectedReceivableRepository($this->pdo, $this->encryption);
        $this->transactions = new TransactionRepository($this->pdo, $this->encryption);
        $this->allocations = FinanceTestHelper::allocationService($this->pdo, $this->encryption, $this->receivables);

        $this->service = new ReconciliationService(
            $this->receivables,
            new ReceivableAllocationRepository($this->pdo),
            $this->transactions,
            new AccountRepository($this->pdo, $this->encryption),
            new AccountVisibility(TreasurerScope::systemCaller()),
            $this->allocations,
            new MemberService(new MemberYearRepository($this->pdo), $this->encryption, Connection::withPdo($this->pdo)),
            new HouseholdService(new HouseholdRepository($this->pdo, $this->encryption), $this->encryption)
        );

        $this->pdo->exec("INSERT INTO finance_accounts (name, account_type, status) VALUES ('Compte Unité', 'bank', 'active')");
        $this->accountId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO finance_accounts (name, account_type, status) VALUES ('Compte Louveteaux', 'bank', 'active')");
        $this->otherAccountId = (int) $this->pdo->lastInsertId();

        $this->scoutYearId = FinanceTestHelper::createScoutYear($this->pdo, '2025-2026', '2025-09-01', '2026-08-31', true);

        // One household of three, so the split proposal has something to
        // propose. The address is what makes them a household.
        foreach (['Lucie', 'Antoine', 'Margaux'] as $firstName) {
            $this->memberIds[$firstName] = $this->createMember($firstName, 'Vandenbrande', 'Rue du Bois', '12');
        }
        $this->memberIds['Solo'] = $this->createMember('Timéo', 'Roskam', 'Rue Haute', '3');
    }

    // ── à répartir ──────────────────────────────────────────────────────

    /**
     * The most frequent real case: the site asks for one transfer per
     * receivable, and the household pays the three in one go, quoting the
     * communication of whichever child's letter was on top.
     */
    public function testOneTransferForAWholeHouseholdIsProposedAsASplit(): void
    {
        $lucie = $this->receivable('Lucie', 4500, '+++123/4567/89012+++');
        $this->receivable('Antoine', 4500, '+++123/4567/89025+++');
        $this->receivable('Margaux', 3825, '+++123/4567/89038+++');

        $this->credit('VANDENBRANDE M +++123/4567/89012+++', 128.25);

        $view = $this->build();

        $this->assertCount(1, $view['split']);
        $proposal = $view['split'][0];
        $this->assertSame($lucie, $proposal['named_receivable_id']);
        $this->assertSame(8325, $proposal['remainder_cents']);
        $this->assertSame([4500, 3825], array_map(static fn(array $l): int => $l['amount_cents'], $proposal['lines']));
        $this->assertSame(0, $proposal['unassigned_cents']);
    }

    public function testASurplusWithNoHouseholdSiblingIsNotASplitButATropPercu(): void
    {
        $this->receivable('Solo', 3825, '+++123/4567/89012+++');
        $this->credit('Virement arrondi +++123/4567/89012+++', 45.00);

        $view = $this->build();

        $this->assertSame([], $view['split']);
        $this->assertCount(1, $view['overpaid']);
        $this->assertSame(675, $view['overpaid'][0]['amount_overpaid']);
    }

    public function testApplyingTheProposedSplitSettlesTheWholeHousehold(): void
    {
        $lucie = $this->receivable('Lucie', 4500, '+++123/4567/89012+++');
        $antoine = $this->receivable('Antoine', 4500, '+++123/4567/89025+++');
        $margaux = $this->receivable('Margaux', 3825, '+++123/4567/89038+++');
        $transactionId = $this->credit('VANDENBRANDE M +++123/4567/89012+++', 128.25);

        $proposal = $this->build()['split'][0];
        $amounts = [];
        foreach ($proposal['lines'] as $line) {
            $amounts[$line['receivable_id']] = $line['amount_cents'];
        }
        $this->allocations->split($transactionId, $amounts, Role::INTENDANT, 7);

        foreach ([$lucie, $antoine, $margaux] as $receivableId) {
            $this->assertSame(
                ReceivableSettlement::STATUS_PAID,
                $this->settlement($receivableId)->status,
                'receivable ' . $receivableId
            );
        }
        // And nothing is left over, so nobody carries a trop-perçu — least
        // of all Lucie, whose communication the transfer happened to name.
        $this->assertSame([], $this->build()['overpaid']);
    }

    // ── non imputés ─────────────────────────────────────────────────────

    public function testACreditNoCommunicationMatchedIsListedWithItsReason(): void
    {
        $this->receivable('Lucie', 4500, '+++123/4567/89012+++');
        $this->credit('DUPONT J « cotisation Léa »', 45.00);

        $view = $this->build();

        $this->assertCount(1, $view['orphans']);
        $this->assertSame(4500, $view['orphans'][0]['amount_cents']);
        $this->assertStringContainsString('Aucune communication', $view['orphans'][0]['reason']);
    }

    /**
     * "No communication at all" and "a communication that names nothing
     * here" send a treasurer to two different places, so the screen says
     * which one it is.
     */
    public function testACreditWhoseCommunicationNamesNothingHereSaysSo(): void
    {
        $this->receivable('Lucie', 4500, '+++123/4567/89012+++');
        $this->credit('Virement +++999/8888/77766+++', 45.00);

        $view = $this->build();

        $this->assertCount(1, $view['orphans']);
        $this->assertStringContainsString('ne correspond à aucune créance', $view['orphans'][0]['reason']);
    }

    public function testAFullySettledCreditIsInNoTabAtAll(): void
    {
        $this->receivable('Lucie', 4500, '+++123/4567/89012+++');
        $this->credit('Virement +++123/4567/89012+++', 45.00);

        $view = $this->build();

        $this->assertSame(0, $view['counts']['split']);
        $this->assertSame(0, $view['counts']['orphans']);
        $this->assertSame(0, $view['counts']['overpaid']);
        $this->assertSame(0, $view['counts']['cross_account']);
    }

    // ── trop-perçus ─────────────────────────────────────────────────────

    /**
     * The surplus belongs to the receivable: two instalments of 30 € for
     * a receivable of 45 € show an excess on neither one alone.
     */
    public function testATropPercuIsCentredOnTheReceivableAndListsItsInstalments(): void
    {
        $id = $this->receivable('Solo', 4500, '+++123/4567/89012+++');
        $this->credit('Premier versement +++123/4567/89012+++', 30.00);
        $this->credit('Second versement +++123/4567/89012+++', 30.00);

        $view = $this->build();

        $this->assertCount(1, $view['overpaid']);
        $row = $view['overpaid'][0];
        $this->assertSame($id, $row['receivable_id']);
        $this->assertSame(6000, $row['amount_received']);
        $this->assertSame(4500, $row['amount_due']);
        $this->assertSame(1500, $row['amount_overpaid']);
        $this->assertCount(2, $row['instalments']);
    }

    /**
     * Often the right answer: a parent who rounds up means to pay, not to
     * be sent 6,75 € back.
     */
    public function testATropPercuOffersTheHouseholdsOtherOpenReceivables(): void
    {
        $this->receivable('Lucie', 3825, '+++123/4567/89012+++');
        $antoine = $this->receivable('Antoine', 4500, '+++123/4567/89025+++');
        $this->credit('Virement arrondi +++123/4567/89012+++', 45.00);

        $row = $this->build()['overpaid'][0];

        $this->assertCount(1, $row['siblings']);
        $this->assertSame($antoine, $row['siblings'][0]['receivable_id']);
    }

    public function testTransferringTheSurplusMovesItOntoTheSibling(): void
    {
        $lucie = $this->receivable('Lucie', 3825, '+++123/4567/89012+++');
        $antoine = $this->receivable('Antoine', 4500, '+++123/4567/89025+++');
        $this->credit('Virement arrondi +++123/4567/89012+++', 45.00);
        $this->allocations->reconcileAccount($this->accountId);

        $this->allocations->transferOverpayment($lucie, $antoine, 675, Role::INTENDANT, 7);

        $this->assertSame(0, $this->settlement($lucie)->amountOverpaidCents);
        $this->assertSame(675, $this->settlement($antoine)->amountAllocatedCents);
        $this->assertSame([], $this->build()['overpaid']);
    }

    public function testTransferringMoreThanTheSurplusIsRefused(): void
    {
        $lucie = $this->receivable('Lucie', 3825, '+++123/4567/89012+++');
        $antoine = $this->receivable('Antoine', 4500, '+++123/4567/89025+++');
        $this->credit('Virement arrondi +++123/4567/89012+++', 45.00);
        $this->allocations->reconcileAccount($this->accountId);

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessageMatches('/trop-perçu/');
        $this->allocations->transferOverpayment($lucie, $antoine, 4500, Role::INTENDANT, 7);
    }

    // ── mauvais compte ──────────────────────────────────────────────────

    /**
     * A payment landed here that belongs to another account's receivable.
     * The signal names the account and repeats the communication —
     * without it, the regularising transfer would arrive attached to
     * nothing and both signals would persist.
     */
    public function testAPaymentForAnotherAccountIsReportedHereWithItsCommunication(): void
    {
        $this->receivables->create('finance', 1, $this->otherAccountId, 4500, '+++123/4567/89041+++', null, $this->memberIds['Solo']);
        $this->credit('Virement +++123/4567/89041+++', 45.00);

        $view = $this->build();

        $this->assertCount(1, $view['cross_account']['received_here']);
        $signal = $view['cross_account']['received_here'][0];
        $this->assertSame('Compte Louveteaux', $signal['target_account']);
        $this->assertSame('+++123/4567/89041+++', $signal['communication']);
    }

    /**
     * The symmetric signal, and the deliberate exception to the account
     * partition: date, amount, account name — and nothing else. Without
     * it, this treasurer would chase a family that has already paid.
     */
    public function testAReceivableOfThisAccountPaidElsewhereIsReportedToo(): void
    {
        $this->receivable('Solo', 4500, '+++123/4567/89012+++');
        $this->transactions->create(
            $this->otherAccountId, $this->scoutYearId, 'REF-X', '2026-02-18',
            'Virement +++123/4567/89012+++', 45.00, null, null, 'import', null
        );

        $view = $this->build();

        $this->assertCount(1, $view['cross_account']['paid_elsewhere']);
        $signal = $view['cross_account']['paid_elsewhere'][0];
        $this->assertSame('Compte Louveteaux', $signal['other_account']);
        $this->assertSame(4500, $signal['amount_cents']);
        $this->assertSame('2026-02-18', $signal['date']);
        // Nothing about the other account's own movement beyond that.
        $this->assertSame(
            ['receivable_id', 'name', 'amount_cents', 'date', 'other_account'],
            array_keys($signal)
        );
    }

    /**
     * Nothing is imputed across accounts, ever: the receivable stays
     * unpaid until the money physically arrives here.
     */
    public function testNothingIsAllocatedAcrossTheTwoAccounts(): void
    {
        $id = $this->receivable('Solo', 4500, '+++123/4567/89012+++');
        $this->transactions->create(
            $this->otherAccountId, $this->scoutYearId, 'REF-X', '2026-02-18',
            'Virement +++123/4567/89012+++', 45.00, null, null, 'import', null
        );

        $this->build();

        $this->assertSame(ReceivableSettlement::STATUS_UNPAID, $this->settlement($id)->status);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM finance_receivable_allocations')->fetchColumn());
    }

    // ── the account partition ───────────────────────────────────────────

    public function testAnAccountTheViewerCannotSeeIsRefused(): void
    {
        $this->pdo->exec("UPDATE finance_accounts SET role_min_view = 'admin' WHERE id = {$this->accountId}");

        $this->expectException(FinanceException::class);
        $this->service->build($this->accountId, $this->scoutYearId, Role::INTENDANT);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function build(): array
    {
        return $this->service->build($this->accountId, $this->scoutYearId, Role::INTENDANT);
    }

    private function receivable(string $firstName, int $amountCents, string $communication): int
    {
        return $this->receivables->create(
            'finance',
            count($this->receivables->findByAccountId($this->accountId)) + 1,
            $this->accountId,
            $amountCents,
            $communication,
            null,
            $this->memberIds[$firstName]
        );
    }

    private function credit(string $label, float $amount): int
    {
        return $this->transactions->create(
            $this->accountId,
            $this->scoutYearId,
            null,
            '2026-02-18',
            $label,
            $amount,
            null,
            null,
            'import',
            null
        );
    }

    private function settlement(int $receivableId): ReceivableSettlement
    {
        $receivable = $this->receivables->findById($receivableId);
        self::assertNotNull($receivable);

        return $this->allocations->settlementFor($receivable);
    }

    private function createMember(string $firstName, string $lastName, string $street, string $number): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
        $stmt->execute(['D-' . $firstName]);
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, is_active)
             VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $memberId,
            $this->scoutYearId,
            $this->encryption->encrypt($firstName, 'member_years.first_name'),
            $this->encryption->encrypt($lastName, 'member_years.last_name'),
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        // The household is the ADDRESS: written through the application's
        // own normalizer and blind index, so a fixture cannot quietly
        // group people the running site would not.
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_addresses (member_year_id, address_type, street_encrypted, number_encrypted, postal_code_encrypted, city_encrypted, address_normalized_blind_index)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberYearId,
            'Domicile',
            $this->encryption->encrypt($street, 'member_addresses.street'),
            $this->encryption->encrypt($number, 'member_addresses.number'),
            $this->encryption->encrypt('1348', 'member_addresses.postal_code'),
            $this->encryption->encrypt('Ottignies', 'member_addresses.city'),
            $this->encryption->blindIndex(AddressNormalizer::normalize($street, $number, null, '1348'), 'address'),
        ]);

        return $memberId;
    }
}
