<?php

declare(strict_types=1);

namespace Modules\Gallery\Controller;

use Core\Config\SettingService;
use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\Role;
use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\Media;
use Modules\Gallery\Repository\MediaRepository;
use Modules\Gallery\Repository\StorageLocationRepository;
use Modules\Gallery\Service\AlbumService;
use Modules\Gallery\Service\GalleryAccessService;
use Modules\Gallery\Service\GalleryException;
use Modules\Gallery\Service\MediaService;
use Modules\Gallery\Service\StorageLocationService;
use Core\Member\SectionService;
use Twig\Environment;

class GalleryChiefController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private AlbumService $albumService,
        private MediaService $mediaService,
        private MediaRepository $mediaRepository,
        private GalleryAccessService $accessService,
        private SectionService $sectionService,
        private SettingService $settingService,
        private StorageLocationRepository $storageLocationRepository,
        private StorageLocationService $storageLocationService
    ) {
    }

    /**
     * GET /gallery/manage
     *
     * @param array<string, string> $params
     */
    public function manage(Request $request, array $params): Response
    {
        [$role, $email] = $this->currentIdentity();

        $albums = array_map(fn(Album $a) => [
            'album' => $a,
            'can_edit' => $this->accessService->canManageAlbum($role, $a->sectionId, $email),
        ], $this->albumService->findAllForManage());

        $this->storageLocationService->ensureLegacyLocationBackfilled();

        return $this->render('@gallery/manage.html.twig', [
            'albums' => $albums,
            'allow_local' => $this->storageLocationRepository->findAll() !== [],
            'allow_external' => (bool) $this->settingService->get('gallery_allow_external', 'gallery', true),
        ]);
    }

    /**
     * GET /gallery/create
     *
     * @param array<string, string> $params
     */
    public function create(Request $request, array $params): Response
    {
        return $this->render('@gallery/album_form.html.twig', $this->formContext(null));
    }

    /**
     * POST /gallery
     *
     * @param array<string, string> $params
     */
    public function store(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            return new Response('Jeton CSRF invalide.', 403);
        }

        [$role, $email] = $this->currentIdentity();
        $accountId = (int) AuthSession::getUserAccountId();

        try {
            $album = $this->albumService->create(
                (string) $request->getBody('type', Album::TYPE_LOCAL),
                (string) $request->getBody('title', ''),
                $this->nullableString($request->getBody('subtitle')),
                (string) $request->getBody('album_date', date('Y-m-d')),
                $this->nullableInt($request->getBody('section_id')),
                $this->nullableString($request->getBody('external_url')),
                $accountId,
                $role,
                $email
            );
        } catch (GalleryException $e) {
            $context = $this->formContext(null);
            $context['submit_error'] = $e->getMessage();
            return $this->render('@gallery/album_form.html.twig', $context)->setStatusCode(422);
        }

        return $this->redirect('/gallery/' . $album->id . '/edit');
    }

    /**
     * GET /gallery/{id}/edit
     *
     * @param array<string, string> $params
     */
    public function edit(Request $request, array $params): Response
    {
        $album = $this->albumService->findById((int) $params['id']);
        if ($album === null) {
            return new Response('Not Found', 404);
        }

        return $this->render('@gallery/album_form.html.twig', $this->formContext($album));
    }

    /**
     * POST /gallery/{id}
     *
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            return new Response('Jeton CSRF invalide.', 403);
        }

        $album = $this->albumService->findById((int) $params['id']);
        if ($album === null) {
            return new Response('Not Found', 404);
        }
        [$role, $email] = $this->currentIdentity();

        try {
            $this->albumService->update(
                $album->id,
                (string) $request->getBody('title', ''),
                $this->nullableString($request->getBody('subtitle')),
                (string) $request->getBody('album_date', $album->albumDate),
                $this->nullableInt($request->getBody('section_id')),
                $this->nullableString($request->getBody('external_url')),
                $role,
                $email
            );
        } catch (GalleryException $e) {
            $context = $this->formContext($album);
            $context['submit_error'] = $e->getMessage();
            return $this->render('@gallery/album_form.html.twig', $context)->setStatusCode(422);
        }

        return $this->redirect('/gallery/' . $album->id . '/edit');
    }

    /**
     * POST /gallery/{id}/delete
     *
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data) || !CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        [$role, $email] = $this->currentIdentity();

        try {
            $this->albumService->delete((int) $params['id'], $role, $email);
        } catch (GalleryException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json(['success' => true]);
    }

    /**
     * POST /gallery/{id}/media — one file per request (module spec: JS
     * uploads in parallel batches of 5 XHR requests).
     *
     * @param array<string, string> $params
     */
    public function uploadMedia(Request $request, array $params): Response
    {
        $csrf = (string) $request->getBody('_csrf_token', '');
        if (!CsrfGuard::validateToken($csrf)) {
            return $this->json(['success' => false, 'error' => 'Jeton CSRF invalide.'], 403);
        }

        $album = $this->albumService->findById((int) $params['id']);
        if ($album === null) {
            return $this->json(['success' => false, 'error' => 'Album introuvable.'], 404);
        }

        $uploadedFile = $request->getFile('file');
        if ($uploadedFile === null) {
            return $this->json(['success' => false, 'error' => 'Aucun fichier envoyé.'], 400);
        }

        [$role, $email] = $this->currentIdentity();
        $accountId = AuthSession::getUserAccountId();

        try {
            $media = $this->mediaService->upload($album, $uploadedFile, $role, $email, $accountId);
        } catch (GalleryException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json(['success' => true, 'media_id' => $media->id]);
    }

    /**
     * POST /gallery/{id}/media/reorder
     *
     * @param array<string, string> $params
     */
    public function reorderMedia(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data) || !CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $album = $this->albumService->findById((int) $params['id']);
        if ($album === null) {
            return $this->json(['success' => false, 'error' => 'Album introuvable.'], 404);
        }

        [$role, $email] = $this->currentIdentity();
        $orderedIds = array_map('intval', (array) ($data['ordered_ids'] ?? []));

        try {
            $this->mediaService->reorder($album, $orderedIds, $role, $email);
        } catch (GalleryException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json(['success' => true]);
    }

    /**
     * POST /gallery/{id}/media/{media_id}/delete
     *
     * @param array<string, string> $params
     */
    public function deleteMedia(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data) || !CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $album = $this->albumService->findById((int) $params['id']);
        $media = $album !== null ? $this->mediaRepository->findById((int) $params['media_id']) : null;
        if ($album === null || $media === null || $media->albumId !== $album->id) {
            return $this->json(['success' => false, 'error' => 'Média introuvable.'], 404);
        }

        [$role, $email] = $this->currentIdentity();

        try {
            $this->mediaService->delete($media, $album, $role, $email);
        } catch (GalleryException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json(['success' => true]);
    }

    /**
     * POST /gallery/{id}/cover
     *
     * @param array<string, string> $params
     */
    public function setCover(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data) || !CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        [$role, $email] = $this->currentIdentity();

        try {
            $this->albumService->setCover((int) $params['id'], (int) ($data['media_id'] ?? 0), $role, $email);
        } catch (GalleryException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json(['success' => true]);
    }

    /**
     * POST /gallery/{id}/refresh-og
     *
     * @param array<string, string> $params
     */
    public function refreshOg(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data) || !CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        [$role, $email] = $this->currentIdentity();

        try {
            $album = $this->albumService->refreshOg((int) $params['id'], $role, $email);
        } catch (GalleryException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json([
            'success' => true,
            'og_title' => $album->ogTitle,
            'og_description' => $album->ogDescription,
            'og_image_url' => $album->ogImageFileId !== null ? '/files/' . $album->ogImageFileId : null,
        ]);
    }

    /**
     * @return array{0: Role, 1: string}
     */
    private function currentIdentity(): array
    {
        return [Role::fromString(AuthSession::getRole()), AuthSession::getEmail() ?? ''];
    }

    /**
     * @return array<string, mixed>
     */
    private function formContext(?Album $album): array
    {
        [$role, $email] = $this->currentIdentity();
        $managedSectionIds = $this->accessService->getManagedSectionIds($email);
        $isAdmin = $role->hasAccess(Role::ADMIN);

        $sections = array_values(array_filter(
            $this->sectionService->getAllWithBranches(),
            fn(array $s) => $isAdmin || in_array((int) $s['id'], $managedSectionIds, true)
        ));

        // Media is hidden while a migration is in flight — a partially
        // copied set could otherwise render inconsistently (module spec:
        // "the gallery is not available" during migration).
        $media = $album !== null && !$album->isMigrating() ? $this->mediaRepository->findByAlbumId($album->id) : [];

        $this->storageLocationService->ensureLegacyLocationBackfilled();
        $locations = $this->storageLocationRepository->findAll();
        $defaultLocation = $this->storageLocationRepository->findDefault();

        return [
            'album' => $album,
            // Only meaningful for edit/update (a real album, whose title
            // is worth showing in the trail) — create/store pass $album as
            // null, so this falls back to the route's static
            // breadcrumb.label ("Nouvel album") via Twig's default()
            // filter, same as News's editorContext() pattern.
            'breadcrumb_current' => $album?->title,
            'sections' => $sections,
            'allow_local' => $locations !== [],
            'allow_external' => (bool) $this->settingService->get('gallery_allow_external', 'gallery', true),
            'video_upload_allowed' => $this->mediaService->videoUploadAllowed(),
            'max_media_per_album' => (int) $this->settingService->get('gallery_max_media_per_album', 'gallery', 200),
            'locations' => $locations,
            'default_location_id' => $defaultLocation?->id,
            'media' => $album !== null ? array_map(fn(Media $m) => [
                'media' => $m,
                'thumb_url' => $this->mediaService->resolveUrl($m, $album, 'thumb'),
            ], $media) : [],
            'csrf_token' => CsrfGuard::generateToken(),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = (string) ($value ?? '');
        return trim($value) === '' ? null : $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
