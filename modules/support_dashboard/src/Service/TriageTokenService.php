<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Service;

use Core\Config\SettingService;
use Core\Journal\JournalService;

/**
 * The credential the automated GitHub triage presents to obtain the
 * anonymised extract of a ticket's archive (ARCHITECTURE.md §8.49sexies).
 *
 * **Only a hash is ever stored.** The token is 32 random bytes, shown once
 * to the superadmin who generated it — to be pasted into the repository's
 * `SUPPORT_TRIAGE_TOKEN` secret — and its SHA-256 goes into the
 * `support_triage_token_hash` setting. A fast hash rather than bcrypt for
 * the same reason `Modules\MassMail\Controller\UnsubscribeController`
 * uses one (SECURITY.md §4): with that much entropy there is nothing to
 * brute-force, and the route this guards is anonymous, so every wrong
 * guess should cost a comparison and not a bcrypt round.
 *
 * The setting is declared `secret` and `editable: false` in `module.json`
 * — invisible on Configuration > Réglages, `[REDACTED]` in a diagnostic
 * archive, writable only here. What is stored is not a credential
 * anyway, but a hash that reads like one is a hash somebody will copy
 * somewhere, and the type keeps it off every screen.
 */
class TriageTokenService
{
    public const SETTING_KEY = 'support_triage_token_hash';

    public const MODULE_ID = 'support_dashboard';

    /** 32 bytes, rendered as 64 hex characters — the shape `secrets.enc`'s bearer secrets have too. */
    private const TOKEN_BYTES = 32;

    public function __construct(
        private SettingService $settings,
        private JournalService $journal
    ) {
    }

    /**
     * Whether a token has been issued and not revoked. The route serves
     * nothing while this is false, and the tickets page says so.
     */
    public function isConfigured(): bool
    {
        return $this->storedHash() !== '';
    }

    /**
     * Generate a fresh token, store its hash, and return the token — the
     * only time it is ever readable. A previous token stops working at
     * once: there is one hash, so issuing is also rotating.
     */
    public function issue(): string
    {
        $token = bin2hex(random_bytes(self::TOKEN_BYTES));

        $this->settings->setInternal(self::SETTING_KEY, self::hashOf($token), self::MODULE_ID);

        $this->journal->log(
            'support_dashboard',
            'support_triage_token_issued',
            'security',
            'Jeton de triage GitHub généré : les extraits anonymisés peuvent être servis',
            []
        );

        return $token;
    }

    /**
     * Forget the hash. Every extract request then fails as unauthenticated
     * until a new token is issued — which is what to do the moment a token
     * is suspected to have leaked.
     */
    public function revoke(): void
    {
        $this->settings->setInternal(self::SETTING_KEY, '', self::MODULE_ID);

        $this->journal->log(
            'support_dashboard',
            'support_triage_token_revoked',
            'security',
            'Jeton de triage GitHub révoqué : plus aucun extrait ne sera servi',
            []
        );
    }

    /**
     * Constant-time comparison of the presented token against the stored
     * hash. False when nothing is configured, whatever is presented.
     */
    public function matches(string $presented): bool
    {
        $stored = $this->storedHash();
        if ($stored === '' || $presented === '') {
            return false;
        }

        return hash_equals($stored, self::hashOf($presented));
    }

    private function storedHash(): string
    {
        return trim((string) ($this->settings->get(self::SETTING_KEY, self::MODULE_ID) ?? ''));
    }

    private static function hashOf(string $token): string
    {
        return hash('sha256', $token);
    }
}
