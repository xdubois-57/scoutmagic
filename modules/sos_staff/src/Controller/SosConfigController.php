<?php

declare(strict_types=1);

namespace Modules\SosStaff\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Member\SectionService;
use Core\Member\UnitStaffSectionService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Modules\SosStaff\Provider\PhoneLine;
use Modules\SosStaff\Provider\ProviderException;
use Modules\SosStaff\Service\ProviderConfigService;
use Modules\SosStaff\Service\SosSettingsService;
use Twig\Environment;

/**
 * /config/sos — the OVH guided configuration flow (module spec §1) and the
 * excluded-sections picker (§1.4). role_min superadmin (Configuration menu)
 * since it holds real API credentials — distinct from /admin/sos (chief
 * d'unité, day-to-day duty planning).
 */
class SosConfigController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private ProviderConfigService $providerConfigService,
        private SosSettingsService $settingsService,
        private SectionService $sectionService,
        private JournalService $journalService
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $credential = $this->providerConfigService->getOvhCredential();
        $config = $credential?->config ?? [];

        // getExcludedSectionIds() ensures the STAFFDU section exists — it
        // must run before getAllWithBranches() so STAFFDU is actually in
        // the list to render (checked+disabled) in the picker below.
        $excludedIds = $this->settingsService->getExcludedSectionIds();
        $allSections = $this->sectionService->getAllWithBranches();

        return $this->render('@sos_staff/config.html.twig', [
            'provider_options' => $this->providerConfigService->getProviderOptions(),
            'has_application_credentials' => !empty($config['application_key']) && !empty($config['application_secret']),
            'has_pending_consumer_key' => !empty($config['consumer_key']) && ($config['consumer_key_validated'] ?? false) !== true,
            'consumer_key_validated' => ($config['consumer_key_validated'] ?? false) === true,
            'billing_account' => $config['billing_account'] ?? null,
            'service_name' => $config['service_name'] ?? null,
            'sos_number' => $config['sos_number'] ?? null,
            'is_active' => $credential?->isActive ?? false,
            'sections' => $allSections,
            'excluded_section_ids' => $excludedIds,
            'staffdu_desk_code' => UnitStaffSectionService::DESK_CODE,
        ]);
    }

    /**
     * Étape 1 (AJAX, JSON).
     *
     * @param array<string, string> $params
     */
    public function saveOvhCredentials(Request $request, array $params): Response
    {
        $data = $this->decodeAndAuthorize($request);
        if ($data instanceof Response) {
            return $data;
        }

        try {
            $this->providerConfigService->saveOvhCredentials(
                (string) ($data['application_key'] ?? ''),
                (string) ($data['application_secret'] ?? '')
            );
        } catch (ProviderException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        $this->journalService->log(
            'sos_staff',
            'ovh_credentials_saved',
            'security',
            'Identifiants OVH (Application Key/Secret) enregistrés',
            [],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true]);
    }

    /**
     * Étape 2a (AJAX, JSON).
     *
     * @param array<string, string> $params
     */
    public function generateConsumerKey(Request $request, array $params): Response
    {
        $data = $this->decodeAndAuthorize($request);
        if ($data instanceof Response) {
            return $data;
        }

        try {
            $validationUrl = $this->providerConfigService->generateConsumerKey();
        } catch (ProviderException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        $this->journalService->log(
            'sos_staff',
            'ovh_consumer_key_generated',
            'security',
            'Consumer Key OVH générée (en attente de validation)',
            [],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true, 'validation_url' => $validationUrl]);
    }

    /**
     * Étape 2b — "J'ai validé, vérifier" (AJAX, JSON).
     *
     * @param array<string, string> $params
     */
    public function validateConsumerKey(Request $request, array $params): Response
    {
        $data = $this->decodeAndAuthorize($request);
        if ($data instanceof Response) {
            return $data;
        }

        try {
            $this->providerConfigService->validateConsumerKey();
        } catch (ProviderException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        $this->journalService->log(
            'sos_staff',
            'ovh_consumer_key_validated',
            'security',
            'Consumer Key OVH validée',
            [],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true]);
    }

    /**
     * Étape 3a (AJAX, JSON).
     *
     * @param array<string, string> $params
     */
    public function listLines(Request $request, array $params): Response
    {
        $data = $this->decodeAndAuthorize($request);
        if ($data instanceof Response) {
            return $data;
        }

        try {
            $lines = $this->providerConfigService->listOvhLines();
        } catch (ProviderException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        return $this->json([
            'success' => true,
            'lines' => array_map(
                fn(PhoneLine $line) => [
                    'billing_account' => $line->billingAccount,
                    'service_name' => $line->serviceName,
                    'number' => $line->number,
                ],
                $lines
            ),
        ]);
    }

    /**
     * Étape 3b (AJAX, JSON).
     *
     * @param array<string, string> $params
     */
    public function selectLine(Request $request, array $params): Response
    {
        $data = $this->decodeAndAuthorize($request);
        if ($data instanceof Response) {
            return $data;
        }

        $billingAccount = (string) ($data['billing_account'] ?? '');
        $serviceName = (string) ($data['service_name'] ?? '');
        if ($billingAccount === '' || $serviceName === '') {
            return $this->json(['success' => false, 'error' => 'Ligne invalide.'], 400);
        }

        $this->providerConfigService->selectOvhLine($billingAccount, $serviceName);

        $this->journalService->log(
            'sos_staff',
            'ovh_line_selected',
            'info',
            'Ligne téléphonique OVH sélectionnée pour le SOS',
            [],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true, 'sos_number' => $this->providerConfigService->getSosNumber()]);
    }

    /**
     * "Tester la connexion" (AJAX, JSON).
     *
     * @param array<string, string> $params
     */
    public function testConnection(Request $request, array $params): Response
    {
        $data = $this->decodeAndAuthorize($request);
        if ($data instanceof Response) {
            return $data;
        }

        try {
            $this->providerConfigService->testConnection();
        } catch (ProviderException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        return $this->json(['success' => true]);
    }

    /**
     * Excluded-sections multi-select (AJAX, JSON) — module spec §1.4.
     *
     * @param array<string, string> $params
     */
    public function updateExcludedSections(Request $request, array $params): Response
    {
        $data = $this->decodeAndAuthorize($request);
        if ($data instanceof Response) {
            return $data;
        }

        $sectionIds = array_map('intval', is_array($data['section_ids'] ?? null) ? $data['section_ids'] : []);
        $this->settingsService->updateExcludedSections($sectionIds);

        $this->journalService->log(
            'sos_staff',
            'excluded_sections_updated',
            'info',
            'Sections exclues du planning SOS modifiées',
            [],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true]);
    }

    /**
     * @return array<string, mixed>|Response an array on success, or an
     *                                       error Response to return as-is
     */
    private function decodeAndAuthorize(Request $request): array|Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $csrf = (string) ($data['_csrf_token'] ?? '');
        if (!CsrfGuard::validateToken($csrf)) {
            return $this->json(['success' => false, 'error' => 'Jeton CSRF invalide.'], 403);
        }

        return $data;
    }
}
