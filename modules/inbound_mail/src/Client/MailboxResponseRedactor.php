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
        return str_replace(self::knownSecrets($credentials), '[masqué]', $text);
    }

    public static function redact(string $text, MailboxCredentials $credentials): string
    {
        $text = self::withoutControls($text);
        // Compare credentials in the same normal form as the response.
        $secrets = array_values(array_filter(array_unique(array_map(self::withoutControls(...), self::knownSecrets($credentials))),
            static fn(string $secret): bool => $secret !== ''));
        usort($secrets, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        $text = str_replace($secrets, '[masqué]', $text);
        $text = preg_replace('/\b(LOGIN|AUTHENTICATE)\b[^\r\n]*/i', '$1 [masqué]', $text) ?? '';
        $text = preg_replace(self::EMAIL_PATTERN, '[masqué]', $text) ?? '';

        return mb_substr($text, 0, 300);
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
