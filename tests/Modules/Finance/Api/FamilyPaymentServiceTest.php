<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Api;

use Core\Database\Connection;
use Core\Import\MemberYearRepository;
use Core\Member\MemberService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Modules\Finance\Api\FamilyPaymentService;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\CampaignRepository;
use Modules\Finance\Repository\CampaignRowRepository;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\StatementImportRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\CampaignService;
use Modules\Finance\Service\ReceivableQrTokenService;
use Modules\Finance\Service\StructuredCommunicationService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class FamilyPaymentServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private CampaignRepository $campaigns;
    private CampaignRowRepository $rows;
    private ExpectedReceivableRepository $receivables;
    private TransactionRepository $transactions;
    private StatementImportRepository $imports;
    private int $accountId;
    private int $scoutYearId;
    /** @var array<string, int> */
    private array $memberIds = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->campaigns = new CampaignRepository($this->pdo);
        $this->rows = new CampaignRowRepository($this->pdo, $this->encryption);
        $this->receivables = new ExpectedReceivableRepository($this->pdo, $this->encryption);
        $this->transactions = new TransactionRepository($this->pdo, $this->encryption);
        $this->imports = new StatementImportRepository($this->pdo);

        $accounts = new AccountRepository($this->pdo, $this->encryption);
        $this->accountId = $accounts->create(
            'Compte Unité',
            Account::TYPE_BANK,
            null,
            'BE71096123456769',
            'Unité SV025 Ottignies',
            'intendant'
        );

        $this->scoutYearId = FinanceTestHelper::createScoutYear($this->pdo, '2025-2026', '2025-09-01', '2026-08-31', true);

        $this->memberIds['Lucie'] = $this->createMember('Lucie', 'famille@test.be');
        $this->memberIds['Antoine'] = $this->createMember('Antoine', 'famille@test.be');
        $this->memberIds['Timeo'] = $this->createMember('Timéo', 'roskam@test.be');

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        $_SESSION = [];
    }

    // ── the block on a member's own page ────────────────────────────────

    public function testAnUnpaidDemandCarriesItsAmountCommunicationAndPaymentDetails(): void
    {
        $this->campaignWith([['Lucie', 3825]]);

        $payments = $this->service()->getOpenPayments($this->memberIds['Lucie']);

        $this->assertCount(1, $payments);
        $this->assertSame('Cotisations 2025-2026', $payments[0]->label);
        $this->assertSame(3825, $payments[0]->amountRemainingCents);
        $this->assertSame('Unité SV025 Ottignies', $payments[0]->beneficiary);
        $this->assertSame('BE71 0961 2345 6769', $payments[0]->iban);
        $this->assertStringContainsString('+++', $payments[0]->communication);
        $this->assertFalse($payments[0]->isPartiallyPaid());
    }

    /**
     * A parent who paid half and reads the whole sum concludes their
     * transfer was lost.
     */
    public function testAPartlyPaidDemandAsksForWhatIsLeft(): void
    {
        $this->campaignWith([['Lucie', 4500]]);
        $this->pay($this->memberIds['Lucie'], 20.00);

        $payments = $this->service()->getOpenPayments($this->memberIds['Lucie']);

        $this->assertCount(1, $payments);
        $this->assertSame(2500, $payments[0]->amountRemainingCents);
        $this->assertSame(4500, $payments[0]->amountDueCents);
        $this->assertSame(2000, $payments[0]->amountReceivedCents);
        $this->assertTrue($payments[0]->isPartiallyPaid());
    }

    public function testASettledDemandLeavesNothingOnThePage(): void
    {
        $this->campaignWith([['Lucie', 4500]]);
        $this->pay($this->memberIds['Lucie'], 45.00);

        $this->assertSame([], $this->service()->getOpenPayments($this->memberIds['Lucie']));
    }

    /**
     * Abandoning a receivable settles it without a payment: the family is
     * not asked for it any more either.
     */
    public function testAWaivedDemandLeavesNothingOnThePage(): void
    {
        $this->campaignWith([['Lucie', 4500]]);
        $receivable = $this->receivables->findByMemberIds([$this->memberIds['Lucie']])[0];
        $this->receivables->setWaived($receivable->id, date('Y-m-d H:i:s'), 7);

        $this->assertSame([], $this->service()->getOpenPayments($this->memberIds['Lucie']));
    }

    /**
     * A QR for an account nobody can pay into would be a payment request
     * a bank refuses — which reads as a broken site, not a missing
     * setting.
     */
    public function testNoIbanMeansNoCodeButStillTheAmountAndTheCommunication(): void
    {
        $this->campaignWith([['Lucie', 3825]]);
        $this->pdo->exec("UPDATE finance_accounts SET iban = NULL WHERE id = {$this->accountId}");

        $payments = $this->service()->getOpenPayments($this->memberIds['Lucie']);

        $this->assertNull($payments[0]->qrUrl);
        $this->assertNull($payments[0]->iban);
        $this->assertSame(3825, $payments[0]->amountRemainingCents);
    }

    public function testTheCodeIsTheSameTokenisedUrlAReminderMailPointsAt(): void
    {
        $this->campaignWith([['Lucie', 3825]]);
        $receivable = $this->receivables->findByMemberIds([$this->memberIds['Lucie']])[0];

        $payments = $this->service()->getOpenPayments($this->memberIds['Lucie']);

        $this->assertSame(
            (new ReceivableQrTokenService($this->encryption))->urlFor($receivable->id, 'https://scoutmagic.test'),
            $payments[0]->qrUrl
        );
    }

    // ── the homepage band ───────────────────────────────────────────────

    /**
     * A parent of three sees ONE band with three lines, never three
     * bands.
     */
    public function testTheBandSumsEverythingTheAddressOwes(): void
    {
        $this->campaignWith([['Lucie', 3825], ['Antoine', 4500], ['Timeo', 1000]]);

        $summary = $this->signedInAs('famille@test.be')->getHomePaymentSummaryForCurrentUser();

        $this->assertNotNull($summary);
        $this->assertSame(8325, $summary['total_cents']);
        $this->assertCount(2, $summary['demands'], "the third child is another address' business");
        $this->assertNull($summary['single_member_year_id'], 'two members owe: no single destination to send them to');
        $this->assertSame(['Lucie', 'Antoine'], array_column($summary['demands'], 'member_name'));
    }

    public function testASingleDemandGetsADirectDestination(): void
    {
        $this->campaignWith([['Timeo', 3825]]);

        $summary = $this->signedInAs('roskam@test.be')->getHomePaymentSummaryForCurrentUser();

        $this->assertNotNull($summary);
        $this->assertNotNull($summary['single_member_year_id']);
        $this->assertSame($summary['demands'][0]['member_year_id'], $summary['single_member_year_id']);
    }

    /** The band only ever appears when something is still owed. */
    public function testAFamilyThatOwesNothingSeesNoBand(): void
    {
        $this->campaignWith([['Timeo', 3825]]);
        $this->pay($this->memberIds['Timeo'], 38.25);

        $this->assertNull($this->signedInAs('roskam@test.be')->getHomePaymentSummaryForCurrentUser());
    }

    /**
     * The home band reads the STORED allocations and never runs its own
     * reconcile pass — that is the deliberate trade behind
     * openReceivablesFor(refresh: false): the most-visited page must not
     * scan an account's whole history per family, and every real
     * transaction write (Service\ImportService) reconciles at arrival, so
     * the stored state is what production always has. Proven by paying
     * WITHOUT the reconcile the import would have run: a fresh read would
     * see the payment, the stored read must not.
     */
    public function testTheHomeBandReadsStoredAllocationsWithoutReconciling(): void
    {
        $this->campaignWith([['Timeo', 3825]]);
        $receivable = $this->receivables->findByMemberIds([$this->memberIds['Timeo']])[0];
        $this->transactions->create(
            $this->accountId, $this->scoutYearId, 'REF-RAW', '2026-02-18',
            'Virement ' . $receivable->communication, 38.25, null, null, 'import', null
        );

        $summary = $this->signedInAs('roskam@test.be')->getHomePaymentSummaryForCurrentUser();

        $this->assertNotNull($summary, 'no allocation row exists yet, so the stored state still says owed');
        // No allocation was written by the read itself.
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM finance_receivable_allocations')->fetchColumn();
        $this->assertSame(0, $count, 'the home read must never write allocations');
    }

    public function testAnAnonymousVisitorGetsNoBandAtAll(): void
    {
        $this->campaignWith([['Timeo', 3825]]);

        $this->assertNull($this->service()->getHomePaymentSummaryForCurrentUser());
    }

    public function testAnAddressWithNoMembersGetsNoBand(): void
    {
        $this->campaignWith([['Timeo', 3825]]);

        $this->assertNull($this->signedInAs('personne@test.be')->getHomePaymentSummaryForCurrentUser());
    }

    /**
     * The date the warning quotes is the last statement actually
     * imported — never a computed bank-closing time.
     */
    public function testTheBandQuotesTheDateOfTheLastImportedStatement(): void
    {
        $this->campaignWith([['Timeo', 3825]]);
        $this->imports->create($this->accountId, 'BE-CODA', 'fevrier.cod', 12, 12, 0, 7, '2026-02-20 08:30:00');

        $summary = $this->signedInAs('roskam@test.be')->getHomePaymentSummaryForCurrentUser();

        $this->assertNotNull($summary);
        $this->assertSame('2026-02-20', $summary['statement_date']);
    }

    /**
     * Nothing imported means nothing can be promised: the sentence is
     * dropped rather than made up.
     */
    public function testNoImportMeansNoDateRatherThanAGuess(): void
    {
        $this->campaignWith([['Timeo', 3825]]);

        $summary = $this->signedInAs('roskam@test.be')->getHomePaymentSummaryForCurrentUser();

        $this->assertNotNull($summary);
        $this->assertNull($summary['statement_date']);
    }

    // ── fixtures ────────────────────────────────────────────────────────

    private function signedInAs(string $email): FamilyPaymentService
    {
        AuthSession::login(1, $email, 'identified');

        return $this->service();
    }

    private function service(): FamilyPaymentService
    {
        return new FamilyPaymentService(
            $this->receivables,
            FinanceTestHelper::allocationService($this->pdo, $this->encryption, $this->receivables),
            new AccountRepository($this->pdo, $this->encryption),
            $this->rows,
            $this->campaigns,
            $this->imports,
            new ReceivableQrTokenService($this->encryption),
            new MemberService(new MemberYearRepository($this->pdo), $this->encryption, Connection::withPdo($this->pdo)),
            $this->scoutYearResolver(),
            'https://scoutmagic.test'
        );
    }

    private function scoutYearResolver(): \Core\ScoutYear\ScoutYearResolver
    {
        $settings = new \Core\Config\SettingService(new \Core\Config\SettingRepository($this->pdo));
        $settings->register(\Core\ScoutYear\ScoutYearResolver::SETTING_PUBLIC_YEAR, '0', 'number', 'Public', 'desc', null, '^[0-9]+$', null, false);
        $settings->register(\Core\ScoutYear\ScoutYearResolver::SETTING_STAFF_YEAR, '0', 'number', 'Staff', 'desc', null, '^[0-9]+$', null, false);

        return new \Core\ScoutYear\ScoutYearResolver(
            new \Core\Config\ScoutYearService($this->pdo),
            $settings,
            new MemberYearRepository($this->pdo)
        );
    }

    /**
     * @param array<int, array{0: string, 1: int}> $lines first name, amount in cents
     */
    private function campaignWith(array $lines): void
    {
        $campaignId = $this->campaigns->create(
            'Cotisations 2025-2026',
            $this->scoutYearId,
            $this->accountId,
            null,
            'cotisations.xlsx',
            [],
            7
        );

        $sequence = 0;
        foreach ($lines as [$firstName, $amountCents]) {
            $sequence++;
            $rowId = $this->rows->create($campaignId, $this->memberIds[$firstName], $amountCents, $sequence, []);
            $this->receivables->create(
                CampaignService::SOURCE_MODULE,
                $rowId,
                $this->accountId,
                $amountCents,
                StructuredCommunicationService::format(str_pad((string) (1000000000 + $sequence), 10, '0', STR_PAD_LEFT)),
                null,
                $this->memberIds[$firstName]
            );
        }
    }

    private function pay(int $memberId, float $amount): void
    {
        $receivable = $this->receivables->findByMemberIds([$memberId])[0];
        $this->transactions->create(
            $this->accountId,
            $this->scoutYearId,
            'REF-' . $receivable->id . '-' . (int) ($amount * 100),
            '2026-02-18',
            'Virement ' . $receivable->communication,
            $amount,
            null,
            null,
            'import',
            null
        );
        // What every real transaction write does right after (Service\
        // ImportService — the only production path that creates movements
        // — reconciles the account before returning): allocations are
        // written at arrival, which is what lets the home band read the
        // stored state without its own reconcile pass.
        FinanceTestHelper::allocationService($this->pdo, $this->encryption, $this->receivables)
            ->reconcileAccount($this->accountId);
    }

    private function createMember(string $firstName, string $email): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
        $stmt->execute(['D-' . $firstName]);
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, email_blind_index, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $memberId,
            $this->scoutYearId,
            $this->encryption->encrypt($firstName, 'member_years.first_name'),
            $this->encryption->encrypt('Vandenbrande', 'member_years.last_name'),
            $this->encryption->encrypt($email, 'member_years.email'),
            $this->encryption->blindIndex(strtolower($email), 'email'),
        ]);

        return $memberId;
    }
}
