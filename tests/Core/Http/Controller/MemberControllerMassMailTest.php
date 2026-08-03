<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Badge\BadgeRepository;
use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Http\Controller\MemberController;
use Core\Http\Request;
use Core\Import\AgeBranchRepository;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\MemberDocumentRepository;
use Core\Member\MemberDocumentService;
use Core\Member\MemberEmailRepository;
use Core\Member\MemberEmailService;
use Core\Member\MemberPageService;
use Core\Member\MemberService;
use Core\Member\MemberYearService;
use Core\Member\SectionService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Modules\MassMail\Api\MassMailQueryInterface;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;

/**
 * MemberController's optional Modules\MassMail\Api\MassMailQueryInterface
 * dependency (ARCHITECTURE.md §7.5), threaded through Core\Member\
 * MemberPageService — verifies the "Communications récentes" section
 * degrades gracefully (simply absent) when mass_mail is disabled/not
 * wired, and is populated when it is.
 *
 * @group database
 */
class MemberControllerMassMailTest extends TestCase
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

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$this->memberId, $scoutYearId, $this->encryption->encrypt('John'), $this->encryption->encrypt('Doe')]);
        $this->memberYearId = (int) $this->pdo->lastInsertId();

        AuthSession::login(1, 'chief@test.example', 'chief');
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function buildTwigCapturingContext(): Environment
    {
        $twig = $this->getMockBuilder(Environment::class)->disableOriginalConstructor()->onlyMethods(['render'])->getMock();
        $twig->method('render')->willReturnCallback(fn($template, $context) => json_encode(array_key_exists('recent_mass_mail_emails', $context)
            ? ['has_key' => true, 'value' => $context['recent_mass_mail_emails']]
            : ['has_key' => false]));
        return $twig;
    }

    private function buildMemberPageService(?MassMailQueryInterface $massMailQuery): MemberPageService
    {
        $connection = Connection::withPdo($this->pdo);
        $memberBadgeRepository = new MemberBadgeRepository($this->pdo);

        $memberEmailService = new MemberEmailService(
            new MemberEmailRepository($this->pdo, $this->encryption),
            $this->createMock(\Core\Mail\MailService::class),
            $this->createMock(Environment::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SectionService($connection, $this->encryption, $memberBadgeRepository),
            $this->memberService,
            new \Core\Config\ScoutYearService($this->pdo),
            'https://example.test',
            'Test Unité'
        );

        return new MemberPageService(
            new SectionService($connection, $this->encryption, $memberBadgeRepository),
            $this->memberService,
            new BadgeRepository($this->pdo),
            $memberBadgeRepository,
            new AgeBranchRepository($this->pdo),
            new MemberDocumentService(new MemberDocumentRepository($this->pdo)),
            $memberEmailService,
            null,
            $massMailQuery
        );
    }

    private function buildController(Environment $twig, ?MassMailQueryInterface $massMailQuery): MemberController
    {
        return new MemberController(
            $twig,
            $this->memberService,
            new MemberYearService(),
            new JournalService(new JournalRepository($this->pdo)),
            $this->buildMemberPageService($massMailQuery)
        );
    }

    public function testRecentEmailsIsEmptyWhenMassMailDependencyIsNull(): void
    {
        $controller = $this->buildController($this->buildTwigCapturingContext(), null);

        $response = $controller->show(new Request('GET', '/members/' . $this->memberYearId, [], [], [], []), ['id' => (string) $this->memberYearId]);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['has_key']);
        $this->assertSame([], $decoded['value']);
    }

    public function testRecentEmailsIsPopulatedWhenMassMailDependencyIsProvided(): void
    {
        $massMailQuery = $this->createMock(MassMailQueryInterface::class);
        $massMailQuery->expects($this->once())
            ->method('getRecentEmailsForMember')
            ->with($this->memberId, 10)
            ->willReturn([['id' => 1, 'subject' => 'Sujet', 'sent_at' => '2026-01-01 10:00:00', 'section_name' => 'Meute A']]);

        $controller = $this->buildController($this->buildTwigCapturingContext(), $massMailQuery);

        $response = $controller->show(new Request('GET', '/members/' . $this->memberYearId, [], [], [], []), ['id' => (string) $this->memberYearId]);

        $decoded = json_decode($response->getBody(), true);
        $this->assertCount(1, $decoded['value']);
        $this->assertSame('Sujet', $decoded['value'][0]['subject']);
    }
}
