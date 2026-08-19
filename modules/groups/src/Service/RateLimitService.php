<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Modules\Groups\Repository\RateLimitRepository;

/**
 * Per-member flood protection on posting and replying — the limit
 * deferred from prompt 4, following Modules\Retro\Service\RateLimitService
 * rather than inventing a second mechanism. Short-lived rows in a small
 * dedicated table, purged by Task\PurgeRateLimitHandler.
 *
 * Unlike retro's, the member id is used directly rather than hashed: see
 * discussion_group_rate_limits' own schema.sql comment — retro hashes
 * because anonymity is its whole feature, and a group post already
 * carries both author identities in plain form.
 *
 * Checked BEFORE the moderation call in Service\PostService and
 * Service\ReplyService, so an abusive burst never reaches the LLM.
 */
class RateLimitService
{
    private const WINDOW_MINUTES = 10;

    public const ACTION_POST = 'post';
    public const ACTION_REPLY = 'reply';

    /**
     * Deliberately generous: this exists to stop a runaway script or a
     * stuck submit button, not to ration a lively conversation. A member
     * in an active thread posts a handful of replies a minute at most.
     *
     * @var array<string, int>
     */
    private const LIMITS = [
        self::ACTION_POST => 15,
        self::ACTION_REPLY => 40,
    ];

    public function __construct(private RateLimitRepository $repository)
    {
    }

    /**
     * @throws GroupsException when this member has exceeded the limit for
     *         $actionType in the current window
     */
    public function checkAndRecord(int $memberId, string $actionType): void
    {
        $limit = self::LIMITS[$actionType] ?? PHP_INT_MAX;
        $since = (new \DateTimeImmutable('-' . self::WINDOW_MINUTES . ' minutes'))->format('Y-m-d H:i:s');

        if ($this->repository->countSince($memberId, $actionType, $since) >= $limit) {
            throw new GroupsException(
                'Vous avez publié beaucoup de messages coup sur coup — merci de patienter quelques instants.',
                GroupsException::TYPE_RATE_LIMITED
            );
        }

        $this->repository->record($memberId, $actionType);
    }
}
