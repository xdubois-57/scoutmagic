<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Database\Connection;
use Core\Http\Controller\AuthController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailException;
use Core\Mail\MailService;
use Core\Security\AuthService;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Core\Mail\Template\EmailTemplateRendererFactory;
use Tests\DatabaseTestHelper;
use Tests\SessionGenerationFixture;
use Tests\TestTwig;

/**
 * The login form must not tell anybody whether an address has an account,
 * and that includes while mail is failing.
 *
 * It used to: an unknown address always got the success answer, while a
 * known one whose send failed got « Impossible d'envoyer l'email. Vérifiez
 * la configuration SMTP. ». A relay outage or an exhausted sending quota —
 * which an outsider may be able to cause — turned the form into an oracle
 * for every address typed into it.
 *
 * Walked through the real controller and the real AuthService, because the
 * leak was the controller passing the service's answer straight through: a
 * test of either alone would not see the response a visitor gets.
 */
#[Group('database')]
class AuthControllerMagicLinkEnumerationTest extends TestCase
{
    private const KNOWN_DELIVERED = 'known-delivered@test.com';
    private const KNOWN_FAILING = 'known-failing@test.com';
    private const UNKNOWN = 'unknown@test.com';

    private \PDO $pdo;
    private AuthController $controller;
    /** @var list<string> every recipient a send was attempted for */
    private array $attemptedRecipients = [];

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];

        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $userRepo = new UserAccountRepository($this->pdo, $encryption);
        $userRepo->create(self::KNOWN_DELIVERED, false);
        $userRepo->create(self::KNOWN_FAILING, false);

        // The relay refuses one of the two known addresses, quoting it the
        // way real SMTP refusals do — which is what the journal must redact.
        $mailService = $this->createStub(MailService::class);
        $mailService->method('send')->willReturnCallback(function (string $to): void {
            $this->attemptedRecipients[] = $to;
            if ($to === self::KNOWN_FAILING) {
                throw new MailException('Destinataire refusé : ' . $to);
            }
        });

        $twig = TestTwig::create();
        $twig->addGlobal('site_name', 'Test Unit');

        $authService = new AuthService(
            Connection::withPdo($this->pdo),
            $encryption,
            $mailService,
            EmailTemplateRendererFactory::overTestDatabase($this->pdo, $twig),
            'https://example.test',
            'Test Unit'
        );
        $authService->setJournalService(new JournalService(new JournalRepository($this->pdo)));

        $this->controller = new AuthController($twig, $authService, SessionGenerationFixture::forPdo($this->pdo));
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testAKnownAddressWhoseSendFailedIsAnsweredLikeAnyOther(): void
    {
        $delivered = $this->describe($this->request(self::KNOWN_DELIVERED));
        $failing = $this->describe($this->request(self::KNOWN_FAILING));
        $unknown = $this->describe($this->request(self::UNKNOWN));

        // The two known addresses were really attempted, the unknown one
        // never: the three cases differ inside, and must not outside.
        $this->assertSame([self::KNOWN_DELIVERED, self::KNOWN_FAILING], $this->attemptedRecipients);

        $this->assertSame($delivered, $failing);
        $this->assertSame($unknown, $failing);
        $this->assertSame(200, $failing['status']);
        $this->assertSame(
            [
                'match_code' => 'six digits',
                'poll_id' => 'integer',
                'poll_token' => '64 hex characters',
                'success' => true,
            ],
            $failing['body']
        );
    }

    public function testTheFailedSendIsStillJournaledWithoutTheAddress(): void
    {
        $this->request(self::KNOWN_FAILING);

        $stmt = $this->pdo->prepare('SELECT context, user_account_id FROM event_log WHERE event_type = ?');
        $stmt->execute(['magic_link_send_failed']);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows, 'a failed magic-link send must reach the journal');

        $context = json_decode((string) $rows[0]['context'], true);
        $this->assertIsArray($context);
        $this->assertSame('Destinataire refusé : [adresse]', $context['error']);
        $this->assertFalse($context['via_secondary_email']);
        $this->assertNotNull($rows[0]['user_account_id']);
        $this->assertStringNotContainsString(self::KNOWN_FAILING, (string) $rows[0]['context']);

        // Nor is the failure written down as a delivery.
        $stmt->execute(['magic_link_email_sent']);
        $this->assertSame([], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    private function request(string $email): Response
    {
        // Each request from a fresh visitor, as an enumerating one would be.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];
        ini_set('session.use_cookies', '0');
        ini_set('session.cache_limiter', '');
        session_start();

        return $this->controller->requestMagicLink(
            new Request('POST', '/login/magic-link', [], [
                'email' => $email,
                '_csrf_token' => CsrfGuard::generateToken(),
                'rgpd_consent' => '1',
            ], [], []),
            []
        );
    }

    /**
     * What a visitor can compare between two answers: the status, the
     * headers, and the body with each per-request random value replaced by
     * the form it must have — a value that differs every time says nothing
     * about the address, a key or a type that differs says everything.
     *
     * @return array{status: int, headers: array<string, string>, body: array<string, mixed>}
     */
    private function describe(Response $response): array
    {
        $body = json_decode($response->getBody(), true);
        $this->assertIsArray($body, 'the answer must be JSON');
        ksort($body);

        foreach ($body as $key => $value) {
            $body[$key] = match ($key) {
                'poll_id' => is_int($value) ? 'integer' : $value,
                'poll_token' => is_string($value) && preg_match('/^[0-9a-f]{64}$/', $value) === 1
                    ? '64 hex characters'
                    : $value,
                'match_code' => is_string($value) && preg_match('/^\d{6}$/', $value) === 1 ? 'six digits' : $value,
                default => $value,
            };
        }

        $headers = $response->getHeaders();
        ksort($headers);

        return ['status' => $response->getStatusCode(), 'headers' => $headers, 'body' => $body];
    }
}
