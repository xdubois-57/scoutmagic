<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback\Seed;

/**
 * One copy of a mailing sent to one seed mailbox (roadmap IT-07).
 *
 * The address is here because the repository decrypts it for the one
 * caller that needs it — the consumer matching an arrival back to its row.
 * It goes no further: the screens, the journal and the support archive
 * carry {@see $provider} instead, which names a company rather than a box
 * (SECURITY.md §11).
 */
class SeedCopy
{
    public function __construct(
        public readonly int $id,
        /** What the sender called its run — opaque to the transport. */
        public readonly string $runReference,
        public readonly string $address,
        /** The mailbox provider: « gmail.com », « outlook.com ». */
        public readonly string $provider,
        public readonly \DateTimeImmutable $sentAt,
        public readonly SeedVerdict $verdict,
        /** The folder as the provider names it, once it is known. */
        public readonly ?string $landedFolder = null,
        public readonly ?\DateTimeImmutable $recordedAt = null
    ) {
    }

    /** Whether this copy is still waiting for an answer. */
    public function isPending(): bool
    {
        return $this->verdict === SeedVerdict::Pending;
    }

    /**
     * The provider a seed address belongs to.
     *
     * The domain, lower-cased, and nothing cleverer: « gmail.com » is what
     * an operator reads the results by, and mapping it to a brand name
     * would be a table to maintain for no gain.
     */
    public static function providerOf(string $address): string
    {
        $at = strrpos($address, '@');

        return $at === false ? 'inconnu' : mb_strtolower(substr($address, $at + 1));
    }
}
