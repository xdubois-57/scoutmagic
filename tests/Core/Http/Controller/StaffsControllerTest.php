<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Http\Controller\StaffsController;
use Core\Http\Request;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * @group database
 */
class StaffsControllerTest extends TestCase
{
    private \PDO $pdo;
    private StaffsController $controller;
    private SectionService $sectionService;
    private MemberService $memberService;
    private EncryptionService $encryption;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        /** @phpstan-ignore-next-line */
        $connection = new Connection($this->pdo);

        $this->sectionService = new SectionService($connection, $this->encryption);
        $memberYearRepo = new MemberYearRepository($this->pdo);
        $this->memberService = new MemberService($memberYearRepo, $this->encryption, $connection);
        $scoutYearService = new ScoutYearService($this->pdo);
        $settingService = new SettingService(new SettingRepository($this->pdo));
        $scoutYearResolver = new ScoutYearResolver($scoutYearService, $settingService, $memberYearRepo);
        $journalRepo = new JournalRepository($this->pdo);
        $journalService = new JournalService($journalRepo);

        // Create scout year
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        // Create Twig
        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $twig = new Environment(new FilesystemLoader($templateDir), [
            'cache' => false,
            'autoescape' => 'html',
        ]);
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'chief@test.be');
        $twig->addGlobal('current_user_role', 'chief');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addFunction(new TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('get_flash', fn() => null));
        $twig->addFunction(new TwigFunction('csrf_token', fn() => 'test'));
        $twig->addFunction(new TwigFunction('file_url', fn() => ''));
        $twig->addFunction(new TwigFunction('param', fn(string $k) => 'Test'));
        $twig->addFilter(new TwigFilter('display_name', function ($member) {
            if ($member instanceof \Core\Member\MemberProfile) {
                return $member->getDisplayName();
            }
            return '';
        }));

        $this->controller = new StaffsController(
            $twig,
            $this->sectionService,
            $this->memberService,
            $scoutYearResolver,
            $journalService
        );

        // Set up session as chief
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'chief@test.be', 'chief');
    }

    private function createBranch(string $code, string $label, int $sortOrder): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, ?)');
        $stmt->execute([$code, $label, $sortOrder]);
        return (int) $this->pdo->lastInsertId();
    }

    private function createSection(string $deskCode, int $branchId, ?string $name = null, ?string $email = null): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name, email) VALUES (?, ?, ?, ?)');
        $stmt->execute([$deskCode, $branchId, $name, $email]);
        return (int) $this->pdo->lastInsertId();
    }

    private function createMemberInSection(int $sectionId, string $firstName, string $functionRole = 'identified', string $email = 'member@test.be'): int
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, email_blind_index, mobile_encrypted)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId, $this->scoutYearId,
            $this->encryption->encrypt($firstName),
            $this->encryption->encrypt('Dupont'),
            $this->encryption->encrypt($email),
            $this->encryption->blindIndex(strtolower($email)),
            $this->encryption->encrypt('0498765432'),
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT OR IGNORE INTO functions (desk_code, label, role) VALUES ('{$functionRole}', 'Animateur', '{$functionRole}')");
        $stmt = $this->pdo->prepare('SELECT id FROM functions WHERE desk_code = ?');
        $stmt->execute([$functionRole]);
        $functionId = (int) $stmt->fetchColumn();

        $stmt = $this->pdo->prepare('SELECT age_branch_id FROM sections WHERE id = ?');
        $stmt->execute([$sectionId]);
        $branchId = (int) $stmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, age_branch_id, is_main_function)
             VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId, $sectionId, $branchId]);

        return $memberYearId;
    }

    public function testIndexRendersWithSectionPickerAndStaffList(): void
    {
        $branchId = $this->createBranch('BAL', 'Baladins', 1);
        $sectionId = $this->createSection('BAL01', $branchId, 'Ma section');
        $this->createMemberInSection($sectionId, 'Alice', 'chief');

        $request = new Request('GET', '/chefs/staffs', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();
        $this->assertStringContainsString('Staffs', $body);
        $this->assertStringContainsString('section-picker', $body);
        $this->assertStringContainsString('Ma section', $body);
        $this->assertStringContainsString('Alice', $body);
    }

    public function testChiefSeesAllSections(): void
    {
        $balId = $this->createBranch('BAL', 'Baladins', 1);
        $louId = $this->createBranch('LOU', 'Louveteaux', 2);
        $this->createSection('BAL01', $balId, 'Section Bal');
        $this->createSection('LOU01', $louId, 'Section Lou');

        $request = new Request('GET', '/chefs/staffs', [], [], [], []);
        $response = $this->controller->index($request, []);

        $body = $response->getBody();
        $this->assertStringContainsString('Section Bal', $body);
        $this->assertStringContainsString('Section Lou', $body);
    }

    public function testIntendantSeesOnlyLinkedSections(): void
    {
        // Set session as intendant
        AuthSession::login(2, 'intendant@test.be', 'intendant');

        $balId = $this->createBranch('BAL', 'Baladins', 1);
        $louId = $this->createBranch('LOU', 'Louveteaux', 2);
        $sectionA = $this->createSection('BAL01', $balId, 'Section Bal');
        $sectionB = $this->createSection('LOU01', $louId, 'Section Lou');

        // Link intendant to sectionA only
        $this->createMemberInSection($sectionA, 'Intendant', 'intendant', 'intendant@test.be');

        $request = new Request('GET', '/chefs/staffs', [], [], [], []);
        $response = $this->controller->index($request, []);

        $body = $response->getBody();
        $this->assertStringContainsString('Section Bal', $body);
        $this->assertStringNotContainsString('Section Lou', $body);
    }

    public function testUpdateSectionSucceedsForChief(): void
    {
        $branchId = $this->createBranch('BAL', 'Baladins', 1);
        $sectionId = $this->createSection('BAL01', $branchId, 'Old Name');

        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $request = $this->createJsonRequest([
            'section_id' => $sectionId,
            'name' => 'New Name',
            'email' => 'new@test.be',
            '_csrf_token' => $token,
        ]);
        $response = $this->controller->updateSection($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);

        $section = $this->sectionService->getSection($sectionId);
        $this->assertSame('New Name', $section['name']);
        $this->assertSame('new@test.be', $section['email']);
    }

    public function testUpdateSectionValidatesCsrf(): void
    {
        $branchId = $this->createBranch('BAL', 'Baladins', 1);
        $sectionId = $this->createSection('BAL01', $branchId);

        $request = $this->createJsonRequest([
            'section_id' => $sectionId,
            'name' => 'Test',
            'email' => 'test@test.be',
            '_csrf_token' => 'invalid-token',
        ]);
        $response = $this->controller->updateSection($request, []);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testEmptyStateWhenNoSections(): void
    {
        $request = new Request('GET', '/chefs/staffs', [], [], [], []);
        $response = $this->controller->index($request, []);

        $body = $response->getBody();
        $this->assertStringContainsString('Aucune section disponible', $body);
    }

    public function testUnconfiguredSectionShowsWarning(): void
    {
        $branchId = $this->createBranch('BAL', 'Baladins', 1);
        $this->createSection('BAL01', $branchId); // No name

        $request = new Request('GET', '/chefs/staffs', [], [], [], []);
        $response = $this->controller->index($request, []);

        $body = $response->getBody();
        $this->assertStringContainsString("n&#039;a pas encore de nom configuré", $body);
    }

    public function testUpdateSectionLogsToJournal(): void
    {
        $branchId = $this->createBranch('BAL', 'Baladins', 1);
        $sectionId = $this->createSection('BAL01', $branchId, 'Old');

        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $request = $this->createJsonRequest([
            'section_id' => $sectionId,
            'name' => 'New',
            'email' => 'new@test.be',
            '_csrf_token' => $token,
        ]);
        $this->controller->updateSection($request, []);

        // Verify journal entry
        $stmt = $this->pdo->query("SELECT * FROM event_log WHERE event_type = 'section_info_updated'");
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('BAL01', $rows[0]['description']);
    }

    public function testUpdateSectionWithNonExistentSectionReturnsError(): void
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $request = $this->createJsonRequest([
            'section_id' => 9999,
            'name' => 'Test',
            '_csrf_token' => $token,
        ]);
        $response = $this->controller->updateSection($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertSame(400, $response->getStatusCode());
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createJsonRequest(array $data): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/chefs/staffs/update-section', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();

        $request->method('getRawBody')->willReturn(json_encode($data));

        return $request;
    }
}
