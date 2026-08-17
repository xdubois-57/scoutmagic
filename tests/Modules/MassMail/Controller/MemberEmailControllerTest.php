<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Controller;

use Core\Database\Connection;
use Core\Http\Request;
use Core\Import\MemberYearRepository;
use Core\Member\MemberService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Core\View\TwigFactory;
use Modules\MassMail\Api\MassMailQueryInterface;
use Modules\MassMail\Controller\MemberEmailController;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Modules\MassMail\Controller\MemberEmailController — the member page's
 * "view as sent" detail page for one received email (member page §4,
 * ARCHITECTURE.md §8.22). Lives in this module (not core's
 * MemberController) since the content is entirely mass_mail's own data —
 * only ever registered/reachable when mass_mail is enabled (module.json).
 * RBAC boundary (chief/admin or the member themselves — role_min:identified
 * alone is not the real boundary) plus graceful 404 when the recipient
 * doesn't resolve to this member.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MemberEmailControllerTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private MemberService $memberService;
    private int $memberYearId;
    private int $memberId;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];

        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->memberService = new MemberService(new MemberYearRepository($this->pdo), $this->encryption, Connection::withPdo($this->pdo));

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $scoutYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_1')");
        $this->memberId = (int) $this->pdo->lastInsertId();

        $emailBlindIndex = $this->encryption->blindIndex('member@test.example');
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, email_blind_index)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $this->memberId, $scoutYearId,
            $this->encryption->encrypt('John'), $this->encryption->encrypt('Doe'),
            $this->encryption->encrypt('member@test.example'), $emailBlindIndex,
        ]);
        $this->memberYearId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function buildController(MassMailQueryInterface $massMailQuery): MemberEmailController
    {
        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $twig = TwigFactory::create($templateDir, true);
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('menus', null);
        $twig->addGlobal('csp_nonce', 'n');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'identified');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('_editable_content_service', null);

        return new MemberEmailController($twig, $this->memberService, $massMailQuery);
    }

    public function testDeniedToAStrangerLoggedInAsIdentified(): void
    {
        AuthSession::login(1, 'stranger@test.example', 'identified');
        $massMailQuery = $this->createMock(MassMailQueryInterface::class);
        $massMailQuery->expects($this->never())->method('findEmailDetailForMember');

        $controller = $this->buildController($massMailQuery);
        $response = $controller->show(
            new Request('GET', "/members/{$this->memberYearId}/emails/1", [], [], [], []),
            ['id' => (string) $this->memberYearId, 'recipient_id' => '1']
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAllowedForTheMemberThemselves(): void
    {
        AuthSession::login(1, 'member@test.example', 'identified');
        $massMailQuery = $this->createMock(MassMailQueryInterface::class);
        $massMailQuery->method('findEmailDetailForMember')
            ->with($this->memberId, 5)
            ->willReturn(['subject' => 'Sujet', 'body_html' => '<p>Corps</p>', 'sent_at' => '2026-01-01 10:00:00', 'section_name' => 'Meute A']);

        $controller = $this->buildController($massMailQuery);
        $response = $controller->show(
            new Request('GET', "/members/{$this->memberYearId}/emails/5", [], [], [], []),
            ['id' => (string) $this->memberYearId, 'recipient_id' => '5']
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Sujet', $response->getBody());
    }

    public function testAllowedForAChiefViewingAnyMember(): void
    {
        AuthSession::login(1, 'chief@test.example', 'chief');
        $massMailQuery = $this->createMock(MassMailQueryInterface::class);
        $massMailQuery->method('findEmailDetailForMember')
            ->willReturn(['subject' => 'Sujet', 'body_html' => '<p>Corps</p>', 'sent_at' => '2026-01-01 10:00:00', 'section_name' => 'Meute A']);

        $controller = $this->buildController($massMailQuery);
        $response = $controller->show(
            new Request('GET', "/members/{$this->memberYearId}/emails/5", [], [], [], []),
            ['id' => (string) $this->memberYearId, 'recipient_id' => '5']
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testReturns404WhenRecipientDoesNotBelongToThisMember(): void
    {
        AuthSession::login(1, 'member@test.example', 'identified');
        $massMailQuery = $this->createMock(MassMailQueryInterface::class);
        $massMailQuery->method('findEmailDetailForMember')->willReturn(null);

        $controller = $this->buildController($massMailQuery);
        $response = $controller->show(
            new Request('GET', "/members/{$this->memberYearId}/emails/999", [], [], [], []),
            ['id' => (string) $this->memberYearId, 'recipient_id' => '999']
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testReturns404ForANonExistentMemberYear(): void
    {
        AuthSession::login(1, 'stranger@test.example', 'chief');
        $massMailQuery = $this->createMock(MassMailQueryInterface::class);

        $controller = $this->buildController($massMailQuery);
        $response = $controller->show(
            new Request('GET', '/members/999999/emails/1', [], [], [], []),
            ['id' => '999999', 'recipient_id' => '1']
        );

        $this->assertSame(404, $response->getStatusCode());
    }
}
