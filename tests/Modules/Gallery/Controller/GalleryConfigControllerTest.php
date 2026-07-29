<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Controller;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Modules\Gallery\Controller\GalleryConfigController;
use Modules\Gallery\Repository\S3SecretRepository;
use Modules\Gallery\Service\FfmpegAvailability;
use Modules\Gallery\Service\S3ErrorExplainerService;
use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmResponse;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Gallery\GalleryTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * @group database
 */
class GalleryConfigControllerTest extends TestCase
{
    private \PDO $pdo;
    private GalleryConfigController $controller;
    private SettingService $settingService;
    private S3SecretRepository $s3SecretRepository;
    private Environment $twig;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GalleryTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->settingService = new SettingService(new SettingRepository($this->pdo));
        foreach ([
            'gallery_allow_local' => ['1', 'boolean'], 'gallery_allow_external' => ['1', 'boolean'],
            'gallery_storage_backend' => ['local', 'select'], 'gallery_storage_local_subdir' => ['gallery', 'text'],
            'gallery_s3_provider' => ['custom', 'select'], 'gallery_s3_endpoint' => ['', 'text'],
            'gallery_s3_region' => ['', 'text'], 'gallery_s3_bucket' => ['', 'text'],
            'gallery_s3_access_key' => ['', 'text'], 'gallery_s3_public_url' => ['', 'url'],
            'gallery_max_media_per_album' => ['200', 'number'], 'gallery_max_photo_upload_mb' => ['30', 'number'],
            'gallery_photo_max_dimension' => ['3000', 'number'], 'gallery_allow_video' => ['1', 'boolean'],
            'gallery_max_video_upload_mb' => ['2048', 'number'], 'gallery_max_video_duration_sec' => ['1800', 'number'],
            'gallery_keep_original_video' => ['0', 'boolean'],
        ] as $key => [$default, $type]) {
            $this->settingService->register($key, $default, $type, $key, $key, 'gallery');
        }

        $this->s3SecretRepository = new S3SecretRepository($this->pdo, $encryption);
        $ffmpegAvailability = $this->createMock(FfmpegAvailability::class);
        $ffmpegAvailability->method('check')->willReturn(false);
        $journalService = new JournalService(new JournalRepository($this->pdo));

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $moduleViews = dirname(__DIR__, 4) . '/modules/gallery/views';
        $loader = new FilesystemLoader($templateDir);
        $loader->addPath($moduleViews, 'gallery');
        $this->twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);
        $this->twig->addGlobal('site_name', 'Test');
        $this->twig->addGlobal('is_authenticated', true);
        $this->twig->addGlobal('current_user_role', 'superadmin');
        $this->twig->addGlobal('config_mode', false);
        $this->twig->addGlobal('cookie_consent_given', true);
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('csp_nonce', 'test-nonce');
        $this->twig->addFunction(new TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $this->twig->addFunction(new TwigFunction('get_flash', fn() => null));
        $this->twig->addFunction(new TwigFunction('csrf_token', fn() => 'test'));
        $this->twig->addFunction(new TwigFunction('file_url', fn() => ''));

        $this->controller = new GalleryConfigController(
            $this->twig, $this->settingService, $this->s3SecretRepository, $ffmpegAvailability, $journalService,
            new S3ErrorExplainerService()
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'admin@test.be', 'superadmin');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    private function csrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        return $token;
    }

    public function testIndexRendersFfmpegWarningWhenUnavailable(): void
    {
        $response = $this->controller->index(new Request('GET', '/config/gallery', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('FFmpeg', $response->getBody());
    }

    public function testSaveRequiresCsrf(): void
    {
        $request = new Request('POST', '/config/gallery', [], ['_csrf_token' => 'bad'], [], []);

        $response = $this->controller->save($request, []);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testSavePersistsSettings(): void
    {
        $token = $this->csrfToken();
        $request = new Request('POST', '/config/gallery', [], [
            '_csrf_token' => $token,
            'gallery_storage_backend' => 'local',
            'gallery_storage_local_subdir' => 'photos',
            'gallery_s3_provider' => 'hetzner',
            'gallery_s3_endpoint' => '', 'gallery_s3_region' => '', 'gallery_s3_bucket' => '',
            'gallery_s3_access_key' => '', 'gallery_s3_public_url' => '',
            'gallery_max_media_per_album' => '150', 'gallery_max_photo_upload_mb' => '20',
            'gallery_photo_max_dimension' => '2500', 'gallery_max_video_upload_mb' => '1024',
            'gallery_max_video_duration_sec' => '900',
        ], [], []);

        $response = $this->controller->save($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('photos', $this->settingService->get('gallery_storage_local_subdir', 'gallery'));
        $this->assertSame('150', (string) $this->settingService->get('gallery_max_media_per_album', 'gallery'));
    }

    public function testSaveChecksGalleryAllowVideoWhenPresentInBody(): void
    {
        $this->settingService->set('gallery_allow_video', '0', 'gallery');
        $token = $this->csrfToken();

        $response = $this->controller->save(new Request('POST', '/config/gallery', [], array_merge(
            $this->minimalSaveBody($token),
            ['gallery_allow_video' => '1']
        ), [], []), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->settingService->clearCache();
        $this->assertSame('1', (string) $this->settingService->get('gallery_allow_video', 'gallery'));
    }

    public function testSaveUnchecksGalleryAllowVideoWhenAbsentFromBody(): void
    {
        $this->settingService->set('gallery_allow_video', '1', 'gallery');
        $token = $this->csrfToken();

        $response = $this->controller->save(new Request('POST', '/config/gallery', [], $this->minimalSaveBody($token), [], []), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->settingService->clearCache();
        $this->assertSame('0', (string) $this->settingService->get('gallery_allow_video', 'gallery'));
    }

    public function testSaveRejectsUnknownStorageBackendSilently(): void
    {
        $token = $this->csrfToken();
        $request = new Request('POST', '/config/gallery', [], array_merge($this->minimalSaveBody($token), [
            'gallery_storage_backend' => 'evil',
        ]), [], []);

        $this->controller->save($request, []);

        $this->assertSame('local', $this->settingService->get('gallery_storage_backend', 'gallery'));
    }

    public function testSaveRejectsUnknownS3ProviderSilently(): void
    {
        $token = $this->csrfToken();
        $request = new Request('POST', '/config/gallery', [], array_merge($this->minimalSaveBody($token), [
            'gallery_s3_provider' => 'evil',
        ]), [], []);

        $this->controller->save($request, []);

        $this->assertSame('custom', $this->settingService->get('gallery_s3_provider', 'gallery'));
    }

    public function testSaveWithBlankSecretKeepsExistingSecret(): void
    {
        $this->s3SecretRepository->set('original-secret');
        $token = $this->csrfToken();
        $request = new Request('POST', '/config/gallery', [], array_merge($this->minimalSaveBody($token), [
            'gallery_s3_secret_key' => '',
        ]), [], []);

        $this->controller->save($request, []);

        $this->assertSame('original-secret', $this->s3SecretRepository->get());
    }

    public function testSaveWithNonBlankSecretReplacesIt(): void
    {
        $this->s3SecretRepository->set('original-secret');
        $token = $this->csrfToken();
        $request = new Request('POST', '/config/gallery', [], array_merge($this->minimalSaveBody($token), [
            'gallery_s3_secret_key' => 'new-secret',
        ]), [], []);

        $this->controller->save($request, []);

        $this->assertSame('new-secret', $this->s3SecretRepository->get());
    }

    public function testTestConnectionRequiresCsrf(): void
    {
        $request = $this->jsonRequest(['_csrf_token' => 'bad']);

        $response = $this->controller->testConnection($request, []);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testTestConnectionFailsFastAgainstARefusedConnection(): void
    {
        $token = $this->csrfToken();
        $request = $this->jsonRequest([
            '_csrf_token' => $token,
            'endpoint' => 'http://127.0.0.1:1',
            'region' => 'eu',
            'bucket' => 'test',
            'access_key' => 'a',
            'secret_key' => 'b',
        ]);

        $response = $this->controller->testConnection($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testExplainS3ErrorRequiresCsrf(): void
    {
        $request = $this->jsonRequest(['_csrf_token' => 'bad']);

        $response = $this->controller->explainS3Error($request, []);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testExplainS3ErrorFailsWhenTheLlmConnectorIsUnavailable(): void
    {
        $token = $this->csrfToken();
        $request = $this->jsonRequest(['_csrf_token' => $token, 'error' => 'boom']);

        $response = $this->controller->explainS3Error($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testExplainS3ErrorReturnsTheAiExplanationAndPassesTheSecretKeyLengthOnlyNeverItsValue(): void
    {
        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->expects($this->once())->method('complete')->with($this->callback(function ($request) {
            $this->assertStringContainsString('longueur : 18', $request->prompt);
            $this->assertStringContainsString('scaleway', $request->prompt);
            return true;
        }))->willReturn(new LlmResponse('Vérifiez le nom du bucket dans la console Scaleway.', null, 10, 10));

        $controller = new GalleryConfigController(
            $this->twig, $this->settingService, $this->s3SecretRepository,
            $this->createMock(FfmpegAvailability::class), new JournalService(new JournalRepository($this->pdo)),
            new S3ErrorExplainerService($llmConnector)
        );

        // The controller action's signature has no "secret_key" field at
        // all (Controller\GalleryConfigController::explainS3Error) — only
        // secret_key_length is ever read — so the secret cannot leak here
        // even if a malicious client tried to send it in the request body.
        $token = $this->csrfToken();
        $request = $this->jsonRequest([
            '_csrf_token' => $token, 'provider' => 'scaleway', 'endpoint' => 'https://s3.fr-par.scw.cloud',
            'region' => 'fr-par', 'bucket' => 'scoutmagic', 'access_key' => 'AK123',
            'secret_key' => 'this-would-be-ignored-even-if-sent', 'secret_key_length' => 18,
            'error' => '403 Forbidden',
        ]);

        $response = $controller->explainS3Error($request, []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertSame('Vérifiez le nom du bucket dans la console Scaleway.', $decoded['explanation']);
    }

    public function testExplainS3ErrorReturns422WhenTheLlmCallFails(): void
    {
        $llmConnector = $this->createMock(LlmConnectorInterface::class);
        $llmConnector->method('isAvailable')->willReturn(true);
        $llmConnector->method('complete')->willThrowException(new LlmException('Provider timeout.'));

        $controller = new GalleryConfigController(
            $this->twig, $this->settingService, $this->s3SecretRepository,
            $this->createMock(FfmpegAvailability::class), new JournalService(new JournalRepository($this->pdo)),
            new S3ErrorExplainerService($llmConnector)
        );

        $token = $this->csrfToken();
        $response = $controller->explainS3Error($this->jsonRequest(['_csrf_token' => $token, 'error' => 'boom']), []);

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertSame(422, $response->getStatusCode());
    }

    /**
     * @return array<string, string>
     */
    private function minimalSaveBody(string $token): array
    {
        return [
            '_csrf_token' => $token,
            'gallery_storage_backend' => 'local',
            'gallery_storage_local_subdir' => 'gallery',
            'gallery_s3_provider' => 'custom',
            'gallery_s3_endpoint' => '', 'gallery_s3_region' => '', 'gallery_s3_bucket' => '',
            'gallery_s3_access_key' => '', 'gallery_s3_public_url' => '',
            'gallery_max_media_per_album' => '200', 'gallery_max_photo_upload_mb' => '30',
            'gallery_photo_max_dimension' => '3000', 'gallery_max_video_upload_mb' => '2048',
            'gallery_max_video_duration_sec' => '1800',
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonRequest(array $data): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/config/gallery/test-connection', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn(json_encode($data));
        return $request;
    }
}
