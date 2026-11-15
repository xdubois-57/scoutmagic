<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Http\Controller\AccountController;
use Core\Http\Request;
use Tests\RequestWithInput;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\Security\RecentAuthentication;
use Core\Security\UserAccountRepository;
use Core\Security\WebAuthnCredentialRepository;
use Core\Security\WebAuthnService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;

#[\PHPUnit\Framework\Attributes\Group('database')]
class AccountControllerTest extends TestCase
{
    private AccountController $controller;
    private UserAccountRepository $userRepo;
    private WebAuthnCredentialRepository $webAuthnRepo;
    private \PDO $pdo;
    private EncryptionService $encryption;
    private int $userId;
    private \Core\Photo\AccountPhotoService $accountPhotoService;

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            session_start();
        }
        $_SESSION = [];

        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(random_bytes(32), random_bytes(32));

        $this->userRepo = new UserAccountRepository($this->pdo, $this->encryption);
        $this->webAuthnRepo = new WebAuthnCredentialRepository($this->pdo);

        $webAuthnService = new WebAuthnService(
            $this->webAuthnRepo,
            $this->userRepo,
            'localhost',
            'Test Scout',
            'https://localhost'
        );

        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturn('<html></html>');

        $this->accountPhotoService = new \Core\Photo\AccountPhotoService(
            new \Core\Photo\AccountPhotoRepository($this->pdo)
        );
        $this->controller = new AccountController(
            $twig,
            $this->userRepo,
            $this->webAuthnRepo,
            $webAuthnService,
            $this->accountPhotoService
        );

        // Create a user and authenticate
        $account = $this->userRepo->create('test@example.com');
        $this->userId = $account->id;
        AuthSession::login($this->userId, 'test@example.com', 'identified');
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testIndexPageRendersForAuthenticatedUser(): void
    {
        $request = new Request('GET', '/account', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * The « Ma signature » card (issue #926) is told whether the SESSION's
     * account keeps a signature, and since when — never the image, which
     * this offline-cached page must not carry.
     */
    public function testIndexTellsTheCardWhetherThisAccountKeepsASignature(): void
    {
        $signatures = new \Core\Signature\PersonalSignatureService(
            new \Core\Signature\PersonalSignatureRepository($this->pdo, $this->encryption),
            new \Core\Journal\JournalService(new \Core\Journal\JournalRepository($this->pdo))
        );
        $other = $this->userRepo->create('other@example.com');
        $image = imagecreatetruecolor(10, 10);
        ob_start();
        imagepng($image);
        $signatures->save($other->id, \Core\Signature\SignatureImage::fromBytes((string) ob_get_clean()), new \DateTimeImmutable());

        /** @var list<array<string, mixed>> $contexts */
        $contexts = [];
        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturnCallback(function (string $name, array $context) use (&$contexts): string {
            $contexts[] = $context;

            return '<html></html>';
        });
        $controller = new AccountController(
            $twig,
            $this->userRepo,
            $this->webAuthnRepo,
            new WebAuthnService($this->webAuthnRepo, $this->userRepo, 'localhost', 'Test Scout', 'https://localhost'),
            null,
            null,
            $signatures
        );

        $controller->index(new Request('GET', '/account', [], [], [], []), []);

        $this->assertFalse($contexts[0]['has_personal_signature'], 'another account’s signature is not this one’s');
        $this->assertNull($contexts[0]['personal_signature_saved_at']);
        foreach ($contexts[0] as $value) {
            $this->assertNotInstanceOf(\Core\Signature\SignaturePng::class, $value);
        }
    }

    public function testWithoutTheSignatureServiceTheCardIsNotOffered(): void
    {
        /** @var list<array<string, mixed>> $contexts */
        $contexts = [];
        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturnCallback(function (string $name, array $context) use (&$contexts): string {
            $contexts[] = $context;

            return '<html></html>';
        });
        $controller = new AccountController(
            $twig,
            $this->userRepo,
            $this->webAuthnRepo,
            new WebAuthnService($this->webAuthnRepo, $this->userRepo, 'localhost', 'Test Scout', 'https://localhost')
        );

        $controller->index(new Request('GET', '/account', [], [], [], []), []);

        $this->assertNull($contexts[0]['has_personal_signature']);
    }

    public function testIndexRedirectsToLoginWhenNotAuthenticated(): void
    {
        AuthSession::logout();
        $_SESSION = [];

        $request = new Request('GET', '/account', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testUpdateProfileSavesFirstNameAndLastName(): void
    {
        $csrfToken = CsrfGuard::generateToken();

        $request = new Request('POST', '/account/profile', [], [
            '_csrf_token' => $csrfToken,
            'first_name' => 'Jean',
            'last_name' => 'Dupont',
        ], [], []);

        $response = $this->controller->updateProfile($request, []);

        $this->assertSame(302, $response->getStatusCode());

        // Verify data was saved
        $account = $this->userRepo->findById($this->userId);
        $this->assertSame('Jean', $account->firstName);
        $this->assertSame('Dupont', $account->lastName);
    }

    public function testUpdateProfileRefusesToEmptyEitherName(): void
    {
        $this->userRepo->updateProfile($this->userId, 'Jean', 'Dupont');

        foreach ([['', 'Dupont'], ['Jean', ''], ['  ', '  ']] as [$firstName, $lastName]) {
            $request = new Request('POST', '/account/profile', [], [
                '_csrf_token' => CsrfGuard::generateToken(),
                'first_name' => $firstName,
                'last_name' => $lastName,
            ], [], []);

            $this->assertSame(302, $this->controller->updateProfile($request, [])->getStatusCode());

            $account = $this->userRepo->findById($this->userId);
            $this->assertSame('Jean', $account->firstName);
            $this->assertSame('Dupont', $account->lastName);
        }
    }

    public function testCompleteProfileScreenRendersWhileANameIsMissing(): void
    {
        $request = new Request('GET', '/account/complete-profile', [], [], [], []);
        $response = $this->controller->completeProfile($request, []);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCompleteProfileScreenIsADeadEndOnceTheProfileIsComplete(): void
    {
        $this->userRepo->updateProfile($this->userId, 'Jean', 'Dupont');

        $request = new Request('GET', '/account/complete-profile', [], [], [], []);
        $response = $this->controller->completeProfile($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/', $response->getHeaders()['Location']);
    }

    public function testCompleteProfileScreenRedirectsToLoginWhenNotAuthenticated(): void
    {
        AuthSession::logout();
        $_SESSION = [];

        $request = new Request('GET', '/account/complete-profile', [], [], [], []);
        $response = $this->controller->completeProfile($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaders()['Location']);
    }

    public function testSavingBothNamesReleasesTheSession(): void
    {
        $request = new Request('POST', '/account/complete-profile', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'first_name' => '  Camille ',
            'last_name' => ' Renard ',
        ], [], []);

        $response = $this->controller->saveCompleteProfile($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/', $response->getHeaders()['Location']);

        $account = $this->userRepo->findById($this->userId);
        $this->assertSame('Camille', $account->firstName);
        $this->assertSame('Renard', $account->lastName);
    }

    /**
     * A refusal re-renders the screen (200) rather than redirecting: there
     * is nowhere to redirect to but this screen, and the round trip would
     * lose whichever field the person did fill in.
     */
    public function testAnIncompleteAnswerReRendersTheScreenAndStoresNothing(): void
    {
        $request = new Request('POST', '/account/complete-profile', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'first_name' => 'Camille',
            'last_name' => '',
        ], [], []);

        $response = $this->controller->saveCompleteProfile($request, []);

        $this->assertSame(200, $response->getStatusCode());

        $account = $this->userRepo->findById($this->userId);
        $this->assertNull($account->firstName);
        $this->assertNull($account->lastName);
    }

    public function testCsrfValidatedOnTheCompleteProfileScreen(): void
    {
        $request = new Request('POST', '/account/complete-profile', [], [
            '_csrf_token' => 'invalid_token',
            'first_name' => 'Hacker',
            'last_name' => 'Mann',
        ], [], []);

        $response = $this->controller->saveCompleteProfile($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNull($this->userRepo->findById($this->userId)->firstName);
    }

    public function testUpdatePasswordSetsPasswordWhenNoneExists(): void
    {
        $csrfToken = CsrfGuard::generateToken();

        $request = new Request('POST', '/account/password', [], [
            '_csrf_token' => $csrfToken,
            'new_password' => 'MySecureP@ss1',
            'confirm_password' => 'MySecureP@ss1',
        ], [], []);

        $response = $this->controller->updatePassword($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertTrue($this->userRepo->hasPassword($this->userId));
    }

    public function testUpdatePasswordChangesExistingPassword(): void
    {
        // Set initial password
        $hash = password_hash('OldPassword', PASSWORD_DEFAULT);
        $this->userRepo->updatePasswordHash($this->userId, $hash);

        $csrfToken = CsrfGuard::generateToken();

        $request = new Request('POST', '/account/password', [], [
            '_csrf_token' => $csrfToken,
            'current_password' => 'OldPassword',
            'new_password' => 'NewSecureP@ss1',
            'confirm_password' => 'NewSecureP@ss1',
        ], [], []);

        $response = $this->controller->updatePassword($request, []);

        $this->assertSame(302, $response->getStatusCode());

        // Verify new password works
        $account = $this->userRepo->findById($this->userId);
        $this->assertTrue(password_verify('NewSecureP@ss1', $account->passwordHash));
    }

    public function testUpdatePasswordFailsWhenCurrentPasswordIsWrong(): void
    {
        // Set initial password
        $hash = password_hash('CorrectPassword', PASSWORD_DEFAULT);
        $this->userRepo->updatePasswordHash($this->userId, $hash);

        $csrfToken = CsrfGuard::generateToken();

        $request = new Request('POST', '/account/password', [], [
            '_csrf_token' => $csrfToken,
            'current_password' => 'WrongPassword',
            'new_password' => 'NewPassword',
            'confirm_password' => 'NewPassword',
        ], [], []);

        $response = $this->controller->updatePassword($request, []);

        $this->assertSame(302, $response->getStatusCode());

        // Verify password unchanged
        $account = $this->userRepo->findById($this->userId);
        $this->assertTrue(password_verify('CorrectPassword', $account->passwordHash));
    }

    public function testUpdatePasswordFailsWhenNewAndConfirmDontMatch(): void
    {
        $csrfToken = CsrfGuard::generateToken();

        $request = new Request('POST', '/account/password', [], [
            '_csrf_token' => $csrfToken,
            'new_password' => 'PasswordOne',
            'confirm_password' => 'PasswordTwo',
        ], [], []);

        $response = $this->controller->updatePassword($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertFalse($this->userRepo->hasPassword($this->userId));
    }

    public function testUpdatePasswordFailsWhenNewPasswordTooShort(): void
    {
        $csrfToken = CsrfGuard::generateToken();

        $request = new Request('POST', '/account/password', [], [
            '_csrf_token' => $csrfToken,
            'new_password' => 'short',
            'confirm_password' => 'short',
        ], [], []);

        $response = $this->controller->updatePassword($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertFalse($this->userRepo->hasPassword($this->userId));
    }

    public function testUpdatePasswordFailsWhenComplexityRulesNotMet(): void
    {
        $csrfToken = CsrfGuard::generateToken();

        // 12+ chars but no digit/symbol — fails Core\Security\PasswordPolicy
        // even though it passes the old 8-char-only rule.
        $request = new Request('POST', '/account/password', [], [
            '_csrf_token' => $csrfToken,
            'new_password' => 'OnlyLettersHere',
            'confirm_password' => 'OnlyLettersHere',
        ], [], []);

        $response = $this->controller->updatePassword($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertFalse($this->userRepo->hasPassword($this->userId));
    }

    public function testUpdatePasswordStampsPasswordChangedAt(): void
    {
        $csrfToken = CsrfGuard::generateToken();

        $request = new Request('POST', '/account/password', [], [
            '_csrf_token' => $csrfToken,
            'new_password' => 'MySecureP@ss1',
            'confirm_password' => 'MySecureP@ss1',
        ], [], []);

        $this->controller->updatePassword($request, []);

        $account = $this->userRepo->findById($this->userId);
        $this->assertNotNull($account->passwordChangedAt);
    }

    /**
     * Changing a password revokes every session for the account
     * (Core\Security\SessionRevalidator) — including, mechanically, the
     * one doing the changing. The controller re-stamps its own session so
     * the member stays signed in on the tab they are using while every
     * other session is signed out.
     */
    public function testChangingYourOwnPasswordRevokesOtherSessionsButKeepsThisOne(): void
    {
        $csrfToken = CsrfGuard::generateToken();
        $before = time() - 120;
        $_SESSION['_auth']['issued_at'] = $before;

        $request = new Request('POST', '/account/password', [], [
            '_csrf_token' => $csrfToken,
            'new_password' => 'MySecureP@ss1',
            'confirm_password' => 'MySecureP@ss1',
        ], [], []);

        $this->controller->updatePassword($request, []);

        $account = $this->userRepo->findById($this->userId);

        // Every session issued before the change is now revoked...
        $this->assertNotNull($account->sessionsValidFrom);
        $this->assertGreaterThan($before, $account->sessionsValidFrom->getTimestamp());

        // ...except this one, which was re-stamped after the write.
        $this->assertGreaterThanOrEqual(
            $account->sessionsValidFrom->getTimestamp(),
            AuthSession::getIssuedAt()
        );
    }

    /**
     * A failed change must not revoke anything — a wrong current password
     * would otherwise be a way to sign somebody out of every device.
     */
    public function testAFailedPasswordChangeRevokesNothing(): void
    {
        $this->userRepo->updatePasswordHash($this->userId, password_hash('CurrentP@ss1', PASSWORD_DEFAULT));
        $stampBefore = $this->userRepo->findById($this->userId)->sessionsValidFrom;
        $this->assertNotNull($stampBefore);

        $csrfToken = CsrfGuard::generateToken();
        $request = new Request('POST', '/account/password', [], [
            '_csrf_token' => $csrfToken,
            'current_password' => 'WrongCurrentPassword1!',
            'new_password' => 'MySecureP@ss1',
            'confirm_password' => 'MySecureP@ss1',
        ], [], []);

        $this->controller->updatePassword($request, []);

        $this->assertSame(
            $stampBefore->getTimestamp(),
            $this->userRepo->findById($this->userId)->sessionsValidFrom->getTimestamp()
        );
    }

    public function testCsrfValidatedOnProfileUpdate(): void
    {
        $request = new Request('POST', '/account/profile', [], [
            '_csrf_token' => 'invalid_token',
            'first_name' => 'Hacker',
            'last_name' => 'Mann',
        ], [], []);

        $response = $this->controller->updateProfile($request, []);

        $this->assertSame(302, $response->getStatusCode());

        // Verify data was NOT saved
        $account = $this->userRepo->findById($this->userId);
        $this->assertNull($account->firstName);
    }

    public function testPasskeyDeleteRemovesCredential(): void
    {
        // Create a passkey
        $credId = random_bytes(32);
        $id = $this->webAuthnRepo->create($this->userId, $credId, random_bytes(64), 'Test Key');

        // Directly test the repository integration
        $this->webAuthnRepo->delete($id);
        $result = $this->webAuthnRepo->findByCredentialId($credId);
        $this->assertNull($result);
    }

    public function testPasskeyDeleteRefusesOtherUsersCredential(): void
    {
        // Create another user's passkey
        $this->pdo->exec("INSERT INTO user_accounts (email_encrypted, email_blind_index, is_super_admin) VALUES ('enc2', 'blind2', 0)");
        $otherUserId = (int) $this->pdo->lastInsertId();
        $credId = random_bytes(32);
        $id = $this->webAuthnRepo->create($otherUserId, $credId, random_bytes(64), 'Other Key');

        // Verify the credential belongs to another user
        $credentials = $this->webAuthnRepo->findByUserAccountId($this->userId);
        $found = false;
        foreach ($credentials as $cred) {
            if ((int) $cred['id'] === $id) {
                $found = true;
            }
        }
        $this->assertFalse($found);
    }

    public function testPasskeyDeleteAcceptsCsrfFromHeader(): void
    {
        $token = CsrfGuard::generateToken();
        $credId = random_bytes(32);
        $id = $this->webAuthnRepo->create($this->userId, $credId, random_bytes(64), 'Key');

        $request = $this->makeJsonRequest(['id' => $id], ['HTTP_X_CSRF_TOKEN' => $token]);
        $response = $this->controller->passkeyDelete($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertNull($this->webAuthnRepo->findByCredentialId($credId));
    }

    public function testPasskeyDeleteRejectsMissingCsrf(): void
    {
        CsrfGuard::generateToken();
        $credId = random_bytes(32);
        $id = $this->webAuthnRepo->create($this->userId, $credId, random_bytes(64), 'Key');

        $request = $this->makeJsonRequest(['id' => $id], []); // no header, no body token
        $response = $this->controller->passkeyDelete($request, []);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNotNull($this->webAuthnRepo->findByCredentialId($credId));
    }

    // --- the account's own photo ----------------------------------------

    public function testDeletePhotoRemovesTheCallersOwnPhoto(): void
    {
        $fileId = $this->createFile();
        $this->accountPhotoService->setPhoto($this->userId, $fileId);

        $response = $this->controller->deletePhoto(
            new Request('POST', '/account/photo/delete', [], ['_csrf_token' => CsrfGuard::generateToken()], [], []),
            []
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNull($this->accountPhotoService->resolveFileId($this->userId));
    }

    /**
     * There is no id in this request to get wrong — the session's own
     * account is the only thing it can act on — so the guard that matters
     * is the CSRF token.
     */
    public function testDeletePhotoRefusesWithoutAValidCsrfToken(): void
    {
        $fileId = $this->createFile();
        $this->accountPhotoService->setPhoto($this->userId, $fileId);

        $this->controller->deletePhoto(
            new Request('POST', '/account/photo/delete', [], ['_csrf_token' => 'wrong'], [], []),
            []
        );

        $this->assertSame($fileId, $this->accountPhotoService->resolveFileId($this->userId));
    }

    public function testDeletePhotoSendsAVisitorWithNoSessionToTheLoginPage(): void
    {
        AuthSession::logout();

        $response = $this->controller->deletePhoto(
            new Request('POST', '/account/photo/delete', [], ['_csrf_token' => CsrfGuard::generateToken()], [], []),
            []
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaders()['Location'] ?? null);
    }

    // --- the holder is told when a way into the account changes (#1115) --

    public function testSettingAFirstPasswordAlertsTheHolder(): void
    {
        $controller = $this->controllerWithAlerts();

        $controller->updatePassword(new Request('POST', '/account/password', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'new_password' => 'MySecureP@ss1',
            'confirm_password' => 'MySecureP@ss1',
        ], [], []), []);

        $this->assertSame(['test@example.com'], array_column($this->alertsSent, 'to'));
        $this->assertStringContainsString('Un mot de passe a été défini', $this->alertsSent[0]['text']);
        $this->assertSame(['password_set'], $this->journaledEvents());
    }

    public function testChangingThePasswordAlertsTheHolder(): void
    {
        $this->userRepo->updatePasswordHash($this->userId, password_hash('OldPassword', PASSWORD_DEFAULT));
        $controller = $this->controllerWithAlerts();

        $controller->updatePassword(new Request('POST', '/account/password', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'current_password' => 'OldPassword',
            'new_password' => 'NewSecureP@ss1',
            'confirm_password' => 'NewSecureP@ss1',
        ], [], []), []);

        $this->assertSame(['test@example.com'], array_column($this->alertsSent, 'to'));
        $this->assertStringContainsString(
            'Le mot de passe de votre compte a été modifié',
            $this->alertsSent[0]['text']
        );
        $this->assertSame(['password_changed'], $this->journaledEvents());
    }

    public function testARefusedPasswordChangeAlertsNobody(): void
    {
        $this->userRepo->updatePasswordHash($this->userId, password_hash('OldPassword', PASSWORD_DEFAULT));
        $controller = $this->controllerWithAlerts();

        $controller->updatePassword(new Request('POST', '/account/password', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'current_password' => 'WrongPassword',
            'new_password' => 'NewSecureP@ss1',
            'confirm_password' => 'NewSecureP@ss1',
        ], [], []), []);

        $this->assertSame([], $this->alertsSent);
        $this->assertSame([], $this->journaledEvents());
    }

    public function testRegisteringAPasskeyAlertsTheHolder(): void
    {
        $webAuthn = $this->createStub(WebAuthnService::class);
        $webAuthn->method('verifyRegistration')->willReturn(42);
        $controller = $this->controllerWithAlerts($webAuthn);

        $response = $controller->passkeyRegister($this->makeJsonRequest(
            ['device_label' => 'Clé USB bleue'],
            ['HTTP_X_CSRF_TOKEN' => CsrfGuard::generateToken()]
        ), []);

        $this->assertTrue(json_decode($response->getBody(), true)['success']);
        $this->assertSame(['test@example.com'], array_column($this->alertsSent, 'to'));
        $this->assertStringContainsString('Clé USB bleue', $this->alertsSent[0]['text']);
        $this->assertSame(['passkey_registered'], $this->journaledEvents());
        $this->assertSame(
            ['credential_id' => 42],
            json_decode((string) $this->pdo->query('SELECT context FROM event_log')->fetchColumn(), true)
        );
    }

    public function testARefusedPasskeyRegistrationAlertsNobody(): void
    {
        $webAuthn = $this->createStub(WebAuthnService::class);
        $webAuthn->method('verifyRegistration')->willThrowException(new \RuntimeException('bad attestation'));
        $controller = $this->controllerWithAlerts($webAuthn);

        $response = $controller->passkeyRegister($this->makeJsonRequest(
            ['device_label' => 'Clé USB bleue'],
            ['HTTP_X_CSRF_TOKEN' => CsrfGuard::generateToken()]
        ), []);

        $this->assertFalse(json_decode($response->getBody(), true)['success']);
        $this->assertSame([], $this->alertsSent);
        $this->assertSame([], $this->journaledEvents());
    }

    /**
     * Issue #1131: a registration body that cannot be read is a 400 with a
     * generic French message — never a 500, never what was wrong.
     *
     * @return iterable<string, array{string}>
     */
    public static function malformedRegistrationBodies(): iterable
    {
        $b64url = static fn(string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $clientData = $b64url('{"type":"webauthn.create","challenge":"x","origin":"https://localhost"}');
        // A CBOR map(3) announced, then cut short after its first key.
        $truncatedCbor = $b64url("\xA3\x63fmt\x64none\x67attStmt");

        yield 'invalid JSON' => ['{"response":'];
        yield 'a JSON string' => ['"bonjour"'];
        yield 'a JSON number' => ['12'];
        yield 'response is a string' => [(string) json_encode(['response' => 'abc'])];
        yield 'clientDataJSON is a number' => [(string) json_encode([
            'response' => ['clientDataJSON' => 5, 'attestationObject' => $truncatedCbor],
        ])];
        yield 'attestationObject missing' => [(string) json_encode(['response' => ['clientDataJSON' => $clientData]])];
        yield 'attestationObject is not base64url' => [(string) json_encode([
            'response' => ['clientDataJSON' => $clientData, 'attestationObject' => '%%%'],
        ])];
        yield 'truncated CBOR' => [(string) json_encode([
            'response' => ['clientDataJSON' => $clientData, 'attestationObject' => $truncatedCbor],
        ])];
        yield 'device_label is an array' => [(string) json_encode([
            'response' => ['clientDataJSON' => $clientData, 'attestationObject' => $truncatedCbor],
            'device_label' => ['x'],
        ])];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedRegistrationBodies')]
    public function testAMalformedPasskeyRegistrationIsA400(string $body): void
    {
        $this->controller->passkeyRegisterOptions(
            new Request('GET', '/account/passkey/register-options', [], [], [], []),
            []
        );
        $request = new RequestWithInput(
            'POST',
            '/account/passkey/register',
            [],
            [],
            [],
            ['HTTP_X_CSRF_TOKEN' => CsrfGuard::generateToken()],
            $body
        );

        $response = $this->controller->passkeyRegister($request, []);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            ['success' => false, 'error' => 'Requête invalide.'],
            json_decode($response->getBody(), true)
        );
        $this->assertSame([], $this->webAuthnRepo->findByUserAccountId($this->userId));
    }

    /** The technical reason stays in the exception; the visitor reads the generic sentence. */
    public function testAMalformedRegistrationNamesNothingTechnical(): void
    {
        $webAuthn = $this->createStub(WebAuthnService::class);
        $webAuthn->method('verifyRegistration')
            ->willThrowException(new \Core\Security\WebAuthnMalformedRequest('response.attestationObject is bad'));
        $controller = $this->controllerWithAlerts($webAuthn);

        $response = $controller->passkeyRegister($this->makeJsonRequest(
            ['device_label' => 'Clé USB bleue'],
            ['HTTP_X_CSRF_TOKEN' => CsrfGuard::generateToken()]
        ), []);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringNotContainsString('attestationObject', $response->getBody());
        $this->assertSame([], $this->alertsSent);
    }

    public function testAPasskeyRegistrationAnsweringAnExpiredChallengeIsAskedToRetry(): void
    {
        $challenge = random_bytes(32);
        $_SESSION['webauthn_challenge'] = [
            'challenge' => base64_encode($challenge),
            'issued_at' => time() - WebAuthnService::CHALLENGE_TTL_SECONDS - 1,
        ];
        $_SESSION['webauthn_user_id'] = $this->userId;
        $b64url = static fn(string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $authData = hash('sha256', 'localhost', true) . "\x45\x00\x00\x00\x00" . str_repeat("\x00", 16)
            . "\x00\x01\x11" . "\xA1\x01\x02";

        $response = $this->controller->passkeyRegister($this->makeJsonRequest(
            [
                'response' => [
                    'clientDataJSON' => $b64url((string) json_encode([
                        'type' => 'webauthn.create',
                        'challenge' => $b64url($challenge),
                        'origin' => 'https://localhost',
                    ])),
                    'attestationObject' => $b64url("\xA1\x68authData\x58" . chr(strlen($authData)) . $authData),
                ],
                'device_label' => 'Clé',
            ],
            ['HTTP_X_CSRF_TOKEN' => CsrfGuard::generateToken()]
        ), []);

        $this->assertSame(
            ['success' => false, 'error' => 'Le délai est dépassé. Veuillez réessayer.'],
            json_decode($response->getBody(), true)
        );
        $this->assertArrayNotHasKey('webauthn_challenge', $_SESSION);
        $this->assertSame([], $this->webAuthnRepo->findByUserAccountId($this->userId));
    }

    public function testRemovingAPasskeyAlertsTheHolder(): void
    {
        $id = $this->webAuthnRepo->create($this->userId, random_bytes(32), random_bytes(64), 'Clé USB bleue');
        $controller = $this->controllerWithAlerts();

        $response = $controller->passkeyDelete(
            $this->makeJsonRequest(['id' => $id], ['HTTP_X_CSRF_TOKEN' => CsrfGuard::generateToken()]),
            []
        );

        $this->assertTrue(json_decode($response->getBody(), true)['success']);
        $this->assertSame(['test@example.com'], array_column($this->alertsSent, 'to'));
        $this->assertStringContainsString('retirée de votre compte', $this->alertsSent[0]['text']);
        $this->assertStringContainsString('Clé USB bleue', $this->alertsSent[0]['text']);
        $this->assertSame(['passkey_deleted'], $this->journaledEvents());
    }

    /**
     * Somebody else's key is answered « introuvable » and stays where it
     * is — and nobody is told about a removal that did not happen.
     */
    public function testRefusingToRemoveSomebodyElsesPasskeyAlertsNobody(): void
    {
        $other = $this->userRepo->create('other@example.com');
        $id = $this->webAuthnRepo->create($other->id, random_bytes(32), random_bytes(64), 'Other Key');
        $controller = $this->controllerWithAlerts();

        $response = $controller->passkeyDelete(
            $this->makeJsonRequest(['id' => $id], ['HTTP_X_CSRF_TOKEN' => CsrfGuard::generateToken()]),
            []
        );

        $this->assertFalse(json_decode($response->getBody(), true)['success']);
        $this->assertSame([], $this->alertsSent);
        $this->assertSame([], $this->journaledEvents());
    }

    // --- a new way in needs a recent login (#1115) ---------------------

    public function testAFirstPasswordIsRefusedWhenTheLastLoginIsTooOld(): void
    {
        $this->makeLastLoginStale();
        $controller = $this->controllerWithAlerts();

        $response = $controller->updatePassword(new Request('POST', '/account/password', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'new_password' => 'MySecureP@ss1',
            'confirm_password' => 'MySecureP@ss1',
        ], [], []), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login?return=%2Faccount&reauth=1', $response->getHeaders()['Location'] ?? null);
        $this->assertFalse($this->userRepo->hasPassword($this->userId));
        $this->assertNull($this->userRepo->findById($this->userId)->sessionsValidFrom);
        $this->assertSame([], $this->alertsSent);
        $this->assertSame([], $this->journaledEvents());
    }

    public function testAFirstPasswordIsAcceptedRightAfterALogin(): void
    {
        $_SESSION['_auth']['authenticated_at'] = time() - RecentAuthentication::WINDOW_SECONDS + 5;
        $controller = $this->controllerWithAlerts();

        $response = $controller->updatePassword(new Request('POST', '/account/password', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'new_password' => 'MySecureP@ss1',
            'confirm_password' => 'MySecureP@ss1',
        ], [], []), []);

        $this->assertSame('/account', $response->getHeaders()['Location'] ?? null);
        $this->assertTrue($this->userRepo->hasPassword($this->userId));
        $this->assertSame(['password_set'], $this->journaledEvents());
    }

    /**
     * Setting a first password proves nothing about who typed it, so it
     * must not extend the window it was let through by.
     */
    public function testSettingAFirstPasswordDoesNotRefreshTheLastLogin(): void
    {
        $at = time() - 120;
        $_SESSION['_auth']['authenticated_at'] = $at;

        $this->controller->updatePassword(new Request('POST', '/account/password', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'new_password' => 'MySecureP@ss1',
            'confirm_password' => 'MySecureP@ss1',
        ], [], []), []);

        $this->assertTrue($this->userRepo->hasPassword($this->userId));
        $this->assertSame($at, AuthSession::authenticatedAt());
    }

    /**
     * A CHANGE asks for the current password, which is the proof: it goes
     * through however old the login is, and counts as a fresh one.
     */
    public function testAPasswordChangeWithTheCurrentPasswordWorksHoweverOldTheLogin(): void
    {
        $this->userRepo->updatePasswordHash($this->userId, password_hash('OldPassword', PASSWORD_DEFAULT));
        $this->makeLastLoginStale();
        $controller = $this->controllerWithAlerts();

        $response = $controller->updatePassword(new Request('POST', '/account/password', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'current_password' => 'OldPassword',
            'new_password' => 'NewSecureP@ss1',
            'confirm_password' => 'NewSecureP@ss1',
        ], [], []), []);

        $this->assertSame('/account', $response->getHeaders()['Location'] ?? null);
        $account = $this->userRepo->findById($this->userId);
        $this->assertTrue(password_verify('NewSecureP@ss1', (string) $account->passwordHash));
        $this->assertSame(['password_changed'], $this->journaledEvents());
        $this->assertTrue(RecentAuthentication::isRecent());
    }

    public function testAWrongCurrentPasswordIsNoFreshProof(): void
    {
        $this->userRepo->updatePasswordHash($this->userId, password_hash('OldPassword', PASSWORD_DEFAULT));
        $this->makeLastLoginStale();

        $this->controller->updatePassword(new Request('POST', '/account/password', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'current_password' => 'WrongPassword',
            'new_password' => 'NewSecureP@ss1',
            'confirm_password' => 'NewSecureP@ss1',
        ], [], []), []);

        $this->assertFalse(RecentAuthentication::isRecent());
    }

    public function testPasskeyOptionsAreRefusedWhenTheLastLoginIsTooOld(): void
    {
        $this->makeLastLoginStale();
        $webAuthn = $this->createMock(WebAuthnService::class);
        $webAuthn->expects($this->never())->method('generateRegistrationOptions');
        $controller = $this->controllerWithAlerts($webAuthn);

        $response = $controller->passkeyRegisterOptions(
            new Request('GET', '/account/passkey/register-options', [], [], [], []),
            []
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            [
                'success' => false,
                'error' => RecentAuthentication::MESSAGE,
                'reauth_url' => '/login?return=%2Faccount&reauth=1',
            ],
            json_decode($response->getBody(), true)
        );
    }

    public function testPasskeyOptionsAreServedRightAfterALogin(): void
    {
        $response = $this->controller->passkeyRegisterOptions(
            new Request('GET', '/account/passkey/register-options', [], [], [], []),
            []
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayHasKey('challenge', json_decode($response->getBody(), true));
    }

    public function testPasskeyRegistrationIsRefusedWhenTheLastLoginIsTooOld(): void
    {
        $this->makeLastLoginStale();
        $webAuthn = $this->createMock(WebAuthnService::class);
        $webAuthn->expects($this->never())->method('verifyRegistration');
        $controller = $this->controllerWithAlerts($webAuthn);

        $response = $controller->passkeyRegister($this->makeJsonRequest(
            ['device_label' => 'Clé USB bleue'],
            ['HTTP_X_CSRF_TOKEN' => CsrfGuard::generateToken()]
        ), []);

        $this->assertSame(403, $response->getStatusCode());
        $payload = json_decode($response->getBody(), true);
        $this->assertFalse($payload['success']);
        $this->assertSame('/login?return=%2Faccount&reauth=1', $payload['reauth_url']);
        $this->assertSame([], $this->alertsSent);
        $this->assertSame([], $this->journaledEvents());
    }

    public function testPasskeyRegistrationGoesThroughRightAfterALogin(): void
    {
        $_SESSION['_auth']['authenticated_at'] = time() - RecentAuthentication::WINDOW_SECONDS + 5;
        $webAuthn = $this->createStub(WebAuthnService::class);
        $webAuthn->method('verifyRegistration')->willReturn(42);
        $controller = $this->controllerWithAlerts($webAuthn);

        $response = $controller->passkeyRegister($this->makeJsonRequest(
            ['device_label' => 'Clé USB bleue'],
            ['HTTP_X_CSRF_TOKEN' => CsrfGuard::generateToken()]
        ), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue(json_decode($response->getBody(), true)['success']);
        $this->assertSame(['passkey_registered'], $this->journaledEvents());
    }

    /** Removing a key takes a way in away; it is not gated. */
    public function testRemovingAPasskeyIsNotGatedByTheLastLogin(): void
    {
        $id = $this->webAuthnRepo->create($this->userId, random_bytes(32), random_bytes(64), 'Clé USB bleue');
        $this->makeLastLoginStale();

        $response = $this->controller->passkeyDelete(
            $this->makeJsonRequest(['id' => $id], ['HTTP_X_CSRF_TOKEN' => CsrfGuard::generateToken()]),
            []
        );

        $this->assertTrue(json_decode($response->getBody(), true)['success']);
    }

    /**
     * « Déconnecter partout » (issue #1115): every passkey removed, every
     * device revoked, every session — this one included — ended, and the
     * holder sent to the login page.
     */
    public function testSignOutEverywhereTakesBackSessionsPasskeysAndDevices(): void
    {
        $devices = new \Core\Contact\Device\DeviceCredentialRepository($this->pdo);
        $this->webAuthnRepo->create($this->userId, random_bytes(32), random_bytes(64), 'Clé');
        $device = $devices->create($this->userId, 'Téléphone', 's')->id;
        $before = $this->userRepo->findById($this->userId)?->sessionsValidFrom;

        $response = $this->controllerWithRevoker($devices)->signOutEverywhere(
            new Request(
                'POST',
                '/account/sign-out-everywhere',
                [],
                ['_csrf_token' => CsrfGuard::generateToken()],
                [],
                []
            ),
            []
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaders()['Location']);
        $this->assertSame([], $this->webAuthnRepo->findByUserAccountId($this->userId));
        $this->assertTrue($devices->findById($device)?->isRevoked());
        $this->assertNull($before);
        $this->assertNotNull($this->userRepo->findById($this->userId)?->sessionsValidFrom);
        $this->assertFalse(AuthSession::isAuthenticated());
        $this->assertContains('account_accesses_revoked', $this->journaledEvents());
    }

    public function testSignOutEverywhereRefusesAMissingCsrfTokenAndChangesNothing(): void
    {
        $devices = new \Core\Contact\Device\DeviceCredentialRepository($this->pdo);
        $this->webAuthnRepo->create($this->userId, random_bytes(32), random_bytes(64), 'Clé');
        CsrfGuard::generateToken();

        $this->controllerWithRevoker($devices)->signOutEverywhere(
            new Request('POST', '/account/sign-out-everywhere', [], ['_csrf_token' => 'wrong'], [], []),
            []
        );

        $this->assertCount(1, $this->webAuthnRepo->findByUserAccountId($this->userId));
        $this->assertNull($this->userRepo->findById($this->userId)?->sessionsValidFrom);
        $this->assertTrue(AuthSession::isAuthenticated());
    }

    /**
     * Not gated on a recent login: it only ever takes access away.
     */
    public function testSignOutEverywhereWorksWithAStaleLogin(): void
    {
        $this->makeLastLoginStale();
        $this->webAuthnRepo->create($this->userId, random_bytes(32), random_bytes(64), 'Clé');

        $this->controllerWithRevoker(new \Core\Contact\Device\DeviceCredentialRepository($this->pdo))
            ->signOutEverywhere(
                new Request('POST', '/x', [], ['_csrf_token' => CsrfGuard::generateToken()], [], []),
                []
            );

        $this->assertSame([], $this->webAuthnRepo->findByUserAccountId($this->userId));
    }

    /**
     * With no revoker wired, the sessions still end.
     */
    public function testSignOutEverywhereWithoutARevokerStillEndsTheSessions(): void
    {
        $this->controller->signOutEverywhere(
            new Request('POST', '/x', [], ['_csrf_token' => CsrfGuard::generateToken()], [], []),
            []
        );

        $this->assertNotNull($this->userRepo->findById($this->userId)?->sessionsValidFrom);
        $this->assertFalse(AuthSession::isAuthenticated());
    }

    public function testSignOutEverywhereSendsAnAnonymousVisitorToTheLoginPage(): void
    {
        AuthSession::logout();

        $response = $this->controller->signOutEverywhere(new Request('POST', '/x', [], [], [], []), []);

        $this->assertSame('/login', $response->getHeaders()['Location']);
    }

    private function controllerWithRevoker(\Core\Contact\Device\DeviceCredentialRepository $devices): AccountController
    {
        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturn('<html></html>');

        return new AccountController(
            $twig,
            $this->userRepo,
            $this->webAuthnRepo,
            new WebAuthnService($this->webAuthnRepo, $this->userRepo, 'localhost', 'Test Scout', 'https://localhost'),
            null,
            null,
            null,
            null,
            new \Core\Security\AccountAccessRevoker(
                $this->webAuthnRepo,
                $devices,
                new \Core\Journal\JournalService(new \Core\Journal\JournalRepository($this->pdo))
            )
        );
    }

    private function makeLastLoginStale(): void
    {
        $_SESSION['_auth']['authenticated_at'] = time() - RecentAuthentication::WINDOW_SECONDS - 1;
    }

    /** @var list<array{to: string, text: string}> */
    private array $alertsSent = [];

    private function controllerWithAlerts(?WebAuthnService $webAuthn = null): AccountController
    {
        $this->alertsSent = [];
        $mailService = $this->createStub(\Core\Mail\MailService::class);
        $mailService->method('send')->willReturnCallback(
            function (string $to, string $subject, string $bodyHtml, string $bodyText): void {
                $this->alertsSent[] = ['to' => $to, 'text' => $bodyText];
            }
        );

        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturn('<html></html>');

        return new AccountController(
            $twig,
            $this->userRepo,
            $this->webAuthnRepo,
            $webAuthn ?? new WebAuthnService(
                $this->webAuthnRepo,
                $this->userRepo,
                'localhost',
                'Test Scout',
                'https://localhost'
            ),
            null,
            null,
            null,
            new \Core\Security\AccountSecurityAlertService(
                $this->userRepo,
                $mailService,
                \Tests\Core\Mail\Template\EmailTemplateRendererFactory::overTestDatabase(
                    $this->pdo,
                    \Tests\TestTwig::create()
                ),
                'Test Scout',
                'https://localhost',
                new \Core\Journal\JournalService(new \Core\Journal\JournalRepository($this->pdo))
            )
        );
    }

    /** @return list<string> */
    private function journaledEvents(): array
    {
        return array_map(
            'strval',
            $this->pdo->query('SELECT event_type FROM event_log ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN)
        );
    }

    private function createFile(): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min)
             VALUES ('core/account_photos/x.webp', 'x.webp', 'image/webp', 1, 'identified')"
        );
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $server
     */
    private function makeJsonRequest(array $body, array $server): Request
    {
        $mock = new RequestWithInput('POST', '/account/passkey/delete', [], [], [], $server, (string) json_encode($body));

        return $mock;
    }
}
