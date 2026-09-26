<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback\Dmarc;

use Core\Config\SettingService;
use Core\Service\DateInput;

/**
 * Which addresses this unit's own SPF record authorises, and through which
 * `include:` (roadmap IT-06, issue #421).
 *
 * **The gap this fills is the one case where the page has something
 * important to say and cannot say it.** {@see KnownSenders} names the
 * relays the unit declared TO THIS SITE. A source that authenticates and is
 * none of them shows as `198.51.100.7` — « Autre » — and almost always is a
 * mail service the unit really uses and never told the site about: a
 * newsletter tool, a provider, an old service nobody cancelled. Its
 * addresses are usually right there in the unit's own SPF record, behind an
 * `include:`, because somebody had to put them there for that mail to pass
 * at all. Reading that chain turns the row from a riddle into a sentence:
 * « déclarée dans votre SPF, via `_spf.google.com` » — a token the operator
 * can find in their own DNS and act on.
 *
 * **Named, not cleared.** This is the trap the issue names, and it is a
 * trap because the truthful reading is the reassuring one: an address in
 * the record is an address somebody authorised, which says nothing about
 * whether they still want to. So a source this class places keeps its
 * « À identifier » badge and stays in the count that warns before
 * `p=reject` — see {@see \Core\Http\Controller\OutboundMailController
 * ::unknownAuthenticatingCount()}. Naming it tells the volunteer WHERE to
 * look; it does not tell them the answer is fine.
 *
 * **The page renders a remembered reading and never a lookup of its own.**
 * Same rule, same reason, same button as `KnownSenders` and
 * {@see \Core\Mail\DnsCheckMemory}: walking an `include:` chain is a
 * handful of serial DNS queries, and these screens are the ones somebody
 * opens when mail is already broken. The resolution happens in the
 * « Vérifier les enregistrements » action and nowhere else.
 *
 * **A reading that could not be taken places nothing**, which is « Autre »
 * and the safe side — wrong in that direction costs a volunteer ten
 * minutes, wrong in the other tells them an unknown sender is accounted
 * for and nobody looks again.
 *
 * **What this deliberately does not read**, each leaving its addresses
 * unplaced rather than misplaced:
 *
 * - `a`, `a:host`, `mx`, `mx:host`, `exists:` and `ptr`. They authorise
 *   addresses too, and each would need its own record type resolved. The
 *   issue is about the `include:` chain, `include:` is where a third-party
 *   provider's ranges live, and a host the unit runs itself is the case
 *   `KnownSenders` already covers from the relay list.
 * - a mechanism carrying a `-`, `~` or `?` qualifier. `-ip4:203.0.113.0/24`
 *   is a range the record REFUSES, and reporting it as « déclarée dans
 *   votre SPF » would be the opposite of what the operator wrote.
 * - an IPv4-mapped IPv6 source (`::ffff:198.51.100.7`) against an `ip4:`
 *   range: the packed lengths differ, so it does not match. Aggregate
 *   reporters write IPv4 as a dotted quad, so this stays theoretical — and
 *   unplaced is the harmless outcome either way.
 *
 * **`ip4:0.0.0.0/0` is honoured, not special-cased.** A record that
 * authorises the whole internet then places every source, which reads
 * oddly and is exactly true: that is a finding about the record, and
 * hiding it to keep the table tidy would be the screen lying to keep its
 * composure.
 */
class SpfCoverage
{
    public const SETTING_KEY = 'mail_dmarc_spf_coverage';

    /**
     * How many `include:`/`redirect=` resolutions one reading may spend.
     *
     * **Ten, because that is the number RFC 7208 §4.6.4 gives receivers**,
     * and a chain that needs an eleventh is a chain real receivers abandon
     * with a `permerror` — so a range hiding behind it authorises nothing
     * in practice either. The ceiling is not this class being careful, it
     * is this class stopping where the protocol stops.
     *
     * Borrowed rather than reimplemented, and the difference matters: the
     * walk below skips a domain it has already read, which a strict
     * evaluator does not. So a chain that revisits one host is read further
     * here than a receiver would read it. That is right for NAMING a
     * source and would be wrong for AUTHORISING one, which is why nothing
     * in this class returns a verdict on authorisation.
     */
    public const MAX_LOOKUPS = 10;

    /**
     * How many ranges one reading may keep.
     *
     * The reading goes into `settings.setting_value`, a MySQL `TEXT` —
     * 65 535 bytes — and a truncated JSON blob is an unreadable reading
     * rather than a short one. For scale, the published chains of Google,
     * Microsoft and Mailgun together are well under a hundred ranges.
     *
     * **This is a count, and the size is bounded separately** — by writing
     * each `via` once and referring to it by index. Review of #571 was right
     * that the first version's arithmetic did not hold: it repeated the
     * attribution string in every range, so 256 ranges under a 253-character
     * `include:` target came to some 80 KB, over the column. With the index
     * the bound is provable rather than hoped for: there are at most
     * `MAX_LOOKUPS + 1` distinct attributions (the unit's own domain, plus
     * one per top-level `include:`, each of which costs a lookup), so at most
     * 11 × 253 bytes of names, and 256 ranges of a 32-character hex address
     * and a small integer. Under 12 KB at its worst.
     */
    public const MAX_RANGES = 256;

    /**
     * How long a reading may name anything.
     *
     * **This is the one input here that changes without anybody at the
     * unit touching it.** A relay's own address moves when the unit moves
     * it; a provider's published ranges move when the provider decides to,
     * and a reading kept for ever would go on naming « _spf.google.com »
     * for an address Google handed back years ago. Past this age the
     * reading places nothing and the page says why, rather than placing
     * confidently from a record it read last winter.
     *
     * Thirty days, matching the window of reports the page shows: a reading
     * older than the traffic it explains is explaining somebody else's
     * addresses.
     */
    public const MAX_AGE_DAYS = 30;

    /**
     * Why a reading is incomplete. Three, because they are three different
     * things for the operator to do: shorten the record, accept that we keep
     * fewer ranges than it publishes, or try again later.
     */
    public const PARTIAL_LOOKUPS = 'lookups';
    public const PARTIAL_RANGES = 'ranges';
    public const PARTIAL_UNREADABLE = 'unreadable';

    /**
     * @param list<array{via: string, bytes: string, prefix: int}> $ranges
     */
    private function __construct(
        public readonly ?\DateTimeImmutable $takenAt,
        public readonly string $domain,
        public readonly ?string $partial,
        private array $ranges
    ) {
    }

    /**
     * The reading the last DNS check left behind, or an empty one.
     *
     * Never null, for `KnownSenders::remembered()`'s reason: « rien n'a été
     * résolu » is an answer this class gives correctly, and a null would
     * push that branch onto every caller.
     */
    public static function remembered(SettingService $settings): self
    {
        $raw = (string) ($settings->get(self::SETTING_KEY) ?? '');
        if ($raw === '') {
            return self::nothing();
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::nothing();
        }

        if (!is_array($decoded)) {
            return self::nothing();
        }

        $takenAt = DateInput::fromStorage(isset($decoded['at']) ? (string) $decoded['at'] : null);
        if ($takenAt === null) {
            // A reading with no date cannot be aged out, and a reading that
            // cannot be aged out is the one failure mode `MAX_AGE_DAYS`
            // exists to prevent. Dropping it is « Autre », the safe side.
            return self::nothing();
        }

        $partial = isset($decoded['partial']) ? (string) $decoded['partial'] : null;

        return new self(
            $takenAt,
            (string) ($decoded['domain'] ?? ''),
            in_array($partial, [self::PARTIAL_LOOKUPS, self::PARTIAL_RANGES, self::PARTIAL_UNREADABLE], true)
                ? $partial
                : null,
            self::decodeRanges($decoded['ranges'] ?? null, $decoded['vias'] ?? null)
        );
    }

    /**
     * Walk this unit's SPF chain and keep what it authorises. **Blocks on
     * DNS**, so it is called from an explicit action and never from a page
     * render.
     *
     * **The resolver is handed in, and it is not optional.** It used to
     * default to null and fall back to a `dns_get_record()` of this class's
     * own, which was a second way for the outbound-mail screens to reach the
     * network — the very thing the review of #571 asked to remove. Every
     * caller already passed one, so the fallback was dead code that only a
     * future caller could wake up, having forgotten why it should not.
     *
     * @param \Closure $txt `fn(string $host): ?list<string>` — null for « the
     *                      resolver could not be asked », which is not the
     *                      same answer as an empty list, and
     *                      {@see self::walk()} acts on the difference
     */
    public static function refresh(
        SettingService $settings,
        string $domain,
        \Closure $txt,
        ?\DateTimeImmutable $now = null
    ): self {
        $domain = trim($domain);
        $takenAt = $now ?? new \DateTimeImmutable();

        $walk = $domain === '' ? ['ranges' => [], 'partial' => null] : self::walk($domain, $txt);

        // **Each attribution written once, and referred to by index.**
        // Repeating it per range is what made the encoded reading able to
        // outgrow the `TEXT` column (see `MAX_RANGES`); there are at most a
        // dozen distinct ones, so an index bounds the blob by arithmetic
        // instead of by hope.
        $vias = [];
        $rows = [];
        foreach ($walk['ranges'] as $range) {
            $index = array_search($range['via'], $vias, true);
            if ($index === false) {
                $index = count($vias);
                $vias[] = $range['via'];
            }

            $rows[] = ['v' => $index, 'b' => bin2hex($range['bytes']), 'p' => $range['prefix']];
        }

        $encoded = json_encode([
            'at' => $takenAt->format('Y-m-d H:i:s'),
            // Stored rather than passed in again: the page then needs
            // nothing but the reading to tell « directement dans votre
            // enregistrement » from « via un include: », even after
            // somebody edits the sending domain.
            'domain' => $domain,
            'partial' => $walk['partial'],
            'vias' => $vias,
            'ranges' => $rows,
        ]);

        // `setInternal()` because this is written by an action and never by
        // hand, the posture `DnsCheckMemory` and `KnownSenders` both take.
        $settings->setInternal(self::SETTING_KEY, $encoded === false ? '' : $encoded);

        return new self($takenAt, $domain, $walk['partial'], $walk['ranges']);
    }

    /**
     * The SPF token that accounts for this address, or null when the record
     * does not — which includes every address a reading too old to be
     * trusted would have placed.
     *
     * The token is what the operator will find in their own DNS: the
     * `include:` target for a delegated range, and the unit's own domain
     * for a range the record states itself. {@see isOwnDomain()} tells the
     * two apart.
     */
    public function viaFor(string $sourceIp, ?\DateTimeImmutable $now = null): ?string
    {
        if ($this->isStale($now)) {
            return null;
        }

        $candidate = @inet_pton(trim($sourceIp));
        if ($candidate === false) {
            return null;
        }

        foreach ($this->ranges as $range) {
            if (self::contains($range['bytes'], $range['prefix'], $candidate)) {
                return $range['via'];
            }
        }

        return null;
    }

    /**
     * Whether that token is the unit's own domain — the record stating a
     * range itself — rather than something it delegates to.
     */
    public function isOwnDomain(string $via): bool
    {
        return $this->domain !== '' && strcasecmp($via, $this->domain) === 0;
    }

    /**
     * Is this reading about the domain the site sends from **today**?
     *
     * **A reading outlives the address it was taken for** (found in review on
     * #571). Changing the sending address changes the domain the site signs
     * for, and the stored ranges then belong to the old one: for up to
     * `MAX_AGE_DAYS` the page would go on calling them « déclarée dans votre
     * SPF », and {@see self::isOwnDomain()} — which compares against the
     * STORED domain — would call a range « directement dans votre
     * enregistrement » for a domain that is no longer the unit's.
     *
     * Asked on the read side rather than cleared when the address is saved,
     * and deliberately: a reading can also be left behind by a restore or by
     * an edit made anywhere else, and one question asked where the answer is
     * used covers every one of those. The reading is left in place — it costs
     * nothing and the next check overwrites it.
     */
    public function isFor(string $domain): bool
    {
        return $this->domain !== '' && strcasecmp($this->domain, trim($domain)) === 0;
    }

    /** Whether a reading has ever been taken. */
    public function isEmpty(): bool
    {
        return $this->takenAt === null;
    }

    /**
     * Whether the reading is too old to name anything. An empty one is not
     * stale — it is absent, which the page says differently.
     */
    public function isStale(?\DateTimeImmutable $now = null): bool
    {
        if ($this->takenAt === null) {
            return false;
        }

        $age = ($now ?? new \DateTimeImmutable())->getTimestamp() - $this->takenAt->getTimestamp();

        return $age > self::MAX_AGE_DAYS * 86400;
    }

    /** How many ranges the reading holds, for the page's own sentence. */
    public function rangeCount(): int
    {
        return count($this->ranges);
    }

    private static function nothing(): self
    {
        return new self(null, '', null, []);
    }

    /**
     * The chain, breadth-first, each range carrying the token the operator
     * can see in their own record.
     *
     * **Attribution is decided at the top level and then carried down.**
     * `_spf.google.com` includes `_netblocks3.google.com`, which is where
     * the addresses actually are — and which appears nowhere in the unit's
     * record. Naming it would send the volunteer hunting for a string they
     * cannot find. So every range found below a top-level `include:` is
     * reported as that `include:`, however deep it was.
     *
     * @return array{ranges: list<array{via: string, bytes: string, prefix: int}>, partial: ?string}
     */
    private static function walk(string $domain, \Closure $txt): array
    {
        /** @var list<array{host: string, via: string}> $queue */
        $queue = [['host' => $domain, 'via' => $domain]];
        $seen = [strtolower($domain) => true];
        $lookups = 0;
        $ranges = [];
        $keys = [];
        $partial = null;

        while ($queue !== []) {
            $current = array_shift($queue);
            $texts = ($txt)($current['host']);

            // **« Nothing published » and « nobody answered » are opposite
            // readings, and this used to collapse them** (found in review on
            // #571). A resolver that fails midway left the chain short while
            // the stored reading looked complete: sources that ARE covered
            // then read as « Autre » — the safe side — but with nothing on
            // screen to say the reading was partial, which is the failure
            // `DnsRecordReader` names a skipped type to avoid.
            if ($texts === null) {
                $partial ??= self::PARTIAL_UNREADABLE;
                continue;
            }

            $record = self::recordIn($texts);
            if ($record === null) {
                continue;
            }

            // **`all` makes `redirect=` dead text** (RFC 7208 §6.1: « if the
            // SPF record contains an `all` mechanism, the `redirect` modifier
            // MUST be ignored », whatever the order of the terms). Following
            // it anyway would name a target that supplied no part of the
            // record's verdict — « déclarée dans votre SPF, via X » for an X
            // no receiver ever read. `include:` is unaffected: it is a
            // mechanism, evaluated in place, and `all` only ends the list
            // after it.
            $redirectIsDeadText = self::hasAll($record);

            foreach (self::mechanisms($record) as $mechanism) {
                [$name, $value] = $mechanism;

                if ($name === 'redirect' && $redirectIsDeadText) {
                    continue;
                }

                if ($name === 'ip4' || $name === 'ip6') {
                    $range = self::parseRange($name, $value);
                    if ($range === null) {
                        continue;
                    }

                    // Keyed so one range published twice in a chain — a
                    // provider listing a block in two of its own includes —
                    // costs one slot rather than two.
                    $key = bin2hex($range['bytes']) . '/' . $range['prefix'] . '@' . $current['via'];
                    if (isset($keys[$key])) {
                        continue;
                    }

                    if (count($ranges) >= self::MAX_RANGES) {
                        // Named rather than silent, for the reason
                        // `DnsRecordReader` names a skipped type: a reading
                        // that is simply short reads as a record that
                        // authorises less than it does.
                        $partial ??= self::PARTIAL_RANGES;
                        continue;
                    }

                    $keys[$key] = true;
                    $ranges[] = ['via' => $current['via'], 'bytes' => $range['bytes'], 'prefix' => $range['prefix']];
                    continue;
                }

                if ($name !== 'include' && $name !== 'redirect') {
                    continue;
                }

                $next = strtolower(trim($value));
                if ($next === '' || isset($seen[$next])) {
                    // Skipping a host already read is what lets a chain
                    // that revisits one domain be read to its end instead
                    // of spending the budget twice on the same answer. It
                    // also terminates a cyclic record, which the budget
                    // would do anyway — the budget is the bound, this is
                    // what the bound is spent on.
                    continue;
                }

                if ($lookups >= self::MAX_LOOKUPS) {
                    $partial ??= self::PARTIAL_LOOKUPS;
                    continue;
                }

                $lookups++;
                $seen[$next] = true;
                // The attribution becomes this target only at the top
                // level; deeper, whatever it already was is kept.
                $queue[] = [
                    'host' => $next,
                    'via' => $current['via'] === $domain ? $value : $current['via'],
                ];
            }
        }

        return ['ranges' => $ranges, 'partial' => $partial];
    }

    /**
     * The `v=spf1` record among a host's TXT records, or null.
     *
     * **`str_starts_with`, case-sensitive — the same test
     * {@see \Core\Mail\DnsVerifier::checkSpfForHosts()} applies**, so the
     * two screens cannot end up disagreeing about whether this unit has an
     * SPF record at all. RFC 7208 §4.5 makes the version check
     * case-insensitive and neither reader is; a record spelled `V=spf1`
     * would be honoured by receivers and read by neither of these. Recorded
     * separately rather than fixed in one of the two places.
     *
     * @param list<string> $texts
     */
    private static function recordIn(array $texts): ?string
    {
        foreach ($texts as $record) {
            if (str_starts_with($record, 'v=spf1')) {
                return $record;
            }
        }

        return null;
    }

    /**
     * Whether a record carries an `all` mechanism, qualifier or not.
     *
     * Read off the raw record rather than through {@see self::mechanisms()},
     * which only yields terms carrying a value: `all` has neither `:` nor
     * `=`, so it never appears there.
     */
    private static function hasAll(string $record): bool
    {
        foreach (preg_split('/\s+/', trim($record)) ?: [] as $token) {
            if ($token !== '' && in_array($token[0], ['+', '-', '~', '?'], true)) {
                $token = substr($token, 1);
            }

            if (strcasecmp($token, 'all') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The mechanisms of one record as `[name, value]`, lowercased name.
     *
     * **A qualifier other than `+` is a refusal or a doubt, and this class
     * only reports what a record vouches for.** `-ip4:203.0.113.0/24` names
     * a range the operator wrote down in order to REJECT it; putting it on
     * screen as « déclarée dans votre SPF » would report their intent
     * backwards.
     *
     * That rule lives in exactly one place, and it is the `+` below: a
     * qualifier that is not stripped stays glued to the name, so `-ip4`
     * never equals `ip4` and the mechanism is simply not one this class
     * knows. A second test — refusing `-`, `~` and `?` explicitly — read
     * better and was pure ornament: every mutation of it passed, because
     * the name comparison was already doing the work. Two defences that
     * mask each other are one defence and one decoration, and it is the
     * decoration a later reader trusts.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function mechanisms(string $record): array
    {
        $out = [];

        foreach (preg_split('/\s+/', trim($record)) ?: [] as $token) {
            if ($token === '') {
                continue;
            }

            if ($token[0] === '+') {
                $token = substr($token, 1);
            }

            // `redirect=` is a modifier and `include:` a mechanism, which
            // matters to an evaluator and not here: both name another
            // record, and both are a string the operator can see in their
            // own zone. Split on whichever separator comes first so
            // `ip6:2001:db8::/32` is not cut at its own colon.
            $at = strcspn($token, ':=');
            if ($at === strlen($token)) {
                continue;
            }

            $name = strtolower(substr($token, 0, $at));
            $value = substr($token, $at + 1);
            if ($value === '') {
                continue;
            }

            $out[] = [$name, $value];
        }

        return $out;
    }

    /**
     * `198.51.100.0/24` or a bare address, as bytes and a prefix length.
     *
     * The bytes are kept **as published**, unmasked. Canonicalising them
     * here would put the prefix arithmetic in two places — once to store,
     * once to compare — and the copy that is never exercised is the one a
     * later reader trusts. {@see contains()} masks both sides, so it is the
     * only place that knows what a prefix means, and a mutation there
     * fails.
     *
     * **The family has to match the mechanism that declared it**, and
     * `inet_pton()` alone does not say so: it reads `198.51.100.0` happily
     * whoever asked, so `ip6:198.51.100.0/24` — a typo, or a record
     * somebody edited in a hurry — would otherwise be filed as a perfectly
     * good IPv4 range and put « déclarée dans votre SPF » under a source no
     * receiver authorises from it. A test written for the malformed cases
     * is what caught it.
     *
     * @return ?array{bytes: string, prefix: int}
     */
    private static function parseRange(string $name, string $value): ?array
    {
        $slash = strrpos($value, '/');
        $address = $slash === false ? $value : substr($value, 0, $slash);
        $bytes = @inet_pton(trim($address));
        if ($bytes === false) {
            return null;
        }

        if (strlen($bytes) !== ($name === 'ip4' ? 4 : 16)) {
            return null;
        }

        $bits = strlen($bytes) * 8;
        if ($slash === false) {
            return ['bytes' => $bytes, 'prefix' => $bits];
        }

        $written = substr($value, $slash + 1);
        if ($written === '' || preg_match('/^\d{1,3}$/', $written) !== 1) {
            return null;
        }

        $prefix = (int) $written;

        return $prefix > $bits ? null : ['bytes' => $bytes, 'prefix' => $prefix];
    }

    /**
     * Whether a packed address falls inside a packed range.
     *
     * **The one place a prefix length means anything.** Both sides are
     * masked here, which is why nothing else needs to.
     */
    private static function contains(string $network, int $prefix, string $candidate): bool
    {
        // Different families are different lengths, and an `ip4:` range can
        // no more contain an IPv6 address than the reverse.
        if (strlen($network) !== strlen($candidate)) {
            return false;
        }

        $whole = intdiv($prefix, 8);
        if ($whole > 0 && substr($network, 0, $whole) !== substr($candidate, 0, $whole)) {
            return false;
        }

        $bits = $prefix % 8;
        if ($bits === 0) {
            return true;
        }

        $mask = chr(0xFF << (8 - $bits) & 0xFF);

        return ($network[$whole] & $mask) === ($candidate[$whole] & $mask);
    }

    /**
     * The stored ranges, refusing anything this object could not use.
     *
     * **This repeats the parser's prefix test, and it is not the parser's
     * test.** Here the input is JSON that has been through the database
     * since it was written — by a hand, by an older version of this class,
     * by a restore — so the refusal defends this object's own invariant.
     * There the input is a DNS record, and the refusal is about what the
     * record said. The two are easy to mistake for one because every test
     * in this class goes through the round trip, which lets either one
     * carry the other: {@see
     * \Tests\Core\Mail\Feedback\Dmarc\SpfCoverageTest
     * ::testAMalformedRangeDoesNotSpendASlotOnItsWayToBeingDropped()} is
     * the case that tells them apart, and it exists because a mutation
     * showed that nothing else did.
     *
     * @return list<array{via: string, bytes: string, prefix: int}>
     */
    private static function decodeRanges(mixed $stored, mixed $storedVias): array
    {
        $vias = [];
        foreach (is_array($storedVias) ? $storedVias : [] as $via) {
            if (is_string($via) && $via !== '') {
                $vias[] = $via;
            }
        }

        $ranges = [];

        foreach (is_array($stored) ? $stored : [] as $row) {
            if (!is_array($row)) {
                continue;
            }

            // The index into the names above. A row pointing outside it is a
            // row this reader cannot attribute, which is dropped like any
            // other it cannot use — never attributed to whatever happens to
            // sit at index zero.
            $via = isset($row['v']) && is_int($row['v']) ? ($vias[$row['v']] ?? '') : '';
            $hex = isset($row['b']) && is_string($row['b']) ? $row['b'] : '';
            $prefix = isset($row['p']) ? (int) $row['p'] : -1;
            $bytes = $hex === '' ? false : @hex2bin($hex);

            if ($via === '' || $bytes === false || $prefix < 0 || $prefix > strlen($bytes) * 8) {
                continue;
            }

            $ranges[] = ['via' => $via, 'bytes' => $bytes, 'prefix' => $prefix];
        }

        return $ranges;
    }
}
