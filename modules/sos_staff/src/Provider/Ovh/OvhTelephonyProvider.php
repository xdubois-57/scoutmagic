<?php

declare(strict_types=1);

namespace Modules\SosStaff\Provider\Ovh;

use Modules\SosStaff\Provider\ForwardingState;
use Modules\SosStaff\Provider\PhoneLine;
use Modules\SosStaff\Provider\PhoneProviderInterface;
use Modules\SosStaff\Provider\ProviderException;

/**
 * OVH Télécom implementation (module spec §7) — the only provider
 * implemented today, selected via Service\ProviderConfigService's factory.
 * Thin adapter over OvhApiClient: translates OVH's raw REST shape into the
 * generic PhoneProviderInterface contract, and OvhApiException into
 * ProviderException so callers never need to know OVH is involved.
 */
class OvhTelephonyProvider implements PhoneProviderInterface
{
    /** The two rights this module ever needs — requested at Consumer Key
     *  generation time (module spec §1.2 étape 2). */
    public const ACCESS_RULES = [
        ['method' => 'GET', 'path' => '/telephony*'],
        ['method' => 'PUT', 'path' => '/telephony*'],
    ];

    public function __construct(
        private OvhApiClient $client,
        private string $billingAccount,
        private string $serviceName
    ) {
    }

    public function readForwardingState(): ForwardingState
    {
        $options = $this->requestOrFail(fn() => $this->client->get($this->optionsPath()));

        $active = (bool) ($options['forwardUnconditional'] ?? false);
        $number = $active ? ($options['forwardUnconditionalNumber'] ?? null) : null;

        return new ForwardingState($active, $number !== null ? (string) $number : null);
    }

    public function setForwarding(string $number): void
    {
        $this->requestOrFail(fn() => $this->client->put($this->optionsPath(), [
            'forwardUnconditional' => true,
            'forwardUnconditionalNumber' => $number,
        ]));
    }

    public function testConnection(): bool
    {
        $this->readForwardingState();
        return true;
    }

    public function listLines(): array
    {
        $billingAccounts = $this->requestOrFail(fn() => $this->client->get('/telephony'));

        $lines = [];
        foreach ($billingAccounts as $billingAccount) {
            $billingAccount = (string) $billingAccount;
            $serviceNames = $this->requestOrFail(fn() => $this->client->get("/telephony/{$billingAccount}/line"));
            foreach ($serviceNames as $serviceName) {
                $lines[] = new PhoneLine($billingAccount, (string) $serviceName, (string) $serviceName);
            }
        }

        return $lines;
    }

    private function optionsPath(): string
    {
        return "/telephony/{$this->billingAccount}/line/{$this->serviceName}/options";
    }

    /**
     * @template T
     * @param \Closure(): T $call
     * @return T
     */
    private function requestOrFail(\Closure $call): mixed
    {
        try {
            return $call();
        } catch (OvhApiException $e) {
            throw new ProviderException($e->getMessage(), 0, $e);
        }
    }
}
