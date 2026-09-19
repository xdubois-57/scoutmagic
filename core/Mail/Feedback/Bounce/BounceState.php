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
        public readonly ?string $notifiedCode = null,
        /** When the clock on « this may be working again » started. */
        public readonly ?\DateTimeImmutable $settlingSince = null
    ) {
    }

    /**
     * Has this address been quiet long enough since it was last written
     * to for that silence to mean anything?
     *
     * **The only honest reading of « un envoi réussi ».** A relay
     * accepting a message proves nothing — the bounce for that very send
     * arrives seconds later — and a bounce is not refused at the door
     * either: the far end answers, and that answer then waits for the
     * mailbox poll, up to `SyncMailboxesHandler::MAX_INTERVAL_MINUTES`
     * (1440, a full day). So a send only says anything once it has had
     * that long to come back.
     *
     * `settlingSince` is the moment the clock started: the first send
     * after the last bounce, left alone by the ones that follow, cleared
     * by any new bounce. **The question is how long THAT send has been
     * quiet, which is not the gap between the last two sends.** Asked the
     * second way — as this first was — an address mailed more often than
     * the settling period never settles at all, because no two
     * consecutive sends are ever far enough apart. One stale failure
     * would then sit there for ever and make the next unrelated bounce a
     * second strike rather than a first.
     *
     * And asked either way it must not be the gap between two sends in
     * ONE batch: two siblings share a parent's address, the batch walks
     * them back to back, and the second send would otherwise judge the
     * first clean seconds after it left — deleting the row, `failures`
     * and all, at every mailing, so the second strike never arrived.
     */
    public function hasSettledBy(\DateTimeImmutable $now): bool
    {
        if ($this->settlingSince === null) {
            return false;
        }

        return $this->settlingSince->add(new \DateInterval(self::SETTLING_PERIOD)) <= $now;
    }

    /**
     * Is this send the one that starts the clock? True when nothing is
     * already settling and the send comes after the last bounce — a send
     * that predates it proves nothing about what happened since.
     */
    public function startsSettling(\DateTimeImmutable $sentAt): bool
    {
        return $this->settlingSince === null && $sentAt > $this->lastSeenAt;
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
