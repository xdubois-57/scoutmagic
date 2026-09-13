<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\Config\LocationConfig;
use Core\Storage\Location\Config\ObjectStorageLocationConfig;
use Core\Storage\Location\StorageLocation;
use Core\Storage\Location\StorageLocationException;
use Core\Storage\Location\StorageLocationRepository;
use Core\Storage\Location\StorageLocationService;
use Core\Storage\Location\StorageLocationType;
use Modules\Gallery\Api\GalleryException;
use Modules\Gallery\Service\ObjectStorageErrorExplainerService;
use Twig\Environment;

class GalleryStorageLocationController extends AbstractController
{
    /** @var string[] */
    public const S3_PROVIDERS = ['hetzner', 'cloudflare_r2', 'scaleway', 'ovhcloud', 'custom'];

    public function __construct(
        protected Environment $twig,
        private StorageLocationRepository $storageLocationRepository,
        private StorageLocationService $storageLocationService,
        private JournalService $journalService,
        private ObjectStorageErrorExplainerService $s3ErrorExplainerService
    ) {
    }

    /**
     * GET /config/gallery/locations/new
     *
     * @param array<string, string> $params
     */
    public function create(Request $request, array $params): Response
    {
        return $this->render('@gallery/location_form.html.twig', $this->formContext(null));
    }

    /**
     * POST /config/gallery/locations
     *
     * @param array<string, string> $params
     */
    public function store(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/config/gallery/locations/new')) !== null) {
            return $guard;
        }

        $type = (string) $request->getBody('type', StorageLocationType::Local->value)
            === StorageLocationType::ObjectStorage->value
                ? StorageLocationType::ObjectStorage
                : StorageLocationType::Local;
        $label = trim((string) $request->getBody('label', ''));

        try {
            if ($label === '') {
                throw new GalleryException('Le nom de l\'emplacement est obligatoire.');
            }

            $id = $this->storageLocationService->create(
                $type,
                $label,
                $this->configFromRequest($type, $request),
                $type === StorageLocationType::ObjectStorage
                    ? $this->nullableString($request->getBody('s3_secret_key'))
                    : null
            );
        } catch (GalleryException | StorageLocationException $e) {
            $context = $this->formContext(null);
            $context['submit_error'] = $e->getMessage();
            return $this->render('@gallery/location_form.html.twig', $context)->setStatusCode(422);
        }

        $location = $this->storageLocationRepository->findById($id);
        if ($location !== null) {
            $this->storageLocationService->checkNow($location);
        }

        $this->journalService->log(
            'gallery',
            'storage_location_created',
            'info',
            "Emplacement de stockage « {$label} » créé",
            [],
            (int) AuthSession::getUserAccountId()
        );

        return $this->redirect('/config/gallery');
    }

    /**
     * GET /config/gallery/locations/{id}/edit
     *
     * @param array<string, string> $params
     */
    public function edit(Request $request, array $params): Response
    {
        $location = $this->storageLocationRepository->findById((int) $params['id']);
        if ($location === null) {
            return new Response('Not Found', 404);
        }

        return $this->render('@gallery/location_form.html.twig', $this->formContext($location));
    }

    /**
     * POST /config/gallery/locations/{id} — the type is immutable once
     * created (module spec: an album's location, and by extension the
     * location's own storage kind, never changes after the fact) — only
     * label/connection details can be edited here.
     *
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf(
            $request,
            '/config/gallery/locations/' . (int) ($params['id'] ?? 0) . '/edit'
        )) !== null) {
            return $guard;
        }

        $location = $this->storageLocationRepository->findById((int) $params['id']);
        if ($location === null) {
            return new Response('Not Found', 404);
        }

        $label = trim((string) $request->getBody('label', ''));

        try {
            if ($label === '') {
                throw new GalleryException('Le nom de l\'emplacement est obligatoire.');
            }
            $this->storageLocationService->update(
                $location->id,
                $label,
                $this->configFromRequest($location->type, $request),
                $location->type === StorageLocationType::ObjectStorage
                    ? $this->nullableString($request->getBody('s3_secret_key'))
                    : null
            );
        } catch (GalleryException | StorageLocationException $e) {
            $context = $this->formContext($location);
            $context['submit_error'] = $e->getMessage();
            return $this->render('@gallery/location_form.html.twig', $context)->setStatusCode(422);
        }

        $refreshed = $this->storageLocationRepository->findById($location->id);
        if ($refreshed !== null) {
            $this->storageLocationService->checkNow($refreshed);
        }

        $this->journalService->log(
            'gallery',
            'storage_location_updated',
            'info',
            "Emplacement de stockage « {$label} » modifié",
            [],
            (int) AuthSession::getUserAccountId()
        );

        return $this->redirect('/config/gallery');
    }

    /**
     * POST /config/gallery/locations/{id}/delete
     *
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data) || !CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $id = (int) $params['id'];
        $location = $this->storageLocationRepository->findById($id);
        if ($location === null) {
            return $this->json(['success' => false, 'error' => 'Emplacement introuvable.'], 404);
        }

        try {
            $this->storageLocationService->delete($id);
        } catch (StorageLocationException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        $this->journalService->log(
            'gallery',
            'storage_location_deleted',
            'info',
            "Emplacement de stockage « {$location->label} » supprimé",
            [],
            (int) AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true]);
    }

    /**
     * POST /config/gallery/locations/{id}/default — promotes this location
     * to the sole default, pre-selected for new local albums.
     *
     * @param array<string, string> $params
     */
    public function setDefault(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data) || !CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $location = $this->storageLocationRepository->findById((int) $params['id']);
        if ($location === null) {
            return $this->json(['success' => false, 'error' => 'Emplacement introuvable.'], 404);
        }

        $this->storageLocationService->setDefault($location->id);

        $this->journalService->log(
            'gallery',
            'storage_location_default_changed',
            'info',
            "Emplacement de stockage « {$location->label} » "
                . "défini par défaut",
            [],
            (int) AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true]);
    }

    /**
     * POST /config/gallery/locations/{id}/test — forces an immediate
     * (non-cached) health check and returns the fresh result, for the
     * config page's per-row "Tester" button.
     *
     * @param array<string, string> $params
     */
    public function test(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data) || !CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $location = $this->storageLocationRepository->findById((int) $params['id']);
        if ($location === null) {
            return $this->json(['success' => false, 'error' => 'Emplacement introuvable.'], 404);
        }

        $this->storageLocationService->checkNow($location);
        $refreshed = $this->storageLocationRepository->findById($location->id);

        return $this->json([
            'success' => true,
            'ok' => $refreshed?->lastCheckOk,
            'error' => $refreshed?->lastCheckError,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formContext(?StorageLocation $location): array
    {
        return [
            'location' => $location,
            // What still stands on this location, by name — « Galeries
            // photo ». A count would only say « 3 » and leave the
            // administrator to guess three what.
            'usages' => $location !== null ? $this->storageLocationService->usagesOf($location->id) : [],
            'gallery_s3_ai_available' => $this->s3ErrorExplainerService->isAvailable(),
            // One spelling of « the default folder », shared with
            // StorageLocationService::ensureDefaultExists() and with
            // normalizeSubdir()'s blank-submit fallback. Two spellings is
            // how a form accepted as-is creates a location pointing at a
            // different directory from the one the site already made,
            // both presented as the default local storage.
            'default_local_path' => StorageLocationService::DEFAULT_PATH,
            'csrf_token' => CsrfGuard::generateToken(),
        ];
    }

    /**
     * The local sub-directory is joined onto the storage root by
     * Core\Storage\Location\Backend\StorageBackendFactory, so it is
     * validated here rather than trusted: a value like "../../public" would
     * put every rendition inside the webroot. Kept to plain relative
     * segments — the backend also refuses to resolve outside its own
     * directory, this just makes the refusal a readable message at the
     * point of entry.
     *
     * @throws GalleryException on an absolute path, a parent-directory hop, or
     *                           a character outside [A-Za-z0-9._-] and "/"
     */
    private function normalizeSubdir(mixed $value): string
    {
        $raw = trim((string) ($value ?? ''));
        // Rejected rather than silently reinterpreted as relative: an admin who
        // typed "/etc" meant an absolute path, and quietly storing "etc"
        // (i.e. storage/etc) is a surprise, not a fix.
        if (str_starts_with($raw, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $raw) === 1) {
            throw new GalleryException('Le sous-dossier doit être un chemin relatif, sans « / » au début.');
        }

        $subdir = trim($raw, " \t\n\r\0\x0B/");
        if ($subdir === '') {
            // The SAME folder ensureDefaultExists() creates, not a second
            // spelling of « the default ». They were 'gallery' here and
            // 'modules/gallery' there, so accepting this form blank
            // produced a location pointing at a different directory from
            // the one the site had already made — both presented as the
            // default local storage.
            return StorageLocationService::DEFAULT_PATH;
        }
        if (mb_strlen($subdir) > 255) {
            throw new GalleryException('Le sous-dossier ne peut pas dépasser 255 caractères.');
        }

        foreach (explode('/', $subdir) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || preg_match(
                '/^[A-Za-z0-9._-]+$/',
                $segment
            ) !== 1) {
                throw new GalleryException(
                    'Le sous-dossier ne peut contenir que des lettres, chiffres, points, tirets et « / ».'
                );
            }
        }

        return $subdir;
    }

    /**
     * The per-type configuration record the form just described.
     *
     * One method for both create and update, because the two used to hold
     * two copies of the same ten-argument call and the S3 endpoint check
     * had already been forgotten in one of them once.
     *
     * @throws GalleryException on a sub-directory or an endpoint the site refuses
     */
    private function configFromRequest(StorageLocationType $type, Request $request): LocationConfig
    {
        if ($type !== StorageLocationType::ObjectStorage) {
            return new LocalLocationConfig($this->normalizeSubdir($request->getBody('subdir')));
        }

        $endpoint = (string) $request->getBody('s3_endpoint', '');
        $this->assertValidS3Endpoint($endpoint);

        return new ObjectStorageLocationConfig(
            endpoint: $endpoint,
            region: (string) $request->getBody('s3_region', ''),
            bucket: (string) $request->getBody('s3_bucket', ''),
            accessKey: (string) $request->getBody('s3_access_key', ''),
            provider: $this->nullableProvider($request->getBody('s3_provider')),
            publicUrl: $this->nullableString($request->getBody('s3_public_url'))
        );
    }

    private function nullableProvider(mixed $value): string
    {
        $value = (string) ($value ?? '');
        return in_array($value, self::S3_PROVIDERS, true) ? $value : 'custom';
    }

    private function nullableString(mixed $value): ?string
    {
        $value = (string) ($value ?? '');
        return trim($value) === '' ? null : $value;
    }

    /**
     * The S3 endpoint is connected to server-side with the access key/secret,
     * so it must be a genuine public https host: never http:// (plaintext
     * credentials) and never an internal address a crafted value could turn
     * into an SSRF target (audit M6). A custom port is allowed since
     * S3-compatible providers vary. Runtime storage reads/writes trust the
     * stored value, so this is validated at save time, not only on test.
     *
     * @throws GalleryException
     */
    private function assertValidS3Endpoint(string $endpoint): void
    {
        if (!\Core\Security\SsrfUrlValidator::isPublicHttpsUrl($endpoint, true)) {
            throw new GalleryException('L\'adresse du service S3 doit être une URL https publique.');
        }
    }
}
