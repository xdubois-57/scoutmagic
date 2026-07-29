<?php

declare(strict_types=1);

namespace Modules\Retro\Service;

use Core\Security\EncryptionService;
use Modules\Retro\Repository\RateLimitRepository;

/**
 * Anti-spam without JournalService (module spec: even a timestamped stream
 * of board events in the journal could be correlated with other logs to
 * deanonymise a participant) — a small dedicated table with short-lived
 * rows instead, purged by Task\PurgeRateLimitHandler.
 *
 * identifierFor() never stores or returns a raw cookie/session value —
 * only a one-way HMAC (same Core\Security\EncryptionService::blindIndex()
 * technique as vote deduplication), so the rate-limit table itself carries
 * no reversible link to a person either.
 */
class RateLimitService
{
    private const WINDOW_MINUTES = 10;

    /** @var array<string, int> */
    private const LIMITS = [
        'comment' => 10,
        'vote' => 40,
    ];

    public function __construct(
        private RateLimitRepository $repository,
        private EncryptionService $encryption
    ) {
    }

    /**
     * A stable-for-this-visitor identifier: the anonymous functional
     * cookie value when set, otherwise the PHP session id (ephemeral,
     * resets every browser session — acceptable, this is a best-effort
     * throttle, not a security boundary).
     */
    public function identifierHash(?string $cookieValue, string $sessionId): string
    {
        $raw = $cookieValue ?? $sessionId;

        return $this->encryption->blindIndex('retro_rate_limit:' . $raw);
    }

    /**
     * @throws RetroException when the identifier has exceeded the limit for $actionType
     */
    public function checkAndRecord(string $identifierHash, string $actionType): void
    {
        $limit = self::LIMITS[$actionType] ?? PHP_INT_MAX;
        $since = (new \DateTimeImmutable('-' . self::WINDOW_MINUTES . ' minutes'))->format('Y-m-d H:i:s');

        if ($this->repository->countSince($identifierHash, $actionType, $since) >= $limit) {
            throw new RetroException('Trop d\'actions récentes — merci de patienter quelques instants.', RetroException::TYPE_RATE_LIMITED);
        }

        $this->repository->record($identifierHash, $actionType);
    }
}
