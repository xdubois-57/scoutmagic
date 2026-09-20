<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Api;

/**
 * A consumer that wants the messages it has finished with removed from
 * the mailbox (roadmap IT-07).
 *
 * **Opt-in, and declared** — the same shape as
 * {@see PayloadConsumerInterface}, for the same reason. Removing somebody
 * else's mail is the gravest thing this module could be asked to do, so
 * it is not a flag on {@see MessageConsumerInterface} that every consumer
 * carries and most leave false: it is a second interface a consumer has
 * to reach for on purpose, which makes « who deletes » answerable by
 * reading the implementations rather than by auditing a boolean.
 *
 * **It takes a message and not nothing.** A consumer answers per message,
 * so a box that receives both the copies it measures and a stray human
 * reply keeps the reply: the answer is about this message, never about
 * the mailbox.
 *
 * **What bounds it is the scope.** A consumer is only ever offered
 * messages from the boxes the super-admin opened to it, so « what may I
 * delete » has the same answer as « what may I read » — the question that
 * screen already asks, in the operator's own words, and the mechanism D10
 * chose for exactly this reason. Nothing here can reach a box that was
 * not granted.
 *
 * **The burden an implementer carries**: answer yes only about a message
 * you have actually recorded something from. The reference implementation
 * — {@see \Core\Mail\Feedback\Seed\SeedConsumer} — remembers the
 * message it just wrote a landing for and compares, so a header a
 * stranger wrote is never enough on its own.
 *
 * The client must also implement
 * {@see \Modules\InboundMail\Client\PruningMailboxClientInterface}, which
 * is the other half of the lock and the half that keeps the general
 * read-only contract honest.
 */
interface PruningConsumerInterface
{
    /**
     * Whether this message may be removed now that it has been analysed.
     *
     * **Asked of every consumer the mailbox's scope names, claim or no
     * claim.** `MailboxSyncService::pruneIfAsked()` iterates the scoped
     * consumers and never filters on the analysis results — it cannot,
     * since the seed consumer claims nothing on purpose. The scope is the
     * bound, as the class docblock above says; there is no claim filter
     * in front of this, and an implementer that assumed one and skipped
     * its own check would answer yes for a message it recorded nothing
     * from. **So decide from what YOU wrote down about this message, not
     * from being asked.**
     *
     * Asked only after the analysis has been recorded, and that order
     * matters: a message deleted before its verdict was written is a
     * measurement lost with no way to take it again.
     */
    public function shouldPruneAfterAnalysis(CandidateMessage $message): bool;
}
