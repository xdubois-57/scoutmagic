<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Scheduler;

/**
 * The continuation endpoint's path, in one place.
 *
 * SchedulerContinuation builds a URL out of it and public/index.php
 * registers a route at it; a literal repeated in both is a literal that
 * eventually disagrees with itself, and the failure — hops that reach a
 * 404 and are never read, because nothing reads the response — would be
 * silent.
 */
final class SchedulerContinuationRoute
{
    public const PATH = '/api/scheduler/continue';

    /** The header the hop carries its shared secret in. */
    public const SECRET_HEADER = 'HTTP_X_SCOUTMAGIC_SCHEDULER';
}
