<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

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

    /**
     * An action type absent from this map is unlimited (checkAndRecord()
     * falls back to PHP_INT_MAX), so every throttled action must be listed
     * here — adding the call site alone silently does nothing.
     *
     * @var array<string, int>
     */
    private const LIMITS = [
        'comment' => 10,
        'vote' => 40,
        // Each one is a paid LLM round-trip on a route open to anonymous
        // visitors, so it is the tightest of the three.
        'shorten' => 5,
    ];

    public function __construct(
        private RateLimitRepository $repository,
        private EncryptionService $encryption
    ) {
    }

    /**
     * A stable-for-this-visitor identifier, keyed on the client IP — the same
     * non-resettable signal HumanCheck uses (SECURITY.md §16). The old key
     * was the anonymous cookie value (falling back to the session id), both of
     * which the client picks: discarding the cookie yielded a fresh bucket
     * every request, so the paid LLM "shorten" endpoint had effectively no
     * limit (audit M13). The cookie/session is now only a fallback for the
     * rare case where no IP is available. Hashed (blind index) because an IP
     * is personal data (SECURITY.md §11), exactly as HumanCheck stores it.
     */
    public function identifierHash(?string $ipAddress, ?string $cookieValue, string $sessionId): string
    {
        $raw = ($ipAddress !== null && $ipAddress !== '')
            ? 'ip:' . $ipAddress
            : 'sid:' . ($cookieValue ?? $sessionId);

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
