<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Signature\Controller;

use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Core\Signature\Controller\AccountSignatureController;
use Core\Signature\PersonalSignatureRepository;
use Core\Signature\PersonalSignatureService;
use Core\Signature\SignatureImage;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\RequestWithInput;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * « Ma signature » under Mon compte (issue #926, lot B): what it keeps,
 * what it refuses, and whose signature it ever touches — the session's,
 * whatever the form says.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class AccountSignatureControllerTest extends TestCase
{
    private const OWNER = 1;
    private const OTHER = 2;

    private PersonalSignatureService $signatures;
    private AccountSignatureController $controller;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $this->signatures = new PersonalSignatureService(
            new PersonalSignatureRepository($pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))),
            new JournalService(new JournalRepository($pdo))
        );
        $this->controller = new AccountSignatureController(
            new Environment(new ArrayLoader([
                'account/signature.html.twig' => '{{ has_signature ? "avec" : "sans" }}|{{ return_to ?? "" }}',
                'errors/404.html.twig' => 'Introuvable',
            ])),
            $this->signatures
        );

        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            session_start();
        }
        $_SESSION = [];
        // An identified account with no member record and no rental role:
        // the role is the floor of every route here.
        AuthSession::login(self::OWNER, 'parent@example.org', 'identified');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        $_SESSION = [];
    }

    private function csrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        return $token;
    }

    private static function drawing(): string
    {
        $image = imagecreatetruecolor(300, 100);
        ob_start();
        imagepng($image);

        return 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $path, array $body, bool $withToken = true): \Core\Http\Response
    {
        if ($withToken) {
            $body['_csrf_token'] = $this->csrfToken();
        }
        $request = new Request('POST', $path, [], $body, [], []);

        return $path === '/account/signature/delete'
            ? $this->controller->delete($request, [])
            : $this->controller->save($request, []);
    }

    private function storeFor(int $account): void
    {
        $image = imagecreatetruecolor(10, 10);
        ob_start();
        imagepng($image);
        $this->signatures->save($account, SignatureImage::fromBytes((string) ob_get_clean()), new \DateTimeImmutable());
    }

    public function testThePageOpensForAnIdentifiedAccountAndIsNeverCached(): void
    {
        $response = $this->controller->index(new Request('GET', '/account/signature', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('sans|', $response->getBody());
        $this->assertSame('private, no-store', $response->getHeaders()['Cache-Control'] ?? null);
    }

    public function testWithoutASessionThePageSendsToTheLogin(): void
    {
        AuthSession::logout();

        $response = $this->controller->index(new Request('GET', '/account/signature', [], [], [], []), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaders()['Location'] ?? null);
    }

    public function testADrawnSignatureIsKeptForTheSessionsAccount(): void
    {
        $response = $this->post('/account/signature', ['signature_data' => self::drawing()]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/account/signature', $response->getHeaders()['Location'] ?? null);
        $this->assertTrue($this->signatures->has(self::OWNER));
        $this->assertSame('success', FlashMessage::get()['type'] ?? null);
    }

    /**
     * The account is the session's. A `user_account_id` posted beside the
     * drawing — or as the only thing that differs — changes nothing: the
     * other account gets no signature, and keeps the one it has.
     */
    public function testAPostedAccountIdIsIgnoredWhenSaving(): void
    {
        $this->post('/account/signature', ['signature_data' => self::drawing(), 'user_account_id' => (string) self::OTHER]);

        $this->assertTrue($this->signatures->has(self::OWNER));
        $this->assertFalse($this->signatures->has(self::OTHER));
    }

    public function testAPostedAccountIdIsIgnoredWhenDeleting(): void
    {
        $this->storeFor(self::OTHER);

        $this->post('/account/signature/delete', ['user_account_id' => (string) self::OTHER]);

        $this->assertTrue($this->signatures->has(self::OTHER), 'another account’s signature is never reachable from here');
    }

    public function testDeletingRemovesTheOwnersSignature(): void
    {
        $this->storeFor(self::OWNER);

        $response = $this->post('/account/signature/delete', []);

        $this->assertSame('/account/signature', $response->getHeaders()['Location'] ?? null);
        $this->assertFalse($this->signatures->has(self::OWNER));
    }

    public function testWithoutAValidCsrfTokenNothingIsKeptNorDeleted(): void
    {
        $this->post('/account/signature', ['signature_data' => self::drawing()], false);
        $this->assertFalse($this->signatures->has(self::OWNER));

        $this->storeFor(self::OWNER);
        $this->post('/account/signature/delete', ['_csrf_token' => 'forged'], false);
        $this->assertTrue($this->signatures->has(self::OWNER));
    }

    public function testNothingToKeepIsRefusedWithASentence(): void
    {
        $response = $this->post('/account/signature', []);

        $this->assertSame('/account/signature', $response->getHeaders()['Location'] ?? null);
        $this->assertSame(
            ['type' => 'error', 'message' => 'Tracez votre signature ou choisissez une image.'],
            FlashMessage::get()
        );
        $this->assertFalse($this->signatures->has(self::OWNER));
    }

    public function testAnUnreadableDrawingIsRefusedWithTheImagesOwnSentence(): void
    {
        $this->post('/account/signature', ['signature_data' => 'data:text/html;base64,PHA+']);

        $this->assertSame('error', FlashMessage::get()['type'] ?? null);
        $this->assertFalse($this->signatures->has(self::OWNER));
    }

    /**
     * A file is read only if PHP received it as an upload of this request:
     * a path of the server's own, presented as the « file », is not one.
     */
    public function testAFileThatIsNotAnUploadIsNeverRead(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'sig');
        $image = imagecreatetruecolor(10, 10);
        imagepng($image, $path);

        try {
            $request = new RequestWithInput(
                'POST',
                '/account/signature',
                [],
                ['_csrf_token' => $this->csrfToken()],
                [],
                [],
                '',
                ['name' => 'signature.png', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => 1, 'type' => 'image/png']
            );
            $this->controller->save($request, []);
        } finally {
            unlink($path);
        }

        $this->assertFalse($this->signatures->has(self::OWNER));
    }

    public function testAfterSavingItGoesBackWhereItWasAsked(): void
    {
        $response = $this->post('/account/signature', [
            'signature_data' => self::drawing(),
            'retour' => '/mes-locations/chalet/reservations/12',
        ]);

        $this->assertSame('/mes-locations/chalet/reservations/12', $response->getHeaders()['Location'] ?? null);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function foreignReturnProvider(): array
    {
        return [
            'another site' => ['https://evil.example/'],
            'scheme-relative' => ['//evil.example/'],
            'backslash trick' => ['/\\evil.example'],
            'this page again' => ['/account/signature'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('foreignReturnProvider')]
    public function testAReturnAddressElsewhereIsRefused(string $retour): void
    {
        $response = $this->post('/account/signature', ['signature_data' => self::drawing(), 'retour' => $retour]);
        $this->assertSame('/account/signature', $response->getHeaders()['Location'] ?? null);

        $page = $this->controller->index(new Request('GET', '/account/signature', ['retour' => $retour], [], [], []), []);
        $this->assertSame('avec|', $page->getBody());
    }

    public function testTheImageIsServedToItsOwnerOnlyAndNeverCached(): void
    {
        $this->storeFor(self::OWNER);

        $response = $this->controller->image(new Request('GET', '/account/signature/image', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/png', $response->getHeaders()['Content-Type'] ?? null);
        $this->assertSame('private, no-store', $response->getHeaders()['Cache-Control'] ?? null);
        $this->assertSame('nosniff', $response->getHeaders()['X-Content-Type-Options'] ?? null);
        $this->assertStringStartsWith("\x89PNG", $response->getBody());
    }

    /**
     * Two accounts — even a parent and a leader sharing a household's
     * details — never see each other's: the other account, which keeps
     * none, gets the same 404 as an anonymous visitor.
     */
    public function testAnotherAccountNeverReceivesTheImage(): void
    {
        $this->storeFor(self::OWNER);
        $request = new Request('GET', '/account/signature/image', ['user_account_id' => (string) self::OWNER], [], [], []);

        AuthSession::login(self::OTHER, 'animateur@example.org', 'admin');
        $this->assertSame(404, $this->controller->image($request, [])->getStatusCode());

        AuthSession::logout();
        $this->assertSame(404, $this->controller->image($request, [])->getStatusCode());
    }
}
