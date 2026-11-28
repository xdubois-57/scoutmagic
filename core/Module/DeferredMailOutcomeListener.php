<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Module;

use Core\Mail\Transport\DeferredMailAbandonment;
use Core\Scheduler\TaskContext;

/**
 * Optional hook a module implements to hear what became of a message it
 * sent and the deferred queue kept (D9, issue #1454) — without core
 * knowing the module exists (ARCHITECTURE.md §7.4).
 *
 * The sender names its message when it sends it
 * (`Core\Mail\SendTracking::$origin`); the queue stores that reference
 * beside the message; `Core\Mail\Transport\Task\DrainDeferredMailHandler`,
 * the one place a deferred message is ever settled, hands it back here.
 * `mass_mail` is the one implementation, which is how a mailing's tracking
 * stops reading « Envoyé » for a message that only waited, and how an
 * abandonment a day later lands on the recipient's line.
 *
 * Resolved on the scheduled path, through `TaskContext::getOptional()`
 * (`Core\Scheduler\TaskCapabilities`), under the providing module's live
 * enabled state — so the context is handed in and the implementation
 * builds what it needs from it, as a task handler does.
 *
 * **The origin is opaque here and every origin is offered.** An
 * implementation answers for the references it wrote and ignores every
 * other one silently.
 *
 * **One question before, two reports after.** `deferredMailWithdrawn()`
 * is asked right before the replay, and is the one method that decides
 * something: a sender that withdraws its message has it abandoned unsent.
 * It exists because a sender may keep a « do not write to me » the core
 * cannot see — `mass_mail`'s group-mail unsubscribe — and a message that
 * waited a day must not reach somebody who said stop in that day. The
 * other two are told after the fact: by then the message has already
 * left, or already been abandoned, and a listener that throws changes
 * nothing about that.
 */
interface DeferredMailOutcomeListener
{
    /**
     * Whether the deferred message carrying $origin should still leave —
     * null when it should (and for an origin this listener did not write),
     * otherwise why not. Asked by the drain right before the replay.
     *
     * A listener that throws here has its message KEPT, unsent, for the
     * next pass: sending to somebody who may have asked not to be written
     * to is the one mistake the question exists to prevent.
     */
    public function deferredMailWithdrawn(string $origin, TaskContext $context): ?DeferredMailAbandonment;

    /** A deferred message carrying $origin has now left. */
    public function deferredMailSent(string $origin, TaskContext $context): void;

    /** A deferred message carrying $origin was given up on, for $reason. */
    public function deferredMailAbandoned(
        string $origin,
        DeferredMailAbandonment $reason,
        TaskContext $context
    ): void;
}
