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
        public readonly ?string $notifiedCode = null,
        /** When a message for this address was last handed to a relay. */
        public readonly ?\DateTimeImmutable $lastSendAt = null
    ) {
    }

    /**
     * Did the last send produce no bounce?
     *
     * **The only honest reading of « un envoi réussi ».** A relay
     * accepting a message proves nothing — the bounce for that very send
     * arrives seconds later — so a send can only be judged once the next
     * one comes round. If the last send is more recent than the last
     * bounce, nothing came back from it, and the address is working.
     */
    public function lastSendWasClean(): bool
    {
        return $this->lastSendAt !== null && $this->lastSendAt > $this->lastSeenAt;
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
