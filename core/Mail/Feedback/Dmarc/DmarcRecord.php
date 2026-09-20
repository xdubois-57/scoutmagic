<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback\Dmarc;

/**
 * One line of a DMARC aggregate report: a sending address, how many
 * messages came from it, and whether they authenticated (roadmap IT-06).
 *
 * **It names no recipient, and that is not an omission of ours.** RFC 7489
 * aggregate reports carry counters and source addresses only — never who
 * was written to, never a subject, never a body. That is what settles the
 * RGPD question here and what separates these from forensic reports, which
 * this site does not ask for and does not accept (D12).
 */
class DmarcRecord
{
    public function __construct(
        /** The IP that sent, exactly as the reporter wrote it. */
        public readonly string $sourceIp,
        /** How many messages this line accounts for. */
        public readonly int $count,
        /** `none`, `quarantine` or `reject` — what the receiver did. */
        public readonly string $disposition,
        public readonly bool $dkimPassed,
        public readonly bool $spfPassed,
        /** The domain in the From: header these messages claimed. */
        public readonly string $headerFrom
    ) {
    }

    /**
     * **Either one is enough, and that is DMARC's own rule** (RFC 7489
     * §6.6.2): a message passes when SPF *or* DKIM aligns, not both. A
     * screen that demanded both would show a unit's own relay as failing
     * while every message it sends arrives perfectly well — and would
     * have somebody chase a problem that is not there.
     */
    public function authenticated(): bool
    {
        return $this->dkimPassed || $this->spfPassed;
    }
}
