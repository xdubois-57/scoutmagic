<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

class UserAccount
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly ?string $firstName,
        public readonly ?string $lastName,
        public readonly ?string $passwordHash,
        public readonly bool $isSuperAdmin,
        public readonly ?\DateTimeImmutable $lastLoginAt,
        /**
         * Whether this account may still log in — see the
         * user_accounts.is_active column comment. Defaults to true so a
         * caller constructing an account by hand gets the ordinary case.
         */
        public readonly bool $isActive = true,
        public readonly ?\DateTimeImmutable $passwordChangedAt = null,
        /**
         * Sessions issued before this instant are revoked — see the
         * user_accounts.sessions_valid_from column comment and
         * Core\Security\SessionRevalidator.
         */
        public readonly ?\DateTimeImmutable $sessionsValidFrom = null,
        public readonly ?string $quietHoursStart = null,
        public readonly ?string $quietHoursEnd = null,
        public readonly bool $notificationDiscretion = false
    ) {
    }
}
