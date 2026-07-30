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
use Core\Security\Role;
use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\StorageLocation;
use Modules\Gallery\Repository\StorageLocationRepository;
use Modules\Gallery\Service\AlbumService;
use Modules\Gallery\Service\FfmpegAvailability;
use Modules\Gallery\Service\GalleryException;
use Modules\Gallery\Service\S3ErrorExplainerService;
use Modules\Gallery\Service\Storage\S3StorageBackend;
use Modules\Gallery\Service\StorageLocationService;
use Twig\Environment;

class GalleryConfigController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private SettingService $settingService,
        private FfmpegAvailability $ffmpegAvailability,
        private JournalService $journalService,
        private S3ErrorExplainerService $s3ErrorExplainerService,
        private StorageLocationService $storageLocationService,
        private StorageLocationRepository $storageLocationRepository,
        private AlbumService $albumService
    ) {
    }

    /**
     * GET /config/gallery
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $this->storageLocationService->ensureLegacyLocationBackfilled();

        return $this->render('@gallery/config.html.twig', $this->buildContext());
    }

    /**
     * POST /config/gallery — storage locations are managed on their own
     * pages now (Controller\GalleryStorageLocationController); this only
     * covers the non-storage settings (allowed album types, media limits,
     * video settings).
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
            'gallery_max_media_per_album', 'gallery_max_photo_upload_mb', 'gallery_photo_max_dimension',
            'gallery_max_video_upload_mb', 'gallery_max_video_duration_sec',
        ];
        $booleanKeys = ['gallery_allow_external', 'gallery_allow_video', 'gallery_keep_original_video'];

        try {
            foreach ($textKeys as $key) {
                $this->settingService->set($key, (string) $request->getBody($key, ''), 'gallery');
            }
            foreach ($booleanKeys as $key) {
                $this->settingService->set($key, $request->getBody($key) !== null ? '1' : '0', 'gallery');
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
     * from the submitted (not necessarily saved) form values, so the admin
     * can verify credentials before committing them to a new or edited
     * location (Controller\GalleryStorageLocationController's create/edit
     * forms reuse this same endpoint).
     *
     * @param array<string, string> $params
     */
    public function testConnection(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data) || !CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $backend = new S3StorageBackend(
            (string) ($data['endpoint'] ?? ''),
            (string) ($data['region'] ?? ''),
            (string) ($data['bucket'] ?? ''),
            (string) ($data['access_key'] ?? ''),
            (string) ($data['secret_key'] ?? '')
        );

        $error = $backend->testConnection();
        if ($error === null) {
            return $this->json(['success' => true]);
        }

        return $this->json(['success' => false, 'error' => 'Connexion impossible : ' . $error], 422);
    }

    /**
     * POST /config/gallery/explain-s3-error — asks the LLM connector to
     * diagnose a failed S3 test-connection for the admin, given only the
     * non-secret config fields, the secret key's length, and the provider's
     * own error message. Never receives or forwards the secret key itself.
     *
     * @param array<string, string> $params
     */
    public function explainS3Error(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data) || !CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        try {
            $explanation = $this->s3ErrorExplainerService->explain(
                (string) ($data['provider'] ?? 'custom'),
                (string) ($data['endpoint'] ?? ''),
                (string) ($data['region'] ?? ''),
                (string) ($data['bucket'] ?? ''),
                (string) ($data['access_key'] ?? ''),
                (int) ($data['secret_key_length'] ?? 0),
                (string) ($data['error'] ?? '')
            );
        } catch (GalleryException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json(['success' => true, 'explanation' => $explanation]);
    }

    /**
     * POST /config/gallery/albums/{id}/migrate — starts (or retries) a
     * background storage migration of a local album to a different
     * location. Superadmin-only (moved here from the chief-facing album
     * edit page — see AlbumService::startMigration() for the mechanics,
     * unchanged).
     *
     * @param array<string, string> $params
     */
    public function migrateAlbumStorage(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data) || !CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $role = Role::fromString(AuthSession::getRole());
        $email = AuthSession::getEmail() ?? '';

        try {
            $this->albumService->startMigration((int) $params['id'], (int) ($data['target_location_id'] ?? 0), $role, $email);
        } catch (GalleryException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json(['success' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContext(): array
    {
        $locations = array_map(
            fn(StorageLocation $l) => $this->storageLocationService->checkFresh($l),
            $this->storageLocationRepository->findAll()
        );

        return [
            'ffmpeg_available' => $this->ffmpegAvailability->check(),
            'gallery_s3_ai_available' => $this->s3ErrorExplainerService->isAvailable(),
            'locations' => $locations,
            'local_albums' => array_values(array_filter($this->albumService->findAllForManage(), fn(Album $a) => $a->isLocal())),
            'location_album_counts' => array_combine(
                array_map(fn(StorageLocation $l) => $l->id, $locations),
                array_map(fn(StorageLocation $l) => $this->storageLocationRepository->countAlbumsUsing($l->id), $locations)
            ),
            'gallery_allow_external' => (bool) $this->settingService->get('gallery_allow_external', 'gallery', true),
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
