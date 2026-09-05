<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Controller;

use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\File\FileRepository;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\SectionService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Modules\Finance\Controller\ConfigAccountController;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Repository\BalanceCheckpointRepository;
use Modules\Finance\Repository\CategoryRepository;
use Modules\Finance\Repository\CategoryRuleRepository;
use Modules\Finance\Repository\FiscalYearRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\AccountTransferCategoryService;
use Modules\Finance\Service\AccountVisibility;
use Modules\Finance\Service\BalanceService;
use Modules\Finance\Service\FinanceService;
use Modules\Finance\Service\TreasurerScope;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * « Configuration > Finance > Comptes » — the weakest-covered
 * configuration page in the repository, measured at 15 %.
 *
 * Most of what a configuration page does is dull, and dull is exactly why
 * it goes untested: somebody types a name, it is saved, the page reloads.
 * One thing here is not dull, and it is the reason this file exists.
 *
 * **A receipt's file inherits its account's `role_min_view` at upload
 * time.** So the day an admin raises that floor — « les reçus de ce
 * compte ne se voient plus qu'à partir de chef » — every receipt already
 * uploaded has to follow. If it does not, the page reports the change,
 * shows the new floor, and the old receipts go on enforcing the old one:
 * a permission change that looks applied and is not, which is the worst
 * shape a permission bug can take.
 *
 * The rest is the ordinary contract of a JSON auto-save endpoint, and it
 * is here because a configuration page that fails a save silently is a
 * setting somebody believes they changed.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ConfigAccountControllerTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private AccountRepository $accountRepository;
    private AttachmentRepository $attachmentRepository;
    private FileRepository $fileRepository;
    private ConfigAccountController $controller;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $connection = Connection::withPdo($this->pdo);
        $this->accountRepository = new AccountRepository($this->pdo, $this->encryption);
        $this->attachmentRepository = new AttachmentRepository($this->pdo, $this->encryption);
        $this->fileRepository = new FileRepository($this->pdo);

        $categoryRepository = new CategoryRepository($this->pdo);
        $categoryRuleRepository = new CategoryRuleRepository($this->pdo);
        $transactionRepository = new TransactionRepository($this->pdo, $this->encryption);
        $sectionService = new SectionService($connection, $this->encryption, new MemberBadgeRepository($this->pdo));

        $financeService = new FinanceService(
            $this->accountRepository,
            $categoryRepository,
            new FiscalYearRepository($this->pdo, new ScoutYearService($this->pdo)),
            $sectionService,
            $transactionRepository,
            new BalanceService(new BalanceCheckpointRepository($this->pdo), $transactionRepository),
            new SettingService(new SettingRepository($this->pdo)),
            $categoryRuleRepository,
            new AccountTransferCategoryService($categoryRepository, $categoryRuleRepository, $transactionRepository),
            new AccountVisibility(TreasurerScope::systemCaller())
        );

        $this->controller = new ConfigAccountController(
            $this->twig(),
            $financeService,
            $sectionService,
            $this->attachmentRepository,
            $this->fileRepository,
            new JournalService(new JournalRepository($this->pdo))
        );

        AuthSession::login(1, 'tresorier@example.be', 'admin');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    // ── the permission floor that must follow ─────────────────────────

    /**
     * The property the controller's own docblock singles out: an
     * already-uploaded receipt must not keep enforcing a floor its
     * account no longer has.
     */
    public function testRaisingAnAccountsFloorRaisesItOnTheReceiptsAlreadyUploaded(): void
    {
        $accountId = $this->createAccount('Compte unité', 'intendant');
        $fileId = $this->attachReceiptFile($accountId, 'intendant');

        $this->save([
            'action' => 'update',
            'id' => $accountId,
            'name' => 'Compte unité',
            'account_type' => Account::TYPE_BANK,
            'role_min_view' => 'chief',
        ]);

        $this->assertSame('chief', $this->roleMinOf($fileId));
    }

    public function testLoweringItLowersThemToo(): void
    {
        $accountId = $this->createAccount('Compte unité', 'chief');
        $fileId = $this->attachReceiptFile($accountId, 'chief');

        $this->save([
            'action' => 'update',
            'id' => $accountId,
            'name' => 'Compte unité',
            'account_type' => Account::TYPE_BANK,
            'role_min_view' => 'intendant',
        ]);

        $this->assertSame('intendant', $this->roleMinOf($fileId));
    }

    public function testAReceiptOfAnotherAccountIsLeftAlone(): void
    {
        $mine = $this->createAccount('Compte unité', 'intendant');
        $other = $this->createAccount('Compte section', 'intendant');
        $otherFile = $this->attachReceiptFile($other, 'intendant');

        $this->save([
            'action' => 'update',
            'id' => $mine,
            'name' => 'Compte unité',
            'account_type' => Account::TYPE_BANK,
            'role_min_view' => 'admin',
        ]);

        $this->assertSame('intendant', $this->roleMinOf($otherFile));
    }

    // ── the ordinary contract of an auto-save endpoint ────────────────

    public function testCreatingAnAccountSavesItAndSaysWhichOne(): void
    {
        $response = $this->save([
            'action' => 'create',
            'name' => 'Caisse camp',
            'account_type' => Account::TYPE_CASH,
            'role_min_view' => 'intendant',
        ]);

        $answer = json_decode($response->getBody(), true);
        $this->assertTrue($answer['success']);
        $this->assertSame('Caisse camp', $this->accountRepository->findById((int) $answer['account_id'])->name);
    }

    public function testARefusedSaveSaysWhySoTheFormCanShowIt(): void
    {
        $response = $this->save([
            'action' => 'create',
            'name' => '',
            'account_type' => Account::TYPE_CASH,
            'role_min_view' => 'intendant',
        ]);

        $this->assertSame(400, $response->getStatusCode());
        $answer = json_decode($response->getBody(), true);
        $this->assertFalse($answer['success']);
        $this->assertNotSame('', (string) $answer['error'], 'A save that fails silently is a setting somebody believes they changed.');
    }

    public function testAnUnknownActionIsRefusedRatherThanGuessed(): void
    {
        $response = $this->save(['action' => 'supprimer-tout', 'id' => 1]);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testABodyThatIsNotJsonIsRefused(): void
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/config/finance/accounts', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn('pas du json');

        $response = $this->controller->save($request, []);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testASaveWithoutACsrfTokenCreatesNothing(): void
    {
        $payload = [
            'action' => 'create',
            'name' => 'Compte pirate',
            'account_type' => Account::TYPE_CASH,
            'role_min_view' => 'intendant',
        ];
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/config/finance/accounts', [], $payload, [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn((string) json_encode($payload));

        $this->controller->save($request, []);

        $this->assertSame([], $this->accountNames());
    }

    // ── archiving, not deleting ───────────────────────────────────────

    /**
     * Money that moved through an account does not stop having moved
     * through it: an account leaves the pickers, it does not leave the
     * history.
     */
    public function testDeactivatingAnAccountKeepsIt(): void
    {
        $accountId = $this->createAccount('Ancien compte', 'intendant');

        $this->save(['action' => 'deactivate', 'id' => $accountId]);

        $this->assertSame(Account::STATUS_INACTIVE, $this->accountRepository->findById($accountId)->status);
        $this->assertContains('Ancien compte', $this->accountNames());
    }

    public function testAnAccountCanBeBroughtBack(): void
    {
        $accountId = $this->createAccount('Compte saisonnier', 'intendant');
        $this->save(['action' => 'deactivate', 'id' => $accountId]);

        $this->save(['action' => 'activate', 'id' => $accountId]);

        $this->assertSame(Account::STATUS_ACTIVE, $this->accountRepository->findById($accountId)->status);
    }

    // ── the journal ───────────────────────────────────────────────────

    public function testEachChangeLeavesATraceNamingTheAccountAndItsAuthor(): void
    {
        $this->save([
            'action' => 'create',
            'name' => 'Caisse camp',
            'account_type' => Account::TYPE_CASH,
            'role_min_view' => 'intendant',
        ]);

        $entries = (new JournalRepository($this->pdo))->search();
        $this->assertCount(1, $entries);
        $this->assertSame('account_created', $entries[0]['event_type']);
        $this->assertSame(1, (int) $entries[0]['user_account_id']);
    }

    // ── the page ──────────────────────────────────────────────────────

    public function testThePageListsTheAccountsThatExist(): void
    {
        $this->createAccount('Compte unité', 'intendant');

        $body = $this->controller
            ->index(new Request('GET', '/config/finance/accounts', [], [], [], []), [])
            ->getBody();

        $this->assertStringContainsString('Compte unité', $body);
    }

    // ── harness ───────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $payload
     */
    private function save(array $payload): \Core\Http\Response
    {
        $payload['_csrf_token'] = CsrfGuard::generateToken();

        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/config/finance/accounts', [], $payload, [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn((string) json_encode($payload));

        return $this->controller->save($request, []);
    }

    private function createAccount(string $name, string $roleMinView): int
    {
        return $this->accountRepository->create($name, Account::TYPE_BANK, null, null, null, $roleMinView);
    }

    /**
     * A receipt file, stamped with the floor its account had at upload
     * time — which is what Service\ReceiptService does for real.
     */
    private function attachReceiptFile(int $accountId, string $roleMin): int
    {
        $fileId = $this->fileRepository->create(
            'finance/recu-stored.pdf',
            'recu.pdf',
            'application/pdf',
            1024,
            $roleMin,
            'finance',
            null
        );

        $this->pdo->prepare(
            'INSERT INTO finance_attachments (account_id, file_id, mime_type, original_filename, uploaded_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$accountId, $fileId, 'application/pdf', 'recu.pdf', '2026-09-01 10:00:00']);

        return $fileId;
    }

    private function roleMinOf(int $fileId): string
    {
        $stmt = $this->pdo->prepare('SELECT role_min FROM files WHERE id = ?');
        $stmt->execute([$fileId]);

        return (string) $stmt->fetchColumn();
    }

    /**
     * @return array<int, string>
     */
    private function accountNames(): array
    {
        return array_map(
            static fn (Account $account): string => $account->name,
            $this->accountRepository->findAllOrdered()
        );
    }

    private function twig(): Environment
    {
        $loader = new FilesystemLoader(dirname(__DIR__, 4) . '/core/View/templates');
        $loader->addPath(dirname(__DIR__, 4) . '/modules/finance/views', 'finance');
        $twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);
        $twig->addFunction(new TwigFunction('asset', static fn (string $path): string => $path));
        $twig->addFunction(new TwigFunction('csrf_field', static fn (): string => '', ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('csrf_token', static fn (): string => 'test'));
        $twig->addFunction(new TwigFunction('get_flash', static fn () => null));
        $twig->addFunction(new TwigFunction('file_url', static fn (): string => ''));
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'admin');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/config/finance/accounts');
        $twig->addGlobal('csp_nonce', 'test-nonce');

        return $twig;
    }
}
