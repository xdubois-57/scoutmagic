<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Database\Connection;
use Core\Http\Controller\MemberController;
use Core\Http\Request;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\DepartureRepository;
use Core\Member\DepartureService;
use Core\Member\MemberPageService;
use Core\Member\MemberService;
use Core\Member\MemberYearService;
use Core\Badge\MemberBadgeRepository;
use Core\Member\SectionService;
use Core\Member\SectionStaffAuthorizationService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;

/**
 * MemberController::updateScoutYearOffset — the AJAX save behind the "Décalage
 * année scoute" control: CSRF, offset validation, persistence, and journaling.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MemberControllerScoutYearOffsetTest extends TestCase
{
    private \PDO $pdo;
    private MemberController $controller;
    private MemberService $memberService;
    private EncryptionService $encryption;
    private int $scoutYearId;
    private int $sectionId;
    private int $otherSectionId;
    private int $staffFunctionId;
    private int $animeFunctionId;

    /** The address the signed-in chief of these tests uses. */
    private const CHIEF_EMAIL = 'chief@test.example';

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];

        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $memberYearRepo = new MemberYearRepository($this->pdo);
        $this->memberService = new MemberService($memberYearRepo, $this->encryption, Connection::withPdo($this->pdo));
        $journalService = new JournalService(new JournalRepository($this->pdo));

        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService($connection, $this->encryption, new MemberBadgeRepository($this->pdo));

        $this->controller = new MemberController(
            $this->createMock(Environment::class),
            $this->memberService,
            new MemberYearService(),
            $journalService,
            $this->createMock(MemberPageService::class),
            new DepartureService(new DepartureRepository($this->pdo, $this->encryption), $journalService),
            new SectionStaffAuthorizationService($connection, $this->encryption, $sectionService),
            $sectionService
        );

        // Scout year 2025-2026 → reference year 2025.
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 2)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (age_branch_id, desk_code, name) VALUES ({$branchId}, 'lou1', 'Meute')");
        $this->sectionId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (age_branch_id, desk_code, name) VALUES ({$branchId}, 'lou2', 'Autre meute')");
        $this->otherSectionId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO functions (desk_code, label, role, confirmed) VALUES ('ANIM', 'Animateur', 'chief', 1)");
        $this->staffFunctionId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role, confirmed) VALUES ('MEMBRE', 'Membre', 'identified', 1)");
        $this->animeFunctionId = (int) $this->pdo->lastInsertId();

        // The signed-in chief, an animateur of the first section: the write
        // guard resolves them by e-mail, exactly as the site does.
        $this->staffMemberYear(self::CHIEF_EMAIL, $this->sectionId);
    }

    /** Creates a staff member_year for $email, animating $sectionId. */
    private function staffMemberYear(string $email, int $sectionId): int
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('STAFF_" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
                 email_encrypted, email_blind_index)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId,
            $this->scoutYearId,
            $this->encryption->encrypt('Akela', 'member_years.first_name'),
            $this->encryption->encrypt('Loup', 'member_years.last_name'),
            $this->encryption->encrypt($email, 'member_years.email'),
            $this->encryption->blindIndex($email, 'email'),
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id) VALUES (?, ?, ?)'
        );
        $stmt->execute([$memberYearId, $this->staffFunctionId, $sectionId]);

        return $memberYearId;
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /**
     * Creates a member_year with the given birth date. Reference year is 2025,
     * so a birth date of 2014-01-01 gives a raw age of 11 (louveteaux 4e année).
     */
    private function createMemberYear(string $birthDate, ?int $sectionId = null): int
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('TEST_" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, birth_date_encrypted)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId,
            $this->scoutYearId,
            $this->encryption->encrypt('John', 'member_years.first_name'),
            $this->encryption->encrypt('Doe', 'member_years.last_name'),
            $this->encryption->encrypt($birthDate, 'member_years.birth_date'),
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        // An animé of the section, which is what makes the caller's own
        // staffed section the one being written into.
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id) VALUES (?, ?, ?)'
        );
        $stmt->execute([$memberYearId, $this->animeFunctionId, $sectionId ?? $this->sectionId]);

        return $memberYearId;
    }

    private function startSessionWithCsrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        AuthSession::login(1, self::CHIEF_EMAIL, 'chief');

        return $token;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonRequest(array $data): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/members/1/scout-year-offset', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn(json_encode($data));

        return $request;
    }

    public function testValidOffsetIsPersistedAndReturnsBranchYearLabel(): void
    {
        $token = $this->startSessionWithCsrfToken();
        // 2014-01-01 → raw age 11 in 2025 (louveteaux 4e année). Offset -1 moves
        // the effective age to 10 → louveteaux 3e année.
        $memberYearId = $this->createMemberYear('2014-01-01');

        $response = $this->controller->updateScoutYearOffset(
            $this->jsonRequest(['offset' => -1, '_csrf_token' => $token]),
            ['id' => (string) $memberYearId]
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertSame('3e année louveteaux', $decoded['branch_year_label']);
        $this->assertSame('#639922', $decoded['branch_color']);

        $stmt = $this->pdo->prepare('SELECT scout_year_offset FROM member_years WHERE id = ?');
        $stmt->execute([$memberYearId]);
        $this->assertSame(-1, (int) $stmt->fetchColumn());
    }

    public function testBoundaryOffsetMovesAMemberIntoTheNextBranch(): void
    {
        $token = $this->startSessionWithCsrfToken();
        // 2014-01-01 → raw age 11 (louveteaux 4e) → offset +1 → effective age 12 → éclaireurs 1ère année.
        $memberYearId = $this->createMemberYear('2014-01-01');

        $response = $this->controller->updateScoutYearOffset(
            $this->jsonRequest(['offset' => 1, '_csrf_token' => $token]),
            ['id' => (string) $memberYearId]
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertSame('1ère année éclaireurs', $decoded['branch_year_label']);
    }

    public function testInvalidCsrfTokenIsRejected(): void
    {
        $this->startSessionWithCsrfToken();
        $memberYearId = $this->createMemberYear('2014-01-01');

        $response = $this->controller->updateScoutYearOffset(
            $this->jsonRequest(['offset' => -1, '_csrf_token' => 'wrong-token']),
            ['id' => (string) $memberYearId]
        );

        $this->assertSame(403, $response->getStatusCode());
        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
    }

    public function testOutOfRangeOffsetIsRejected(): void
    {
        $token = $this->startSessionWithCsrfToken();
        $memberYearId = $this->createMemberYear('2014-01-01');

        $response = $this->controller->updateScoutYearOffset(
            $this->jsonRequest(['offset' => 2, '_csrf_token' => $token]),
            ['id' => (string) $memberYearId]
        );

        $this->assertSame(400, $response->getStatusCode());
        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);

        $stmt = $this->pdo->prepare('SELECT scout_year_offset FROM member_years WHERE id = ?');
        $stmt->execute([$memberYearId]);
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testNonExistentMemberYearReturns404(): void
    {
        $token = $this->startSessionWithCsrfToken();

        $response = $this->controller->updateScoutYearOffset(
            $this->jsonRequest(['offset' => 1, '_csrf_token' => $token]),
            ['id' => '999999']
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * #221. `role_min: chief` proves the caller animates something, not
     * that they animate THIS animé — the same rule the Départs write on the
     * same card has always applied.
     */
    public function testAChiefOfAnotherSectionIsRefused(): void
    {
        $token = $this->startSessionWithCsrfToken();
        $memberYearId = $this->createMemberYear('2014-01-01', $this->otherSectionId);

        $response = $this->controller->updateScoutYearOffset(
            $this->jsonRequest(['offset' => 1, '_csrf_token' => $token]),
            ['id' => (string) $memberYearId]
        );

        $this->assertSame(403, $response->getStatusCode());
        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);

        $stmt = $this->pdo->prepare('SELECT scout_year_offset FROM member_years WHERE id = ?');
        $stmt->execute([$memberYearId]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'Le décalage a été écrit malgré le refus.');
    }

    public function testOffsetChangeIsJournaled(): void
    {
        $token = $this->startSessionWithCsrfToken();
        $memberYearId = $this->createMemberYear('2014-01-01');

        $this->controller->updateScoutYearOffset(
            $this->jsonRequest(['offset' => 1, '_csrf_token' => $token]),
            ['id' => (string) $memberYearId]
        );

        $stmt = $this->pdo->prepare("SELECT * FROM event_log WHERE event_type = 'member_scout_year_offset_changed'");
        $stmt->execute();
        $entries = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertCount(1, $entries);
        $this->assertSame('core', $entries[0]['category']);
        // No personal data in the journal — only the FK and the offset values.
        $context = json_decode($entries[0]['context'], true);
        $this->assertSame($memberYearId, $context['member_year_id']);
        $this->assertSame(0, $context['old_offset']);
        $this->assertSame(1, $context['new_offset']);
    }

    public function testNoOffsetChangeDoesNotJournal(): void
    {
        $token = $this->startSessionWithCsrfToken();
        $memberYearId = $this->createMemberYear('2014-01-01');

        // Default offset is already 0.
        $this->controller->updateScoutYearOffset(
            $this->jsonRequest(['offset' => 0, '_csrf_token' => $token]),
            ['id' => (string) $memberYearId]
        );

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM event_log WHERE event_type = 'member_scout_year_offset_changed'");
        $stmt->execute();
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }
}
