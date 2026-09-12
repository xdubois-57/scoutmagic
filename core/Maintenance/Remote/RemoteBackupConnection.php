<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Remote;

use Core\Config\SettingService;
use Core\Security\SecretManager;

/**
 * Where the off-site destination's credentials live, and what the screen
 * is allowed to know about them.
 *
 * **The split is the whole class.** Two of the three values a Google
 * connection needs are secrets, and a `settings` row would put them on the
 * generic Configuration > Settings page — which renders a value as plain
 * text beside its key. So the client secret and the refresh token go to
 * `secrets.enc` through {@see SecretManager}, encrypted at rest with the
 * site's master key, exactly where the SMTP password and the VAPID private
 * key already are.
 *
 * The client ID stays in `settings`, deliberately and not by oversight: it
 * travels in the authorisation URL the operator's own browser follows, so
 * it is public by construction, and keeping it visible is what lets an
 * operator check they pasted the right one.
 *
 * Everything else here — the account, the folder, the state, the last
 * error — is what the screen reports, and none of it is a credential.
 */
final class RemoteBackupConnection
{
    public const PROVIDER_SETTING = 'remote_backup_provider';
    public const CLIENT_ID_SETTING = 'remote_backup_client_id';
    public const ACCOUNT_SETTING = 'remote_backup_account';
    public const FOLDER_SETTING = 'remote_backup_folder_id';
    public const CONNECTED_AT_SETTING = 'remote_backup_connected_at';
    public const STATE_SETTING = 'remote_backup_state';
    public const LAST_ERROR_SETTING = 'remote_backup_last_error';

    /**
     * The two keys inside `secrets.enc`.
     *
     * Public because the ratchet test
     * `tests/Core/Maintenance/Remote/RemoteBackupSecrecyTest.php` asserts
     * that neither name is ever registered as a setting — a rule that only
     * holds if both halves read the same list.
     *
     * @var string[]
     */
    public const SECRET_KEYS = ['remote_backup_client_secret', 'remote_backup_refresh_token'];

    public const PROVIDER_NONE = 'none';
    public const PROVIDER_GOOGLE_DRIVE = 'google_drive';

    /** Raccordé et utilisable pour autant qu'on sache. */
    public const STATE_CONNECTED = 'connected';

    /** Jamais raccordé, ou déraccordé volontairement. */
    public const STATE_DISCONNECTED = 'disconnected';

    /** Raccordé un jour, mais Google n'accepte plus l'autorisation. */
    public const STATE_NEEDS_REAUTH = 'needs_reauth';

    /** Where Google sends the browser back. Registered in the project too. */
    public const REDIRECT_PATH = '/config/maintenance/remote/callback';

    /** The folder this application creates in the operator's Drive. */
    public const FOLDER_NAME = 'ScoutMagic — sauvegardes';

    public function __construct(
        private readonly SettingService $settings,
        private readonly SecretManager $secrets
    ) {
    }

    /**
     * Declares the settings rows this feature owns.
     *
     * `editable: false` on every one of them: they are written by the
     * connection flow, and a value hand-edited on the generic settings
     * page would describe a connection that does not exist.
     */
    public static function register(SettingService $settings): void
    {
        $settings->register(self::PROVIDER_SETTING, self::PROVIDER_NONE, 'text',
            'Destination hors site', 'Le service distant raccordé pour les sauvegardes.', null, null, null, false, 300);
        $settings->register(self::CLIENT_ID_SETTING, '', 'text',
            'Identifiant client OAuth', 'Identifiant du client OAuth du projet Google de l\'unité.',
            null, null, null, false, 301);
        $settings->register(self::ACCOUNT_SETTING, '', 'text',
            'Compte distant raccordé', 'L\'adresse du compte Google actuellement raccordé.', null, null, null, false, 302);
        $settings->register(self::FOLDER_SETTING, '', 'text',
            'Dossier distant', 'Le dossier créé par ce site sur le service distant.', null, null, null, false, 303);
        $settings->register(self::CONNECTED_AT_SETTING, '', 'text',
            'Raccordé le', 'Date du dernier raccordement réussi.', null, null, null, false, 304);
        $settings->register(self::STATE_SETTING, self::STATE_DISCONNECTED, 'text',
            'État du raccordement', 'Raccordé, déraccordé, ou à reconnecter.', null, null, null, false, 305);
        $settings->register(self::LAST_ERROR_SETTING, '', 'text',
            'Dernier échec distant', 'La dernière raison pour laquelle le service distant a refusé.',
            null, null, null, false, 306);
    }

    public function state(): string
    {
        $state = (string) ($this->settings->get(self::STATE_SETTING) ?: self::STATE_DISCONNECTED);

        return in_array($state, [self::STATE_CONNECTED, self::STATE_DISCONNECTED, self::STATE_NEEDS_REAUTH], true)
            ? $state
            : self::STATE_DISCONNECTED;
    }

    public function isConnected(): bool
    {
        return $this->state() === self::STATE_CONNECTED && $this->refreshToken() !== '';
    }

    public function clientId(): string
    {
        return (string) ($this->settings->get(self::CLIENT_ID_SETTING) ?: '');
    }

    public function clientSecret(): string
    {
        return $this->secret('remote_backup_client_secret');
    }

    public function refreshToken(): string
    {
        return $this->secret('remote_backup_refresh_token');
    }

    public function account(): string
    {
        return (string) ($this->settings->get(self::ACCOUNT_SETTING) ?: '');
    }

    public function folderId(): string
    {
        return (string) ($this->settings->get(self::FOLDER_SETTING) ?: '');
    }

    public function connectedAt(): string
    {
        return (string) ($this->settings->get(self::CONNECTED_AT_SETTING) ?: '');
    }

    public function lastError(): string
    {
        return (string) ($this->settings->get(self::LAST_ERROR_SETTING) ?: '');
    }

    /**
     * This site's own address, for building the OAuth redirect URI.
     *
     * Read here rather than in the controller so the one place that knows
     * where these values live keeps knowing it.
     */
    public function baseUrl(): string
    {
        return (string) ($this->settings->get('base_url') ?: '');
    }

    /**
     * The address Google must send the browser back to, spelled exactly
     * once.
     *
     * It has to match the value registered in the operator's Google
     * project CHARACTER FOR CHARACTER — Google refuses the exchange on any
     * difference, a trailing slash included. So the screen shows this
     * string and the callback builds from this string: two spellings would
     * be a failure nobody could diagnose from either of them.
     */
    public function redirectUri(): string
    {
        return rtrim($this->baseUrl(), '/') . self::REDIRECT_PATH;
    }

    /** Whether the operator has entered a client ID and secret at all. */
    public function hasCredentials(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    public function saveCredentials(string $clientId, string $clientSecret): void
    {
        $this->settings->setInternal(self::CLIENT_ID_SETTING, trim($clientId));
        $this->writeSecrets(['remote_backup_client_secret' => trim($clientSecret)]);
    }

    /**
     * Records a successful connection.
     *
     * The refresh token is written LAST of the three, after the account
     * and the folder: {@see isConnected()} reads it, so a write interrupted
     * half way leaves a site that knows it is not connected rather than one
     * that believes it is and cannot say to what.
     */
    public function saveConnection(string $refreshToken, string $account, string $folderId): void
    {
        $this->settings->setInternal(self::PROVIDER_SETTING, self::PROVIDER_GOOGLE_DRIVE);
        $this->settings->setInternal(self::ACCOUNT_SETTING, $account);
        $this->settings->setInternal(self::FOLDER_SETTING, $folderId);
        $this->settings->setInternal(self::CONNECTED_AT_SETTING, date('c'));
        $this->settings->setInternal(self::LAST_ERROR_SETTING, '');
        $this->writeSecrets(['remote_backup_refresh_token' => $refreshToken]);
        $this->settings->setInternal(self::STATE_SETTING, self::STATE_CONNECTED);
    }

    /**
     * Marks the grant as gone, without forgetting which account it was.
     *
     * The account and the folder stay: an operator told « reconnectez le
     * compte » needs to know WHICH compte, and the folder id is still the
     * right one once the grant comes back. The token does not stay — it
     * cannot be used again, and a dead credential on disk is a credential
     * to leak for nothing.
     */
    public function markNeedsReauthorisation(string $reason): void
    {
        $this->settings->setInternal(self::STATE_SETTING, self::STATE_NEEDS_REAUTH);
        $this->settings->setInternal(self::LAST_ERROR_SETTING, $reason);
        $this->writeSecrets(['remote_backup_refresh_token' => '']);
    }

    public function recordFailure(string $reason): void
    {
        $this->settings->setInternal(self::LAST_ERROR_SETTING, $reason);
    }

    public function clearFailure(): void
    {
        $this->settings->setInternal(self::LAST_ERROR_SETTING, '');
    }

    /**
     * Forgets everything, including the client credentials.
     *
     * Disconnecting is what an operator does when they are handing the
     * site on, or when they no longer want it able to write into their
     * Drive. Keeping the client secret "in case" would defeat the point of
     * the button.
     */
    public function disconnect(): void
    {
        $this->settings->setInternal(self::PROVIDER_SETTING, self::PROVIDER_NONE);
        $this->settings->setInternal(self::STATE_SETTING, self::STATE_DISCONNECTED);
        $this->settings->setInternal(self::ACCOUNT_SETTING, '');
        $this->settings->setInternal(self::FOLDER_SETTING, '');
        $this->settings->setInternal(self::CONNECTED_AT_SETTING, '');
        $this->settings->setInternal(self::LAST_ERROR_SETTING, '');
        $this->settings->setInternal(self::CLIENT_ID_SETTING, '');
        $this->writeSecrets(['remote_backup_client_secret' => '', 'remote_backup_refresh_token' => '']);
    }

    private function secret(string $key): string
    {
        try {
            $secrets = $this->secrets->readSecrets();
        } catch (\Throwable) {
            // A site whose secrets cannot be read has a much larger problem
            // than its backup destination, and every caller here treats an
            // empty credential as "not connected" — which is the truthful
            // answer in that state.
            return '';
        }

        return is_string($secrets[$key] ?? null) ? (string) $secrets[$key] : '';
    }

    /**
     * Writes into `secrets.enc` without ever writing over what it cannot
     * read.
     *
     * **The read is not a convenience, it is the guard.** `secrets.enc`
     * holds one JSON document: the SMTP password, the column encryption
     * key, the VAPID private key and these two, all together. Writing it
     * means writing all of it. A site whose secrets have become unreadable
     * — a corrupted file, a master key replaced — is already in serious
     * trouble, and the one thing that would make it unrecoverable is this
     * method helpfully replacing the file with a fresh document containing
     * nothing but a cleared Drive token. So it refuses instead, and says
     * why.
     *
     * @param array<string, string> $values
     * @throws RemoteBackupException
     */
    private function writeSecrets(array $values): void
    {
        try {
            $secrets = $this->secrets->readSecrets();
        } catch (\Throwable $e) {
            throw RemoteBackupException::of(
                'Les secrets de ce site n\'ont pas pu être lus. Rien n\'a été modifié : les réécrire effacerait '
                . 'aussi le mot de passe SMTP et les clés de chiffrement.',
                $e
            );
        }

        foreach ($values as $key => $value) {
            if ($value === '') {
                unset($secrets[$key]);
                continue;
            }
            $secrets[$key] = $value;
        }
        $this->secrets->writeSecrets($secrets);
    }
}
