<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Controller;

use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Security\AuthSession;
use Core\File\FileRepository;
use Core\File\UploadHandler;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Import\FunctionRepository;
use Core\Import\ImportJournalRepository;
use Core\Mail\MailException;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Security\EncryptionService;
use Modules\MassMail\Controller\MassMailController;
use Modules\MassMail\Repository\MailingListRepository;
use Modules\MassMail\Repository\MemberResolutionRepository;
use Modules\MassMail\Service\AudienceImportService;
use Modules\MassMail\Service\MailingListService;
use Modules\MassMail\Service\MassMailAccessService;
use Modules\MassMail\Repository\Email;
use Modules\MassMail\Service\MassMailService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\MassMail\MassMailTestHelper;
use Twig\Environment;

/**
 * What this endpoint is allowed to SAY when something fails.
 *
 * Core\Mail\MailException is never a Core\Exception\UserFacingException: it
 * is constructed in Core\Mail\MailService::send() from PHPMailer's
 * `ErrorInfo`, so its message is raw SMTP English every single time — and
 * Controller\MassMailController::testSend() used to concatenate it into a
 * JSON body the composer page renders.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ErrorMessageLeakTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($this->pdo);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Since #218 every {id} action of this controller answers 404 for
        // an email this session may not touch, so a test about the FLASH
        // MESSAGE has to get past that door first: a chef d'unité, and a
        // service that has the email to hand back.
        AuthSession::login(1, 'chef@test.be', 'admin');
    }

    private function draft(): Email
    {
        return new Email(
            1, 'Sujet', '<p>Corps</p>', 1, Email::LIST_TYPE_DEFAULT_ACTIVE_MEMBERS, null, null, null,
            [1], Email::STATUS_DRAFT, '2026-01-01 10:00:00', '2026-01-01 10:00:00', null, 1
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    /**
     * An ordinary form POST — what the composition page sends now that
     * the dialog and its JSON payloads are gone.
     *
     * @param array<string, mixed> $body
     */
    private function formRequest(array $body): Request
    {
        return new Request('POST', '/', [], $body, [], []);
    }

    private function jsonRequest(array $data): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn((string) json_encode($data));
        return $request;
    }

    private function csrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        return $token;
    }

    private function massMailController(MassMailService $massMailService): MassMailController
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService($connection, $encryption, new MemberBadgeRepository($this->pdo));

        return new MassMailController(
            $this->createMock(Environment::class),
            $massMailService,
            new MailingListService(
                new MailingListRepository($this->pdo),
                new MemberResolutionRepository($this->pdo, $encryption),
                $sectionService,
                new FunctionRepository($this->pdo)
            ),
            $this->createMock(MassMailAccessService::class),
            $this->createMock(MemberService::class),
            $sectionService,
            new ScoutYearService($this->pdo),
            $this->createMock(ImportJournalRepository::class),
            $this->createMock(SettingService::class),
            $this->createMock(UploadHandler::class),
            new FileRepository($this->pdo),
            $this->createMock(AudienceImportService::class)
        );
    }

    public function testTestSendNeverFlashesThePhpMailerTextToTheChief(): void
    {
        $massMailService = $this->createMock(MassMailService::class);
        $massMailService->method('findById')->willReturn($this->draft());
        $massMailService->method('sendTestEmail')->willThrowException(new MailException(
            'SMTP connect() failed. https://github.com/PHPMailer/PHPMailer/wiki/Troubleshooting'
        ));

        $response = $this->massMailController($massMailService)->testSend(
            $this->formRequest(['_csrf_token' => $this->csrfToken(), 'to' => 'chef@test.be']),
            ['id' => '1']
        );

        $this->assertSame(302, $response->getStatusCode());
        $error = (string) (FlashMessage::get()['message'] ?? '');
        $this->assertStringNotContainsString('SMTP', $error);
        $this->assertStringNotContainsString('PHPMailer', $error);
        $this->assertStringNotContainsString('github.com', $error);
        $this->assertStringContainsString("L'email de test n'a pas pu être envoyé", $error);
    }

    /**
     * The other branch of the same method must keep working: a
     * MassMailService refusal IS written for the chief (MassMailException is
     * marked user-facing), so it is shown as-is and needs no fallback.
     */
    public function testTestSendStillShowsAMarkedRefusalVerbatim(): void
    {
        $massMailService = $this->createMock(MassMailService::class);
        $massMailService->method('findById')->willReturn($this->draft());
        $massMailService->method('sendTestEmail')->willThrowException(
            new \Modules\MassMail\Api\MassMailException('Adresse email invalide.')
        );

        $response = $this->massMailController($massMailService)->testSend(
            $this->formRequest(['_csrf_token' => $this->csrfToken(), 'to' => 'pas-une-adresse']),
            ['id' => '1']
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('Adresse email invalide.', FlashMessage::get()['message'] ?? null);
    }
}
