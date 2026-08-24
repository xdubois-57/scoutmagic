<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Finance\Controller;

use Core\Badge\BadgeRepository;
use Core\Badge\BadgeService;
use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\SectionService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Modules\Finance\Controller\ToolsController;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\BalanceCheckpointRepository;
use Modules\Finance\Repository\CategoryRepository;
use Modules\Finance\Repository\CategoryRuleRepository;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\FiscalYearRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\AccountTransferCategoryService;
use Modules\Finance\Service\AccountVisibility;
use Modules\Finance\Service\BalanceService;
use Modules\Finance\Service\FinanceService;
use Modules\Finance\Service\SepaQrCodeService;
use Modules\Finance\Service\StructuredCommunicationService;
use Modules\Finance\Service\TreasurerScope;
use Modules\Finance\Service\TreasurerScopeService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The "Outils" page: a payment QR, and a communication checker.
 *
 * Two of these tests exist for things that are easy to get wrong and
 * invisible when you do: the QR tool must create no receivable (a row
 * written here would fill the reconciliation page with money nobody
 * promised), and the checker must not describe a receivable booked
 * against an account the caller cannot open — which would hand back
 * through this page exactly what §8.69 and §8.70 removed everywhere else.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ToolsControllerTest extends TestCase
{
    private \PDO $pdo;
    private Environment $twig;
    private ExpectedReceivableRepository $receivableRepository;
    private TreasurerScopeService $treasurerRule;
    private int $louveteauxId = 1;
    private int $eclaireursId = 2;
    private int $badgeId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->receivableRepository = new ExpectedReceivableRepository($this->pdo, $encryption);
        $this->treasurerRule = new TreasurerScopeService(
            Connection::withPdo($this->pdo),
            new BadgeRepository($this->pdo),
            new MemberBadgeRepository($this->pdo)
        );

        $this->pdo->exec("INSERT INTO scout_years (id, label, start_date, end_date, is_current) VALUES (1, '2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->pdo->exec("INSERT INTO age_branches (id, desk_code, label, sort_order) VALUES (1, 'LOU', 'Louveteaux', 20), (2, 'ECL', 'Éclaireurs', 30)");
        $this->pdo->exec("INSERT INTO sections (id, age_branch_id, desk_code, name) VALUES (1, 1, 'LOU01', 'Louveteaux'), (2, 2, 'ECL01', 'Éclaireurs')");
        $this->pdo->exec("INSERT INTO functions (id, desk_code, label, role) VALUES (1, 'ANIM', 'Animateur', 'chief')");
        $stmt = $this->pdo->prepare('INSERT INTO badges (name, is_default, is_active) VALUES (?, 1, 1)');
        $stmt->execute([BadgeService::BADGE_TREASURER]);
        $this->badgeId = (int) $this->pdo->lastInsertId();

        $this->twig = $this->buildTwig();

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    // --- the page itself ---

    public function testThePageRendersBothTools(): void
    {
        AuthSession::login(1, 'intendant@test.be', 'intendant');

        $body = $this->controller()->index(new Request('GET', '/finance/tools', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('Code QR de paiement', $body);
        $this->assertStringContainsString('Vérifier une communication', $body);
        $this->assertStringContainsString('/finance/tools', $body, 'the page picker links back to itself');
    }

    // --- the QR generator ---

    public function testItRendersAQrForAValidPayment(): void
    {
        AuthSession::login(1, 'intendant@test.be', 'intendant');

        $body = $this->postQr(['beneficiary' => 'Unité Saint-Michel', 'iban' => 'BE71 0961 2345 6769', 'amount' => '25,00', 'communication' => 'Camp 2026'])->getBody();

        $this->assertStringContainsString('data:image/png;base64,', $body);
        // Grouped for reading, which is the only place format() belongs.
        $this->assertStringContainsString('BE71 0961 2345 6769', $body);
    }

    public function testItCreatesNoReceivable(): void
    {
        AuthSession::login(1, 'intendant@test.be', 'intendant');

        $this->postQr(['beneficiary' => 'Unité', 'iban' => 'BE71096123456769', 'amount' => '25,00', 'communication' => 'Camp']);

        // A row here would put money nobody promised onto the
        // reconciliation page, for ever, with nothing to trace it to.
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM finance_expected_receivables')->fetchColumn());
    }

    public function testItRefusesAnIbanThatFailsItsChecksum(): void
    {
        AuthSession::login(1, 'intendant@test.be', 'intendant');

        // One digit changed from a real IBAN — the length is right and
        // only the mod-97 check catches it.
        $body = $this->postQr(['beneficiary' => 'Unité', 'iban' => 'BE71096123456760', 'amount' => '25,00'])->getBody();

        // Decoded first: Twig autoescapes, so the apostrophe in the
        // message reaches the page as &#039; and asserting on the source
        // string would fail for the wrong reason.
        $this->assertStringContainsString("Cet IBAN n'est pas valide.", html_entity_decode($body));
        $this->assertStringNotContainsString('data:image/png;base64,', $body);
    }

    public function testItRefusesAnAmountThatIsNotPositive(): void
    {
        AuthSession::login(1, 'intendant@test.be', 'intendant');

        foreach (['0', '-5', 'gratuit', ''] as $amount) {
            $body = $this->postQr(['beneficiary' => 'Unité', 'iban' => 'BE71096123456769', 'amount' => $amount])->getBody();
            $this->assertStringContainsString('Le montant doit être un nombre positif.', $body, "amount: {$amount}");
        }
    }

    public function testItAcceptsACommaAsTheDecimalSeparator(): void
    {
        AuthSession::login(1, 'intendant@test.be', 'intendant');

        // Every French-speaking keyboard produces one, and refusing it
        // would make the tool feel broken for its actual users.
        $body = $this->postQr(['beneficiary' => 'Unité', 'iban' => 'BE71096123456769', 'amount' => '25,50'])->getBody();

        $this->assertStringContainsString('data:image/png;base64,', $body);
        $this->assertStringContainsString('25,50', $body);
    }

    public function testItRefusesAWrongCsrfTokenWithoutGenerating(): void
    {
        AuthSession::login(1, 'intendant@test.be', 'intendant');

        $response = $this->controller()->generateQr(
            new Request('POST', '/finance/tools/qr', [], ['beneficiary' => 'U', 'iban' => 'BE71096123456769', 'amount' => '25', '_csrf_token' => 'wrong'], [], []),
            []
        );

        $this->assertSame('/finance/tools', $response->getHeaders()['Location'] ?? null);
    }

    public function testItRefusesACommunicationLongerThanTheEpcFieldAllows(): void
    {
        AuthSession::login(1, 'intendant@test.be', 'intendant');

        // The EPC payload's remittance field is 140 characters; anything
        // longer would be silently truncated into the QR, which is worse
        // than refusing it — the payer would see a mangled reference.
        $body = $this->postQr([
            'beneficiary' => 'Unité',
            'iban' => 'BE71096123456769',
            'amount' => '25,00',
            'communication' => str_repeat('a', 141),
        ])->getBody();

        $this->assertStringContainsString('ne peut pas dépasser 140', $body);
        $this->assertStringNotContainsString('data:image/png;base64,', $body);
    }

    public function testWithoutAQrGeneratorThePageSaysSoRatherThanCrashing(): void
    {
        AuthSession::login(1, 'intendant@test.be', 'intendant');

        $body = $this->controller(null, withQrGenerator: false)->generateQr(
            new Request('POST', '/finance/tools/qr', [], [
                'beneficiary' => 'Unité', 'iban' => 'BE71096123456769', 'amount' => '25,00',
                '_csrf_token' => CsrfGuard::generateToken(),
            ], [], []),
            []
        )->getBody();

        $this->assertStringContainsString("Le générateur de QR n'est pas disponible.", html_entity_decode($body));
    }

    // --- the communication checker ---

    public function testItReportsAMalformedCommunication(): void
    {
        AuthSession::login(1, 'intendant@test.be', 'intendant');

        $body = $this->postCheck('+++123/4567/89000+++')->getBody();

        $this->assertStringContainsString('Communication invalide', $body);
    }

    public function testItReportsAValidButUnknownCommunication(): void
    {
        AuthSession::login(1, 'intendant@test.be', 'intendant');

        $body = $this->postCheck(StructuredCommunicationService::format('9876543210'))->getBody();

        $this->assertStringContainsString('valide, mais inconnue ici', $body);
    }

    public function testItDescribesAReceivableOnAnAccountTheCallerCanSee(): void
    {
        $treasurer = $this->createTreasurerOf($this->louveteauxId);
        $communication = $this->createReceivable($this->louveteauxId, 'Camp Louveteaux 2026', 2500);
        AuthSession::login(1, 'tresorier@test.be', 'intendant');

        $body = $this->postCheck($communication, $treasurer)->getBody();

        $this->assertStringContainsString('valide et reconnue', $body);
        $this->assertStringContainsString('Camp Louveteaux 2026', $body);
        $this->assertStringContainsString('25,00', $body);
    }

    public function testItSaysNothingAboutAReceivableOnAnAccountTheCallerCannotSee(): void
    {
        $treasurer = $this->createTreasurerOf($this->louveteauxId);
        $communication = $this->createReceivable($this->eclaireursId, 'Camp Éclaireurs 2026', 4200);
        AuthSession::login(1, 'tresorier@test.be', 'intendant');

        $body = $this->postCheck($communication, $treasurer)->getBody();

        // Not "it exists but is not yours": that would still confirm the
        // communication was issued here. It answers exactly as it does for
        // one that was never issued at all.
        $this->assertStringContainsString('valide, mais inconnue ici', $body);
        $this->assertStringNotContainsString('Camp Éclaireurs 2026', $body);
        $this->assertStringNotContainsString('42,00', $body);
        $this->assertStringNotContainsString('Éclaireurs', $body);
    }

    public function testTheJournalRecordsTheOutcomeAndNeitherTheCommunicationNorTheLabel(): void
    {
        $treasurer = $this->createTreasurerOf($this->louveteauxId);
        $communication = $this->createReceivable($this->louveteauxId, 'Camp Louveteaux 2026', 2500);
        AuthSession::login(1, 'tresorier@test.be', 'intendant');

        $this->postCheck($communication, $treasurer);

        $row = $this->pdo->query("SELECT * FROM event_log WHERE event_type = 'communication_checked'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);

        $recorded = json_encode($row);
        // A payment reference and a label say who paid what. A numeric id
        // is enough to follow the thread (SECURITY.md §11).
        $this->assertStringNotContainsString($communication, (string) $recorded);
        $this->assertStringNotContainsString('Camp Louveteaux 2026', (string) $recorded);
        $this->assertStringContainsString('receivable_id', (string) $recorded);
    }

    public function testItAsksForACommunicationRatherThanCheckingNothing(): void
    {
        AuthSession::login(1, 'intendant@test.be', 'intendant');

        $body = $this->postCheck('')->getBody();

        $this->assertStringContainsString('Saisissez une communication', $body);
        // And nothing is journalled for a check that never happened.
        $this->assertFalse($this->pdo->query("SELECT 1 FROM event_log WHERE event_type = 'communication_checked'")->fetch());
    }

    public function testTheCheckerRefusesAWrongCsrfToken(): void
    {
        AuthSession::login(1, 'intendant@test.be', 'intendant');

        $response = $this->controller()->checkCommunication(
            new Request('POST', '/finance/tools/communication', [], ['communication' => '123', '_csrf_token' => 'wrong'], [], []),
            []
        );

        $this->assertSame('/finance/tools', $response->getHeaders()['Location'] ?? null);
    }

    // --- fixtures ---

    /** @param array<string, string> $body */
    private function postQr(array $body): \Core\Http\Response
    {
        $body['_csrf_token'] = CsrfGuard::generateToken();

        return $this->controller()->generateQr(new Request('POST', '/finance/tools/qr', [], $body, [], []), []);
    }

    private function postCheck(string $communication, ?int $linkedMemberId = null): \Core\Http\Response
    {
        return $this->controller($linkedMemberId)->checkCommunication(
            new Request('POST', '/finance/tools/communication', [], ['communication' => $communication, '_csrf_token' => CsrfGuard::generateToken()], [], []),
            []
        );
    }

    private function controller(?int $linkedMemberId = null, bool $withQrGenerator = true): ToolsController
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);
        $accountRepository = new AccountRepository($this->pdo, $encryption);
        $categoryRepository = new CategoryRepository($this->pdo);
        $categoryRuleRepository = new CategoryRuleRepository($this->pdo);
        $transactionRepository = new TransactionRepository($this->pdo, $encryption);
        $scoutYearService = new ScoutYearService($this->pdo);

        $visibility = new AccountVisibility(
            $linkedMemberId === null
                ? TreasurerScope::systemCaller()
                : TreasurerScope::forSession($this->treasurerRule, [$linkedMemberId], 1)
        );

        $financeService = new FinanceService(
            $accountRepository,
            $categoryRepository,
            new FiscalYearRepository($this->pdo, $scoutYearService),
            new SectionService($connection, $encryption, new MemberBadgeRepository($this->pdo)),
            $transactionRepository,
            new BalanceService(new BalanceCheckpointRepository($this->pdo), $transactionRepository),
            new SettingService(new SettingRepository($this->pdo)),
            $categoryRuleRepository,
            new AccountTransferCategoryService($categoryRepository, $categoryRuleRepository, $transactionRepository),
            $visibility
        );

        return new ToolsController(
            $this->twig,
            $financeService,
            $this->receivableRepository,
            new JournalService(new JournalRepository($this->pdo)),
            $withQrGenerator ? new SepaQrCodeService() : null
        );
    }

    /** @return string the receivable's communication */
    private function createReceivable(int $sectionId, string $label, int $amountCents): string
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO finance_accounts (name, account_type, section_id, role_min_view, status) VALUES (?, 'bank', ?, 'intendant', 'active')"
        );
        $stmt->execute(['Compte ' . $sectionId, $sectionId]);
        $accountId = (int) $this->pdo->lastInsertId();

        $communication = StructuredCommunicationService::format(str_pad((string) (1000000000 + $sectionId), 10, '0', STR_PAD_LEFT));
        $this->receivableRepository->create('news', $sectionId, $accountId, $amountCents, $communication, $label);

        return $communication;
    }

    private function createTreasurerOf(int $sectionId): int
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('desk-" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, is_active) VALUES (?, 1, ?, ?, 1)'
        );
        $stmt->execute([$memberId, 'x', 'y']);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare('INSERT INTO member_functions (member_year_id, function_id, section_id) VALUES (?, 1, ?)');
        $stmt->execute([$memberYearId, $sectionId]);

        $stmt = $this->pdo->prepare('INSERT INTO member_badges (member_year_id, badge_id) VALUES (?, ?)');
        $stmt->execute([$memberYearId, $this->badgeId]);

        return $memberId;
    }

    private function buildTwig(): Environment
    {
        $loader = new FilesystemLoader(dirname(__DIR__, 4) . '/core/View/templates');
        $loader->addPath(dirname(__DIR__, 4) . '/modules/finance/views', 'finance');
        $twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);

        $twig->addFilter(new TwigFilter('money_cents', fn($c) => $c === null || $c === '' ? '' : number_format(((int) $c) / 100, 2, ',', ' ') . ' €'));
        $twig->addFilter(new TwigFilter('money', fn($a) => $a === null || $a === '' ? '' : number_format((float) $a, 2, ',', ' ') . ' €'));
        $twig->addFilter(new TwigFilter('date_fr', fn($d) => $d === null || $d === '' ? '' : (new \DateTimeImmutable((string) $d))->format('d/m/Y')));
        $twig->addFilter(new TwigFilter('datetime_fr', fn($d) => $d === null || $d === '' ? '' : (new \DateTimeImmutable((string) $d))->format('d/m/Y à H:i')));
        $twig->addFunction(new TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('csrf_token', fn() => 'test'));
        $twig->addFunction(new TwigFunction('get_flash', fn() => null));
        $twig->addFunction(new TwigFunction('file_url', fn() => ''));
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'test@test.be');
        $twig->addGlobal('current_user_role', 'intendant');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/finance/tools');
        $twig->addGlobal('csp_nonce', 'test-nonce');

        return $twig;
    }
}
