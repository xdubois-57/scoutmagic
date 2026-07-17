<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Database\Connection;
use Core\Http\Controller\MemberController;
use Core\Http\Request;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\MemberService;
use Core\Member\MemberYearService;
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
class MemberControllerScoutYearOffsetTest extends TestCase
{
    private \PDO $pdo;
    private MemberController $controller;
    private MemberService $memberService;
    private EncryptionService $encryption;
    private int $scoutYearId;

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

        $this->controller = new MemberController(
            $this->createMock(Environment::class),
            $this->memberService,
            new MemberYearService(),
            $journalService
        );

        // Scout year 2025-2026 → reference year 2025.
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /**
     * Creates a member_year with the given birth date. Reference year is 2025,
     * so a birth date of 2014-01-01 gives a raw age of 11 (louveteaux 4e année).
     */
    private function createMemberYear(string $birthDate): int
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
            $this->encryption->encrypt('John'),
            $this->encryption->encrypt('Doe'),
            $this->encryption->encrypt($birthDate),
        ]);

        return (int) $this->pdo->lastInsertId();
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
        AuthSession::login(1, 'chief@test.example', 'chief');

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
