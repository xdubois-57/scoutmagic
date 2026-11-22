<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Client;

use Modules\InboundMail\Mailbox\MailboxCredentials;

/** Shared masking at the protocol boundary and before a diagnostic is journaled. */
final class MailboxResponseRedactor
{
    private const EMAIL_PATTERN = <<<'REGEX'
/(?:"(?:[^"\\\r\n]|\\.)*"|[^\s<>"()\[\],;:]+)@(?:\[[^\]\r\n]+\]|[^\s<>"()\[\],;:]+)/u
REGEX;

    public static function maskKnownSecrets(string $text, #[\SensitiveParameter] MailboxCredentials $credentials): string
    {
        return self::maskOverlappingIdentifiers($text, self::knownSecrets($credentials));
    }

    public static function redact(string $text, MailboxCredentials $credentials): string
    {
        $text = self::withoutControls($text);
        // Compare credentials in the same normal form as the response.
        $secrets = array_values(array_filter(array_unique(array_map(self::withoutControls(...), self::knownSecrets($credentials))),
            static fn(string $secret): bool => $secret !== ''));
        usort($secrets, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        $text = self::maskOverlappingIdentifiers($text, $secrets);
        return mb_substr($text, 0, 300);
    }

    /** @param list<string> $secrets */
    private static function maskOverlappingIdentifiers(string $text, array $secrets): string
    {
        $ranges = [];
        foreach ($secrets as $secret) {
            if ($secret === '') {
                continue;
            }
            $offset = 0;
            while (($start = strpos($text, $secret, $offset)) !== false) {
                $ranges[] = [$start, $start + strlen($secret)];
                $offset = $start + 1;
            }
        }
        // Raw protocol text can contain invalid UTF-8. Byte matching preserves
        // offsets and still hides whole addresses before credential replacement.
        $unicode = mb_check_encoding($text, 'UTF-8') ? 'u' : '';
        $emailPattern = $unicode === '' ? substr(self::EMAIL_PATTERN, 0, -1) : self::EMAIL_PATTERN;
        preg_match_all($emailPattern, $text, $emails, PREG_OFFSET_CAPTURE);
        foreach ($emails[0] as [$email, $start]) {
            $ranges[] = [$start, $start + strlen($email)];
        }
        // Locate command payloads in that same original text: a credential
        // matching part of LOGIN must not prevent its payload from being hidden.
        $gap = '(?:(?![\r\n])[\p{Cc}\p{Cf}])*';
        $verbs = '(?:' . implode('|', array_map(
            static fn(string $verb): string => implode($gap, str_split($verb)),
            ['LOGIN', 'AUTHENTICATE']
        )) . ')';
        $commands = [
            // Uppercase commands are hidden even when echoed inside prose.
            '/\b' . $verbs . '\b[ \t]+([^\r\n]+)/' . $unicode,
            // Lower/mixed-case commands need a response position, not "Your login".
            '/^(?:[ \t]*-[ \t]+)?(?:[A-Z]*[0-9]+[ \t]+)?(?:NO|BAD|BYE)(?:[ \t]+\[[^\]\r\n]*\])?[ \t]+'
                . '(?:[A-Z]*[0-9]+[ \t]+)?' . $verbs . '\b[ \t]+([^\r\n]+)/im' . $unicode,
        ];
        foreach ($commands as $pattern) {
            preg_match_all($pattern, $text, $payloads, PREG_OFFSET_CAPTURE);
            foreach ($payloads[1] as [$payload, $start]) {
                $ranges[] = [$start, $start + strlen($payload)];
            }
        }
        usort($ranges, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($ranges as [$start, $end]) {
            $last = count($merged) - 1;
            if ($last >= 0 && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }
        $masked = '';
        $offset = 0;
        foreach ($merged as [$start, $end]) {
            // Masking must not join protocol records or move later message
            // content into the first response line retained by diagnostics.
            $lineBreaks = preg_replace('/[^\r\n]/', '', substr($text, $start, $end - $start)) ?? '';
            $masked .= substr($text, $offset, $start - $offset) . '[masqué]' . $lineBreaks;
            $offset = $end;
        }

        return $masked . substr($text, $offset);
    }

    /** @return list<string> */
    private static function knownSecrets(MailboxCredentials $credentials): array
    {
        $secrets = [];
        foreach ([$credentials->username, $credentials->password,
            "\0" . $credentials->username . "\0" . $credentials->password,
            $credentials->username . "\0" . $credentials->username . "\0" . $credentials->password] as $secret) {
            if ($secret !== '') {
                $secrets[] = $secret;
                // A protocol read may end before an echoed multiline credential does.
                foreach (preg_split('/\r\n|\r|\n/', $secret) ?: [] as $part) {
                    if ($part !== '') {
                        $secrets[] = $part;
                    }
                }
                $secrets[] = addcslashes($secret, '\\"');
                $secrets[] = base64_encode($secret);
            }
        }
        usort($secrets, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        return array_values(array_unique($secrets));
    }

    private static function withoutControls(string $text): string
    {
        return preg_replace('/[\p{Cc}\p{Cf}]/u', '', mb_scrub($text, 'UTF-8')) ?? '';
    }
}
