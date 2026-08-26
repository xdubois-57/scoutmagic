<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Controller;

use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Import\MemberYearRepository;
use Core\Member\AddressNormalizer;
use Core\Member\Household\HouseholdRepository;
use Core\Member\Household\HouseholdService;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Modules\Finance\Controller\ReconciliationController;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\BalanceCheckpointRepository;
use Modules\Finance\Repository\CategoryRepository;
use Modules\Finance\Repository\CategoryRuleRepository;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\FiscalYearRepository;
use Modules\Finance\Repository\ReceivableAllocationRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\AccountTransferCategoryService;
use Modules\Finance\Service\AccountVisibility;
use Modules\Finance\Service\BalanceService;
use Modules\Finance\Service\FinanceService;
use Modules\Finance\Service\ReceivableAllocationService;
use Modules\Finance\Service\ReceivableSettlement;
use Modules\Finance\Service\ReconciliationService;
use Modules\Finance\Service\SepaQrCodeService;
use Modules\Finance\Service\TreasurerScope;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ReconciliationControllerTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private ReconciliationController $controller;
    private ExpectedReceivableRepository $receivables;
    private TransactionRepository $transactions;
    private ReceivableAllocationService $allocations;
    private int $accountId;
    private int $scoutYearId;
    /** @var array<string, int> */
    private array $memberIds = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $accountRepository = new AccountRepository($this->pdo, $this->encryption);
        $this->transactions = new TransactionRepository($this->pdo, $this->encryption);
        $this->receivables = new ExpectedReceivableRepository($this->pdo, $this->encryption);
        $this->allocations = FinanceTestHelper::allocationService($this->pdo, $this->encryption, $this->receivables);

        $categoryRepository = new CategoryRepository($this->pdo);
        $categoryRuleRepository = new CategoryRuleRepository($this->pdo);
        $scoutYearService = new ScoutYearService($this->pdo);
        $accountVisibility = new AccountVisibility(TreasurerScope::systemCaller());

        $financeService = new FinanceService(
            $accountRepository,
            $categoryRepository,
            new FiscalYearRepository($this->pdo, $scoutYearService),
            new SectionService(Connection::withPdo($this->pdo), $this->encryption, new MemberBadgeRepository($this->pdo)),
            $this->transactions,
            new BalanceService(new BalanceCheckpointRepository($this->pdo), $this->transactions),
            new SettingService(new SettingRepository($this->pdo)),
            $categoryRuleRepository,
            new AccountTransferCategoryService($categoryRepository, $categoryRuleRepository, $this->transactions),
            $accountVisibility
        );

        $memberService = new MemberService(new MemberYearRepository($this->pdo), $this->encryption, Connection::withPdo($this->pdo));

        $this->controller = new ReconciliationController(
            $this->twig(),
            new ReconciliationService(
                $this->receivables,
                new ReceivableAllocationRepository($this->pdo),
                $this->transactions,
                $accountRepository,
                $accountVisibility,
                $this->allocations,
                $memberService,
                new HouseholdService(new HouseholdRepository($this->pdo, $this->encryption), $this->encryption)
            ),
            $this->allocations,
            $this->receivables,
            $financeService,
            $memberService,
            $scoutYearService,
            new SepaQrCodeService()
        );

        $this->accountId = $accountRepository->create('Compte Unité', Account::TYPE_BANK, null, 'BE71096123456769', 'Unité SV025', 'intendant');
        $this->pdo->prepare("UPDATE finance_accounts SET status = 'active' WHERE id = ?")->execute([$this->accountId]);

        $currentStart = (int) substr(ScoutYearService::labelForDate(new \DateTimeImmutable()), 0, 4);
        $this->scoutYearId = FinanceTestHelper::createScoutYear(
            $this->pdo,
            $currentStart . '-' . ($currentStart + 1),
            $currentStart . '-09-01',
            ($currentStart + 1) . '-08-31',
            true
        );

        foreach (['Lucie', 'Antoine'] as $firstName) {
            $this->memberIds[$firstName] = $this->createMember($firstName, 'Vandenbrande');
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'intendant@test.be', 'intendant');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    // ── the screen ──────────────────────────────────────────────────────

    public function testTheScreenRendersWithNothingToReconcile(): void
    {
        $response = $this->controller->index($this->get(), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Rapprochement', $response->getBody());
    }

    public function testTheSplitTabProposesTheHouseholdsOtherReceivables(): void
    {
        $this->receivable('Lucie', 4500, '+++123/4567/89012+++');
        $this->receivable('Antoine', 4500, '+++123/4567/89025+++');
        $this->credit('VANDENBRANDE M +++123/4567/89012+++', 90.00);

        $response = $this->controller->index($this->get(['tab' => 'split']), []);

        $body = $response->getBody();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Vandenbrande Antoine', $body);
        $this->assertStringContainsString('Imputer', $body);
    }

    public function testTheCrossAccountTabInsistsOnTheOriginalCommunication(): void
    {
        $this->pdo->exec("INSERT INTO finance_accounts (name, account_type, status) VALUES ('Compte Louveteaux', 'bank', 'active')");
        $otherAccountId = (int) $this->pdo->lastInsertId();
        $this->receivables->create('finance', 99, $otherAccountId, 4500, '+++123/4567/89041+++', null, $this->memberIds['Lucie']);
        $this->credit('Virement +++123/4567/89041+++', 45.00);

        $body = $this->controller->index($this->get(['tab' => 'cross_account']), [])->getBody();

        $this->assertStringContainsString("Reprenez la communication d'origine", $body);
        $this->assertStringContainsString('+++123/4567/89041+++', $body);
        $this->assertStringContainsString('mouvement interne', $body);
    }

    // ── the gestures ────────────────────────────────────────────────────

    public function testConfirmingASplitAllocatesEachShare(): void
    {
        $lucie = $this->receivable('Lucie', 4500, '+++123/4567/89012+++');
        $antoine = $this->receivable('Antoine', 4500, '+++123/4567/89025+++');
        $transactionId = $this->credit('VANDENBRANDE M +++123/4567/89012+++', 90.00);
        $this->allocations->reconcileAccount($this->accountId);

        $response = $this->controller->applySplit(
            new Request('POST', '/x', [], [
                '_csrf_token' => $this->csrfToken(),
                'account_id' => (string) $this->accountId,
                'amount' => [(string) $antoine => '45,00'],
            ], [], []),
            ['transactionId' => (string) $transactionId]
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(ReceivableSettlement::STATUS_PAID, $this->settlement($lucie)->status);
        $this->assertSame(ReceivableSettlement::STATUS_PAID, $this->settlement($antoine)->status);
    }

    public function testAttachingAnOrphanCreditSettlesTheReceivable(): void
    {
        $lucie = $this->receivable('Lucie', 4500, '+++123/4567/89012+++');
        $transactionId = $this->credit('DUPONT J « cotisation »', 45.00);

        $this->controller->attach(
            new Request('POST', '/x', [], [
                '_csrf_token' => $this->csrfToken(),
                'account_id' => (string) $this->accountId,
                'receivable_id' => (string) $lucie,
                'amount' => '45,00',
            ], [], []),
            ['transactionId' => (string) $transactionId]
        );

        $this->assertSame(ReceivableSettlement::STATUS_PAID, $this->settlement($lucie)->status);
    }

    public function testNothingIsAttachedWithoutAValidCsrfToken(): void
    {
        $lucie = $this->receivable('Lucie', 4500, '+++123/4567/89012+++');
        $transactionId = $this->credit('DUPONT J « cotisation »', 45.00);

        $this->controller->attach(
            new Request('POST', '/x', [], [
                '_csrf_token' => 'wrong',
                'account_id' => (string) $this->accountId,
                'receivable_id' => (string) $lucie,
                'amount' => '45,00',
            ], [], []),
            ['transactionId' => (string) $transactionId]
        );

        $this->assertSame(ReceivableSettlement::STATUS_UNPAID, $this->settlement($lucie)->status);
    }

    public function testDeclaringASurplusOwedBackChangesTheStateAndNotTheMoney(): void
    {
        $lucie = $this->receivable('Lucie', 3825, '+++123/4567/89012+++');
        $this->credit('Virement arrondi +++123/4567/89012+++', 45.00);
        $this->allocations->reconcileAccount($this->accountId);

        $this->controller->resolveOverpayment(
            new Request('POST', '/x', [], [
                '_csrf_token' => $this->csrfToken(),
                'account_id' => (string) $this->accountId,
                'action' => 'refund',
            ], [], []),
            ['receivableId' => (string) $lucie]
        );

        $settlement = $this->settlement($lucie);
        $this->assertSame(ReceivableSettlement::REFUND_REQUESTED, $settlement->refundState);
        $this->assertSame(675, $settlement->amountOverpaidCents);
    }

    public function testMovingASurplusOntoASiblingClearsIt(): void
    {
        $lucie = $this->receivable('Lucie', 3825, '+++123/4567/89012+++');
        $antoine = $this->receivable('Antoine', 4500, '+++123/4567/89025+++');
        $this->credit('Virement arrondi +++123/4567/89012+++', 45.00);
        $this->allocations->reconcileAccount($this->accountId);

        $this->controller->resolveOverpayment(
            new Request('POST', '/x', [], [
                '_csrf_token' => $this->csrfToken(),
                'account_id' => (string) $this->accountId,
                'action' => 'transfer',
                'target_receivable_id' => (string) $antoine,
                'amount' => '6,75',
            ], [], []),
            ['receivableId' => (string) $lucie]
        );

        $this->assertSame(0, $this->settlement($lucie)->amountOverpaidCents);
        $this->assertSame(675, $this->settlement($antoine)->amountAllocatedCents);
    }

    // ── the QR ──────────────────────────────────────────────────────────

    /**
     * The QR asks for what is STILL DUE. Asking for the original amount
     * again would manufacture the surplus the rest of this screen exists
     * to clear up.
     */
    public function testTheQrAsksForWhatIsStillDueAndNamesTheMember(): void
    {
        $lucie = $this->receivable('Lucie', 4500, '+++123/4567/89012+++');
        $this->credit('Acompte +++123/4567/89012+++', 20.00);
        $this->allocations->reconcileAccount($this->accountId);

        $response = $this->controller->qr($this->get(), ['id' => (string) $lucie]);
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('25,00', $body, 'the QR page shows the remaining amount');
        $this->assertStringNotContainsString('45,00 €</p>', $body, 'never the original amount as the headline');
        $this->assertStringContainsString('Lucie Vandenbrande', $body);
        $this->assertStringContainsString('data:image/png;base64,', $body);
        // The payment details are repeated in text: a QR that will not
        // scan must never leave somebody without a way to pay.
        $this->assertStringContainsString('+++123/4567/89012+++', $body);
        $this->assertStringContainsString('BE71', $body);
    }

    public function testASettledReceivableHasNoQrToShow(): void
    {
        $lucie = $this->receivable('Lucie', 4500, '+++123/4567/89012+++');
        $this->credit('Virement +++123/4567/89012+++', 45.00);
        $this->allocations->reconcileAccount($this->accountId);

        $body = $this->controller->qr($this->get(), ['id' => (string) $lucie])->getBody();

        // Twig escapes the apostrophe, so the assertion reads a stretch
        // without one rather than the sentence as written.
        $this->assertStringContainsString('pas de QR à montrer', $body);
    }

    public function testAReceivableOnAnAccountTheViewerCannotSeeIsANotFound(): void
    {
        $lucie = $this->receivable('Lucie', 4500, '+++123/4567/89012+++');
        $this->pdo->exec("UPDATE finance_accounts SET role_min_view = 'admin' WHERE id = {$this->accountId}");

        $this->assertSame(404, $this->controller->qr($this->get(), ['id' => (string) $lucie])->getStatusCode());
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /**
     * @param array<string, string> $query
     */
    private function get(array $query = []): Request
    {
        return new Request('GET', '/finance/reconciliation', $query + ['account_id' => (string) $this->accountId], [], [], []);
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

    private function createMember(string $firstName, string $lastName): int
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

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_addresses (member_year_id, address_type, street_encrypted, number_encrypted, postal_code_encrypted, city_encrypted, address_normalized_blind_index)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberYearId,
            'Domicile',
            $this->encryption->encrypt('Rue du Bois', 'member_addresses.street'),
            $this->encryption->encrypt('12', 'member_addresses.number'),
            $this->encryption->encrypt('1348', 'member_addresses.postal_code'),
            $this->encryption->encrypt('Ottignies', 'member_addresses.city'),
            $this->encryption->blindIndex(AddressNormalizer::normalize('Rue du Bois', '12', null, '1348'), 'address'),
        ]);

        return $memberId;
    }

    private function csrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        return $token;
    }

    private function twig(): Environment
    {
        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $loader = new FilesystemLoader($templateDir);
        $loader->addPath(dirname(__DIR__, 4) . '/modules/finance/views', 'finance');
        $twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);
        // asset() is what base.html.twig references every static file through
        // (Core\View\TwigFactory); the bare path is enough for a test render.
        $twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $path): string => $path));

        $twig->addFilter(new \Twig\TwigFilter('date_fr', fn($d) => $d === null || $d === '' ? '' : ($d instanceof \DateTimeInterface ? $d : new \DateTimeImmutable((string) $d))->format('d/m/Y')));
        $twig->addFilter(new \Twig\TwigFilter('datetime_fr', fn($d) => $d === null || $d === '' ? '' : ($d instanceof \DateTimeInterface ? $d : new \DateTimeImmutable((string) $d))->format('d/m/Y à H:i')));
        $twig->addFilter(new \Twig\TwigFilter('money', fn($a) => $a === null || $a === '' ? '' : number_format((float) $a, 2, ',', ' ') . ' €'));
        $twig->addFilter(new \Twig\TwigFilter('money_cents', fn($c) => $c === null || $c === '' ? '' : number_format(((int) $c) / 100, 2, ',', ' ') . ' €'));
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'intendant');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/finance/reconciliation');
        $twig->addGlobal('csp_nonce', 'test-nonce');
        $twig->addFunction(new TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('get_flash', fn() => null));
        $twig->addFunction(new TwigFunction('csrf_token', fn() => 'test'));
        $twig->addFunction(new TwigFunction('file_url', fn() => ''));

        return $twig;
    }
}
