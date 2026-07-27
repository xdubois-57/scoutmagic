<?php

declare(strict_types=1);

namespace Modules\Gallery\Controller;

use Core\Config\SettingService;
use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Modules\Gallery\Repository\S3SecretRepository;
use Modules\Gallery\Service\FfmpegAvailability;
use Modules\Gallery\Service\Storage\S3StorageBackend;
use Twig\Environment;

class GalleryConfigController extends AbstractController
{
    /** @var string[] */
    private const S3_PROVIDERS = ['hetzner', 'cloudflare_r2', 'scaleway', 'ovhcloud', 'custom'];

    public function __construct(
        protected Environment $twig,
        private SettingService $settingService,
        private S3SecretRepository $s3SecretRepository,
        private FfmpegAvailability $ffmpegAvailability,
        private JournalService $journalService
    ) {
    }

    /**
     * GET /config/gallery
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        return $this->render('@gallery/config.html.twig', $this->buildContext());
    }

    /**
     * POST /config/gallery
     *
     * @param array<string, string> $params
     */
    public function save(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            $context = $this->buildContext();
            $context['submit_error'] = 'Jeton CSRF invalide.';
            return $this->render('@gallery/config.html.twig', $context)->setStatusCode(403);
        }

        $textKeys = [
            'gallery_storage_local_subdir', 'gallery_s3_endpoint', 'gallery_s3_region',
            'gallery_s3_bucket', 'gallery_s3_access_key', 'gallery_s3_public_url',
            'gallery_max_media_per_album', 'gallery_max_photo_upload_mb', 'gallery_photo_max_dimension',
            'gallery_max_video_upload_mb', 'gallery_max_video_duration_sec',
        ];
        $booleanKeys = ['gallery_allow_local', 'gallery_allow_external', 'gallery_allow_video', 'gallery_keep_original_video'];

        try {
            foreach ($textKeys as $key) {
                $this->settingService->set($key, (string) $request->getBody($key, ''), 'gallery');
            }
            foreach ($booleanKeys as $key) {
                $this->settingService->set($key, $request->getBody($key) !== null ? '1' : '0', 'gallery');
            }

            $storageBackend = (string) $request->getBody('gallery_storage_backend', 'local');
            $this->settingService->set('gallery_storage_backend', $storageBackend === 's3' ? 's3' : 'local', 'gallery');

            $provider = (string) $request->getBody('gallery_s3_provider', 'custom');
            $this->settingService->set('gallery_s3_provider', in_array($provider, self::S3_PROVIDERS, true) ? $provider : 'custom', 'gallery');

            $secret = (string) $request->getBody('gallery_s3_secret_key', '');
            if (trim($secret) !== '') {
                $this->s3SecretRepository->set($secret);
            }
        } catch (\Throwable $e) {
            $context = $this->buildContext();
            $context['submit_error'] = 'Erreur lors de l\'enregistrement : ' . $e->getMessage();
            return $this->render('@gallery/config.html.twig', $context)->setStatusCode(422);
        }

        $this->journalService->log(
            'gallery', 'config_updated', 'info', 'Configuration de la galerie modifiée',
            [], (int) AuthSession::getUserAccountId()
        );

        return $this->redirect('/config/gallery');
    }

    /**
     * POST /config/gallery/test-connection — builds a throwaway S3 client
     * from the submitted (not necessarily saved) form values, so the
     * admin can verify credentials before committing them.
     *
     * @param array<string, string> $params
     */
    public function testConnection(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data) || !CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $secret = (string) ($data['secret_key'] ?? '');
        if (trim($secret) === '') {
            $secret = $this->s3SecretRepository->get() ?? '';
        }

        $backend = new S3StorageBackend(
            (string) ($data['endpoint'] ?? ''),
            (string) ($data['region'] ?? ''),
            (string) ($data['bucket'] ?? ''),
            (string) ($data['access_key'] ?? ''),
            $secret
        );

        if ($backend->testConnection()) {
            return $this->json(['success' => true]);
        }

        return $this->json(['success' => false, 'error' => 'Connexion impossible — vérifiez les identifiants et le bucket.'], 422);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContext(): array
    {
        return [
            'ffmpeg_available' => $this->ffmpegAvailability->check(),
            'gallery_allow_local' => (bool) $this->settingService->get('gallery_allow_local', 'gallery', true),
            'gallery_allow_external' => (bool) $this->settingService->get('gallery_allow_external', 'gallery', true),
            'gallery_storage_backend' => (string) $this->settingService->get('gallery_storage_backend', 'gallery', 'local'),
            'gallery_storage_local_subdir' => (string) $this->settingService->get('gallery_storage_local_subdir', 'gallery', 'gallery'),
            'gallery_s3_provider' => (string) $this->settingService->get('gallery_s3_provider', 'gallery', 'custom'),
            'gallery_s3_endpoint' => (string) $this->settingService->get('gallery_s3_endpoint', 'gallery', ''),
            'gallery_s3_region' => (string) $this->settingService->get('gallery_s3_region', 'gallery', ''),
            'gallery_s3_bucket' => (string) $this->settingService->get('gallery_s3_bucket', 'gallery', ''),
            'gallery_s3_access_key' => (string) $this->settingService->get('gallery_s3_access_key', 'gallery', ''),
            'gallery_s3_public_url' => (string) $this->settingService->get('gallery_s3_public_url', 'gallery', ''),
            'gallery_s3_secret_configured' => $this->s3SecretRepository->get() !== null,
            'gallery_max_media_per_album' => (int) $this->settingService->get('gallery_max_media_per_album', 'gallery', 200),
            'gallery_max_photo_upload_mb' => (int) $this->settingService->get('gallery_max_photo_upload_mb', 'gallery', 30),
            'gallery_photo_max_dimension' => (int) $this->settingService->get('gallery_photo_max_dimension', 'gallery', 3000),
            'gallery_allow_video' => (bool) $this->settingService->get('gallery_allow_video', 'gallery', true),
            'gallery_max_video_upload_mb' => (int) $this->settingService->get('gallery_max_video_upload_mb', 'gallery', 2048),
            'gallery_max_video_duration_sec' => (int) $this->settingService->get('gallery_max_video_duration_sec', 'gallery', 1800),
            'gallery_keep_original_video' => (bool) $this->settingService->get('gallery_keep_original_video', 'gallery', false),
            'csrf_token' => CsrfGuard::generateToken(),
        ];
    }
}
