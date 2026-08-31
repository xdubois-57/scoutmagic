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
     * Detach a message: it leaves **this** business object, and nothing
     * else happens to it.
     *
     * **It is not destroyed, and it used to be.** Detaching is almost
     * always a correction — the message was filed under the wrong booking —
     * and destroying it made the correction impossible to finish. It now
     * falls back into the unit's general mail, where only a chef d'unité
     * sees it and where the module's own retention removes it if nobody
     * re-orients it. A detached message keeps a floor of thirty days
     * whatever its age, so a mis-click has a window to be noticed.
     *
     * `$preserveFileIds` names the files the consumer has re-classified as
     * something of its own — §7.7. They are **released from the message**:
     * its attachment row stops naming them and says why, so the retention
     * purge cannot take a booking's signed contract away with the email it
     * arrived in. A consumer that names a file here becomes responsible for
     * it, `files.owner_id` included; only it knows what it did with them.
     *
     * @param int[] $preserveFileIds
     * @return bool false when the message does not belong to that reference
     */
    public function detach(
        string $consumerId,
        string $businessReference,
        int $messageId,
        array $preserveFileIds = []
    ): bool;

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
     * Release everything held for a business object — used by a consumer's
     * own retention policy (§7.10) and when the object itself is deleted.
     *
     * Each message leaves that object; the ones nothing else recognises are
     * destroyed with their attachments, and the ones another module also
     * recognises stay where they are.
     *
     * @return int the number of messages released from that object
     */
    public function purgeReference(string $consumerId, string $businessReference): int;

    /**
     * Whether any mailbox is configured and enabled at all. A consumer uses
     * this to decide whether to show a communications tab that would
     * otherwise always be empty.
     */
    public function isCollecting(): bool;

    /**
     * The business object a message belongs to, found from the Message-IDs
     * a reply names — §7.6's second level, and the reason a reply carrying
     * no reference still lands on the right file.
     *
     * It lives here rather than in the consumer because the consumer has no
     * way to look inside this module's storage, and it is scoped to the
     * caller's own consumer id for the same reason everything else is.
     *
     * @param string[] $messageIds most specific first
     */
    public function findReferenceByThread(string $consumerId, int $mailboxId, array $messageIds): ?string;

    /**
     * What a non-superadmin may know about the configured mailboxes: a name
     * and whether it is working (§7.4). Never the host, the port or the
     * account — a manager choosing which box their module listens to needs
     * to recognise it, not to be able to reach it.
     *
     * @return array<int, array{name: string, state: string, is_enabled: bool}> keyed by mailbox id
     */
    public function listMailboxSummaries(): array;
}
