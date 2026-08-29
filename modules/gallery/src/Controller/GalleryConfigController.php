<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Controller;

use Core\Config\SettingService;
use Core\Exception\UserFacingMessage;
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
use Modules\Gallery\Service\DelegatedAlbumDescriberRegistry;
use Modules\Gallery\Service\FfmpegAvailability;
use Modules\Gallery\Api\GalleryException;
use Modules\Gallery\Service\S3ErrorExplainerService;
use Modules\Gallery\Service\S3TestFailure;
use Modules\Gallery\Service\Storage\S3StorageBackend;
use Modules\Gallery\Service\StorageLocationService;
use Twig\Environment;

class GalleryConfigController extends AbstractController
{
    /**
     * Accepted range per numeric setting, mirroring the form's own min
     * attributes. Core\Config\SettingService only checks that a 'number'
     * setting is numeric — it happily stores '', '0' or '-5', and those land
     * as a hard 0 at every read site: gallery_max_media_per_album = 0 refused
     * every upload ("limite de 0 médias") and gallery_photo_max_dimension = 0
     * asked GD for a 0x0 canvas, failing every photo in the album.
     *
     * @var array<string, array{min: int, max: int, label: string}>
     */
    private const NUMERIC_SETTINGS = [
        'gallery_max_media_per_album' => ['min' => 1, 'max' => 10000, 'label' => 'Le nombre maximum de médias par album'],
        'gallery_max_photo_upload_mb' => ['min' => 1, 'max' => 1024, 'label' => 'La taille maximale par photo (Mo)'],
        'gallery_photo_max_dimension' => ['min' => 500, 'max' => 20000, 'label' => 'La dimension maximale des photos (px)'],
        'gallery_max_video_upload_mb' => ['min' => 1, 'max' => 65536, 'label' => 'La taille maximale par vidéo (Mo)'],
        'gallery_max_video_duration_sec' => ['min' => 1, 'max' => 86400, 'label' => 'La durée maximale par vidéo (s)'],
    ];

    public function __construct(
        protected Environment $twig,
        private SettingService $settingService,
        private FfmpegAvailability $ffmpegAvailability,
        private JournalService $journalService,
        private S3ErrorExplainerService $s3ErrorExplainerService,
        private StorageLocationService $storageLocationService,
        private StorageLocationRepository $storageLocationRepository,
        private AlbumService $albumService,
        /**
         * Turns a delegated album's (owner_type, owner_id) into a name an
         * administrator recognises. Optional: with no delegating module
         * installed the registry is simply empty, and the fallback label is
         * the owner_type itself — never a hidden album.
         */
        private DelegatedAlbumDescriberRegistry $delegatedAlbumDescriberRegistry = new DelegatedAlbumDescriberRegistry()
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
            $context['submit_error'] = self::SESSION_EXPIRED_MESSAGE;
            return $this->render('@gallery/config.html.twig', $context)->setStatusCode(403);
        }

        $booleanKeys = ['gallery_allow_external', 'gallery_allow_video', 'gallery_keep_original_video'];

        // Validate every numeric field up front, so a single bad value can't
        // leave half the settings written and half not.
        $numericValues = [];
        foreach (self::NUMERIC_SETTINGS as $key => $bounds) {
            $raw = trim((string) $request->getBody($key, ''));
            if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
                return $this->saveError("{$bounds['label']} doit être un nombre entier.");
            }
            $value = (int) $raw;
            if ($value < $bounds['min'] || $value > $bounds['max']) {
                return $this->saveError("{$bounds['label']} doit être comprise entre {$bounds['min']} et {$bounds['max']}.");
            }
            $numericValues[$key] = (string) $value;
        }

        try {
            foreach ($numericValues as $key => $value) {
                $this->settingService->set($key, $value, 'gallery');
            }
            foreach ($booleanKeys as $key) {
                $this->settingService->set($key, $request->getBody($key) !== null ? '1' : '0', 'gallery');
            }
        } catch (\Throwable $e) {
            // A bare \Throwable: a PDOException naming a column, a
            // SettingException naming a key. The journal keeps it; the page
            // gets a sentence somebody wrote for it.
            $this->journalService->log(
                'gallery', 'config_update_failed', 'info', 'Échec de l\'enregistrement de la configuration de la galerie',
                ['error' => $e->getMessage()], (int) AuthSession::getUserAccountId()
            );

            return $this->saveError(UserFacingMessage::from(
                $e,
                "La configuration n'a pas pu être enregistrée — vérifiez les valeurs saisies, puis réessayez."
            ));
        }

        $this->journalService->log(
            'gallery', 'config_updated', 'info', 'Configuration de la galerie modifiée',
            [], (int) AuthSession::getUserAccountId()
        );

        return $this->redirect('/config/gallery');
    }

    /**
     * Re-renders the config page with a validation message, HTTP 422 — same
     * shape as the catch-all below it.
     */
    private function saveError(string $message): Response
    {
        $context = $this->buildContext();
        $context['submit_error'] = $message;

        return $this->render('@gallery/config.html.twig', $context)->setStatusCode(422);
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

        // The edit form deliberately leaves the secret field blank ("laisser
        // vide pour conserver la clé actuelle"), so testing an existing
        // location used to send an empty secret and always fail on
        // authentication. When the caller names the location it is editing,
        // fall back to that location's stored secret.
        $secretKey = (string) ($data['secret_key'] ?? '');
        if ($secretKey === '') {
            $locationId = (int) ($data['location_id'] ?? 0);
            $location = $locationId > 0 ? $this->storageLocationRepository->findById($locationId) : null;
            if ($location !== null && $location->isS3()) {
                $secretKey = (string) $this->storageLocationRepository->getSecret($location->id);
            }
        }

        // The endpoint is connected to server-side with the access key/secret,
        // so it must be a genuine public https host — never http:// (which
        // would send the credentials in plaintext) and never an internal
        // address (SSRF) — audit M6. A custom port is allowed since
        // S3-compatible providers vary, but the host must still be public.
        $endpoint = (string) ($data['endpoint'] ?? '');
        if (!\Core\Security\SsrfUrlValidator::isPublicHttpsUrl($endpoint, true)) {
            return $this->json(['success' => false, 'error' => 'L\'adresse du service doit être une URL https publique.'], 422);
        }

        $backend = new S3StorageBackend(
            $endpoint,
            (string) ($data['region'] ?? ''),
            (string) ($data['bucket'] ?? ''),
            (string) ($data['access_key'] ?? ''),
            $secretKey
        );

        $error = $backend->testConnection();
        if ($error === null) {
            S3TestFailure::forget();

            return $this->json(['success' => true]);
        }

        // $error is already a French sentence; the AWS SDK's own words are
        // on lastTechnicalError() and stay here — in the journal, and in
        // the session for explainS3Error(), which is the one reader that
        // has any use for them. They never reach the page.
        $summary = 'Connexion impossible : ' . $error;
        $this->journalService->log(
            'gallery', 's3_test_connection_failed', 'info', 'Échec du test de connexion à un stockage S3',
            ['bucket' => (string) ($data['bucket'] ?? ''), 'sdk_error' => $backend->lastTechnicalError()],
            (int) AuthSession::getUserAccountId()
        );
        S3TestFailure::remember($summary, $backend->lastTechnicalError());

        return $this->json(['success' => false, 'error' => $summary], 422);
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

        // The failure comes from the session, never from the request body.
        // The browser only ever had the French summary — useless to
        // diagnose — and a string the browser supplies is a string that
        // goes into a model's prompt having been through a page the admin
        // can edit.
        $failure = S3TestFailure::read();
        if ($failure === null) {
            return $this->json([
                'success' => false,
                'error' => 'Lancez d\'abord un test de connexion : il n\'y a rien à expliquer pour le moment.',
            ], 422);
        }

        try {
            $explanation = $this->s3ErrorExplainerService->explain(
                (string) ($data['provider'] ?? 'custom'),
                (string) ($data['endpoint'] ?? ''),
                (string) ($data['region'] ?? ''),
                (string) ($data['bucket'] ?? ''),
                (string) ($data['access_key'] ?? ''),
                (int) ($data['secret_key_length'] ?? 0),
                $failure['summary'],
                $failure['technical']
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
            // Albums another module owns. Listed HERE and nowhere else in
            // gallery: what they hold and who may see them belong to their
            // owner, but they take real space on a real location and moving
            // them is gallery's job — which was impossible while this page
            // could not so much as name them.
            'delegated_albums' => array_map(
                fn(Album $a) => [
                    'album' => $a,
                    'owner_label' => $this->delegatedAlbumDescriberRegistry->describe(
                        (string) $a->ownerType,
                        (int) $a->ownerId
                    ),
                ],
                array_values(array_filter(
                    $this->albumService->findDelegatedForAdministration(),
                    fn(Album $a) => $a->isLocal()
                ))
            ),
            'location_album_counts' => array_combine(
                array_map(fn(StorageLocation $l) => $l->id, $locations),
                array_map(fn(StorageLocation $l) => $this->storageLocationRepository->countAlbumsUsing($l->id), $locations)
            ),
            // Room left on the volume behind each local location. Null for
            // an S3 location and null on a host that will not answer — the
            // template shows an em dash for both, since "we cannot tell" and
            // "not applicable" are equally not a number of gigabytes.
            'location_disk_space' => array_combine(
                array_map(fn(StorageLocation $l) => $l->id, $locations),
                array_map(fn(StorageLocation $l) => $this->storageLocationService->diskSpaceFor($l), $locations)
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
