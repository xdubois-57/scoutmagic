<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail;

/**
 * What a raw header block says about a message's authentication
 * (roadmap IT-27).
 *
 * **A reading, never a verdict.** SPF, DKIM and DMARC are answers a
 * receiving server wrote down, and this only reports what it found:
 * `pass`, `fail`, `none`, or « non renseigné » when the header is absent
 * — which is a different thing from failing, and the one a maintainer
 * must not confuse. Nothing here re-checks a signature or resolves a
 * record; that is the receiving MTA's job and it already did it.
 *
 * The relay chain is kept because a message arriving late is a question
 * only the `Received` lines can answer, and it is exactly why the whole
 * block is stored encrypted and shown to a superadmin alone: those lines
 * carry IP addresses and server names.
 */
final class MailAuthenticationResults
{
    /** How many `Received` lines are kept. Beyond this the chain is noise. */
    private const MAX_RELAYS = 12;

    /**
     * @return array{spf: string, dkim: string, dmarc: string, relays: list<string>}
     */
    public static function parse(?string $rawHeaders): array
    {
        $headers = (string) $rawHeaders;

        return [
            'spf' => self::verdict($headers, 'spf'),
            'dkim' => self::verdict($headers, 'dkim'),
            'dmarc' => self::verdict($headers, 'dmarc'),
            'relays' => self::relays($headers),
        ];
    }

    /**
     * The verdict for one mechanism: from any `Authentication-Results`
     * first, then from the mechanism's own header where there is one
     * (`Received-SPF`), because plenty of hosts write one and not the
     * other.
     */
    private static function verdict(string $headers, string $mechanism): string
    {
        // EVERY `Authentication-Results`, not just the first. Each hop
        // that checks anything prepends one of its own, and plenty of
        // providers write one header per mechanism — so reading only the
        // first reported « absent » for a mechanism that had passed two
        // lines further down. The topmost one that names the mechanism
        // wins, which is the last server to have checked it.
        //
        // The header folds across lines, so the continuation lines
        // (leading whitespace) belong to it too.
        foreach (self::unfoldAll($headers, 'Authentication-Results') as $block) {
            if (preg_match('/\b' . preg_quote($mechanism, '/') . '=([a-z]+)/i', $block, $m) === 1) {
                return strtolower($m[1]);
            }
        }

        if ($mechanism === 'spf' && preg_match('/^Received-SPF:\s*([a-z]+)/mi', $headers, $m) === 1) {
            return strtolower($m[1]);
        }

        if ($mechanism === 'dkim' && preg_match('/^DKIM-Signature:/mi', $headers) === 1) {
            // A signature is present and nobody wrote down whether it
            // verified: that is « signée, verdict inconnu », not a pass.
            return 'unverified';
        }

        return 'absent';
    }

    /**
     * Whether the receiving server vouched for the `From:` address — the
     * one place a module may ask « did this really come from that
     * address? » (issue #1121). Unlike parse(), this IS a verdict, and it
     * is deliberately narrow.
     *
     * **Only the topmost `Authentication-Results` is believed.** It is the
     * one the receiving provider of the monitored mailbox prepended last;
     * anything below it may have been written by whoever sent the
     * message. parse() reads every header because a diagnosis wants the
     * most information; a trust decision wants the least forgeable, and
     * those are opposite choices. The residual gap is documented: a
     * provider that writes no such header at all leaves the sender's own
     * forged one on top. docs/inbound-mail-setup.md asks for a provider
     * that writes it.
     *
     * Inside that header, DMARC decides when it is there — it is the
     * mechanism that ties SPF or DKIM to the `From:` domain. Without it,
     * an SPF or DKIM pass counts only when its domain is aligned with the
     * `From:` domain (the same or a parent/child of it: relaxed alignment
     * approximated without a public-suffix list). Everything else —
     * no header, a failure, an unaligned pass — is « not verified ».
     */
    public static function senderVerified(?string $rawHeaders, string $fromEmail): bool
    {
        $fromDomain = self::domainOf($fromEmail);
        $topmost = self::unfoldAll((string) $rawHeaders, 'Authentication-Results')[0] ?? null;
        if ($fromDomain === '' || $topmost === null) {
            return false;
        }

        $results = self::resultsOf($topmost);

        $dmarc = array_values(array_filter($results, static fn(array $r): bool => $r['method'] === 'dmarc'));
        if ($dmarc !== []) {
            foreach ($dmarc as $result) {
                // No readable header.from — absent, or quoted and so emptied by
                // segments() — is no alignment, not an assumed one.
                $headerFrom = $result['props']['header.from'] ?? '';
                if ($result['verdict'] !== 'pass' || !self::aligned(self::domainOf($headerFrom), $fromDomain)) {
                    return false;
                }
            }

            return true;
        }

        foreach ($results as $result) {
            if ($result['verdict'] !== 'pass') {
                continue;
            }
            $domain = match ($result['method']) {
                'spf' => self::domainOf($result['props']['smtp.mailfrom'] ?? $result['props']['smtp.helo'] ?? ''),
                'dkim' => self::domainOf($result['props']['header.d'] ?? $result['props']['header.i'] ?? ''),
                default => '',
            };
            if ($domain !== '' && self::aligned($domain, $fromDomain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The `method=result` entries of one header, with their `ptype.property=value`
     * pairs (RFC 8601).
     *
     * Read by tokenizer, never by splitting the raw text, because two
     * things in this header are free text a sender influences and may say
     * anything: comments in parentheses — which NEST, and may escape a
     * parenthesis with a backslash — and quoted strings such as `reason=`.
     * Neither may contribute a separator, a method or a property: a `;`
     * inside `reason="…"` must not start a new result, and a property
     * inside `(outer (inner) …)` must not overwrite the real one. Comments
     * are dropped; a quoted string is kept as an empty `""`, so whatever
     * property it was the value of reads as nothing — which fails closed.
     *
     * @return list<array{method: string, verdict: string, props: array<string, string>}>
     */
    private static function resultsOf(string $header): array
    {
        $results = [];

        // The first part is the authserv-id (`mx.google.com`) — except where
        // a provider writes none and starts with a result, as Microsoft 365
        // does: every part that reads as `method=result` is a result,
        // whichever position it holds. A name never does.
        foreach (self::segments($header) as $entry) {
            if (preg_match('/^\s*([a-z0-9-]+)\s*=\s*([a-z]+)\b(.*)$/is', $entry, $m) !== 1) {
                continue;
            }
            preg_match_all('/([a-z0-9-]+\.[a-z0-9-]+)\s*=\s*([^\s;"]+)/i', $m[3], $pairs, PREG_SET_ORDER);
            $props = [];
            foreach ($pairs as $pair) {
                $props[strtolower($pair[1])] = $pair[2];
            }
            $results[] = ['method' => strtolower($m[1]), 'verdict' => strtolower($m[2]), 'props' => $props];
        }

        return $results;
    }

    /**
     * The header cut on the `;` that separate its parts, comments removed
     * and quoted strings emptied — one character at a time, so that depth,
     * escapes and quotes are tracked rather than guessed.
     *
     * @return list<string>
     */
    private static function segments(string $header): array
    {
        $segments = [];
        $current = '';
        $depth = 0;
        $quoted = false;
        $length = strlen($header);

        for ($i = 0; $i < $length; $i++) {
            $char = $header[$i];

            if ($char === '\\' && ($quoted || $depth > 0)) {
                $i++; // a quoted-pair: the next character is literal, and not ours to read
                continue;
            }
            if ($quoted) {
                if ($char === '"') {
                    $quoted = false;
                    $current .= '"';
                }
                continue;
            }
            if ($char === '(') {
                $depth++;
                continue;
            }
            if ($depth > 0) {
                if ($char === ')') {
                    $depth--;
                    $current .= ' ';
                }
                continue;
            }
            if ($char === '"') {
                $quoted = true;
                $current .= '"';
                continue;
            }
            if ($char === ';') {
                $segments[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $segments[] = $current;

        return $segments;
    }

    /** The domain of an address, or of a bare domain; '' when there is none. */
    private static function domainOf(string $value): string
    {
        $value = strtolower(trim($value, " \t<>\""));
        $at = strrpos($value, '@');

        return rtrim($at === false ? $value : substr($value, $at + 1), '.');
    }

    /** Relaxed alignment, approximated: equal, or one a subdomain of the other. */
    private static function aligned(string $domain, string $fromDomain): bool
    {
        return $domain !== '' && (
            $domain === $fromDomain
            || str_ends_with($fromDomain, '.' . $domain)
            || str_ends_with($domain, '.' . $fromDomain)
        );
    }

    /**
     * @return list<string>
     */
    private static function relays(string $headers): array
    {
        $unfolded = self::unfoldAll($headers, 'Received');
        $relays = [];

        foreach ($unfolded as $line) {
            $relays[] = trim(preg_replace('/\s+/', ' ', $line) ?? $line);
            if (count($relays) >= self::MAX_RELAYS) {
                break;
            }
        }

        return $relays;
    }

    /**
     * Every occurrence of one header, folded continuation lines included.
     *
     * @return list<string>
     */
    private static function unfoldAll(string $headers, string $name): array
    {
        $lines = preg_split('/\R/', $headers) ?: [];
        $found = [];
        $current = null;

        foreach ($lines as $line) {
            if (preg_match('/^' . preg_quote($name, '/') . ':\s*(.*)$/i', $line, $m) === 1) {
                if ($current !== null) {
                    $found[] = $current;
                }
                $current = $m[1];
                continue;
            }

            if ($current !== null && preg_match('/^[ \t]/', $line) === 1) {
                $current .= ' ' . trim($line);
                continue;
            }

            if ($current !== null) {
                $found[] = $current;
                $current = null;
            }
        }

        if ($current !== null) {
            $found[] = $current;
        }

        return $found;
    }
}
