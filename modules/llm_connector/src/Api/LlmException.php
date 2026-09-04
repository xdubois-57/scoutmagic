<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\LlmConnector\Api;

use Core\Exception\UserFacingException;

/**
 * Exception thrown by the LLM connector when a request cannot be fulfilled.
 *
 * Every message this class carries is written for the person looking at the
 * screen, which is what {@see UserFacingException} claims — so the provider's
 * own words never go in it. `apiError()` used to build `'Erreur API : ' .
 * "HTTP 401 — {\"type\":\"error\",\"error\":{\"message\":\"invalid x-api-key\"}}"`
 * from Provider\HttpTransport and that string was rendered verbatim in a
 * flash message and a JSON body.
 *
 * The detail is not lost: it travels in {@see self::$detail}, which
 * Service\LlmConnectorService journals and which a caller may log, and the
 * HTTP status — short, stable, and genuinely useful to whoever configured
 * the provider — stays in the sentence itself.
 */
class LlmException extends \RuntimeException implements UserFacingException
{
    public const NO_PROVIDER = 1;
    public const NO_MODEL = 2;
    public const RATE_LIMITED = 3;
    public const TIMEOUT = 4;
    public const API_ERROR = 5;

    /**
     * The provider's own words — an HTTP response body, a driver name, a
     * JSON error object. For the journal and the log, never for the page:
     * it is English, it is written by a third party, and it is exactly what
     * getMessage() must not contain.
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

    public static function noProvider(): self
    {
        return new self('Aucun fournisseur IA actif configuré.', self::NO_PROVIDER);
    }

    public static function noModel(LlmTier $tier): self
    {
        return new self("Aucun modèle assigné au tier « {$tier->value} ».", self::NO_MODEL);
    }

    /**
     * @param string $detail the provider's own rate-limit response, kept out
     *                       of the message and carried for the journal
     */
    public static function rateLimited(string $detail = ''): self
    {
        return new self(
            'Le fournisseur IA a reçu trop de requêtes coup sur coup — réessayez dans quelques minutes.',
            self::RATE_LIMITED,
            null,
            $detail !== '' ? $detail : null
        );
    }

    public static function timeout(): self
    {
        return new self('Timeout lors de l\'appel au fournisseur IA.', self::TIMEOUT);
    }

    /**
     * @param string   $detail     the provider's own error text — journalled,
     *                             never displayed
     * @param int|null $httpStatus kept in the sentence on purpose: it is
     *                             short, it is not the provider's prose, and
     *                             it is the one thing that tells an admin
     *                             whether to check the key (401/403) or just
     *                             retry (5xx)
     */
    public static function apiError(string $detail, ?int $httpStatus = null): self
    {
        $message = $httpStatus !== null
            ? "Le fournisseur IA a refusé la requête (erreur HTTP {$httpStatus}) — vérifiez la clé et l'adresse du "
                . "fournisseur, puis réessayez."
            : 'Le fournisseur IA a renvoyé une réponse inattendue — réessayez dans quelques instants.';

        return new self($message, self::API_ERROR, null, $detail !== '' ? $detail : null);
    }

    /**
     * The request could not even be built — our side of the wire, not the
     * provider's (an unencodable prompt, see Provider\HttpTransport).
     */
    public static function invalidRequest(string $detail): self
    {
        return new self(
            'La requête n\'a pas pu être préparée pour le fournisseur IA — le contenu à envoyer contient des '
                . 'caractères illisibles.',
            self::API_ERROR,
            null,
            $detail !== '' ? $detail : null
        );
    }

    /**
     * A provider row naming a driver this build has no implementation for —
     * a configuration problem, not a call that failed.
     */
    public static function unknownDriver(string $driver): self
    {
        return new self(
            'Ce type de fournisseur IA n\'est pas pris en charge — choisissez un fournisseur dans la liste proposée.',
            self::API_ERROR,
            null,
            "Unknown driver: {$driver}"
        );
    }
}
