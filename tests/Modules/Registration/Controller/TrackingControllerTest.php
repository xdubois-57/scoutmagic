<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Controller;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\View\TwigFactory;
use Modules\Registration\Controller\TrackingController;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\RegistrationSecondaryEmailRepository;
use Modules\Registration\Service\SecondaryEmailService;
use Modules\Registration\Service\TrackingService;
use Core\Http\Request;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * @group database
 */
class TrackingControllerTest extends TestCase
{
    private \PDO $pdo;
    private TrackingController $controller;
    private array $created;
    private EncryptionService $encryption;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $requestRepository = new RegistrationRequestRepository($this->pdo, $this->encryption);
        $secondaryEmailRepository = new RegistrationSecondaryEmailRepository($this->pdo, $this->encryption);
        $trackingService = new TrackingService($requestRepository, $secondaryEmailRepository, $this->encryption);

        $mailService = $this->createMock(MailService::class);
        $mailService->method('send');
        $twig = TwigFactory::create(dirname(__DIR__, 4) . '/core/View/templates', false, [
            'registration' => dirname(__DIR__, 4) . '/modules/registration/views',
        ]);
        $twig->addGlobal('site_name', 'Unité Test');
        $twig->addGlobal('is_authenticated', false);
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/inscriptions/suivi');
        $journalService = new JournalService(new JournalRepository($this->pdo));
        $secondaryEmailService = new SecondaryEmailService($secondaryEmailRepository, $mailService, $twig, $journalService, 'https://example.com', 'Unité Test');

        $this->controller = new TrackingController($twig, $trackingService, $secondaryEmailService, $requestRepository);

        $scoutYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $this->created = $requestRepository->create($scoutYearId, [
            'parent_name' => 'Marie Dupont', 'child_last_name' => 'Dupont', 'child_first_name' => 'Léa',
            'gender' => 'F', 'birth_date' => '2019-01-01', 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => 'marie@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, []);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        $_SESSION = [];
    }

    public function testShowByTokenSucceedsWithCorrectToken(): void
    {
        $response = $this->controller->showByToken(
            new Request('GET', '/inscriptions/suivi/' . $this->created['id'] . '/' . $this->created['tracking_token'], [], [], [], []),
            ['id' => (string) $this->created['id'], 'token' => $this->created['tracking_token']]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Léa', $response->getBody());
        // Minimal view: never the parent's contact details.
        $this->assertStringNotContainsString('Dupont', $response->getBody());
    }

    public function testShowByTokenFailsWithWrongToken(): void
    {
        $response = $this->controller->showByToken(
            new Request('GET', '/inscriptions/suivi/' . $this->created['id'] . '/wrong', [], [], [], []),
            ['id' => (string) $this->created['id'], 'token' => 'wrong']
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testShowLinkedRejectsAnUnauthenticatedVisitor(): void
    {
        $response = $this->controller->showLinked(
            new Request('GET', '/inscriptions/suivi/demande/' . $this->created['id'], [], [], [], []),
            ['id' => (string) $this->created['id']]
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testShowLinkedRejectsAnIdentifiedVisitorWithAnUnrelatedEmail(): void
    {
        AuthSession::login(1, 'stranger@example.com', 'identified');

        $response = $this->controller->showLinked(
            new Request('GET', '/inscriptions/suivi/demande/' . $this->created['id'], [], [], [], []),
            ['id' => (string) $this->created['id']]
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testShowLinkedSucceedsForTheOriginEmail(): void
    {
        AuthSession::login(1, 'marie@example.com', 'identified');

        $response = $this->controller->showLinked(
            new Request('GET', '/inscriptions/suivi/demande/' . $this->created['id'], [], [], [], []),
            ['id' => (string) $this->created['id']]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Dupont', $response->getBody());
    }

    public function testAddEmailRejectsAnUnrelatedVisitor(): void
    {
        AuthSession::login(1, 'stranger@example.com', 'identified');

        $response = $this->controller->addEmail(
            new Request('POST', '/inscriptions/suivi/demande/' . $this->created['id'] . '/emails', [], [
                '_csrf_token' => CsrfGuard::generateToken(), 'email' => 'secondary@example.com',
            ], [], []),
            ['id' => (string) $this->created['id']]
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM registration_secondary_emails')->fetchColumn());
    }
}
