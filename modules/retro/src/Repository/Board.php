<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Retro\Repository;

class Board
{
    /** @var string[] */
    public const STATUSES = ['open', 'closed', 'archived'];

    /** @var string[] */
    public const VOTE_MODES = ['unlimited', 'budget'];

    /**
     * Who may open the board — Service\BoardAccess says what each means.
     *
     * @var string[]
     */
    public const ACCESS_MODES = ['animators', 'identified', 'public'];

    /** What a board is created with, by hand or from an event (issue #833). */
    public const DEFAULT_ACCESS_MODE = 'animators';

    /** @var string[] */
    public const AUTO_CLOSE_DELAYS = ['none', '2h', '24h', '3d', '7d'];

    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $boardDate,
        public readonly ?int $calendarEventId,
        public readonly string $token,
        public readonly ?string $shortCode,
        public readonly string $status,
        public readonly string $accessMode,
        public readonly string $voteMode,
        public readonly int $voteBudget,
        public readonly bool $votesVisible,
        public readonly int $maxCommentLength,
        public readonly string $autoCloseDelay,
        public readonly ?string $autoCloseAt,
        public readonly ?string $aiSummary,
        public readonly ?int $createdBy,
        public readonly bool $closeNotifyEnabled,
        public readonly ?string $closeNotifyEmail,
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
