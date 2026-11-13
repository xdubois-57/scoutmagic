<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Retro\Service;

use Core\Security\EncryptionService;
use Core\Security\RateLimitAddress;
use Modules\Retro\Repository\Board;
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

    /**
     * Every value retro_rate_limits.action_type may hold — the per-visitor
     * types above plus the per-board AI budget. The column is an ENUM, and
     * a type missing from it is a failed INSERT on every request in strict
     * SQL mode (issue #1128: "shorten" was), so a test holds the two
     * together.
     */
    public const ACTION_TYPES = ['comment', 'vote', 'shorten', self::BOARD_AI_SHORTEN, self::BOARD_AI_MODERATION];

    /**
     * The per-visitor limits all come down to « per address », and a
     * visitor with many addresses is many visitors (issue #1128). On a
     * public board every one of them may spend the unit's AI budget, so
     * the board itself has a ceiling too: this many paid AI calls per
     * board per day, whoever asks. A lively retro of forty people posting
     * three comments each stays well under both.
     *
     * Two budgets, never one: « Raccourcir » needs nothing but the link,
     * while moderation decides whether a comment can be posted at all. A
     * shared pool would let anybody drain it with « Raccourcir » alone and
     * shut the board's comments for the day (review of #1196).
     */
    public const BOARD_AI_SHORTEN = 'board_ai_shorten';
    public const BOARD_AI_MODERATION = 'board_ai_moderation';

    /** @var array<string, int> */
    public const BOARD_AI_DAILY_BUDGETS = [
        self::BOARD_AI_SHORTEN => 100,
        self::BOARD_AI_MODERATION => 300,
    ];
    private const BOARD_AI_WINDOW_MINUTES = 24 * 60;

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
     *
     * An IPv6 address counts by its /64, the block one subscriber is given
     * and can rotate through freely (issue #1128, Core\Security\
     * RateLimitAddress); an IPv4 address stays whole.
     */
    public function identifierHash(?string $ipAddress, ?string $cookieValue, string $sessionId): string
    {
        $raw = ($ipAddress !== null && $ipAddress !== '')
            ? 'ip:' . RateLimitAddress::key($ipAddress)
            : 'sid:' . ($cookieValue ?? $sessionId);

        return $this->encryption->blindIndex($raw, 'retro_rate_limit');
    }

    /**
     * @throws RetroException when the identifier has exceeded the limit for $actionType
     */
    public function checkAndRecord(string $identifierHash, string $actionType): void
    {
        $limit = self::LIMITS[$actionType] ?? PHP_INT_MAX;

        if (!$this->reserve($identifierHash, $actionType, $limit, self::WINDOW_MINUTES)) {
            throw new RetroException(
                'Trop d\'actions récentes — merci de patienter quelques instants.',
                RetroException::TYPE_RATE_LIMITED
            );
        }
    }

    /**
     * Counts one paid AI call against one of a public board's daily
     * budgets (BOARD_AI_DAILY_BUDGETS: self::BOARD_AI_SHORTEN or
     * self::BOARD_AI_MODERATION), to be called right before the call is
     * made and after the visitor's own limit has let it through. A board
     * that is not public is left alone: only members reach it, and they
     * are who the unit pays the AI for.
     *
     * @throws RetroException when the board has spent that budget for the day
     */
    public function checkAndRecordBoardAiCall(Board $board, string $budget): void
    {
        if (!isset(self::BOARD_AI_DAILY_BUDGETS[$budget])) {
            throw new \InvalidArgumentException('Unknown board AI budget: ' . $budget);
        }
        if ($board->accessMode !== 'public') {
            return;
        }

        // Not personal data — but the column holds blind indexes only, and
        // a board id in clear next to them would read as one.
        $boardHash = $this->encryption->blindIndex('board:' . $board->id, 'retro_rate_limit');

        if (!$this->reserve($boardHash, $budget, self::BOARD_AI_DAILY_BUDGETS[$budget], self::BOARD_AI_WINDOW_MINUTES)) {
            throw new RetroException(
                $budget === self::BOARD_AI_SHORTEN
                    ? '« Raccourcir » a atteint sa limite du jour pour ce tableau — réessaie demain.'
                    : 'La modération a atteint sa limite du jour pour ce tableau — réessaie demain, '
                        . 'ou demande à un animateur.',
                RetroException::TYPE_RATE_LIMITED
            );
        }
    }

    private function reserve(string $identifierHash, string $actionType, int $limit, int $windowMinutes): bool
    {
        $since = (new \DateTimeImmutable('-' . $windowMinutes . ' minutes'))->format('Y-m-d H:i:s');

        if ($this->repository->countSince($identifierHash, $actionType, $since) >= $limit) {
            return false;
        }

        $this->repository->record($identifierHash, $actionType);

        return true;
    }
}
