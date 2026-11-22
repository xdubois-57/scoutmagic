<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Service;

use Modules\InboundMail\Client\MailboxConnectionException;
use Modules\InboundMail\Client\MailboxResponseRedactor;
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
                // A credential containing a line break must be masked before splitting:
                // otherwise the first response line could retain part of that credential.
                $serverMessage = MailboxResponseRedactor::maskKnownSecrets($message, $credentials);
                $lines = preg_split('/\r\n|\r|\n/', $serverMessage) ?: [];
                $candidates = [$lines[0] ?? ''];
                // Recognize the fixed library frame before masking: a username
                // such as "Command" can also occur in that frame.
                if ($class === 'ResponseException'
                    && preg_match('/\ACommand failed to process:\r?\nCauses:\r?\n/', $message) === 1) {
                    $candidates = [];
                    // Validation reports causes as bullets. Stop before its debug
                    // transcript; never search arbitrary later lines for a status.
                    foreach (array_slice($lines, 2) as $line) {
                        if (!str_starts_with($line, "\t- ")) {
                            break;
                        }
                        $candidates[] = substr($line, 3);
                    }
                }
                foreach ($candidates as $line) {
                    if (strlen($line) > 16384) {
                        continue;
                    }
                    if (preg_match('/^\s*(?:(?:\*|[A-Za-z0-9]+)\s+)?(NO|BAD|BYE)\b(.*)$/', $line, $response) === 1) {
                        $details['imap_status'] = strtoupper($response[1]);
                        if (preg_match('/^\s*\[([A-Z][A-Z0-9-]{0,63})\]/i', $response[2], $code) === 1) {
                            $details['imap_code'] = strtoupper($code[1]);
                        } else {
                            unset($details['imap_code']);
                        }
                        $responses[] = MailboxResponseRedactor::redact($response[1] . $response[2], $credentials);
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
}
