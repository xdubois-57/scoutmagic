<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Service;

/**
 * Replaces what identifies a person in a diagnostic archive with stable,
 * meaningless tokens (ARCHITECTURE.md §8.49sexies).
 *
 * **Stable, not masked.** An IP address becomes `ip-3` — the same `ip-3`
 * on every line it appears on, so a reader can still tell that twelve
 * failed logins came from one client and not twelve, which is exactly
 * the kind of pattern a triage needs. Truncating the last octet would
 * keep the address personal data (a pseudonym is still a pseudonym); a
 * per-run counter with no stored mapping keeps nothing that leads back.
 * The map lives in this object, and this object lives for one extract.
 *
 * What is replaced, in the order it runs:
 *
 *   - e-mail addresses, first, because they carry dots and would
 *     otherwise be cut in half by the address rule below;
 *   - IPv4 addresses, and IPv6 ones under two shapes — the eight-group
 *     form, and any compressed `::` form that carries at least one
 *     digit (`face::cafe` is not matched, and neither is `Foo::bar`);
 *   - long hexadecimal identifiers (32 characters and more): session
 *     ids, password-reset tokens, file ids — none of them personal by
 *     themselves, all of them worth nothing to a triage;
 *   - the VALUE of a query-string parameter whose name says it carries a
 *     credential or an address.
 *
 * What is deliberately not touched: internal numeric ids, page paths,
 * user agents, timestamps. A member id is a number that means nothing
 * without the database it points into, and a path is what the bug is
 * about.
 */
final class TriageExtractScrubber
{
    /** @var array<string, array<string, string>> kind => (original => token) */
    private array $tokens = [];

    /** @var array<string, int> kind => next counter */
    private array $counters = [];

    private const EMAIL = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/';

    /**
     * Four dotted groups, not glued to a longer dotted number on either
     * side — a full stop closing the sentence is not a fifth group.
     */
    private const IPV4 = '/(?<!\d\.)(?<!\w)(?:\d{1,3}\.){3}\d{1,3}(?!\.\d)(?!\w)/';

    /** Eight hexadecimal groups, seven colons. */
    private const IPV6_FULL = '/(?<![\w:])(?:[0-9a-f]{1,4}:){7}[0-9a-f]{1,4}(?![\w:])/i';

    /**
     * A compressed form: some groups, a `::`, some groups — with at
     * least one digit in the run, which is what keeps `Class::method`
     * and `face::cafe` out.
     */
    private const IPV6_COMPRESSED = '/(?<![\w:])(?=[0-9a-f:]*\d)(?:[0-9a-f]{1,4}(?::[0-9a-f]{1,4}){0,6})?::(?:[0-9a-f]{1,4}(?::[0-9a-f]{1,4}){0,6})?(?![\w:])/i';

    private const LONG_HEX = '/\b[0-9a-f]{32,}\b/i';

    private const SENSITIVE_QUERY_VALUE = '/([?&](?:token|key|secret|password|passwd|signature|sig|code|email|mail|hash|session|auth)=)[^&\s"\'<>]+/i';

    public function scrub(string $text): string
    {
        $text = (string) preg_replace_callback(
            self::EMAIL,
            fn (array $m): string => $this->token('email', strtolower($m[0])),
            $text
        );
        $text = (string) preg_replace_callback(
            self::IPV4,
            fn (array $m): string => $this->token('ip', $m[0]),
            $text
        );
        $text = (string) preg_replace_callback(
            self::IPV6_FULL,
            fn (array $m): string => $this->token('ip', strtolower($m[0])),
            $text
        );
        $text = (string) preg_replace_callback(
            self::IPV6_COMPRESSED,
            fn (array $m): string => $this->token('ip', strtolower($m[0])),
            $text
        );
        $text = (string) preg_replace_callback(
            self::LONG_HEX,
            fn (array $m): string => $this->token('hex', strtolower($m[0])),
            $text
        );

        return (string) preg_replace(self::SENSITIVE_QUERY_VALUE, '$1…', $text);
    }

    /**
     * The stable token for one value of one kind — `ip-1`, `user-4` —
     * allocated on first sight. Public so a caller that knows a whole
     * column is a user id can tokenise it without the value having to
     * look like anything.
     */
    public function token(string $kind, string $value): string
    {
        if (isset($this->tokens[$kind][$value])) {
            return $this->tokens[$kind][$value];
        }

        $this->counters[$kind] = ($this->counters[$kind] ?? 0) + 1;

        return $this->tokens[$kind][$value] = $kind . '-' . $this->counters[$kind];
    }

    /**
     * How many distinct values of one kind were replaced — for the
     * extract's own README, which says what was done rather than asking
     * to be trusted.
     */
    public function count(string $kind): int
    {
        return $this->counters[$kind] ?? 0;
    }
}
