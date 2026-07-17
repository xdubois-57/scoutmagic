<?php

declare(strict_types=1);

namespace Tests\Core\Member\Controller;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Import\MemberYearRepository;
use Core\Member\Controller\MemberSearchController;
use Core\Member\MemberService;
use Core\Member\MemberYearService;
use Core\Member\Repository\MemberSearchRepository;
use Core\Member\Service\MemberSearchService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Core\View\TextNormalizerExtension;
use Core\Http\Request;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * @group database
 */
class MemberSearchControllerTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $enc;
    private MemberSearchController $controller;
    private int $yearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->enc = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);

        $scoutYearService = new ScoutYearService($this->pdo);
        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register(ScoutYearResolver::SETTING_PUBLIC_YEAR, '0', 'number', 'P', 'P', null, '^[0-9]+$', null, false);
        $settingService->register(ScoutYearResolver::SETTING_STAFF_YEAR, '0', 'number', 'S', 'S', null, '^[0-9]+$', null, false);

        $memberYearRepo = new MemberYearRepository($this->pdo);
        $resolver = new ScoutYearResolver($scoutYearService, $settingService, $memberYearRepo);
        $searchService = new MemberSearchService(new MemberSearchRepository($connection, $this->enc));
        $memberService = new MemberService($memberYearRepo, $this->enc, $connection);

        $this->yearId = $scoutYearService->ensureYear('2025-2026');
        // Pin the public year so the effective year is deterministic.
        $settingService->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->yearId);

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $twig = new Environment(new FilesystemLoader($templateDir), ['cache' => false, 'autoescape' => 'html']);
        $twig->addExtension(new TextNormalizerExtension());
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'admin@test.be');
        $twig->addGlobal('current_user_role', 'admin');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('csp_nonce', 'n');
        $twig->addFunction(new TwigFunction('csrf_field', fn() => '', ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('get_flash', fn() => null));
        $twig->addFunction(new TwigFunction('csrf_token', fn() => 't'));
        $twig->addFunction(new TwigFunction('file_url', fn() => ''));
        $twig->addFunction(new TwigFunction('param', fn(string $k) => 'Test'));
        $twig->addFilter(new TwigFilter('display_name', fn($m) => $m instanceof \Core\Member\MemberProfile ? $m->getDisplayName() : (string) $m));

        $this->controller = new MemberSearchController($twig, $searchService, $memberService, $resolver, new MemberYearService());

        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            session_start();
        }
        AuthSession::login(1, 'admin@test.be', 'admin');
    }

    private function seedMember(?string $birthDate = null): int
    {
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('BAL', 'Baladins', 1)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('BAL01', {$branchId}, 'Ruche')");
        $sectionId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role, confirmed) VALUES ('ANIM', 'Animateur', 'chief', 1)");
        $functionId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D1')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, totem_encrypted, email_encrypted, mobile_encrypted, birth_date_encrypted, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $memberId, $this->yearId,
            $this->enc->encrypt('jean'), $this->enc->encrypt('DUPONT'),
            $this->enc->encrypt('renard'), $this->enc->encrypt('jean@ex.be'),
            $this->enc->encrypt('0476123456'),
            $birthDate !== null ? $this->enc->encrypt($birthDate) : null,
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId, $sectionId]);

        return $memberYearId;
    }

    private function get(array $query = []): Request
    {
        return new Request('GET', '/admin/members', $query, [], [], []);
    }

    public function testEmptyStateWhenNoQuery(): void
    {
        $response = $this->controller->index($this->get(), []);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Entrez un nom', $response->getBody());
    }

    public function testSearchRendersNormalizedResults(): void
    {
        $this->seedMember();

        $body = $this->controller->index($this->get(['q' => 'dupont']), [])->getBody();

        // Last name stored all-caps ("DUPONT"), displayed title-cased.
        $this->assertStringContainsString('Dupont', $body);
        $this->assertStringNotContainsString('DUPONT', $body);
        $this->assertStringContainsString('inscrit', $body);
        // Result rows link to the detail anchor so the detail is scrolled into view.
        $this->assertStringContainsString('#member-detail', $body);
    }

    public function testNoResultsMessage(): void
    {
        $this->seedMember();
        $body = $this->controller->index($this->get(['q' => 'zzznothing']), [])->getBody();
        $this->assertStringContainsString('Aucun membre trouvé', $body);
    }

    public function testDetailCardRendersForValidMember(): void
    {
        $id = $this->seedMember();

        $body = $this->controller->index($this->get(['q' => 'dupont', 'member' => (string) $id]), [])->getBody();

        $this->assertStringContainsString('Données Desk', $body);
        $this->assertStringContainsString('id="member-detail"', $body);
        $this->assertStringContainsString('Données du site', $body);
        $this->assertStringContainsString('jean@ex.be', $body);
        // Phone normalized for display.
        $this->assertStringContainsString('+32 476 12 34 56', $body);
        $this->assertStringContainsString('Animateur', $body);
    }

    public function testDetailCardShowsScoutYearOffsetControlAndBranchYearLabel(): void
    {
        // 2014-01-01 → raw age 11 in scout year 2025-2026 (reference year 2025)
        // → louveteaux, 4e année.
        $id = $this->seedMember('2014-01-01');

        $body = $this->controller->index($this->get(['q' => 'dupont', 'member' => (string) $id]), [])->getBody();

        $this->assertStringContainsString('Décalage année scoute', $body);
        $this->assertStringContainsString('id="scout-year-offset-card"', $body);
        $this->assertStringContainsString('data-offset="-1"', $body);
        $this->assertStringContainsString('data-offset="0"', $body);
        $this->assertStringContainsString('data-offset="1"', $body);
        $this->assertStringContainsString('4e année louveteaux', $body);
        $this->assertStringContainsString('#639922', $body);
        // No offset set yet → "Normal" is the active segment.
        $this->assertMatchesRegularExpression(
            '/offset-btn active"\s+style="min-height:44px;" data-offset="0"/',
            $body
        );
    }

    public function testNotFoundForInvalidMember(): void
    {
        $this->seedMember();
        $response = $this->controller->index($this->get(['member' => '99999']), []);
        $this->assertSame(404, $response->getStatusCode());
    }
}
