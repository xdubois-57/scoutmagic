<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ScoutYear\Step;

/**
 * The outcome of ScoutYearTransitionService::markStep().
 *
 * NOT_MARKABLE: the key is unknown, an observed step, or a step of a
 * disabled module — no human may set it, ever. REFUSED: a human step whose
 * check refuses the mark right now ($reason, French, shown as is).
 */
final class StepMarkResult
{
    public const MARKED = 'marked';
    public const UNMARKED = 'unmarked';
    public const NOT_MARKABLE = 'not_markable';
    public const REFUSED = 'refused';

    private function __construct(
        public readonly string $outcome,
        public readonly ?string $reason = null
    ) {
    }

    public static function marked(): self
    {
        return new self(self::MARKED);
    }

    public static function unmarked(): self
    {
        return new self(self::UNMARKED);
    }

    public static function notMarkable(): self
    {
        return new self(self::NOT_MARKABLE);
    }

    public static function refused(string $reason): self
    {
        return new self(self::REFUSED, $reason);
    }

    public function isApplied(): bool
    {
        return $this->outcome === self::MARKED || $this->outcome === self::UNMARKED;
    }
}
