<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Controller;

use Core\Badge\MemberBadgeRepository;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Config\ScoutYearService;
use Core\Database\Connection;
use Core\File\FileRepository;
use Core\File\UploadHandler;
use Core\Http\Request;
use Core\Import\FunctionRepository;
use Core\Import\ImportJournalRepository;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Security\EncryptionService;
use Modules\MassMail\Controller\ConfigController;
use Modules\MassMail\Controller\MassMailController;
use Modules\MassMail\Repository\MailingListRepository;
use Modules\MassMail\Repository\MemberResolutionRepository;
use Modules\MassMail\Service\MailingListService;
use Modules\MassMail\Service\MassMailAccessService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;

/**
 * Every mutating action in Controller\MassMailController and
 * Controller\ConfigController funnels through the same private
 * checkCsrf()/CsrfGuard::validateToken() helper — these two representative
 * endpoints (one per controller) spot-check that a missing/invalid token
 * is rejected before any repository write happens (module spec: "CSRF sur
 * chaque formulaire/action").
 *
 * @group database
 */
class CsrfTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        \Tests\Modules\MassMail\MassMailTestHelper::createTables($this->pdo);
    }

    private function jsonRequest(array $data): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn(json_encode($data));
        return $request;
    }

    public function testMassMailControllerCreateRejectsInvalidCsrfToken(): void
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService($connection, $encryption, new MemberBadgeRepository($this->pdo));
        $listService = new MailingListService(
            new MailingListRepository($this->pdo),
            new MemberResolutionRepository($this->pdo, $encryption),
            $sectionService,
            new FunctionRepository($this->pdo)
        );

        $controller = new MassMailController(
            $this->createMock(Environment::class),
            $this->createMock(\Modules\MassMail\Service\MassMailService::class),
            $listService,
            $this->createMock(MassMailAccessService::class),
            $this->createMock(MemberService::class),
            $sectionService,
            new ScoutYearService($this->pdo),
            $this->createMock(ImportJournalRepository::class),
            $this->createMock(SettingService::class),
            $this->createMock(UploadHandler::class),
            new FileRepository($this->pdo)
        );

        // CSRF is checked before buildAuthorization() is ever evaluated, so
        // the mocked collaborators above are never actually invoked here.
        $response = $controller->create($this->jsonRequest(['subject' => 'x', '_csrf_token' => 'invalid']), []);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('invalide', (string) $response->getBody());

        // No draft was created.
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM mass_mail_emails')->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testConfigControllerCreateListRejectsInvalidCsrfToken(): void
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService($connection, $encryption, new MemberBadgeRepository($this->pdo));
        $listService = new MailingListService(
            new MailingListRepository($this->pdo),
            new MemberResolutionRepository($this->pdo, $encryption),
            $sectionService,
            new FunctionRepository($this->pdo)
        );

        $controller = new ConfigController(
            $this->createMock(Environment::class),
            $listService,
            new SettingService(new SettingRepository($this->pdo))
        );

        $response = $controller->createList($this->jsonRequest(['name' => 'x', '_csrf_token' => 'invalid']), []);

        $this->assertSame(400, $response->getStatusCode());

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM mass_mail_lists')->fetchColumn();
        $this->assertSame(0, $count);
    }
}
