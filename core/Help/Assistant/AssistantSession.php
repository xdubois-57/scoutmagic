<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Help\Assistant;

use Core\Security\SessionStore;

/**
 * Where a conversation with the assistant lives: the PHP session, and
 * nowhere else.
 *
 * There is no table, no history page and no administration screen for
 * this, deliberately (locked decision D5). A conversation is free text a
 * human typed and can hold a name, an address, an amount; storing it
 * would create a personal-data table, a retention period, a right of
 * access and an entry on the RGPD page — for a convenience worth none of
 * that. It dies with the session, which is what makes the whole feature
 * need no RGPD change beyond naming the endpoint.
 *
 * The same reasoning is why there is no cookie: the PHP session already
 * exists for every signed-in visitor, so `Core\Cookie\CookieRegistry` has
 * nothing new to declare.
 *
 * Only the endpoint touches this. Core\Help\Assistant\AssistantService
 * never does — a Service reading `$_SESSION` is the rule AGENTS.md's
 * layering section exists to prevent, and the precedent for wrapping it
 * this way is Core\ScoutYear\ScoutYearSession.
 */
final class AssistantSession
{
    private const SESSION_KEY = '_help_assistant_conversation';

    /**
     * How many exchanges travel into the next prompt. Enough for a
     * follow-up ("et pour une autre section ?") to make sense; short
     * enough that the prompt does not grow without bound and that an
     * old, unrelated question stops steering the answer.
     */
    public const MAX_EXCHANGES = 6;

    /**
     * A conversation that has been idle this long is somebody else's, or
     * nobody's. Read on access rather than swept: there is no scheduled
     * task for a session, and a stale conversation costs nothing until
     * it is asked for.
     */
    public const IDLE_TIMEOUT_MINUTES = 60;

    /**
     * The exchanges still in play, oldest first — an empty list when
     * there is none or when the last one is past the timeout.
     *
     * @return array<int, array{question: string, answer: string}>
     */
    public static function history(): array
    {
        $stored = SessionStore::get(self::SESSION_KEY);
        if (!is_array($stored) || !isset($stored['exchanges'], $stored['updated_at'])) {
            return [];
        }
        if (!is_array($stored['exchanges']) || !is_int($stored['updated_at'])) {
            return [];
        }
        if ($stored['updated_at'] < time() - self::IDLE_TIMEOUT_MINUTES * 60) {
            return [];
        }

        $exchanges = [];
        foreach ($stored['exchanges'] as $exchange) {
            if (is_array($exchange) && isset($exchange['question'], $exchange['answer'])
                && is_string($exchange['question']) && is_string($exchange['answer'])
            ) {
                $exchanges[] = ['question' => $exchange['question'], 'answer' => $exchange['answer']];
            }
        }

        return $exchanges;
    }

    /**
     * Appends one exchange, keeping the last MAX_EXCHANGES.
     *
     * An expired conversation is replaced rather than extended: history()
     * has already decided it is gone, and appending to it would resurrect
     * exchanges the reader was told were forgotten.
     */
    public static function remember(string $question, string $answer): void
    {
        $exchanges = self::history();
        $exchanges[] = ['question' => $question, 'answer' => $answer];

        SessionStore::set(self::SESSION_KEY, [
            'exchanges' => array_slice($exchanges, -self::MAX_EXCHANGES),
            'updated_at' => time(),
        ]);
    }

    /**
     * Drops the conversation. Called on logout — a shared computer must
     * not hand the next person the previous one's questions.
     */
    public static function clear(): void
    {
        SessionStore::remove(self::SESSION_KEY);
    }
}
