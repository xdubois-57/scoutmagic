<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Api;

/**
 * What another module may do with the mail this module collected (§7.11).
 *
 * **Every method is scoped to one business reference of one consumer.**
 * There is deliberately no `findAll()`, no `findByMailbox()` and no
 * `search()`: a manager who may open a booking must not thereby gain a
 * window onto the unit's whole mailbox, and the way to make that
 * impossible is to never offer the query. A consumer id is passed on every
 * call for the same reason — `rental` asking for `finance`'s messages gets
 * nothing, rather than getting them because it knew a reference.
 *
 * Consumed the §7.5 way: a nullable dependency the consumer degrades
 * without.
 */
interface InboundMailInterface
{
    /**
     * The messages attached to one business object, oldest first.
     *
     * @return InboundMessage[]
     */
    public function findForReference(string $consumerId, string $businessReference): array;

    /**
     * One message, but only if it really belongs to that reference —
     * an id alone is never enough. A consumer that has an id from
     * somewhere else gets null rather than somebody else's mail.
     */
    public function findOneForReference(string $consumerId, string $businessReference, int $messageId): ?InboundMessage;

    /**
     * Detach a message: it leaves the business object and, there being no
     * unattached queue to fall into (§7.6), is deleted along with the
     * attachments nothing else has claimed.
     *
     * @return bool false when the message does not belong to that reference
     */
    public function detach(string $consumerId, string $businessReference, int $messageId): bool;

    /**
     * Move a message from one business object to another **within the same
     * consumer**. The caller is responsible for having checked that the
     * user may reach BOTH references (§7.7) — this module cannot know a
     * consumer's authorisation rules, which is exactly why the caller is
     * required to narrow the target list before offering it.
     *
     * @return bool false when the message does not belong to $fromReference
     */
    public function move(string $consumerId, string $fromReference, string $toReference, int $messageId): bool;

    /**
     * Delete everything held for a business object — used by a consumer's
     * own retention policy (§7.10) and when the object itself is deleted.
     *
     * @return int the number of messages removed
     */
    public function purgeReference(string $consumerId, string $businessReference): int;

    /**
     * Whether any mailbox is configured and enabled at all. A consumer uses
     * this to decide whether to show a communications tab that would
     * otherwise always be empty.
     */
    public function isCollecting(): bool;
}
