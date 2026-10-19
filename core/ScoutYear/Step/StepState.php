<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ScoutYear\Step;

/**
 * A step's state. « À vérifier » is not a state: points to check stack on
 * top of TODO or DONE (a human step may be marked done with points still
 * open). BLOCKED is only ever an observed step whose action the
 * server refuses — a human step whose MARKING is refused stays TODO and
 * carries a markRefusal instead (TransitionStep::isBlocking()).
 */
enum StepState: string
{
    case TODO = 'todo';
    case DONE = 'done';
    case BLOCKED = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::TODO => 'À faire',
            self::DONE => 'Étape faite',
            self::BLOCKED => 'Bloquée',
        };
    }
}
