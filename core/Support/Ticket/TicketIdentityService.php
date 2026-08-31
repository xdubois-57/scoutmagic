<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Ticket;

use Core\Config\SettingService;
use Core\Journal\JournalService;
use Core\Statistics\InstallationIdentityService;
use Core\Statistics\StatisticsSender;

/**
 * The identity and the destination a support ticket is sent with
 * (roadmap IT-24).
 *
 * **Support is not bought with data.** A unit that refused the daily usage
 * report has no installation id and no secret, and asking it to switch
 * telemetry on before it may report a bug would be exactly that trade.
 * So the identity is provisioned on the spot, from the same
 * `Core\Statistics\InstallationIdentityService` the report would have
 * used — the id is a `settings` row, the secret goes **only** into
 * `secrets.enc` — and `statistics_enabled` is not touched. Nobody asked
 * for a daily report; they asked to send one ticket.
 *
 * The receiver's side of that is trust-on-first-use, the same rule the
 * statistics intake applies, and the row it creates is marked as having no
 * telemetry so the dashboard does not read it as an installation gone
 * silent (ARCHITECTURE.md §8.49ter).
 *
 * **The guards are called, not copied.** Where the bearer secret is being
 * sent has to satisfy exactly what a report satisfies: HTTPS, and a real
 * public host — never `https://localhost/`, a bare IP or a single-label
 * name, which is a request leaving with our credential towards something
 * inside the hosting network. `StatisticsSender::isPublicHost()` is that
 * rule, and it stays the one implementation of it.
 */
class TicketIdentityService
{
    public const ENDPOINT_PATH = '/api/support/tickets';

    /** No destination is configured at all. */
    public const GUARD_NO_DESTINATION = 'no_destination';
    /** The destination is cleartext — the secret travels in a header. */
    public const GUARD_INSECURE_DESTINATION = 'insecure_destination';
    /** The destination is not a publicly-resolvable name. */
    public const GUARD_NON_PUBLIC_DESTINATION = 'non_public_destination';
    /** `secrets.enc` is unavailable, so no secret can be stored or read. */
    public const GUARD_SECRET_UNAVAILABLE = 'secret_unavailable';

    public function __construct(
        private SettingService $settingService,
        private InstallationIdentityService $identityService,
        private ?JournalService $journalService = null
    ) {
    }

    /**
     * The receiver's ticket endpoint, or null when no destination is
     * configured or the configured one fails a guard.
     */
    public function endpoint(): ?string
    {
        $destination = $this->destination();
        if ($destination === '' || $this->firstFailingGuard() !== null) {
            return null;
        }

        return $destination . self::ENDPOINT_PATH;
    }

    /**
     * The first guard the configured destination fails, or null when it
     * passes them all. The reason is a category, for a French message the
     * caller composes — never a detail about the host.
     */
    public function firstFailingGuard(): ?string
    {
        $destination = $this->destination();
        if ($destination === '') {
            return self::GUARD_NO_DESTINATION;
        }

        if (!str_starts_with(strtolower($destination), 'https://')) {
            return self::GUARD_INSECURE_DESTINATION;
        }

        if (!StatisticsSender::isPublicHost($destination)) {
            return self::GUARD_NON_PUBLIC_DESTINATION;
        }

        return null;
    }

    /**
     * The installation's identity, provisioning it if this is the first
     * time anything has needed one — **without enabling the daily report**.
     *
     * Returns null only when the secret cannot be stored at all
     * (`secrets.enc` missing during first-run setup), which is the one
     * case where nothing can be sent and the caller has to say so.
     */
    public function ensureIdentity(): ?TicketIdentity
    {
        // Read BEFORE asking for it: getInstallationId() provisions, so
        // asking first and comparing afterwards would always answer "it
        // was already there".
        $hadIdentity = (string) ($this->settingService->get(
            InstallationIdentityService::INSTALLATION_ID_SETTING
        ) ?? '') !== '';

        $installationId = $this->identityService->getInstallationId();
        $secret = $this->identityService->getSecret();

        if ($secret === null || $secret === '') {
            return null;
        }

        if (!$hadIdentity) {
            // Worth an entry: an identity appearing on an installation that
            // never reported is a fact an operator should be able to find
            // later, and the entry is also where "we did NOT switch
            // telemetry on" is written down. The secret is of course never
            // journaled.
            $this->journalService?->log(
                'core',
                'support_identity_provisioned',
                'info',
                "Identité d'installation créée pour l'envoi d'un ticket de support, sans activer l'envoi de statistiques",
                ['installation_id' => $installationId]
            );
        }

        return new TicketIdentity($installationId, $secret);
    }

    /** Whether the daily usage report is on. Read, never written here. */
    public function telemetryEnabled(): bool
    {
        return (string) ($this->settingService->get('statistics_enabled') ?? '0') === '1';
    }

    private function destination(): string
    {
        return rtrim(trim((string) ($this->settingService->get('statistics_destination') ?? '')), '/');
    }
}
