<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Statistics;

use Core\Config\SettingService;
use Core\Journal\JournalService;
use Core\Security\SecretManager;

/**
 * The installation's own identity for usage statistics (ARCHITECTURE.md
 * §8.47): a stable, opaque identifier and the shared secret that
 * authenticates its reports to the receiver.
 *
 * Both are generated lazily on first use and never derived from anything
 * about the unit — not the URL, not an email, not a name. The identifier is
 * a plain `settings` row (it is not a credential: it travels in every
 * payload in clear, and the receiver needs it to recognise the sender). The
 * secret is a credential and therefore lives **only** in `secrets.enc`,
 * exactly like `github_webhook_secret` (§8.17): never in `settings`, never
 * in the journal, never handed to a view.
 *
 * Lifecycle consequences, both intentional:
 * - a full reset (Core\Maintenance\Task\FullResetHandler) truncates
 *   `settings` and deletes `secrets.enc`, so the installation comes back
 *   with a brand-new identity and registers itself afresh on the receiver;
 * - restoring a backup restores both, so the identity is preserved.
 */
class InstallationIdentityService
{
    public const INSTALLATION_ID_SETTING = 'statistics_installation_id';
    public const SECRET_NAME = 'statistics_secret';

    private const INSTALLATION_ID_BYTES = 16;
    private const SECRET_BYTES = 32;

    public function __construct(
        private SettingService $settingService,
        private SecretManager $secretManager,
        private ?JournalService $journalService = null
    ) {
    }

    /**
     * The installation identifier: 32 lowercase hexadecimal characters.
     *
     * Generation is idempotent under concurrency: the value is claimed with
     * a single conditional UPDATE, and a caller that loses the race re-reads
     * and adopts whatever is actually persisted rather than keeping the
     * identifier it just generated.
     *
     * @throws StatisticsException when the setting row does not exist,
     *         i.e. the composition root never registered it — a wiring bug,
     *         deliberately loud rather than a silently unpersisted identity.
     */
    public function getInstallationId(): string
    {
        $current = $this->readInstallationId();
        if ($current !== null) {
            return $current;
        }

        $candidate = bin2hex(random_bytes(self::INSTALLATION_ID_BYTES));
        $claimed = $this->settingService->claimIfEmpty(self::INSTALLATION_ID_SETTING, $candidate);

        $persisted = $this->readInstallationId();
        if ($persisted !== null) {
            return $persisted;
        }

        if (!$claimed) {
            throw new StatisticsException(
                "Setting '" . self::INSTALLATION_ID_SETTING . "' is not registered."
            );
        }

        return $candidate;
    }

    /**
     * The shared secret authenticating this installation's reports, 64
     * lowercase hexadecimal characters — or null while the site is not
     * initialized yet.
     *
     * Returning null rather than throwing is deliberate: `secrets.enc` does
     * not exist during first-run setup, and neither the payload preview nor
     * the support page may fatal there. Every caller treats null as "no
     * report can be sent yet".
     */
    public function getSecret(): ?string
    {
        if (!$this->secretManager->isInitialized()) {
            return null;
        }

        try {
            $secrets = $this->secretManager->readSecrets();
        } catch (\Throwable) {
            return null;
        }

        $existing = $secrets[self::SECRET_NAME] ?? null;
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $secret = bin2hex(random_bytes(self::SECRET_BYTES));
        $secrets[self::SECRET_NAME] = $secret;

        try {
            $this->secretManager->writeSecrets($secrets);
        } catch (\Throwable) {
            return null;
        }

        return $secret;
    }

    /**
     * Regenerate both the identifier and the secret.
     *
     * The receiver treats an unknown identifier as a first registration, so
     * regenerating deliberately makes this installation appear as a new one
     * — which is exactly what an operator asking for it wants. Journaled at
     * `security` level; the secret itself is of course never journaled.
     */
    public function regenerate(): void
    {
        $this->settingService->setInternal(
            self::INSTALLATION_ID_SETTING,
            bin2hex(random_bytes(self::INSTALLATION_ID_BYTES))
        );

        if ($this->secretManager->isInitialized()) {
            $secrets = $this->secretManager->readSecrets();
            $secrets[self::SECRET_NAME] = bin2hex(random_bytes(self::SECRET_BYTES));
            $this->secretManager->writeSecrets($secrets);
        }

        $this->journalService?->log(
            'core',
            'statistics_identity_regenerated',
            'security',
            'Identité de cette installation régénérée pour les statistiques d\'utilisation'
        );
    }

    private function readInstallationId(): ?string
    {
        $value = $this->settingService->get(self::INSTALLATION_ID_SETTING);
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return preg_match('/^[0-9a-f]{32}$/', $value) === 1 ? $value : null;
    }
}
