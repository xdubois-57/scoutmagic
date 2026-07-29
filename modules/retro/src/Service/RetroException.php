<?php

declare(strict_types=1);

namespace Modules\Retro\Service;

/**
 * $type lets the controller distinguish cases the UI reacts to
 * differently (module spec's draft-error states: too-long text offers an
 * AI-shorten action, a moderation flag doesn't) without parsing the
 * message string.
 */
class RetroException extends \RuntimeException
{
    public const TYPE_GENERIC = 'generic';
    public const TYPE_TOO_LONG = 'too_long';
    public const TYPE_OFFENSIVE = 'offensive';
    public const TYPE_RATE_LIMITED = 'rate_limited';
    public const TYPE_CLOSED = 'closed';

    public function __construct(
        string $message,
        public readonly string $type = self::TYPE_GENERIC,
        public readonly ?string $suggestion = null
    ) {
        parent::__construct($message);
    }
}
