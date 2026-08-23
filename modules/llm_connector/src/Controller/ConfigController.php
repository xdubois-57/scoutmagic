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
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmTier;
use Modules\LlmConnector\Provider\AnthropicProvider;
use Modules\LlmConnector\Provider\MistralProvider;
use Modules\LlmConnector\Provider\ScalewayProvider;
use Modules\LlmConnector\Repository\ProviderModelRepository;
use Modules\LlmConnector\Repository\ProviderRepository;
use Modules\LlmConnector\Service\OcrModelSelector;
use Core\Scheduler\SchedulerService;
use Twig\Environment;

class ConfigController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private ProviderRepository $providerRepo,
        private ProviderModelRepository $modelRepo,
        private OcrModelSelector $ocrModelSelector,
        private SchedulerService $schedulerService,
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
        // Ensure weekly refresh task is scheduled
        $this->ensureWeeklyRefreshScheduled();

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
            return $this->json(['success' => false, 'error' => 'La clé API est obligatoire pour un nouveau fournisseur.']);
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

            $this->ocrModelSelector->setJournalService($this->journalService, AuthSession::getUserAccountId());
            $tierMap = $this->ocrModelSelector->selectTiers($driver, $modelIds);
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
            // A bare \Throwable here caught anything the driver stack threw
            // — including Api\LlmException, whose message IS written for the
            // admin, and a PDOException, whose message is not. The helper is
            // what tells the two apart; the journal keeps the rest.
            $this->journalService->log(
                'llm_connector',
                'test_connection_failed',
                'info',
                'Échec du test de connexion au fournisseur IA',
                [
                    'provider_id' => $provider['id'],
                    'error' => $e->getMessage(),
                    'error_detail' => $e instanceof LlmException ? $e->detail : null,
                ],
                AuthSession::getUserAccountId()
            );

            return $this->json([
                'success' => false,
                'error' => UserFacingMessage::from(
                    $e,
                    "La connexion au fournisseur IA a échoué — vérifiez la clé d'API et l'adresse du service, puis réessayez."
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

    private function createDriver(string $driver, string $apiEndpoint, string $apiKey): \Modules\LlmConnector\Provider\LlmProviderInterface
    {
        return match ($driver) {
            'anthropic' => new AnthropicProvider($apiEndpoint, $apiKey),
            'mistral' => new MistralProvider($apiEndpoint, $apiKey),
            'scaleway' => new ScalewayProvider($apiEndpoint, $apiKey),
            default => throw new \RuntimeException("Unknown driver: {$driver}"),
        };
    }

    /**
     * Ensure a weekly model refresh task is scheduled.
     * If no future task exists, schedule one for 7 days from now.
     */
    private function ensureWeeklyRefreshScheduled(): void
    {
        $existing = $this->schedulerService->find('llm_connector', 'refresh_models', 'weekly');
        
        // If a pending task exists in the future, nothing to do
        if ($existing !== null && $existing['status'] === 'pending' && strtotime($existing['run_at']) > time()) {
            return;
        }

        // Schedule next refresh in 7 days
        $nextRun = new \DateTimeImmutable('+7 days');
        $this->schedulerService->schedule('llm_connector', 'refresh_models', $nextRun, [], 'weekly');
    }
}
