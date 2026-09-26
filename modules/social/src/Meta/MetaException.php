<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Meta;

use Core\Exception\UserFacingException;

/**
 * A call to Meta that did not give what was asked.
 *
 * Two halves, as for the Google Drive connection: the message is a French
 * sentence written for the administrator and safe to display; `detail` is
 * what Meta itself said — its error code and message, tokens redacted —
 * for the journal only. Meta's prose is English, and it has no place in a
 * sentence a volunteer reads.
 *
 * `transient` marks a failure that says nothing about the authorisation —
 * no answer, a 5xx, a rate limit: a check keeps its last verdict on one.
 */
final class MetaException extends \RuntimeException implements UserFacingException
{
    /** Meta's code for a token that is invalid, expired or withdrawn. */
    private const INVALID_TOKEN_CODE = 190;

    public function __construct(
        string $message,
        public readonly string $detail = '',
        public readonly bool $authRefused = false,
        public readonly bool $transient = false
    ) {
        parent::__construct($message);
    }

    public static function unreachable(): self
    {
        return new self(
            'Meta ne répond pas. Vérifiez la connexion du serveur à Internet, puis réessayez.',
            'no response',
            false,
            true
        );
    }

    /**
     * An error answer. A refused token says so, because its remedy is
     * different — reconnect — from everything else's — retry.
     */
    public static function fromError(int $status, int $code, string $metaMessage): self
    {
        $detail = trim(sprintf('HTTP %d, code %d: %s', $status, $code, self::redact($metaMessage)));

        if ($code === self::INVALID_TOKEN_CODE || $status === 401) {
            return new self(
                'Meta a refusé l\'autorisation de ce site : elle a expiré ou a été retirée. Reconnectez le compte.',
                $detail,
                true
            );
        }

        // Meta down, or asking to slow down: says nothing about the
        // authorisation, so a check must not conclude that it was refused.
        if ($status >= 500 || $status === 429) {
            return new self('Meta ne répond pas pour l\'instant. Réessayez plus tard.', $detail, false, true);
        }

        return new self('Meta a refusé la demande. Le journal en garde le détail.', $detail);
    }

    public static function unexpected(string $what): self
    {
        return new self('Meta a répondu d\'une façon inattendue. Réessayez plus tard.', $what);
    }

    /**
     * Anything that looks like a token or a secret — a long unbroken run of
     * letters and digits — is replaced before it can reach the journal.
     * Meta's messages do not normally quote one; « normally » is not a
     * guarantee this module can give on Meta's behalf.
     */
    public static function redact(string $text): string
    {
        $text = (string) preg_replace('/[A-Za-z0-9_\-|]{32,}/', '[…]', $text);

        return mb_strimwidth(trim($text), 0, 300, '…');
    }
}
