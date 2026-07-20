<?php

declare(strict_types=1);

namespace Modules\SosStaff\Provider;

/**
 * A telephony provider capable of managing unconditional call forwarding on
 * a single line — the abstraction module spec §7 asks for, so a second
 * provider (Twilio, ...) can be added later without touching the callers
 * (Service\ProviderConfigService's active-provider factory, Service\RedirectService).
 * Only one provider/line is ever active at a time (module spec §1.1/§7) —
 * this interface has no concept of "which line", it is always already
 * scoped to the configured one.
 */
interface PhoneProviderInterface
{
    /**
     * Read the forwarding state directly from the provider (never a
     * locally cached value) — used to display the real-time state (§2.2)
     * and for the anti-duplicate-change check (§4.1).
     *
     * @throws ProviderException on any provider/network failure
     */
    public function readForwardingState(): ForwardingState;

    /**
     * Configure unconditional forwarding to $number (E.164-ish
     * international format).
     *
     * @throws ProviderException on any provider/network failure
     */
    public function setForwarding(string $number): void;

    /**
     * Verify that the configured credentials + line are valid and
     * reachable. Returns true on success; throws with a human-readable
     * (French) message otherwise — callers surface that message directly.
     *
     * @throws ProviderException on failure, with a display-ready message
     */
    public function testConnection(): bool;

    /**
     * List the phone lines available on the account, for the
     * configuration page's line picker (§1.2 étape 3).
     *
     * @return PhoneLine[]
     * @throws ProviderException on any provider/network failure
     */
    public function listLines(): array;
}
