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
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;

/**
 * MemberController::updateDeparture — the AJAX save behind the admin
 * member-search page's "Départ prévu l'année prochaine" control. Same
 * Core\Member\DepartureService the registration module's "Départs" page
 * uses (Modules\Registration\Controller\DeparturesControllerTest covers
 * that page's own CSRF/scoping rules) — this test focuses on the parts
 * specific to reaching the same service from a member found by search.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MemberControllerDepartureTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private MemberController $controller;
    private DepartureService $departureService;
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
        $memberService = new MemberService($memberYearRepo, $this->encryption, Connection::withPdo($this->pdo));
        $journalService = new JournalService(new JournalRepository($this->pdo));
        $this->departureService = new DepartureService(new DepartureRepository($this->pdo, $this->encryption), $journalService);

        $this->controller = new MemberController(
            $this->createMock(Environment::class),
            $memberService,
            new MemberYearService(),
            $journalService,
            $this->createMock(MemberPageService::class),
            $this->departureService
        );

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function createMemberYear(): int
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('TEST_" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$memberId, $this->scoutYearId, $this->encryption->encrypt('John', 'member_years.first_name'), $this->encryption->encrypt('Doe', 'member_years.last_name')]);

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
        AuthSession::login(1, 'admin@test.example', 'admin');

        return $token;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonRequest(array $data): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/members/1/departure', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn(json_encode($data));

        return $request;
    }

    public function testInvalidCsrfTokenIsRejected(): void
    {
        $this->startSessionWithCsrfToken();
        $memberYearId = $this->createMemberYear();

        $response = $this->controller->updateDeparture(
            $this->jsonRequest(['leaving' => true, '_csrf_token' => 'wrong']),
            ['id' => (string) $memberYearId]
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($decoded['success']);
        $this->assertFalse($this->departureService->getStatus($memberYearId)->leaving);
    }

    public function testUnknownMemberReturns404(): void
    {
        $token = $this->startSessionWithCsrfToken();

        $response = $this->controller->updateDeparture(
            $this->jsonRequest(['leaving' => true, '_csrf_token' => $token]),
            ['id' => '999999']
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testMarksMemberAsLeaving(): void
    {
        $token = $this->startSessionWithCsrfToken();
        $memberYearId = $this->createMemberYear();

        $response = $this->controller->updateDeparture(
            $this->jsonRequest(['leaving' => true, '_csrf_token' => $token]),
            ['id' => (string) $memberYearId]
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $status = $this->departureService->getStatus($memberYearId);
        $this->assertNotNull($status);
        $this->assertTrue($status->leaving);
    }

    public function testUnmarksMemberAsLeaving(): void
    {
        $token = $this->startSessionWithCsrfToken();
        $memberYearId = $this->createMemberYear();
        $this->departureService->markLeaving($memberYearId, null);

        $response = $this->controller->updateDeparture(
            $this->jsonRequest(['leaving' => false, '_csrf_token' => $token]),
            ['id' => (string) $memberYearId]
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertFalse($this->departureService->getStatus($memberYearId)->leaving);
    }

    public function testUpdatesCommentIndependentlyOfLeavingFlag(): void
    {
        $token = $this->startSessionWithCsrfToken();
        $memberYearId = $this->createMemberYear();
        $this->departureService->markLeaving($memberYearId, null);

        $response = $this->controller->updateDeparture(
            $this->jsonRequest(['comment' => 'Déménagement', '_csrf_token' => $token]),
            ['id' => (string) $memberYearId]
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $status = $this->departureService->getStatus($memberYearId);
        $this->assertTrue($status->leaving);
        $this->assertSame('Déménagement', $status->comment);
    }
}
