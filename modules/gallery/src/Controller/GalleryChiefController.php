<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Controller;

use Core\Config\SettingService;
use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\Role;
use Core\Service\IntegerInput;
use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\Media;
use Modules\Gallery\Repository\MediaRepository;
use Modules\Gallery\Repository\StorageLocationRepository;
use Modules\Gallery\Service\AlbumService;
use Modules\Gallery\Service\GalleryAccessService;
use Modules\Gallery\Api\GalleryException;
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
        private StorageLocationService $storageLocationService,
        // Trailing/nullable so existing constructions keep working; without it
        // the chunked-upload protocol below simply reports unavailable.
        private ?\Core\File\ChunkedUploadStore $chunkedUploadStore = null,
        // Trailing/nullable for the same reason: with it, the manage list
        // defaults to the current + previous scout years (?all=1 shows
        // everything); without it, the full unfiltered list as before.
        private ?\Core\Config\ScoutYearService $scoutYearService = null
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

        $sectionLabels = $this->sectionLabels();

        // Recent scout years by default (same two-year window as the
        // chief-facing /gallery list), with ?all=1 as the escape into the
        // whole history — an installation's every album ever has no
        // business being read to manage this season's.
        $showAll = $request->getQuery('all') === '1' || $this->scoutYearService === null;
        $albumRows = $showAll
            ? $this->albumService->findAllForManage()
            : $this->albumService->findForManageByScoutYears($this->recentScoutYearIds());

        $albums = array_map(fn(Album $a) => [
            'album' => $a,
            'can_edit' => $this->accessService->canManageAlbum($role, $a->sectionId, $email),
            // The list used to render a hardcoded "Section spécifique" for
            // every scoped album, so it never told a chief WHICH section.
            'section_label' => $a->sectionId !== null
                ? ($sectionLabels[$a->sectionId] ?? 'Section inconnue')
                : null,
        ], $albumRows);

        $this->storageLocationService->ensureLegacyLocationBackfilled();

        return $this->render('@gallery/manage.html.twig', [
            'albums' => $albums,
            'show_all_years' => $showAll,
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
        if (($guard = $this->guardCsrf($request, '/gallery/create')) !== null) {
            return $guard;
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
        if ($album === null || $album->isDelegated()) {
            // A delegated album never appears in this chief-facing surface
            // — reachable only through its owning module.
            return new Response('Not Found', 404);
        }
        // module.json's role_min only says "a chief"; which SECTIONS that
        // chief manages is this module's own rule (Service\
        // GalleryAccessService), and every write path already enforces it.
        // Without it here, the form rendered another section's whole media
        // grid to a chief who cannot touch any of it — and contradicted the
        // can_edit flag manage() computes for the very link that leads here.
        [$role, $email] = $this->currentIdentity();
        if (!$this->accessService->canManageAlbum($role, $album->sectionId, $email)) {
            return new Response('Forbidden', 403);
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
        if (($guard = $this->guardCsrf($request, '/gallery/' . (int) ($params['id'] ?? 0) . '/edit')) !== null) {
            return $guard;
        }

        $album = $this->albumService->findById((int) $params['id']);
        if ($album === null || $album->isDelegated()) {
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
        if (($guard = $this->guardCsrfJson($request, $csrf)) !== null) {
            return $guard;
        }

        $album = $this->albumService->findById((int) $params['id']);
        if ($album === null || $album->isDelegated()) {
            return $this->json(['success' => false, 'error' => 'Album introuvable.'], 404);
        }

        [$role, $email] = $this->currentIdentity();

        // A large file arrives as a sequence of small chunks through this
        // same route (same RBAC/CSRF), reassembled server-side — what lets
        // the document-root-wide post_max_size stay small (audit M2).
        $uploadId = (string) $request->getBody('upload_id', '');
        if ($uploadId !== '') {
            return $this->uploadMediaChunk($request, $album, $role, $email, $uploadId);
        }

        $uploadedFile = $request->getFile('file');
        if ($uploadedFile === null) {
            return $this->json(['success' => false, 'error' => 'Aucun fichier envoyé.'], 400);
        }

        $accountId = AuthSession::getUserAccountId();

        try {
            $media = $this->mediaService->upload($album, $uploadedFile, $role, $email, $accountId);
        } catch (GalleryException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json(['success' => true, 'media_id' => $media->id]);
    }

    /**
     * One ~8 MB chunk of a large media upload. Authorisation is re-checked on
     * EVERY chunk (not only when the last one creates the media), so an
     * unauthorised caller can never consume temp disk chunk by chunk; the
     * assembled size is capped by the store while it grows. On the last
     * chunk the assembled file goes through the exact same
     * MediaService::upload() validation (real-MIME allowlist, per-type size
     * ceiling) as a single-POST upload.
     */
    private function uploadMediaChunk(Request $request, Album $album, Role $role, string $email, string $uploadId): Response
    {
        if ($this->chunkedUploadStore === null) {
            return $this->json(['success' => false, 'error' => 'Envoi fragmenté indisponible.'], 500);
        }

        try {
            $this->mediaService->assertCanUpload($album, $role, $email);
        } catch (GalleryException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        $chunk = $request->getFile('file');
        if ($chunk === null || empty($chunk['tmp_name'])) {
            return $this->json(['success' => false, 'error' => 'Aucun fragment envoyé.'], 400);
        }

        $offset = (int) $request->getBody('chunk_offset', '0');
        $isLast = (string) $request->getBody('last', '0') === '1';

        // Assembly ceiling: the larger of the two media limits — the exact
        // per-type ceiling is enforced by MediaService on the finished file.
        $maxMb = max(
            (int) $this->settingService->get('gallery_max_photo_upload_mb', 'gallery', 30),
            (int) $this->settingService->get('gallery_max_video_upload_mb', 'gallery', 2048)
        );

        try {
            $assembled = $this->chunkedUploadStore->appendChunk(
                $uploadId,
                session_id(),
                $offset,
                (string) $chunk['tmp_name'],
                $isLast,
                $maxMb * 1024 * 1024
            );
        } catch (\Core\File\UploadException $e) {
            try {
                $received = $this->chunkedUploadStore->receivedBytes($uploadId, session_id());
            } catch (\Core\File\UploadException) {
                $received = 0;
            }
            return $this->json(['success' => false, 'error' => $e->getMessage(), 'received' => $received], 409);
        }

        if ($assembled === null) {
            return $this->json(['success' => true, 'received' => $this->chunkedUploadStore->receivedBytes($uploadId, session_id())]);
        }

        $syntheticFile = [
            'tmp_name' => $assembled,
            'name' => (string) $request->getBody('name', 'media'),
            'size' => (int) (filesize($assembled) ?: 0),
            'error' => UPLOAD_ERR_OK,
        ];

        try {
            $media = $this->mediaService->upload($album, $syntheticFile, $role, $email, AuthSession::getUserAccountId());
        } catch (GalleryException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        } finally {
            $this->chunkedUploadStore->discard($uploadId, session_id());
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
        if ($album === null || $album->isDelegated()) {
            return $this->json(['success' => false, 'error' => 'Album introuvable.'], 404);
        }

        [$role, $email] = $this->currentIdentity();
        $orderedIds = IntegerInput::idList($data['ordered_ids'] ?? []);
        if ($orderedIds === null) {
            return $this->json(['success' => false, 'error' => 'Identifiant invalide.'], 400);
        }

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
        if ($album === null || $album->isDelegated() || $media === null || $media->albumId !== $album->id) {
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
     * @return array<int, string> section id => display name
     */
    private function sectionLabels(): array
    {
        $labels = [];
        foreach ($this->sectionService->getAllWithBranches() as $section) {
            // desk_code is non-nullable in SectionService's own return
            // shape, so it is already the terminal fallback — a further
            // ?? '' after it is unreachable, which PHPStan reports.
            $labels[(int) $section['id']] = (string) ($section['name'] ?? $section['desk_code']);
        }

        return $labels;
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

    /**
     * Current + previous scout year ids — the same window
     * GalleryController applies to the family-facing list.
     *
     * @return int[]
     */
    private function recentScoutYearIds(): array
    {
        \assert($this->scoutYearService !== null);

        return $this->scoutYearService->currentAndPreviousYearIds();
    }
}
