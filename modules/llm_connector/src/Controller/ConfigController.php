<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\LlmConnector\Controller;

use Core\Exception\UserFacingMessage;
use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Modules\LlmConnector\Api\LlmTier;
use Modules\LlmConnector\Provider\AnthropicProvider;
use Modules\LlmConnector\Provider\MistralProvider;
use Modules\LlmConnector\Provider\ModelProbeCache;
use Modules\LlmConnector\Provider\ScalewayProvider;
use Modules\LlmConnector\Repository\ProviderModelRepository;
use Modules\LlmConnector\Repository\ProviderRepository;
use Modules\LlmConnector\Service\LastFailureStore;
use Modules\LlmConnector\Service\OcrModelSelector;
use Modules\LlmConnector\Service\ProviderCatalogRefresher;
use Modules\LlmConnector\Service\TierModelResolver;
use Core\Scheduler\SchedulerService;
use Modules\LlmConnector\Task\RefreshModelsHandler;
use Twig\Environment;

class ConfigController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private ProviderRepository $providerRepo,
        private ProviderModelRepository $modelRepo,
        private OcrModelSelector $ocrModelSelector,
        private SchedulerService $schedulerService,
        private JournalService $journalService,
        /** Cleared by a passing test of the provider the site uses. */
        private ?LastFailureStore $lastFailure = null
    ) {
    }

    /**
     * GET /config/llm — configuration page.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        RefreshModelsHandler::bootstrap($this->schedulerService);

        $providers = $this->providerRepo->findAll();

        // Group providers and models by driver. On the rare install that
        // already carries two rows for one driver (nothing enforced
        // uniqueness before), keep the lowest id — that is the row
        // ProviderRepository::findFirstActive() and findByDriver() both pick,
        // so the page shows the provider actually in use rather than a
        // shadowed duplicate.
        $providersByDriver = [];
        $modelsByDriver = [];
        foreach ($providers as $provider) {
            $driver = $provider['driver'];
            if (isset($providersByDriver[$driver]) && $providersByDriver[$driver]['id'] <= $provider['id']) {
                continue;
            }
            $providersByDriver[$driver] = $provider;
            $modelsByDriver[$driver] = $this->modelRepo->findByProvider($provider['id']);
        }

        $activeProvider = null;
        $cheapModel = null;
        $capableModel = null;
        $ocrModel = null;

        foreach ($providers as $provider) {
            if ($provider['is_active']) {
                $activeProvider = $provider;
                $resolver = new TierModelResolver($this->modelRepo);
                $scope = $this->probeScope($provider['id']);
                $cheapModel = $resolver->resolve($provider['id'], $provider['driver'], LlmTier::CHEAP, $scope);
                $capableModel = $resolver->resolve($provider['id'], $provider['driver'], LlmTier::CAPABLE, $scope);
                $ocrModel = $resolver->resolve($provider['id'], $provider['driver'], LlmTier::OCR, $scope);
                break;
            }
        }

        return $this->render('@llm_connector/config/index.html.twig', [
            'providers_by_driver' => $providersByDriver,
            'models_by_driver' => $modelsByDriver,
            'active_provider' => $activeProvider,
            'cheap_model' => $cheapModel,
            'capable_model' => $capableModel,
            'ocr_model' => $ocrModel,
            'available_drivers' => $this->getAvailableDrivers(),
        ]);
    }

    /**
     * POST /config/llm/providers — save or create a provider.
     *
     * @param array<string, string> $params
     */
    public function saveProvider(Request $request, array $params): Response
    {
        $json = json_decode($request->getRawBody(), true);
        if (!is_array($json)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.']);
        }

        $csrfToken = (string) ($json['_csrf_token'] ?? '');
        if (($guard = $this->guardCsrfJson($request, $csrfToken)) !== null) {
            return $guard;
        }

        $name = trim((string) ($json['name'] ?? ''));
        $driver = trim((string) ($json['driver'] ?? ''));
        $apiEndpoint = trim((string) ($json['api_endpoint'] ?? ''));
        $apiKey = (string) ($json['api_key'] ?? '');
        $providerId = isset($json['id']) ? (int) $json['id'] : null;

        if ($name === '' || $driver === '' || $apiEndpoint === '') {
            return $this->json(['success' => false, 'error' => 'Tous les champs obligatoires doivent être remplis.']);
        }

        $validDrivers = array_column($this->getAvailableDrivers(), 'id');
        if (!in_array($driver, $validDrivers, true)) {
            return $this->json(['success' => false, 'error' => 'Driver invalide.']);
        }

        if (!$this->isValidEndpoint($apiEndpoint)) {
            return $this->json(['success' => false, 'error' => "L'adresse de l'API doit être une URL https valide."]);
        }

        // The page is one-provider-per-driver, but the client only sends an
        // id when its dropdown already knew about a saved provider. Without
        // this, a save that lost the id created a second row for the same
        // driver — invisible in the UI (which keys by driver) yet still
        // reachable by findFirstActive()'s id ASC ordering.
        if (($providerId === null || $providerId <= 0)) {
            $existing = $this->providerRepo->findByDriver($driver);
            if ($existing !== null) {
                $providerId = $existing['id'];
            }
        }

        // Every validation happens BEFORE the deactivate-then-activate block
        // below. Deactivating first meant a rejected save (missing API key,
        // unknown id) still turned every provider off: the administrator saw
        // an error, assumed nothing had changed, and every AI feature on the
        // site went quiet until the next successful save.
        if ($providerId !== null && $providerId > 0) {
            if ($this->providerRepo->findById($providerId) === null) {
                return $this->json(['success' => false, 'error' => 'Fournisseur introuvable.']);
            }
        } elseif ($apiKey === '') {
            return $this->json(['success' => false, 'error' => 'La clé API est obligatoire pour un nouveau '
                . 'fournisseur.']);
        }

        // "Exactly one active provider" is maintained by a deactivate-then-
        // activate pair, so the two writes belong in one transaction — a
        // failure between them would otherwise leave no provider active.
        $this->providerRepo->transactional(function () use (&$providerId, $name, $driver, $apiEndpoint, $apiKey): void {
            $this->providerRepo->deactivateAll();

            if ($providerId !== null && $providerId > 0) {
                // Update existing — apiKey null means "keep existing"
                $keyToStore = $apiKey !== '' ? $apiKey : null;
                $this->providerRepo->update($providerId, $name, $driver, $apiEndpoint, $keyToStore, true);
            } else {
                $providerId = $this->providerRepo->create($name, $driver, $apiEndpoint, $apiKey, true);
            }
        });

        $this->journalService->log(
            'llm_connector',
            'provider_saved',
            'info',
            "Fournisseur IA « {$name} » enregistré",
            ['provider_id' => $providerId, 'driver' => $driver],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true, 'provider_id' => $providerId]);
    }

    /**
     * POST /config/llm/providers/{id}/test — test connection to a provider.
     *
     * @param array<string, string> $params
     */
    public function testConnection(Request $request, array $params): Response
    {
        $json = json_decode($request->getRawBody(), true);
        if (!is_array($json)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.']);
        }

        $csrfToken = (string) ($json['_csrf_token'] ?? '');
        if (($guard = $this->guardCsrfJson($request, $csrfToken)) !== null) {
            return $guard;
        }

        $providerId = isset($params['id']) ? (int) $params['id'] : 0;
        $provider = $this->providerRepo->findById($providerId);

        if ($provider === null) {
            return $this->json(['success' => false, 'error' => 'Fournisseur introuvable.']);
        }

        // Re-validate at use time, not only at save time: a host that
        // resolved to a public address when saved could resolve to an
        // internal one now (DNS rebinding) — audit M5.
        if (!\Core\Security\SsrfUrlValidator::isPublicHttpsUrl((string) $provider['api_endpoint'])) {
            return $this->json(['success' => false, 'error' => 'Point de terminaison invalide.']);
        }

        $startedAt = \Core\Config\AppClock::now();
        try {
            $driver = $this->createDriver($provider['driver'], $provider['api_endpoint'], $provider['api_key']);
            $this->ocrModelSelector->setJournalService($this->journalService, AuthSession::getUserAccountId());
            $refresher = new ProviderCatalogRefresher(
                $this->modelRepo,
                $this->ocrModelSelector,
                $this->journalService,
                AuthSession::getUserAccountId()
            );
            $catalog = $refresher->refresh($provider['id'], $driver, $provider['configuration_token']);
            if ($provider['is_active'] && $refresher->needsRetry()) {
                RefreshModelsHandler::scheduleRetry($this->schedulerService, $provider['id'], $startedAt, 0);
            }
            $modelCount = count($catalog->chatModelIds());

            if ($modelCount === 0) {
                return $this->json([
                    'success' => false,
                    'error' => 'Le fournisseur répond, mais aucun modèle de conversation compatible n’a pu être '
                        . 'vérifié. Les fonctions IA sont indisponibles. Consultez le journal pour les exclusions.',
                ]);
            }

            $this->journalService->log(
                'llm_connector',
                'models_refreshed',
                'info',
                "Connexion testée et modèles compatibles rafraîchis ({$provider['name']}, {$modelCount} modèle(s))",
                ['provider_id' => $provider['id'], 'model_count' => $modelCount],
                AuthSession::getUserAccountId()
            );
            $this->lastFailure?->clearAfterPassingTest((int) $provider['id'], $this->providerRepo);

            // Fetch assigned tier models
            $resolver = new TierModelResolver($this->modelRepo);
            $scope = ModelProbeCache::scopeFor($provider['driver'], $provider['api_endpoint'], $provider['api_key']);
            $cheapModel = $resolver->resolve($provider['id'], $provider['driver'], LlmTier::CHEAP, $scope);
            $capableModel = $resolver->resolve($provider['id'], $provider['driver'], LlmTier::CAPABLE, $scope);
            $ocrModel = $resolver->resolve($provider['id'], $provider['driver'], LlmTier::OCR, $scope);

            return $this->json([
                'success' => true,
                'message' => "Connexion réussie — {$modelCount} modèle(s) de conversation compatible(s)."
                    . ($ocrModel === null ? ' La lecture des photos et des documents scannés est indisponible.' : ''),
                'provider_name' => $provider['name'],
                'cheap_model' => $cheapModel ? $cheapModel['display_name'] : null,
                'capable_model' => $capableModel ? $capableModel['display_name'] : null,
                'ocr_model' => $ocrModel ? $ocrModel['display_name'] : null,
                'ocr_fallback' => false,
            ]);
        } catch (\Throwable $e) {
            // A bare \Throwable here caught anything the driver stack threw
            // — including Api\LlmException, whose message IS written for the
            // admin, and a PDOException, whose message is not. Log only error
            // metadata here; remote payloads can echo submitted content.
            $this->journalService->log(
                'llm_connector',
                'test_connection_failed',
                'info',
                'Échec du test de connexion au fournisseur IA',
                [
                    'provider_id' => $provider['id'],
                    'error_type' => $e::class,
                    'error_code' => $e->getCode(),
                ],
                AuthSession::getUserAccountId()
            );

            if ($provider['is_active']) {
                RefreshModelsHandler::scheduleRetry($this->schedulerService, $provider['id'], $startedAt, 0);
            }

            return $this->json([
                'success' => false,
                'error' => UserFacingMessage::from(
                    $e,
                    "La connexion au fournisseur IA a échoué — vérifiez la clé d'API et l'adresse du service, puis "
                        . "réessayez."
                ),
            ]);
        }
    }

    /**
     * @return array<int, array{id: string, label: string, default_endpoint: string}>
     */
    private function getAvailableDrivers(): array
    {
        return [
            ['id' => 'anthropic', 'label' => 'Anthropic (Claude)', 'default_endpoint' => 'https://api.anthropic.com'],
            ['id' => 'mistral', 'label' => 'Mistral AI', 'default_endpoint' => 'https://api.mistral.ai'],
            ['id' => 'scaleway', 'label' => 'Scaleway', 'default_endpoint' => 'https://api.scaleway.ai'],
        ];
    }

    private function probeScope(int $providerId): ?string
    {
        try {
            $provider = $this->providerRepo->findById($providerId);
            return $provider === null ? null
                : ModelProbeCache::scopeFor($provider['driver'], $provider['api_endpoint'], $provider['api_key']);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The endpoint is concatenated into a URL and handed to the HTTP layer,
     * so it must be a real https URL with a host — not merely non-empty. The
     * dropdown only supplies a default client-side; the posted value is
     * whatever the client chose to send.
     */
    private function isValidEndpoint(string $apiEndpoint): bool
    {
        if (filter_var($apiEndpoint, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        // The endpoint is fetched server-side (the stored API key is sent to
        // it), so it must be a genuine public https host — never an internal
        // address a crafted endpoint could turn into an SSRF read primitive
        // (audit M5). Reuses the shared private-range guard.
        return \Core\Security\SsrfUrlValidator::isPublicHttpsUrl($apiEndpoint);
    }

    protected function createDriver(
        string $driver,
        string $apiEndpoint,
        string $apiKey
    ): \Modules\LlmConnector\Provider\LlmProviderInterface
    {
        return match ($driver) {
            'anthropic' => new AnthropicProvider($apiEndpoint, $apiKey),
            'mistral' => new MistralProvider($apiEndpoint, $apiKey),
            'scaleway' => new ScalewayProvider($apiEndpoint, $apiKey),
            default => throw new \RuntimeException("Unknown driver: {$driver}"),
        };
    }
}
