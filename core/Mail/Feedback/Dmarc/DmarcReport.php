<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback\Dmarc;

/**
 * One aggregate report, as a mailbox provider sends it (roadmap IT-06).
 *
 * A provider that receives mail claiming to be from this unit's domain
 * writes one of these, usually daily, and posts it to the address the
 * `rua=` tag of the DNS record names — which is the address
 * {@see \Core\Mail\DnsVerifier::checkDmarc()} publishes.
 */
class DmarcReport
{
    /**
     * @param list<DmarcRecord> $records
     */
    public function __construct(
        /** Who sent the report — « google.com », « Yahoo », « Enterprise Outlook ». */
        public readonly string $organisation,
        /** The reporter's own id for it, used to notice the same report twice. */
        public readonly string $reportId,
        /** The domain the report is about. */
        public readonly string $domain,
        public readonly \DateTimeImmutable $begin,
        public readonly \DateTimeImmutable $end,
        /** The policy the reporter saw published: `none`, `quarantine`, `reject`. */
        public readonly string $policy,
        public readonly array $records
    ) {
    }

    /** Every message this report accounts for, authenticated or not. */
    public function totalMessages(): int
    {
        return array_sum(array_map(static fn(DmarcRecord $r): int => $r->count, $this->records));
    }

    public function authenticatedMessages(): int
    {
        return array_sum(array_map(
            static fn(DmarcRecord $r): int => $r->authenticated() ? $r->count : 0,
            $this->records
        ));
    }
}
