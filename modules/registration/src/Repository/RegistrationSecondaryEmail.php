<?php

declare(strict_types=1);

namespace Modules\Registration\Repository;

/**
 * Mirrors Core\Member\MemberEmail's shape exactly (states, fields) — see
 * schema.sql's registration_secondary_emails comment for why this is a
 * module-local table rather than a generalization of member_emails.
 */
final class RegistrationSecondaryEmail
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_VALID = 'valid';
    public const STATUS_INACTIVE = 'inactive';

    public function __construct(
        public readonly int $id,
        public readonly int $registrationRequestId,
        public readonly string $email,
        public readonly string $status,
        public readonly ?string $confirmationTokenHash,
        public readonly ?\DateTimeImmutable $confirmationExpiresAt,
        public readonly ?\DateTimeImmutable $lastConfirmationSentAt,
        public readonly ?\DateTimeImmutable $confirmedAt,
        public readonly ?\DateTimeImmutable $deactivatedAt,
        public readonly \DateTimeImmutable $createdAt
    ) {
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isValid(): bool
    {
        return $this->status === self::STATUS_VALID;
    }

    public function isInactive(): bool
    {
        return $this->status === self::STATUS_INACTIVE;
    }
}
