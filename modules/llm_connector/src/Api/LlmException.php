<?php

declare(strict_types=1);

namespace Modules\LlmConnector\Api;

/**
 * Exception thrown by the LLM connector when a request cannot be fulfilled.
 */
class LlmException extends \RuntimeException
{
    public const NO_PROVIDER = 1;
    public const NO_MODEL = 2;
    public const RATE_LIMITED = 3;
    public const TIMEOUT = 4;
    public const API_ERROR = 5;

    public static function noProvider(): self
    {
        return new self('Aucun fournisseur IA actif configuré.', self::NO_PROVIDER);
    }

    public static function noModel(LlmTier $tier): self
    {
        return new self("Aucun modèle assigné au tier « {$tier->value} ».", self::NO_MODEL);
    }

    public static function rateLimited(string $message = ''): self
    {
        return new self('Rate limit atteint : ' . ($message ?: 'réessayez plus tard.'), self::RATE_LIMITED);
    }

    public static function timeout(): self
    {
        return new self('Timeout lors de l\'appel au fournisseur IA.', self::TIMEOUT);
    }

    public static function apiError(string $message): self
    {
        return new self('Erreur API : ' . $message, self::API_ERROR);
    }
}
