<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\Request;
use Core\Http\Response;
use Core\Scheduler\SchedulerContinuation;
use Core\Scheduler\SchedulerContinuationRoute;
use Twig\Environment;

/**
 * POST /api/scheduler/continue — the site asking itself to keep going.
 *
 * The second machine-to-machine, session-free, CSRF-free route in this
 * codebase, and it follows the first one's reasoning exactly (see
 * WebhookController): the caller is this installation's own PHP process,
 * it has no session and therefore no CSRF token to carry, and CSRF is not
 * the right tool anyway — a shared secret is what authenticates it. Same
 * refusal shape too: 403 and a `security` journal entry on a bad secret,
 * with the secret itself never logged.
 *
 * `role_min: public` for the same reason the webhook is: RbacGuard answers
 * questions about a signed-in person, and there is nobody signed in here.
 * The secret is the whole authorisation.
 */
class SchedulerContinuationController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private SchedulerContinuation $continuation
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function continue(Request $request, array $params): Response
    {
        $presented = $request->getServer(SchedulerContinuationRoute::SECRET_HEADER, '');

        if (!$this->continuation->verifySecret(is_string($presented) ? $presented : null)) {
            $this->continuation->journalRefusedHop(
                is_string($ip = $request->getServer('REMOTE_ADDR', '')) ? $ip : null
            );

            return $this->json(['status' => 'forbidden'], 403);
        }

        // The caller closed the socket the moment it finished writing —
        // nobody is waiting for this response, and nobody will notice the
        // connection being torn down. Without this, the very first hop
        // would be aborted by PHP as soon as it noticed, and the chain
        // would never advance past one link.
        ignore_user_abort(true);

        // Deliberately NOT beginChain(): the hop counter must survive the
        // whole chain, and resetting it here would reset the ceiling at
        // every link and stop it being a ceiling. Only the ignition path
        // (the poor man's cron) starts a new chain.
        $outcome = $this->continuation->runSliceAndContinue();

        return $this->json([
            'processed' => $outcome->processed,
            'work_remains' => $outcome->workRemains,
            'hops' => $this->continuation->hopCount(),
        ]);
    }
}
