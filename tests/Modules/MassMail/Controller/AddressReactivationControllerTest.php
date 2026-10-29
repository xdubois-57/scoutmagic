<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Controller;

use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\EmailAddressReactivationService;
use Core\Member\MemberEmailRepository;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Modules\MassMail\Controller\AddressReactivationController;
use Modules\MassMail\Repository\ContactRepository;
use Modules\MassMail\Repository\SuppressedAddressRepository;
use Modules\MassMail\Service\AddressReactivationService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\MassMail\MassMailTestHelper;
use Twig\Environment;

#[\PHPUnit\Framework\Attributes\Group('database')]
final class AddressReactivationControllerTest extends TestCase
{
    private \PDO $pdo;
    private SuppressedAddressRepository $suppressed;
    private AddressReactivationController $controller;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->suppressed = new SuppressedAddressRepository($this->pdo);
        $this->suppressed->suppress('external@example.org');
        $journal = new JournalService(new JournalRepository($this->pdo));
        $this->controller = new AddressReactivationController(
            $this->createStub(Environment::class),
            new EmailAddressReactivationService(new MemberEmailRepository($this->pdo, $encryption), $journal,
                new AddressReactivationService($this->pdo, new ContactRepository($this->pdo, $encryption),
                    $this->suppressed, $journal))
        );
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(0, 'admin@example.org', 'admin');
        FlashMessage::get();
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        FlashMessage::get();
    }

    private function post(string $email, bool $validCsrf = true): void
    {
        $response = $this->controller->reactivate(new Request('POST',
            '/admin/listes-de-diffusion/adresses/reactivate', [], [
                'email' => $email, '_csrf_token' => $validCsrf ? CsrfGuard::generateToken() : 'invalid',
            ], [], []), []);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/listes-de-diffusion', $response->getHeaders()['Location']);
    }

    public function testExpiredCsrfChangesNothingAndUsesTheStandardFlash(): void
    {
        $this->post('external@example.org', false);
        $this->assertTrue($this->suppressed->isSuppressed('external@example.org'));
        $this->assertSame('Votre session a expiré. Rechargez la page et réessayez.', FlashMessage::get()['message']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM event_log')->fetchColumn());
    }

    public function testConfirmedPostRestoresAndARepeatedPostSaysItWasAlreadyRestored(): void
    {
        $this->post(' EXTERNAL@example.org ');
        $this->assertFalse($this->suppressed->isSuppressed('external@example.org'));
        $this->assertSame('success', FlashMessage::get()['type']);
        $this->post('external@example.org');
        $this->assertSame("Cette adresse n'était pas désinscrite.", FlashMessage::get()['message']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM event_log')->fetchColumn());
    }

    public function testAnInvalidAddressIsRefusedWithoutChangingAnySuppression(): void
    {
        $this->post('not-an-address');
        $this->assertTrue($this->suppressed->isSuppressed('external@example.org'));
        $this->assertSame('Adresse e-mail invalide.', FlashMessage::get()['message']);
    }

    public function testAStorageFailureReturnsTheStandardErrorWithoutTechnicalDetails(): void
    {
        $this->pdo->exec('DROP TABLE mass_mail_suppressed_addresses');
        $this->post('external@example.org');
        $this->assertSame("L'adresse n'a pas pu être réactivée. Réessayez plus tard.", FlashMessage::get()['message']);
    }
}
