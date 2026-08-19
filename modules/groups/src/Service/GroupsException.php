<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

/**
 * $type lets the Controller distinguish the cases the composer reacts to
 * differently — a moderation flag renders the suggested rewording inline
 * and keeps the member's draft, a rate limit just asks them to wait —
 * without parsing the message string. Same shape and reasoning as
 * Modules\Retro\Service\RetroException.
 *
 * $suggestion carries the AI's proposed rewording and is the ONLY place
 * any part of a rejected message travels. It is shown straight back to
 * its own author and never stored, journaled or logged (module spec).
 */
class GroupsException extends \RuntimeException
{
    public const TYPE_GENERIC = 'generic';
    public const TYPE_OFFENSIVE = 'offensive';
    public const TYPE_RATE_LIMITED = 'rate_limited';

    public function __construct(
        string $message,
        public readonly string $type = self::TYPE_GENERIC,
        public readonly ?string $suggestion = null
    ) {
        parent::__construct($message);
    }
}
