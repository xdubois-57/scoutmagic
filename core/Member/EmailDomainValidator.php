<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

/**
 * Best-effort DNS check for a submitted email's domain (module addendum:
 * "limit typos") — wraps PHP's checkdnsrr() behind a class so
 * MemberEmailService::addEmail() can be tested without a real network
 * lookup (inject a stub in tests instead). Checks MX first (the record
 * type that actually matters for mail delivery), falling back to A/AAAA
 * for domains that accept mail directly on the bare domain (rare, but
 * valid). A resolver failure (e.g. outbound DNS blocked on some
 * restricted shared hosting) is indistinguishable from "domain doesn't
 * exist" at this level — this is a typo-catching aid, not a guarantee of
 * deliverability, and never blocks anything except adding a brand new
 * address.
 */
class EmailDomainValidator
{
    public function hasValidDomain(string $email): bool
    {
        $domain = substr((string) strrchr($email, '@'), 1);
        if ($domain === '') {
            return false;
        }

        // A single lookup, no retry/sleep. The previous 3-attempt loop with
        // usleep(300000) held a worker up to ~600ms on a junk domain, a cheap
        // request-amplification lever (audit M15). This is a best-effort
        // typo-catching aid, never a deliverability guarantee, so a one-shot
        // check is enough — a genuinely transient resolver blip just means the
        // member is asked to re-add, exactly as a real typo would.
        return $this->resolves($domain);
    }

    private function resolves(string $domain): bool
    {
        return checkdnsrr($domain, 'MX') || checkdnsrr($domain, 'A') || checkdnsrr($domain, 'AAAA');
    }
}
