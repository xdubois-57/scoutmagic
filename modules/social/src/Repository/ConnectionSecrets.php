<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Repository;

/**
 * The encrypted half of a row, decrypted.
 *
 * Three values, every one of which lets whoever holds it act as the unit
 * on Meta: the app secret, the token in use (a Page token, or the
 * Instagram user token), and — only between the Facebook consent and the
 * choice of a Page — the long-lived user token that lists the Pages. The
 * last one is dropped as soon as a Page is chosen: nothing needs it after.
 *
 * Never logged, never rendered, never put in an exception message.
 */
final class ConnectionSecrets
{
    public function __construct(
        public readonly string $appSecret = '',
        public readonly string $accessToken = '',
        public readonly string $pendingUserToken = '',
    ) {
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return new self();
        }

        return new self(
            is_string($data['app_secret'] ?? null) ? $data['app_secret'] : '',
            is_string($data['access_token'] ?? null) ? $data['access_token'] : '',
            is_string($data['pending_user_token'] ?? null) ? $data['pending_user_token'] : '',
        );
    }

    public function toJson(): string
    {
        return (string) json_encode([
            'app_secret' => $this->appSecret,
            'access_token' => $this->accessToken,
            'pending_user_token' => $this->pendingUserToken,
        ]);
    }
}
