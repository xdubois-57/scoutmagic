<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Repository;

use Modules\Social\Api\SocialPlatform;

/**
 * One platform's row, secrets excepted — what a screen may show.
 *
 * The three booleans say which secrets exist without carrying them: the
 * configuration page needs to know whether an app secret is on file
 * (« Laisser vide pour conserver le secret actuel ») and whether a
 * connection is complete, never what either says.
 */
final class SocialConnection
{
    public function __construct(
        public readonly SocialPlatform $platform,
        public readonly string $appId,
        public readonly bool $hasAppSecret,
        public readonly bool $hasAccessToken,
        public readonly bool $awaitsPageChoice,
        public readonly ?string $accountId,
        public readonly ?string $accountName,
        public readonly ?\DateTimeImmutable $connectedAt,
        public readonly ?\DateTimeImmutable $tokenRefreshedAt,
        public readonly ?\DateTimeImmutable $tokenExpiresAt,
        public readonly ?\DateTimeImmutable $checkedAt,
        public readonly ?bool $checkOk,
    ) {
    }

    /** An account is attached and a token is on file to act for it. */
    public function isConnected(): bool
    {
        return $this->hasAccessToken && $this->accountId !== null && $this->accountId !== '';
    }

    /** The token has a known end and it is behind us. */
    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->tokenExpiresAt !== null && $this->tokenExpiresAt <= $now;
    }

    /**
     * Connected, not expired, and not refused at the last check — the
     * one definition of « somewhere to publish » the Api answers with.
     */
    public function isUsable(\DateTimeImmutable $now): bool
    {
        return $this->isConnected() && !$this->isExpired($now) && $this->checkOk !== false;
    }
}
