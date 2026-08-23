<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Retro\Service;

use Core\Exception\UserFacingException;

/**
 * $type lets the controller distinguish cases the UI reacts to
 * differently (module spec's draft-error states: too-long text offers an
 * AI-shorten action, a moderation flag doesn't) without parsing the
 * message string.
 *
 * Marked {@see UserFacingException}: every message is a French sentence
 * written for the participant. The two AI-backed sites used to append
 * Modules\LlmConnector\Api\LlmException's text — they now write their own
 * sentence and pass the cause as $previous, which is what the fourth
 * constructor argument is for.
 */
class RetroException extends \RuntimeException implements UserFacingException
{
    public const TYPE_GENERIC = 'generic';
    public const TYPE_TOO_LONG = 'too_long';
    public const TYPE_OFFENSIVE = 'offensive';
    public const TYPE_RATE_LIMITED = 'rate_limited';
    public const TYPE_CLOSED = 'closed';

    public function __construct(
        string $message,
        public readonly string $type = self::TYPE_GENERIC,
        public readonly ?string $suggestion = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
