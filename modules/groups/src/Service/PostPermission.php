<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

/**
 * The answer to "may this session post in this group?", carrying enough
 * for the UI to say why not, in French, and where to go to fix it.
 * GroupAccessService::canPost() returns this rather than a bare bool
 * precisely so the composer can explain itself instead of silently
 * disappearing.
 */
final class PostPermission
{
    public const REASON_NOT_MEMBER = 'not_member';
    public const REASON_CLOSED = 'closed';
    public const REASON_PAST_YEAR = 'past_year';
    public const REASON_INCOMPLETE_PROFILE = 'incomplete_profile';

    /**
     * The group publishes by moderators only (discussion_groups.
     * posting_policy). The only refusal here that still leaves the member
     * every other way of taking part — commenting, reacting, answering a
     * poll — which is why the message says so.
     */
    public const REASON_MODERATORS_ONLY = 'moderators_only';

    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason = null,
        public readonly string $message = '',
        public readonly ?string $actionUrl = null,
        public readonly ?string $actionLabel = null
    ) {
    }

    public static function allow(): self
    {
        return new self(true);
    }

    public static function deny(
        string $reason,
        string $message,
        ?string $actionUrl = null,
        ?string $actionLabel = null
    ): self
    {
        return new self(false, $reason, $message, $actionUrl, $actionLabel);
    }
}
