<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback;

/**
 * One round trip: a message this site wrote to one of its own addresses,
 * and whether it ever came back (roadmap IT-03).
 */
final class ReturnProbe
{
    public function __construct(
        public readonly int $id,
        public readonly string $address,
        public readonly string $correlationKey,
        public readonly \DateTimeImmutable $sentAt,
        public readonly \DateTimeImmutable $expiresAt,
        public readonly ?\DateTimeImmutable $receivedAt = null,
        public readonly ?int $mailboxId = null
    ) {
    }

    public function hasArrived(): bool
    {
        return $this->receivedAt !== null;
    }

    /**
     * Whether the site is still entitled to wait.
     *
     * A probe that has not arrived and has not expired is « en attente » —
     * a mail path that takes a few minutes is normal, and calling it
     * broken in the meantime would train everybody to ignore the answer.
     */
    public function isWaiting(\DateTimeImmutable $now): bool
    {
        return !$this->hasArrived() && $now < $this->expiresAt;
    }
}
