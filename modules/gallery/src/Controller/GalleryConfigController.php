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
use Core\Storage\Location\StorageLocation;
use Core\Storage\Location\StorageLocationRepository;
use Modules\Gallery\Service\AlbumService;
use Modules\Gallery\Service\DelegatedAlbumDescriberRegistry;
use Modules\Gallery\Service\FfmpegAvailability;
use Modules\Gallery\Api\GalleryException;
use Modules\Gallery\Service\GalleryLocationException;
use Modules\Gallery\Service\GalleryLocationService;
use Core\Storage\Location\StorageLocationService;
use Core\Storage\Location\StorageLocationType;
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
    /**
     * The four tabs, and which settings each of them owns.
     *
     * **A tab saves its own keys and nobody else's**, which is not a
     * detail: an unchecked checkbox submits NOTHING, so a page that writes
     * every boolean it knows about on every save would silently switch off
     * « autoriser les vidéos » each time somebody edited the photo limits
     * on another tab. Splitting the keys by tab makes that impossible
     * rather than careful.
     *
     * « Albums » owns no setting at all — it is the migration table, which
     * acts through its own endpoint — and is listed here so the rail is
     * built from one declaration instead of from a template's memory of it.
     *
     * @var array<string, array{label: string, numeric: list<string>, boolean: list<string>}>
     */
    public const TABS = [
        'general' => [
            'label' => 'Général',
            'numeric' => ['gallery_max_media_per_album'],
            'boolean' => ['gallery_allow_external'],
        ],
        'photos' => [
            'label' => 'Photos',
            'numeric' => ['gallery_max_photo_upload_mb', 'gallery_photo_max_dimension'],
            'boolean' => [],
        ],
        'videos' => [
            'label' => 'Vidéos',
            'numeric' => ['gallery_max_video_upload_mb', 'gallery_max_video_duration_sec'],
            'boolean' => ['gallery_allow_video', 'gallery_keep_original_video'],
        ],
        'albums' => ['label' => 'Albums', 'numeric' => [], 'boolean' => []],
    ];

    private const NUMERIC_SETTINGS = [
        'gallery_max_media_per_album' => [
            'min' => 1,
            'max' => 10000,
            'label' => 'Le nombre maximum de médias par album'
        ],
        'gallery_max_photo_upload_mb' => ['min' => 1, 'max' => 1024, 'label' => 'La taille maximale par photo (Mo)'],
        'gallery_photo_max_dimension' => [
            'min' => 500,
            'max' => 20000,
            'label' => 'La dimension maximale des photos (px)'
        ],
        'gallery_max_video_upload_mb' => ['min' => 1, 'max' => 65536, 'label' => 'La taille maximale par vidéo (Mo)'],
        'gallery_max_video_duration_sec' => ['min' => 1, 'max' => 86400, 'label' => 'La durée maximale par vidéo (s)'],
    ];

    public function __construct(
        protected Environment $twig,
        private SettingService $settingService,
        private FfmpegAvailability $ffmpegAvailability,
        private JournalService $journalService,
        private StorageLocationService $storageLocationService,
        private GalleryLocationService $galleryLocationService,
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
        $this->storageLocationService->ensureDefaultExists();

        return $this->render('@gallery/config.html.twig', $this->buildContext($this->activeTab($request)));
    }

    /**
     * Which tab is being looked at — `general` for anything this page does
     * not recognise, including nothing at all.
     *
     * A query parameter rather than four routes: the four tabs are four
     * views of one configuration, they share a controller and a context,
     * and `partials/page_picker.html.twig` is built for exactly this case
     * (« a synthetic path the caller computes when two views share one
     * route and differ by a query »).
     */
    private function activeTab(Request $request): string
    {
        $tab = (string) ($request->getQuery('onglet') ?? '');

        return isset(self::TABS[$tab]) ? $tab : 'general';
    }

    /**
     * POST /config/gallery — the four tabs' settings.
     *
     * Storage LOCATIONS are declared on `/config/stockage` since IT-02 and
     * this page no longer touches them. What it does keep is the one
     * storage decision that is the gallery's own (D4): which declared
     * location new albums are created on.
     *
     * @param array<string, string> $params
     */
    public function save(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            $context = $this->buildContext($this->activeTab($request));
            $context['submit_error'] = self::SESSION_EXPIRED_MESSAGE;
            return $this->render('@gallery/config.html.twig', $context)->setStatusCode(403);
        }

        $tab = $this->activeTab($request);
        $booleanKeys = self::TABS[$tab]['boolean'];

        // « Emplacement des nouveaux albums » lives on the Général tab. What
        // that choice MEANS — the identifier still naming a location, 0
        // standing for the site's default, whether it moved — belongs to
        // Service\GalleryLocationService::chooseForNewAlbums() and not
        // here; this controller keeps the two things that are genuinely
        // its own, the HTTP refusal and the journal entry.
        $ownsLocation = $tab === 'general';
        $newAlbumLocationId = $ownsLocation
            ? (int) $request->getBody(GalleryLocationService::NEW_ALBUM_LOCATION_SETTING, '0')
            : 0;

        // Validate every numeric field up front, so a single bad value can't
        // leave half the settings written and half not.
        $numericValues = [];
        foreach (self::TABS[$tab]['numeric'] as $key) {
            $bounds = self::NUMERIC_SETTINGS[$key];
            $raw = trim((string) $request->getBody($key, ''));
            if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
                return $this->saveError("{$bounds['label']} doit être un nombre entier.", $tab);
            }
            $value = (int) $raw;
            if ($value < $bounds['min'] || $value > $bounds['max']) {
                return $this->saveError(
                    "{$bounds['label']} doit être comprise entre {$bounds['min']} et {$bounds['max']}.",
                    $tab
                );
            }
            $numericValues[$key] = (string) $value;
        }

        $locationChoice = null;

        try {
            foreach ($numericValues as $key => $value) {
                $this->settingService->set($key, $value, 'gallery');
            }
            foreach ($booleanKeys as $key) {
                $this->settingService->set($key, $request->getBody($key) !== null ? '1' : '0', 'gallery');
            }
            if ($ownsLocation) {
                $locationChoice = $this->galleryLocationService->chooseForNewAlbums($newAlbumLocationId);
            }
        } catch (GalleryLocationException $e) {
            // Its own catch, ahead of the catch-all below: this message is
            // written for the administrator (the location they picked has
            // just been deleted) and says what to do about it, so it is
            // shown rather than turned into « vérifiez les valeurs saisies ».
            return $this->saveError($e->getMessage(), $tab);
        } catch (\Throwable $e) {
            // A bare \Throwable: a PDOException naming a column, a
            // SettingException naming a key. The journal keeps it; the page
            // gets a sentence somebody wrote for it.
            $this->journalService->log(
                'gallery',
                'config_update_failed',
                'info',
                'Échec de l\'enregistrement de la configuration de la galerie',
                ['error' => $e->getMessage()],
                (int) AuthSession::getUserAccountId()
            );

            $message = UserFacingMessage::from(
                $e,
                "La configuration n'a pas pu être enregistrée — vérifiez les valeurs saisies, puis réessayez."
            );

            return $this->saveError($message, $tab);
        }

        $this->journalService->log(
            'gallery',
            'config_updated',
            'info',
            'Configuration de la galerie modifiée',
            [],
            (int) AuthSession::getUserAccountId()
        );

        // `security`, and the chantier's transverse table puts it there
        // deliberately: choosing a storage location decides where this
        // unit's photographs physically go. Journalled separately from the
        // rest of the configuration, because « la galerie écrit désormais
        // ailleurs » is the line somebody auditing this site looks for and
        // it is invisible inside « la configuration a été modifiée ».
        if ($locationChoice !== null && $locationChoice->changed()) {
            $this->journalService->log(
                'gallery',
                'storage_location_chosen',
                'security',
                sprintf('Les nouveaux albums iront désormais sur « %s »', $locationChoice->frenchName()),
                [],
                (int) AuthSession::getUserAccountId()
            );
        }

        return $this->redirect('/config/gallery?onglet=' . $tab);
    }

    /**
     * Re-renders the config page with a validation message, HTTP 422 — same
     * shape as the catch-all below it.
     */
    private function saveError(string $message, string $tab): Response
    {
        $context = $this->buildContext($tab);
        $context['submit_error'] = $message;

        return $this->render('@gallery/config.html.twig', $context)->setStatusCode(422);
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
            $this->albumService->startMigration(
                (int) $params['id'],
                (int) ($data['target_location_id'] ?? 0),
                $role,
                $email
            );
        } catch (GalleryException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json(['success' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContext(string $tab = 'general'): array
    {
        $locations = array_map(
            fn(StorageLocation $l) => $this->storageLocationService->checkFresh($l),
            $this->storageLocationRepository->findAll()
        );

        return [
            'tab' => $tab,
            'tabs' => self::TABS,
            'ffmpeg_available' => $this->ffmpegAvailability->check(),
            // Still needed here, and only for the « Albums » tab: a
            // migration is a move between two of them, so the list of
            // destinations is what the select is built from. Declaring
            // them is /config/stockage's job now, and this page no longer
            // offers it.
            'locations' => $locations,
            'new_album_location_id' => (int) $this->settingService->get(
                GalleryLocationService::NEW_ALBUM_LOCATION_SETTING,
                'gallery',
                0
            ),
            // Each album with the location it is ACTUALLY on, resolved
            // rather than read: an album that has not been pinned yet is
            // sitting on the default, and the raw column would have the
            // screen say « Non défini » about it and offer its own
            // location as somewhere to move it to.
            'local_albums' => array_map(
                fn(Album $a) => [
                    'album' => $a,
                    'location_id' => $this->galleryLocationService->effectiveLocationId($a),
                ],
                array_values(array_filter(
                    $this->albumService->findAllForManage(),
                    fn(Album $a) => $a->isLocal()
                ))
            ),
            // Albums another module owns. Listed HERE and nowhere else in
            // gallery: what they hold and who may see them belong to their
            // owner, but they take real space on a real location and moving
            // them is gallery's job — which was impossible while this page
            // could not so much as name them.
            'delegated_albums' => array_map(
                fn(Album $a) => [
                    'album' => $a,
                    'location_id' => $this->galleryLocationService->effectiveLocationId($a),
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
            // What still stands on each location, by name. A refusal to
            // delete quotes these back, so the page shows the same words
            // rather than a count the administrator would have to decode.
            'location_usages' => array_combine(
                array_map(fn(StorageLocation $l) => $l->id, $locations),
                array_map(fn(StorageLocation $l) => $this->storageLocationService->usagesOf($l->id), $locations)
            ),
            'gallery_allow_external' => (bool) $this->settingService->get('gallery_allow_external', 'gallery', true),
            'gallery_max_media_per_album' => (int) $this->settingService->get(
                'gallery_max_media_per_album',
                'gallery',
                200
            ),
            'gallery_max_photo_upload_mb' => (int) $this->settingService->get(
                'gallery_max_photo_upload_mb',
                'gallery',
                30
            ),
            'gallery_photo_max_dimension' => (int) $this->settingService->get(
                'gallery_photo_max_dimension',
                'gallery',
                3000
            ),
            'gallery_allow_video' => (bool) $this->settingService->get('gallery_allow_video', 'gallery', true),
            'gallery_max_video_upload_mb' => (int) $this->settingService->get(
                'gallery_max_video_upload_mb',
                'gallery',
                2048
            ),
            'gallery_max_video_duration_sec' => (int) $this->settingService->get(
                'gallery_max_video_duration_sec',
                'gallery',
                1800
            ),
            'gallery_keep_original_video' => (bool) $this->settingService->get(
                'gallery_keep_original_video',
                'gallery',
                false
            ),
            'csrf_token' => CsrfGuard::generateToken(),
        ];
    }
}
