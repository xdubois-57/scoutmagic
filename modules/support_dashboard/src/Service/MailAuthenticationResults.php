<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Service;

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
