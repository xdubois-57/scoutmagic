<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SosStaff\Provider\Ovh;

use Core\Exception\UserFacingException;

/**
 * Low-level OVH REST API failure (network error or an HTTP 4xx/5xx from
 * OVH) — caught and re-thrown as Provider\ProviderException by
 * OvhTelephonyProvider, which is the only class other module code should
 * depend on.
 *
 * Marked {@see UserFacingException}: every message here is a French
 * sentence written for the admin configuring the telephony provider. What
 * is NOT in the message is OVH's or cURL's own words — this class used to
 * be built as `"Erreur réseau lors de l'appel à l'API OVH : {$curlError}"`
 * and from the API's `message` field, both of which reached the
 * configuration page verbatim. They now travel in {@see self::$detail},
 * for the journal and the log; the HTTP status stays in the sentence,
 * because it is short, safe and the one thing that distinguishes "vos
 * droits sont insuffisants" from "OVH est en panne".
 */
class OvhApiException extends \RuntimeException implements UserFacingException
{
    /**
     * cURL's error text, or the body OVH answered with — English, written
     * by a third party, and never part of getMessage().
     */
    public readonly ?string $detail;

    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
        ?string $detail = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->detail = $detail;
    }

    /**
     * No response at all: DNS, TLS, connection refused, timeout.
     */
    public static function networkFailure(string $detail): self
    {
        return new self(
            'Le service de téléphonie OVH est injoignable — vérifiez la connexion réseau du serveur, puis réessayez.',
            0,
            null,
            $detail !== '' ? $detail : null
        );
    }

    /**
     * OVH answered, and said no.
     */
    public static function httpError(int $status, string $detail): self
    {
        return new self(
            "L'API OVH a refusé la requête (erreur HTTP {$status}) — vérifiez les clés d'API et les droits "
                . "accordés, puis réessayez.",
            0,
            null,
            $detail !== '' ? $detail : null
        );
    }
}
