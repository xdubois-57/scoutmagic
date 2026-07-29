<?php

declare(strict_types=1);

namespace Modules\Retro\Repository;

class Board
{
    /** @var string[] */
    public const STATUSES = ['open', 'closed', 'archived'];

    /** @var string[] */
    public const VOTE_MODES = ['unlimited', 'budget'];

    /** @var string[] */
    public const ANTI_DUPLICATE_MODES = ['cookie', 'auth'];

    /** @var string[] */
    public const AUTO_CLOSE_DELAYS = ['none', '2h', '24h', '3d', '7d'];

    /** @var string[] */
    public const LINK_VISIBILITIES = ['identified', 'chief', 'unit_chief', 'superadmin'];

    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $boardDate,
        public readonly ?int $calendarEventId,
        public readonly string $token,
        public readonly ?string $shortCode,
        public readonly string $status,
        public readonly bool $listed,
        public readonly string $voteMode,
        public readonly int $voteBudget,
        public readonly bool $votesVisible,
        public readonly string $antiDuplicateMode,
        public readonly int $maxCommentLength,
        public readonly string $autoCloseDelay,
        public readonly ?string $autoCloseAt,
        public readonly ?string $aiSummary,
        public readonly ?int $createdBy,
        public readonly bool $closeNotifyEnabled,
        public readonly ?string $closeNotifyEmail,
        public readonly string $linkVisibility,
        public readonly string $createdAt,
        public readonly ?string $closedAt
    ) {
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }

    public function resultsRevealed(): bool
    {
        return $this->votesVisible || $this->status === 'closed';
    }
}
