<?php

declare(strict_types=1);

namespace Core\Member;

/**
 * One row of member_emails — a secondary email address for a member
 * ('manual', member-added) or a mass-mail status override for the
 * Desk-imported address itself ('desk', see schema/core.sql for why that
 * source never affects login). $email is already decrypted by
 * MemberEmailRepository.
 */
class MemberEmail
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_DESK = 'desk';

    public const STATUS_PENDING = 'pending';
    public const STATUS_VALID = 'valid';
    public const STATUS_INACTIVE = 'inactive';

    public function __construct(
        public readonly int $id,
        public readonly int $memberId,
        public readonly string $email,
        public readonly string $source,
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

    public function isDeskSourced(): bool
    {
        return $this->source === self::SOURCE_DESK;
    }
}
