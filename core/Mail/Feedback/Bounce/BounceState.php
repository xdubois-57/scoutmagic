<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback\Bounce;

/**
 * What this site knows about one address that has bounced (roadmap
 * IT-05).
 *
 * One row per mailbox, never per profile: see the table comment in
 * `schema/core.sql`. A parent's address on three children is one state
 * here, so « cette adresse ne reçoit plus rien » says the same thing
 * wherever it is read.
 */
final class BounceState
{
    /**
     * How long a send is given to bounce before its silence counts as
     * success. Two days: the mailbox poll may sit `MAX_INTERVAL_MINUTES`
     * (1440, a full day) between passes, and the answer has to travel
     * before it can wait.
     */
    public const SETTLING_PERIOD = 'P2D';

    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly BounceCategory $category,
        public readonly BounceSeverity $severity,
        public readonly string $statusCode,
        /** Consecutive permanent failures; a transient one never counts. */
        public readonly int $failures,
        public readonly \DateTimeImmutable $firstSeenAt,
        public readonly \DateTimeImmutable $lastSeenAt,
        public readonly ?\DateTimeImmutable $blockedAt = null,
        public readonly ?string $notifiedCode = null
    ) {
    }

    /**
     * Was the send that preceded this one clean?
     *
     * **The only honest reading of « un envoi réussi ».** A relay
     * accepting a message proves nothing — the bounce for that very send
     * arrives seconds later — so a send can only be judged once the next
     * one comes round. If the previous receipt is more recent than the
     * last bounce, nothing came back from it and the address is working.
     *
     * The receipt is passed in rather than carried here: it lives in
     * `mail_send_receipts`, which exists for a second and more important
     * reason (see that table's comment).
     */
    public function wasSettledBy(?\DateTimeImmutable $previousSendAt, ?\DateTimeImmutable $now = null): bool
    {
        if ($previousSendAt === null || $previousSendAt <= $this->lastSeenAt) {
            return false;
        }

        // **And it must have had TIME not to bounce**, which is the half
        // the first version left out while its own comment claimed it.
        //
        // A bounce is not refused at the door: the far end answers, and
        // that answer then waits for the mailbox poll, up to
        // `SyncMailboxesHandler::MAX_INTERVAL_MINUTES` (1440). So a send
        // made minutes ago has no bounce recorded against it yet whether
        // or not it produced one, and reading that silence as « clean »
        // is reading the poll interval, not the address.
        //
        // What that cost: two messages to one mailbox before the next
        // poll — two siblings sharing a parent's address, resolved into
        // one mass-mail batch, which is precisely the case this feature
        // exists for — and the second send judged the first clean and
        // deleted the row, `failures` and all. Every batch reset the
        // counter the same way, so the second strike never arrived and
        // the address was never blocked.
        //
        // Erring long only delays a forgetting; erring short loses the
        // count that blocking is built on.
        return $previousSendAt->add(new \DateInterval(self::SETTLING_PERIOD))
            <= ($now ?? new \DateTimeImmutable());
    }

    /** Blocked means: the site stops writing to it until somebody says otherwise. */
    public function isBlocked(): bool
    {
        return $this->blockedAt !== null;
    }

    /**
     * Whether a bounce carrying this code is news for this address.
     *
     * Indexed on the code rather than on a delay, which is what makes the
     * transient notification bearable: a full mailbox bounces at every
     * single mailing, so « once per error » is the difference between one
     * message and one per send. And because it is the code that is
     * remembered, a mailbox emptied and full again six months later is a
     * new error again — which it is, to the person who has to empty it.
     */
    public function isNewError(string $statusCode): bool
    {
        return $this->notifiedCode !== $statusCode;
    }
}
