<?php

declare(strict_types=1);

namespace Modules\SosStaff\Service;

use Modules\SosStaff\Provider\Ovh\OvhApiClient;
use Modules\SosStaff\Provider\Ovh\OvhApiException;
use Modules\SosStaff\Provider\Ovh\OvhTelephonyProvider;
use Modules\SosStaff\Provider\PhoneLine;
use Modules\SosStaff\Provider\PhoneProviderInterface;
use Modules\SosStaff\Provider\ProviderException;
use Modules\SosStaff\Repository\ProviderCredential;
use Modules\SosStaff\Repository\ProviderCredentialRepository;

/**
 * Orchestrates the OVH guided configuration flow (module spec §1.2) and is
 * the factory for "the currently active provider" (§7) — every other
 * service that needs to talk to the telephony provider goes through
 * getActiveProvider(), never constructs a provider directly. Only one
 * provider is ever active (§1.1) — enforced by
 * ProviderCredentialRepository::setActive().
 */
class ProviderConfigService
{
    private const PROVIDER_OVH = 'ovh';

    /** Providers shown in the selector, in display order — only OVH is
     *  implemented; the rest are shown disabled with "bientôt" (§1.1). */
    public const AVAILABLE_PROVIDERS = [
        self::PROVIDER_OVH => 'OVH Télécom',
    ];
    public const PLANNED_PROVIDERS = [
        'twilio' => 'Twilio',
    ];

    /**
     * @param (\Closure(string, string, array<string, string>, ?string): array{status: int, body: string})|null $ovhTransport
     *        Injectable HTTP transport for every OvhApiClient this service
     *        builds — null in production (real cURL, see
     *        OvhApiClient::defaultTransport()); tests substitute a fake so
     *        the OVH config flow is verifiable without a network call.
     */
    public function __construct(
        private ProviderCredentialRepository $repository,
        private ?\Closure $ovhTransport = null
    ) {
    }

    /**
     * @return array<int, array{id: string, label: string, is_available: bool, is_active: bool}>
     */
    public function getProviderOptions(): array
    {
        $active = $this->repository->findActive();

        $options = [];
        foreach (self::AVAILABLE_PROVIDERS as $id => $label) {
            $options[] = [
                'id' => $id,
                'label' => $label,
                'is_available' => true,
                'is_active' => $active !== null && $active->provider === $id,
            ];
        }
        foreach (self::PLANNED_PROVIDERS as $id => $label) {
            $options[] = ['id' => $id, 'label' => $label, 'is_available' => false, 'is_active' => false];
        }

        return $options;
    }

    public function getOvhCredential(): ?ProviderCredential
    {
        return $this->repository->findByProvider(self::PROVIDER_OVH);
    }

    /**
     * Étape 1 — save the Application Key/Secret pasted from the OVH API
     * console. Merged into any existing config so a later step's fields
     * (consumer key, line) aren't lost.
     *
     * @throws ProviderException
     */
    public function saveOvhCredentials(string $applicationKey, string $applicationSecret): void
    {
        $applicationKey = trim($applicationKey);
        $applicationSecret = trim($applicationSecret);
        if ($applicationKey === '' || $applicationSecret === '') {
            throw new ProviderException('Application Key et Application Secret sont obligatoires.');
        }

        $this->mergeAndSave([
            'application_key' => $applicationKey,
            'application_secret' => $applicationSecret,
        ]);
    }

    /**
     * Étape 2a — request a fresh Consumer Key from OVH. Stored as
     * "pending" (consumer_key_validated: false) until validateConsumerKey()
     * confirms the admin has approved the rights on OVH's site. Returns
     * the validation URL to display.
     *
     * @throws ProviderException
     */
    public function generateConsumerKey(): string
    {
        $config = $this->requireOvhConfig(['application_key', 'application_secret']);
        $client = $this->buildClient((string) $config['application_key'], (string) $config['application_secret']);

        try {
            $result = $client->requestConsumerKey(OvhTelephonyProvider::ACCESS_RULES);
        } catch (OvhApiException $e) {
            throw new ProviderException($e->getMessage(), 0, $e);
        }

        $this->mergeAndSave([
            'consumer_key' => $result['consumerKey'],
            'consumer_key_validated' => false,
        ]);

        return $result['validationUrl'];
    }

    /**
     * Étape 2b — "J'ai validé, vérifier" : confirm the pending Consumer Key
     * actually works (the admin must have approved it on OVH's site
     * first — an unapproved key fails every authenticated call).
     *
     * @throws ProviderException
     */
    public function validateConsumerKey(): bool
    {
        $config = $this->requireOvhConfig(['application_key', 'application_secret', 'consumer_key']);
        $client = $this->buildClient(
            (string) $config['application_key'],
            (string) $config['application_secret'],
            (string) $config['consumer_key']
        );

        try {
            $client->get('/telephony');
        } catch (OvhApiException $e) {
            throw new ProviderException("La Consumer Key n'est pas (encore) validée : {$e->getMessage()}", 0, $e);
        }

        $this->mergeAndSave(['consumer_key_validated' => true]);
        return true;
    }

    /**
     * Étape 3a — list the account's phone lines for the picker.
     *
     * @return PhoneLine[]
     * @throws ProviderException
     */
    public function listOvhLines(): array
    {
        $config = $this->requireOvhConfig(['application_key', 'application_secret', 'consumer_key']);
        if (($config['consumer_key_validated'] ?? false) !== true) {
            throw new ProviderException('La Consumer Key doit être validée avant de récupérer les lignes.');
        }

        $client = $this->buildClient(
            (string) $config['application_key'],
            (string) $config['application_secret'],
            (string) $config['consumer_key']
        );

        // Not yet a specific line — listLines() doesn't need one.
        return (new OvhTelephonyProvider($client, '', ''))->listLines();
    }

    /**
     * Étape 3b — persist the chosen line and activate OVH as the provider
     * (module spec §1.1/§7: only one provider/number active at a time).
     * The SOS number shown to parents is derived from the line's service
     * name (§1.2 étape 3).
     */
    public function selectOvhLine(string $billingAccount, string $serviceName): void
    {
        $this->mergeAndSave([
            'billing_account' => $billingAccount,
            'service_name' => $serviceName,
            'sos_number' => $serviceName,
        ]);
        $this->repository->setActive(self::PROVIDER_OVH);
    }

    /**
     * The number displayed to admins/parents (module spec §2.1) — null
     * until a line has been selected.
     */
    public function getSosNumber(): ?string
    {
        $active = $this->repository->findActive();
        if ($active === null) {
            return null;
        }
        $number = $active->config['sos_number'] ?? null;
        return is_string($number) && $number !== '' ? $number : null;
    }

    /**
     * The factory every other SOS service must go through to talk to the
     * telephony provider (module spec §7) — null when nothing is fully
     * configured/active yet, which every caller must handle gracefully
     * rather than crash (resilience note: provider unavailable/not
     * configured is an expected, displayable state, not an error page).
     */
    public function getActiveProvider(): ?PhoneProviderInterface
    {
        $active = $this->repository->findActive();
        if ($active === null) {
            return null;
        }

        return match ($active->provider) {
            self::PROVIDER_OVH => $this->buildOvhProvider($active->config),
            default => null,
        };
    }

    /**
     * @throws ProviderException
     */
    public function testConnection(): bool
    {
        $provider = $this->getActiveProvider();
        if ($provider === null) {
            throw new ProviderException('Aucun fournisseur actif configuré.');
        }
        return $provider->testConnection();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function buildOvhProvider(array $config): ?PhoneProviderInterface
    {
        $applicationKey = $config['application_key'] ?? null;
        $applicationSecret = $config['application_secret'] ?? null;
        $consumerKey = $config['consumer_key'] ?? null;
        $billingAccount = $config['billing_account'] ?? null;
        $serviceName = $config['service_name'] ?? null;

        if (!is_string($applicationKey) || !is_string($applicationSecret) || !is_string($consumerKey)
            || !is_string($billingAccount) || !is_string($serviceName)
            || $applicationKey === '' || $applicationSecret === '' || $consumerKey === ''
            || $billingAccount === '' || $serviceName === '') {
            return null;
        }

        $client = $this->buildClient($applicationKey, $applicationSecret, $consumerKey);
        return new OvhTelephonyProvider($client, $billingAccount, $serviceName);
    }

    private function buildClient(string $applicationKey, string $applicationSecret, ?string $consumerKey = null): OvhApiClient
    {
        return new OvhApiClient($applicationKey, $applicationSecret, $consumerKey, transport: $this->ovhTransport);
    }

    /**
     * @param string[] $requiredKeys
     * @return array<string, mixed>
     * @throws ProviderException
     */
    private function requireOvhConfig(array $requiredKeys): array
    {
        $credential = $this->repository->findByProvider(self::PROVIDER_OVH);
        $config = $credential?->config ?? [];

        foreach ($requiredKeys as $key) {
            if (empty($config[$key])) {
                throw new ProviderException('Configuration OVH incomplète (étape précédente manquante).');
            }
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $partial
     */
    private function mergeAndSave(array $partial): void
    {
        $existing = $this->repository->findByProvider(self::PROVIDER_OVH)?->config ?? [];
        $this->repository->save(self::PROVIDER_OVH, array_merge($existing, $partial));
    }
}
