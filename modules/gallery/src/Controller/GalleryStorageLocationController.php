<?php

declare(strict_types=1);

namespace Modules\Gallery\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Modules\Gallery\Repository\StorageLocation;
use Modules\Gallery\Repository\StorageLocationRepository;
use Modules\Gallery\Service\GalleryException;
use Modules\Gallery\Service\S3ErrorExplainerService;
use Modules\Gallery\Service\StorageLocationService;
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
        private S3ErrorExplainerService $s3ErrorExplainerService
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
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            return new Response('Jeton CSRF invalide.', 403);
        }

        $type = (string) $request->getBody('type', StorageLocation::TYPE_LOCAL) === StorageLocation::TYPE_S3
            ? StorageLocation::TYPE_S3
            : StorageLocation::TYPE_LOCAL;
        $label = trim((string) $request->getBody('label', ''));

        try {
            if ($label === '') {
                throw new GalleryException('Le nom de l\'emplacement est obligatoire.');
            }
            if ($this->storageLocationRepository->findByLabel($label) !== null) {
                throw new GalleryException('Ce nom d\'emplacement est déjà utilisé.');
            }

            if ($type === StorageLocation::TYPE_S3) {
                $id = $this->storageLocationRepository->create(
                    StorageLocation::TYPE_S3,
                    $label,
                    null,
                    $this->nullableProvider($request->getBody('s3_provider')),
                    (string) $request->getBody('s3_endpoint', ''),
                    (string) $request->getBody('s3_region', ''),
                    (string) $request->getBody('s3_bucket', ''),
                    (string) $request->getBody('s3_access_key', ''),
                    $this->nullableString($request->getBody('s3_public_url')),
                    $this->nullableString($request->getBody('s3_secret_key'))
                );
            } else {
                $subdir = trim((string) $request->getBody('subdir', ''));
                $id = $this->storageLocationRepository->create(
                    StorageLocation::TYPE_LOCAL,
                    $label,
                    $subdir !== '' ? $subdir : 'gallery',
                    null, null, null, null, null, null, null
                );
            }
        } catch (GalleryException $e) {
            $context = $this->formContext(null);
            $context['submit_error'] = $e->getMessage();
            return $this->render('@gallery/location_form.html.twig', $context)->setStatusCode(422);
        }

        $location = $this->storageLocationRepository->findById($id);
        if ($location !== null) {
            $this->storageLocationService->checkNow($location);
        }

        $this->journalService->log(
            'gallery', 'storage_location_created', 'info', "Emplacement de stockage « {$label} » créé",
            [], (int) AuthSession::getUserAccountId()
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
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            return new Response('Jeton CSRF invalide.', 403);
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
            $existingWithLabel = $this->storageLocationRepository->findByLabel($label);
            if ($existingWithLabel !== null && $existingWithLabel->id !== $location->id) {
                throw new GalleryException('Ce nom d\'emplacement est déjà utilisé.');
            }

            if ($location->isS3()) {
                $this->storageLocationRepository->update(
                    $location->id,
                    $label,
                    null,
                    $this->nullableProvider($request->getBody('s3_provider')),
                    (string) $request->getBody('s3_endpoint', ''),
                    (string) $request->getBody('s3_region', ''),
                    (string) $request->getBody('s3_bucket', ''),
                    (string) $request->getBody('s3_access_key', ''),
                    $this->nullableString($request->getBody('s3_public_url')),
                    $this->nullableString($request->getBody('s3_secret_key'))
                );
            } else {
                $subdir = trim((string) $request->getBody('subdir', ''));
                $this->storageLocationRepository->update(
                    $location->id, $label, $subdir !== '' ? $subdir : 'gallery',
                    null, null, null, null, null, null, null
                );
            }
        } catch (GalleryException $e) {
            $context = $this->formContext($location);
            $context['submit_error'] = $e->getMessage();
            return $this->render('@gallery/location_form.html.twig', $context)->setStatusCode(422);
        }

        $refreshed = $this->storageLocationRepository->findById($location->id);
        if ($refreshed !== null) {
            $this->storageLocationService->checkNow($refreshed);
        }

        $this->journalService->log(
            'gallery', 'storage_location_updated', 'info', "Emplacement de stockage « {$label} » modifié",
            [], (int) AuthSession::getUserAccountId()
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
            $this->storageLocationRepository->delete($id);
        } catch (GalleryException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        $this->journalService->log(
            'gallery', 'storage_location_deleted', 'info', "Emplacement de stockage « {$location->label} » supprimé",
            [], (int) AuthSession::getUserAccountId()
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
            'referenced_count' => $location !== null ? $this->storageLocationRepository->countAlbumsUsing($location->id) : 0,
            'gallery_s3_ai_available' => $this->s3ErrorExplainerService->isAvailable(),
            'csrf_token' => CsrfGuard::generateToken(),
        ];
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
}
