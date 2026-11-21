<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Service;

use Modules\InboundMail\Client\MailboxConnectionException;
use Modules\InboundMail\Mailbox\MailboxCredentials;

/** Safe diagnostic metadata, kept separate from the operator-facing error. */
final class MailboxFailureDiagnostic
{
    private const PHRASES = [
        'connection refused', 'connection timed out', 'timed out', 'failed to enable tls',
        'certificate verify failed', 'empty response', "folder doesn't exist",
        'failed to write - connection closed?', 'connection closed', 'authentication failed',
    ];

    /** @param array<string, mixed> $details */
    private function __construct(public readonly string $step, public readonly array $details)
    {
    }

    public static function from(\Throwable $error, MailboxCredentials $credentials): self
    {
        $step = $error instanceof MailboxConnectionException ? ($error->step ?? 'unknown') : 'processing';
        $classes = [];
        $phrases = [];
        $details = [];
        $responses = [];
        for ($cause = $error; $cause !== null && count($classes) < 8; $cause = $cause->getPrevious()) {
            if (in_array($step, ['unknown', 'processing'], true)
                && $cause instanceof MailboxConnectionException && $cause->step !== null) {
                $step = $cause->step;
            }
            $class = (new \ReflectionClass($cause))->isAnonymous()
                ? 'AnonymousException' : MailboxConnectionException::shortClass($cause);
            $classes[] = $class;
            $message = $cause->getMessage();
            foreach (self::PHRASES as $phrase) {
                if (str_contains(strtolower($message), $phrase)) {
                    $phrases[$phrase] = $phrase;
                }
            }
            if ($class === 'ConnectionFailedException' && $cause->getCode() !== 0) {
                $details['errno'] = $cause->getCode();
                // strerror() is a local fixed vocabulary, never a server-supplied sentence.
                if (function_exists('socket_strerror') && is_int($cause->getCode())) {
                    $details['network_reason'] = socket_strerror($cause->getCode());
                }
            }
            if (preg_match('/\berror:([0-9a-f]{8,16}):[^\r\n:]*:[^\r\n:]*:([a-z0-9 ]+)/i', $message, $ssl) === 1) {
                $details['openssl_code'] = strtoupper($ssl[1]);
                // Only fixed OpenSSL reasons are copied, not arbitrary trailing text.
                foreach (['certificate verify failed', 'wrong version number', 'unsupported protocol',
                    'tlsv1 alert protocol version', 'sslv3 alert handshake failure'] as $reason) {
                    if (strtolower(trim($ssl[2])) === $reason) {
                        $details['openssl_reason'] = $reason;
                    }
                }
            }
            // Only an IMAP response, never a processing exception or a debug transcript.
            if (in_array($class, ['ImapServerErrorException', 'ImapBadRequestException', 'ResponseException'], true)) {
                foreach (preg_split('/\r\n|\r|\n/', $message) ?: [] as $line) {
                    if (strlen($line) > 16384) {
                        continue;
                    }
                    if (preg_match('/^\s*(?:-\s*)?(?:(?:\*|[A-Za-z0-9]+)\s+)?(NO|BAD|BYE)\b(.*)$/i', $line, $response) === 1) {
                        $details['imap_status'] = strtoupper($response[1]);
                        if (preg_match('/^\s*\[([A-Z][A-Z0-9-]{0,63})\]/i', $response[2], $code) === 1) {
                            $details['imap_code'] = strtoupper($code[1]);
                        }
                        $responses[] = self::redact($response[1] . $response[2], $credentials);
                    }
                }
            }
        }
        $details['exception_classes'] = $classes;
        $details['library_reasons'] = array_values($phrases);
        if ($responses !== []) {
            $details['server_response'] = mb_substr(implode(' | ', array_unique($responses)), 0, 300);
        }

        return new self($step, $details);
    }

    /** A stable failure nature: changing server prose never floods the scheduled journal. */
    public function signature(): string
    {
        $codes = $this->details;
        unset($codes['server_response']);
        ksort($codes);

        return hash('sha256', $this->step . (string) json_encode($codes));
    }

    private static function redact(string $text, MailboxCredentials $credentials): string
    {
        $text = self::withoutControls($text);
        $secrets = [];
        foreach ([$credentials->username, $credentials->password,
            "\0" . $credentials->username . "\0" . $credentials->password,
            $credentials->username . "\0" . $credentials->username . "\0" . $credentials->password] as $secret) {
            if ($secret !== '') {
                $secrets[] = $secret;
                $secrets[] = addcslashes($secret, '\\"');
                $secrets[] = base64_encode($secret);
            }
        }
        // Compare credentials in the same normal form as the response.
        $secrets = array_values(array_filter(array_unique(array_map(self::withoutControls(...), $secrets)),
            static fn(string $secret): bool => $secret !== ''));
        usort($secrets, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        $text = str_replace($secrets, '[masqué]', $text);
        $text = preg_replace('/\b(LOGIN|AUTHENTICATE)\b[^\r\n]*/i', '$1 [masqué]', $text) ?? '';
        $text = preg_replace('/[^\s<>"()\[\],;:]+@[^\s<>"()\[\],;:]+/u', '[masqué]', $text) ?? '';

        return mb_substr($text, 0, 300);
    }

    private static function withoutControls(string $text): string
    {
        return preg_replace('/[\p{Cc}\p{Cf}]/u', '', mb_scrub($text, 'UTF-8')) ?? '';
    }
}
