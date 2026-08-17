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
use Core\Member\DepartureRepository;
use Core\Member\DepartureService;
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
#[\PHPUnit\Framework\Attributes\Group('database')]
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

        $settingService = new \Core\Config\SettingService(new \Core\Config\SettingRepository($this->pdo));
        $settingService->register('section_document_compression_enabled', '1', 'boolean', 'x', 'x');
        $settingService->register('section_document_compression_quality', \Core\Pdf\PdfCompressor::QUALITY_BALANCED, 'select', 'x', 'x');
        $settingService->register('section_document_compression_backend', \Core\Pdf\PdfCompressor::BACKEND_NONE, 'text', 'x', 'x', null, null, null, false);
        $storagePath = sys_get_temp_dir() . '/member_controller_mass_mail_test_' . uniqid();
        $sectionDocumentService = new \Core\Member\SectionDocumentService(
            new \Core\Member\SectionDocumentRepository($this->pdo),
            new \Core\Member\SectionMembershipRepository($this->pdo),
            new \Core\File\EncryptedFileStorageService(new \Core\File\FileRepository($this->pdo), $this->encryption, $storagePath),
            new \Core\File\FileRepository($this->pdo),
            new SectionService($connection, $this->encryption, $memberBadgeRepository),
            new \Core\Config\ScoutYearService($this->pdo),
            new JournalService(new JournalRepository($this->pdo)),
            new \Core\Scheduler\SchedulerService(new \Core\Scheduler\SchedulerRepository($this->pdo)),
            $settingService,
            new \Core\Pdf\PdfCompressor($storagePath . '/temp')
        );

        return new MemberPageService(
            new SectionService($connection, $this->encryption, $memberBadgeRepository),
            $this->memberService,
            new BadgeRepository($this->pdo),
            $memberBadgeRepository,
            new AgeBranchRepository($this->pdo),
            new MemberDocumentService(new MemberDocumentRepository($this->pdo)),
            $memberEmailService,
            $sectionDocumentService,
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
            $this->buildMemberPageService($massMailQuery),
            new DepartureService(new DepartureRepository($this->pdo, $this->encryption), new JournalService(new JournalRepository($this->pdo)))
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
