<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

/**
 * A magic link confirmed from another browser with a code that is not the
 * one its requesting window shows (issue #1099). Nothing was confirmed;
 * `$linkSpent` says whether this was the last attempt the link allowed,
 * after which the link itself is refused.
 */
final class MagicLinkCodeMismatch extends \RuntimeException
{
    public function __construct(public readonly bool $linkSpent)
    {
        parent::__construct('The match code does not match.');
    }
}
