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
 *     form, and any compressed `::` form made of hexadecimal groups.
 *     `Foo::bar` survives because `o` is not hexadecimal; a class whose
 *     name is four hexadecimal letters (`Cafe::add`) would not, and that
 *     is the right side to err on: over-replacing costs a reader one
 *     odd token, under-replacing costs somebody an address;
 *   - long hexadecimal identifiers (32 characters and more): session
 *     ids, password-reset tokens, file ids — none of them personal by
 *     themselves, all of them worth nothing to a triage;
 *   - the internal identifier of a person where it is recognisable as
 *     one: the numeric segment after `/members/`, `/membres/`,
 *     `/users/`, `/comptes/` and their kin in a path, and the value of
 *     a JSON or `key=value` field whose name ends in `_id` or names a
 *     member, a user or an account. A member id means nothing without
 *     the unit's database, but the unit's own RGPD text treats it as a
 *     pseudonym and this extract must not carry what that text says it
 *     does not;
 *   - the VALUE of every query-string parameter that is not a bare
 *     number: a search box, a name filter and a reset token all travel
 *     that way, and the triage needs the page, not what was typed into
 *     it.
 *
 * What is deliberately not touched: page paths themselves, user agents,
 * timestamps, plain numbers outside the shapes above. A path is what the
 * bug is about; a site's own address, where a log path or a server
 * summary carries it, names an organisation and not a person, and the
 * extract's README says so rather than claiming otherwise.
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
     * A compressed form: some groups, a `::`, some groups, every group
     * hexadecimal. No digit is required — `dead:beef::cafe` is an
     * address too — so a class name of four hexadecimal letters before a
     * `::` is replaced as well; see the class comment for why that is
     * the acceptable side.
     */
    private const IPV6_COMPRESSED = '/(?<![\w:])'
        . '(?:[0-9a-f]{1,4}(?::[0-9a-f]{1,4}){0,6})?'
        . '::'
        . '(?:[0-9a-f]{1,4}(?::[0-9a-f]{1,4}){0,6})?'
        . '(?![\w:])/i';

    private const LONG_HEX = '/\b[0-9a-f]{32,}\b/i';

    /**
     * The id after a path segment that names a person's record —
     * `/members/{id}`, `/passage/membre/{id}`, `/mass-mail/recipients/{id}`,
     * `/inscriptions/suivi/demande/{id}`, `/password-reset/{id}` and their
     * kin, read off the routes this codebase declares. Deliberately not
     * `/finance/accounts/{id}`, `/gallery/{id}` or `/files/{id}`: an
     * account, an album or a file is not a person, and a log that keeps
     * those numbers is a log a triage can still follow.
     */
    private const PERSON_PATH_ID = '~(/(?:'
        . 'members?|membres?|users?|utilisateurs?|comptes?|user-accounts?'
        . '|recipients?|contacts?|demandes?|attestations?|departs|password-reset'
        . '|animateurs?|parents?|families|familles?|households?|menages?|staff'
        . ')/)(\d+)(?=[/?#\s"\'<>]|$)~i';

    /**
     * A JSON or `key=value` field whose name says it holds a person's
     * id: `"member_id": 42`, `user_account_id=7`, `"user": 3`.
     */
    private const PERSON_FIELD_ID = '/((?:"|\b)(?:[a-z_]*_id|member|user|account|parent|chef)"?\s*[:=]\s*"?)'
        . '(\d+)(?="?(?:[,}\s&]|$))/i';

    /**
     * Every query-string value that is not a bare number. `?page=2` keeps
     * its 2; `?q=Dupont`, `?token=…` and `?email=…` lose what was typed.
     */
    private const QUERY_VALUE = '/([?&][A-Za-z0-9_\[\]\-]+=)(?!\d+(?:[&\s"\'<>]|$))[^&\s"\'<>]+/';

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
        $text = (string) preg_replace_callback(
            self::PERSON_PATH_ID,
            fn (array $m): string => $m[1] . $this->token('id', $m[2]),
            $text
        );
        $text = (string) preg_replace_callback(
            self::PERSON_FIELD_ID,
            fn (array $m): string => $m[1] . $this->token('id', $m[2]),
            $text
        );

        return (string) preg_replace(self::QUERY_VALUE, '$1…', $text);
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
