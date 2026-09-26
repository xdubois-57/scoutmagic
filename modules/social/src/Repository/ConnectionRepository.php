<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Repository;

use Core\Security\EncryptionService;
use Core\Service\DateInput;
use Modules\Social\Api\SocialPlatform;

/**
 * `social_connections`: one row per platform, its secrets encrypted in one
 * column.
 *
 * The only class that encrypts or decrypts them. A secrets column that no
 * longer decrypts — a database restored onto an installation with other
 * keys — reads as « no secrets »: the page then offers to reconnect,
 * which is the one thing that repairs it.
 */
class ConnectionRepository
{
    private const CONTEXT = 'social_connections.secrets';

    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    public function find(SocialPlatform $platform): ?SocialConnection
    {
        $row = $this->row($platform);
        if ($row === null) {
            return null;
        }

        $secrets = $this->decrypt($row['secrets']);

        return new SocialConnection(
            $platform,
            (string) $row['app_id'],
            $secrets->appSecret !== '',
            $secrets->accessToken !== '',
            $secrets->pendingUserToken !== '',
            self::nullableString($row['account_id']),
            self::nullableString($row['account_name']),
            self::date($row['connected_at']),
            self::date($row['token_refreshed_at']),
            self::date($row['token_expires_at']),
            self::date($row['checked_at']),
            $row['check_ok'] === null ? null : (bool) $row['check_ok'],
        );
    }

    public function secretsOf(SocialPlatform $platform): ConnectionSecrets
    {
        $row = $this->row($platform);

        return $row === null ? new ConnectionSecrets() : $this->decrypt($row['secrets']);
    }

    /**
     * Records the unit's Meta app. A null secret keeps the one on file.
     *
     * **A different app id drops the connection**: a token belongs to the
     * app that obtained it, and keeping one beside another app's id would
     * show « Connectée » for a pairing that cannot work.
     */
    public function saveCredentials(SocialPlatform $platform, string $appId, ?string $appSecret): void
    {
        $current = $this->find($platform);
        $secrets = $this->secretsOf($platform);
        $sameApp = $current !== null && $current->appId === $appId;

        $kept = new ConnectionSecrets(
            $appSecret ?? $secrets->appSecret,
            $sameApp ? $secrets->accessToken : '',
            $sameApp ? $secrets->pendingUserToken : ''
        );

        if ($current === null) {
            $this->pdo->prepare(
                'INSERT INTO social_connections (platform, app_id, secrets) VALUES (?, ?, ?)'
            )->execute([$platform->value, $appId, $this->encrypt($kept)]);

            return;
        }

        $sql = $sameApp
            ? 'UPDATE social_connections SET app_id = ?, secrets = ? WHERE platform = ?'
            : 'UPDATE social_connections SET app_id = ?, secrets = ?, account_id = NULL, account_name = NULL,'
                . ' connected_at = NULL, token_refreshed_at = NULL, token_expires_at = NULL, checked_at = NULL,'
                . ' check_ok = NULL WHERE platform = ?';
        $this->pdo->prepare($sql)->execute([$appId, $this->encrypt($kept), $platform->value]);
    }

    /**
     * Holds the Facebook user token while the administrator picks one of
     * several Pages. The previous connection, if any, stays as it was
     * until they do.
     */
    public function holdPendingUserToken(SocialPlatform $platform, string $userToken): void
    {
        $secrets = $this->secretsOf($platform);
        $this->writeSecrets(
            $platform,
            new ConnectionSecrets($secrets->appSecret, $secrets->accessToken, $userToken)
        );
    }

    /**
     * Attaches an account and the token that acts for it; the pending user
     * token, if any, goes. Counts as a successful check.
     */
    public function connect(
        SocialPlatform $platform,
        string $accountId,
        string $accountName,
        string $accessToken,
        ?\DateTimeImmutable $expiresAt,
        \DateTimeImmutable $now
    ): void {
        $secrets = $this->secretsOf($platform);
        $stamp = $now->format('Y-m-d H:i:s');

        $this->pdo->prepare(
            'UPDATE social_connections SET account_id = ?, account_name = ?, secrets = ?, connected_at = ?,'
            . ' token_refreshed_at = ?, token_expires_at = ?, checked_at = ?, check_ok = 1 WHERE platform = ?'
        )->execute([
            $accountId,
            mb_substr($accountName, 0, 255),
            $this->encrypt(new ConnectionSecrets($secrets->appSecret, $accessToken, '')),
            $stamp,
            $stamp,
            $expiresAt?->format('Y-m-d H:i:s'),
            $stamp,
            $platform->value,
        ]);
    }

    /** A renewed token replaces the old one; the account does not change. */
    public function recordRefresh(
        SocialPlatform $platform,
        string $accessToken,
        ?\DateTimeImmutable $expiresAt,
        \DateTimeImmutable $now
    ): void {
        $secrets = $this->secretsOf($platform);

        $this->pdo->prepare(
            'UPDATE social_connections SET secrets = ?, token_refreshed_at = ?, token_expires_at = ?'
            . ' WHERE platform = ?'
        )->execute([
            $this->encrypt(new ConnectionSecrets($secrets->appSecret, $accessToken, $secrets->pendingUserToken)),
            $now->format('Y-m-d H:i:s'),
            $expiresAt?->format('Y-m-d H:i:s'),
            $platform->value,
        ]);
    }

    /** The outcome of the last check, and when it ran. The name Meta gave is kept current. */
    public function recordCheck(
        SocialPlatform $platform,
        bool $ok,
        \DateTimeImmutable $now,
        ?string $accountName = null
    ): void {
        $this->pdo->prepare(
            'UPDATE social_connections SET checked_at = ?, check_ok = ?, account_name = COALESCE(?, account_name)'
            . ' WHERE platform = ?'
        )->execute([
            $now->format('Y-m-d H:i:s'),
            $ok ? 1 : 0,
            $accountName === null ? null : mb_substr($accountName, 0, 255),
            $platform->value,
        ]);
    }

    /**
     * Forgets the platform entirely — app secret included. Keeping it « in
     * case » would defeat the point of the button.
     */
    public function delete(SocialPlatform $platform): void
    {
        $this->pdo->prepare('DELETE FROM social_connections WHERE platform = ?')->execute([$platform->value]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function row(SocialPlatform $platform): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM social_connections WHERE platform = ?');
        $stmt->execute([$platform->value]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function writeSecrets(SocialPlatform $platform, ConnectionSecrets $secrets): void
    {
        $this->pdo->prepare('UPDATE social_connections SET secrets = ? WHERE platform = ?')
            ->execute([$this->encrypt($secrets), $platform->value]);
    }

    private function encrypt(ConnectionSecrets $secrets): string
    {
        return $this->encryption->encrypt($secrets->toJson(), self::CONTEXT);
    }

    private function decrypt(mixed $stored): ConnectionSecrets
    {
        if (!is_string($stored) || $stored === '') {
            return new ConnectionSecrets();
        }

        try {
            return ConnectionSecrets::fromJson($this->encryption->decrypt($stored, self::CONTEXT));
        } catch (\Throwable) {
            return new ConnectionSecrets();
        }
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        return is_string($value) ? DateInput::fromStorage($value) : null;
    }
}
