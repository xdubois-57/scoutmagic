<?php

declare(strict_types=1);

namespace Modules\LlmConnector\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Modules\LlmConnector\Api\LlmTier;
use Modules\LlmConnector\Provider\AnthropicProvider;
use Modules\LlmConnector\Provider\MistralProvider;
use Modules\LlmConnector\Provider\ScalewayProvider;
use Modules\LlmConnector\Repository\ProviderModelRepository;
use Modules\LlmConnector\Repository\ProviderRepository;
use Twig\Environment;

class ConfigController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private ProviderRepository $providerRepo,
        private ProviderModelRepository $modelRepo,
        private JournalService $journalService
    ) {
    }

    /**
     * GET /config/llm — configuration page.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $providers = $this->providerRepo->findAll();

        // Group providers and models by driver
        $providersByDriver = [];
        $modelsByDriver = [];
        foreach ($providers as $provider) {
            $providersByDriver[$provider['driver']] = $provider;
            $modelsByDriver[$provider['driver']] = $this->modelRepo->findByProvider($provider['id']);
        }

        $activeProvider = null;
        $cheapModel = null;
        $capableModel = null;
        $ocrModel = null;

        foreach ($providers as $provider) {
            if ($provider['is_active']) {
                $activeProvider = $provider;
                $models = $this->modelRepo->findByProvider($provider['id']);
                foreach ($models as $model) {
                    if ($model['is_tier_cheap']) {
                        $cheapModel = $model;
                    }
                    if ($model['is_tier_capable']) {
                        $capableModel = $model;
                    }
                    if ($model['is_tier_ocr']) {
                        $ocrModel = $model;
                    }
                }
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
        if (!CsrfGuard::validateToken($csrfToken)) {
            return $this->json(['success' => false, 'error' => 'Jeton CSRF invalide.']);
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

        // Deactivate all providers first, then activate the one being saved
        $this->providerRepo->deactivateAll();

        if ($providerId !== null && $providerId > 0) {
            // Update existing — apiKey null means "keep existing"
            $keyToStore = $apiKey !== '' ? $apiKey : null;
            $this->providerRepo->update($providerId, $name, $driver, $apiEndpoint, $keyToStore, true);
        } else {
            if ($apiKey === '') {
                return $this->json(['success' => false, 'error' => 'La clé API est obligatoire pour un nouveau fournisseur.']);
            }
            $providerId = $this->providerRepo->create($name, $driver, $apiEndpoint, $apiKey, true);
        }

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
        if (!CsrfGuard::validateToken($csrfToken)) {
            return $this->json(['success' => false, 'error' => 'Jeton CSRF invalide.']);
        }

        $providerId = isset($params['id']) ? (int) $params['id'] : 0;
        $provider = $this->providerRepo->findById($providerId);

        if ($provider === null) {
            return $this->json(['success' => false, 'error' => 'Fournisseur introuvable.']);
        }

        try {
            $driver = $this->createDriver($provider['driver'], $provider['api_endpoint'], $provider['api_key']);
            $models = $driver->listModels();

            // Upsert models and auto-assign tiers
            $modelIds = [];
            foreach ($models as $model) {
                $this->modelRepo->upsert($provider['id'], $model['id'], $model['display_name']);
                $modelIds[] = $model['id'];
            }

            // Clean up: delete models not returned by API and stale models (>30 days)
            $this->modelRepo->deleteModelsNotIn($provider['id'], $modelIds);
            $this->modelRepo->deleteStaleModels($provider['id']);

            $tierMap = $driver->resolveTiers($modelIds);
            $this->modelRepo->autoAssignTiers($provider['id'], $tierMap);

            $this->journalService->log(
                'llm_connector',
                'models_refreshed',
                'info',
                "Connexion testée et modèles rafraîchis ({$provider['name']}, " . count($models) . ' modèle(s))',
                ['provider_id' => $provider['id'], 'model_count' => count($models)],
                AuthSession::getUserAccountId()
            );

            // Fetch assigned tier models
            $cheapModel = $this->modelRepo->findByProviderAndTier($provider['id'], LlmTier::CHEAP);
            $capableModel = $this->modelRepo->findByProviderAndTier($provider['id'], LlmTier::CAPABLE);
            $ocrModel = $this->modelRepo->findByProviderAndTier($provider['id'], LlmTier::OCR);

            return $this->json([
                'success' => true,
                'message' => 'Connexion réussie — ' . count($models) . ' modèle(s) trouvé(s).',
                'provider_name' => $provider['name'],
                'cheap_model' => $cheapModel ? $cheapModel['display_name'] : null,
                'capable_model' => $capableModel ? $capableModel['display_name'] : null,
                'ocr_model' => $ocrModel ? $ocrModel['display_name'] : ($cheapModel ? $cheapModel['display_name'] : null),
                'ocr_fallback' => $ocrModel === null && $cheapModel !== null,
            ]);
        } catch (\Throwable $e) {
            return $this->json([
                'success' => false,
                'error' => 'Échec de connexion : ' . $e->getMessage(),
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
            ['id' => 'scaleway', 'label' => 'Scaleway (EU)', 'default_endpoint' => 'https://api.scaleway.ai'],
        ];
    }

    private function createDriver(string $driver, string $apiEndpoint, string $apiKey): \Modules\LlmConnector\Provider\LlmProviderInterface
    {
        return match ($driver) {
            'anthropic' => new AnthropicProvider($apiEndpoint, $apiKey),
            'mistral' => new MistralProvider($apiEndpoint, $apiKey),
            'scaleway' => new ScalewayProvider($apiEndpoint, $apiKey),
            default => throw new \RuntimeException("Unknown driver: {$driver}"),
        };
    }
}
