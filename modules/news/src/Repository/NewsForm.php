<?php

declare(strict_types=1);

namespace Modules\News\Repository;

final class NewsForm
{
    public const ACCESS_PUBLIC = 'public';
    public const ACCESS_IDENTIFIED = 'identified';

    public const RESPONSE_LIMIT_UNLIMITED = 'unlimited';
    public const RESPONSE_LIMIT_ONE_PER_ACCOUNT = 'one_per_account';
    public const RESPONSE_LIMIT_ONE_PER_MEMBER = 'one_per_member';

    public function __construct(
        public readonly int $id,
        public readonly int $newsArticleId,
        public readonly string $access,
        public readonly string $responseLimit,
        public readonly ?string $opensAt,
        public readonly ?string $closesAt,
        public readonly bool $isForceClosed,
        public readonly string $responseRoleMin,
        public readonly bool $dailyDigestEnabled,
        public readonly ?string $lastDigestSentAt,
        public readonly ?int $financeAccountId,
        public readonly string $createdAt
    ) {
    }

    /**
     * Effective open/closed state — always computed live, never stored
     * (module spec: no scheduled task "closes" a form).
     */
    public function isOpen(?\DateTimeImmutable $now = null): bool
    {
        if ($this->isForceClosed) {
            return false;
        }

        $now ??= new \DateTimeImmutable();
        $today = $now->format('Y-m-d');

        if ($this->opensAt !== null && $today < $this->opensAt) {
            return false;
        }
        if ($this->closesAt !== null && $today > $this->closesAt) {
            return false;
        }

        return true;
    }
}
