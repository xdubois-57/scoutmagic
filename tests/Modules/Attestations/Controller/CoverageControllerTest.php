<?php

declare(strict_types=1);

namespace Tests\Modules\Attestations\Controller;

use Core\Config\AppConfig;
use Core\Database\Connection;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Modules\Attestations\Controller\CoverageController;
use Modules\Attestations\Repository\BatchLineRepository;
use Modules\Attestations\Repository\BatchRepository;
use Modules\Attestations\Repository\MemberNameRepository;
use Modules\Attestations\Service\CoverageService;
use Modules\Attestations\Value\AttestationCategory;
use Modules\Attestations\Value\BatchStatus;
use Modules\Attestations\Value\MatchState;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Attestations\AttestationsTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The coverage screen, rendered.
 *
 * What it has to say out loud: who is MISSING, first and in full, because
 * that list is what a chef d'unité copies into an e-mail to the federation.
 * The covered ones are the answer to « en es-tu sûr ? » and are folded away.
 */
#[Group('database')]
class CoverageControllerTest extends TestCase
{
    private \PDO $pdo;
    private Environment $twig;
    private BatchRepository $batches;
    private BatchLineRepository $lines;
    private int $scoutYearId;

    /** @var array<string, int> */
    private array $memberIds = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        AttestationsTestHelper::createTables($this->pdo);
        $this->scoutYearId = AttestationsTestHelper::createScoutYear($this->pdo);
        $this->twig = $this->buildTwig();

        $connection = Connection::withPdo($this->pdo);
        $encryption = AttestationsTestHelper::encryption();
        $this->batches = new BatchRepository($connection);
        $this->lines = new BatchLineRepository($connection, $encryption);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        AuthSession::login(1, 'chef-unite@test.be', 'admin');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        $_SESSION = [];
    }

    private function createMember(string $key, string $first, string $last): void
    {
        $this->memberIds[$key] = AttestationsTestHelper::createMember(
            $this->pdo, $this->scoutYearId, $first, $last
        );
    }

    /** @param list<string> $memberKeys */
    private function publishFor(array $memberKeys): void
    {
        $batchId = $this->batches->create(
            $this->scoutYearId, AttestationCategory::Tax, 'Attestation fiscale 2025',
            count($memberKeys) * 2, 2, count($memberKeys), null
        );

        $position = 0;
        foreach ($memberKeys as $key) {
            $position++;
            $stmt = $this->pdo->prepare(
                'INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute(['a/' . bin2hex(random_bytes(6)), 'x.pdf', 'application/pdf', 10, 'identified']);

            $this->lines->create(
                $batchId, $position, $position * 2 - 1, $position * 2, 'NOM Prenom',
                $this->memberIds[$key], MatchState::Matched, (int) $this->pdo->lastInsertId()
            );
        }

        $stmt = $this->pdo->prepare('UPDATE attestation_batches SET status = ?, published_at = ? WHERE id = ?');
        $stmt->execute([BatchStatus::Published->value, '2026-02-11 09:00:00', $batchId]);
    }

    /** @param array<string, string> $query */
    private function body(array $query = []): string
    {
        return (string) preg_replace('/\s+/', ' ', (string) $this->frontController()
            ->handle(new Request('GET', '/admin/attestations/couverture', $query, [], [], []))
            ->getBody());
    }

    public function testTheMissingAreNamedAndCounted(): void
    {
        $this->createMember('margaux', 'Margaux', 'Vandenbrande');
        $this->createMember('sacha', 'Sacha', 'Meunier');
        $this->publishFor(['margaux']);

        $body = $this->body();

        $this->assertStringContainsString('1 membre(s) sans attestation', $body);
        $this->assertStringContainsString('Sacha Meunier', $body);
        $this->assertMatchesRegularExpression(
            '#id="attestations-missing-count"[^>]*>1<#',
            $body
        );
    }

    /**
     * Said plainly, because the two causes call for two different actions:
     * either the federation has not sent it, or a line found no member.
     */
    public function testTheScreenSaysWhatToDoWithTheMissingList(): void
    {
        $this->createMember('sacha', 'Sacha', 'Meunier');

        $body = $this->body();

        $this->assertStringContainsString('réclamer le complément', $body);
    }

    public function testACoveredMemberIsFoldedAwayRatherThanMixedIn(): void
    {
        $this->createMember('margaux', 'Margaux', 'Vandenbrande');
        $this->createMember('sacha', 'Sacha', 'Meunier');
        $this->publishFor(['margaux']);

        $body = $this->body();

        $this->assertStringContainsString('1 membre(s) ont déjà la leur', $body);
        // The fold itself, so the missing list is what the page opens on.
        $this->assertStringContainsString('<details', $body);
    }

    public function testEverybodyCoveredReadsAsDone(): void
    {
        $this->createMember('margaux', 'Margaux', 'Vandenbrande');
        $this->publishFor(['margaux']);

        $this->assertStringContainsString('Tout le monde a la sienne', $this->body());
    }

    public function testAYearWithNobodyRegisteredSaysSoRatherThanClaimingCompletion(): void
    {
        $body = $this->body();

        $this->assertStringContainsString('Aucun membre inscrit cette année-là', $body);
        $this->assertStringNotContainsString('Tout le monde a la sienne', $body);
    }

    /**
     * A reading, so it travels in a query string: this page gets bookmarked
     * and sent to the chef d'unité who writes to the federation.
     */
    public function testTheCategoryAndYearComeFromTheQueryString(): void
    {
        $this->createMember('margaux', 'Margaux', 'Vandenbrande');
        $this->publishFor(['margaux']);

        // Same member, but asked about attendance certificates: uncovered.
        $body = $this->body([
            'category' => AttestationCategory::Attendance->value,
            'scout_year_id' => (string) $this->scoutYearId,
        ]);

        $this->assertStringContainsString('1 membre(s) sans attestation', $body);
        $this->assertStringContainsString('Margaux Vandenbrande', $body);
    }

    /** An unreadable category falls back rather than failing the page. */
    public function testAnUnknownCategoryFallsBackToTheTaxOne(): void
    {
        $this->createMember('margaux', 'Margaux', 'Vandenbrande');
        $this->publishFor(['margaux']);

        $this->assertStringContainsString(
            'Tout le monde a la sienne',
            $this->body(['category' => 'n-importe-quoi'])
        );
    }

    private function frontController(): FrontController
    {
        $router = new Router();
        $router->addRoute('GET', '/admin/attestations/couverture', CoverageController::class, 'index', 'admin');

        $configFile = sys_get_temp_dir() . '/test_attestations_coverage_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");

        $frontController = new FrontController($router, $this->twig, new AppConfig($configFile));

        $connection = Connection::withPdo($this->pdo);
        $frontController->registerController(
            CoverageController::class,
            new CoverageController(
                $this->twig,
                new CoverageService(
                    new MemberNameRepository($connection, AttestationsTestHelper::encryption()),
                    $this->lines
                ),
                new ScoutYearResolver(
                    new \Core\Config\ScoutYearService($this->pdo),
                    new \Core\Config\SettingService(new \Core\Config\SettingRepository($this->pdo)),
                    new \Core\Import\MemberYearRepository($this->pdo)
                )
            )
        );

        return $frontController;
    }

    private function buildTwig(): Environment
    {
        $loader = new FilesystemLoader(dirname(__DIR__, 4) . '/core/View/templates');
        $loader->addPath(dirname(__DIR__, 4) . '/modules/attestations/views', 'attestations');

        $twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);
        $twig->addFunction(new TwigFunction('asset', static fn(string $path): string => $path));
        $twig->addFilter(new TwigFilter(
            'date_fr',
            static fn($d) => $d === null || $d === '' ? '' : (new \DateTimeImmutable((string) $d))->format('d/m/Y')
        ));
        $twig->addFilter(new TwigFilter(
            'datetime_fr',
            static fn($d) => $d === null || $d === '' ? '' : (new \DateTimeImmutable((string) $d))->format('d/m/Y à H:i')
        ));

        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'test@test.be');
        $twig->addGlobal('current_user_role', 'admin');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/');
        $twig->addGlobal('csp_nonce', 'test-nonce');
        $twig->addFunction(new TwigFunction('csrf_field', static fn(): string => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('csrf_token', static fn(): string => 'test'));
        $twig->addFunction(new TwigFunction('get_flash', static fn() => null));
        $twig->addFunction(new TwigFunction('file_url', static fn(): string => ''));

        return $twig;
    }
}
