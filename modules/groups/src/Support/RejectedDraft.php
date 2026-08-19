<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Support;

use Core\Security\SessionStore;

/**
 * A one-shot carrier for a message the AI moderation refused, so the
 * redirect that follows can hand the author back their own text and the
 * suggested rewording instead of an empty box.
 *
 * This is the ONLY place a rejected message is kept, and it is kept in the
 * author's own session for exactly one page render — never written to the
 * database, never journaled, never logged (module spec). take() clears it
 * on read, so a refused draft does not survive into the next request or
 * leak into an unrelated page.
 *
 * Session-scoped, so it is per-author by construction: nobody else can
 * read it, moderators included.
 */
class RejectedDraft
{
    private const SESSION_KEY = '_groups_rejected_draft';

    public static function set(string $body, ?string $suggestion, ?int $postId = null): void
    {
        SessionStore::set(self::SESSION_KEY, [
            'body' => $body,
            'suggestion' => $suggestion,
            // Which composer to refill: null for the group's own post
            // composer, a post id for that post's reply composer.
            'post_id' => $postId,
        ]);
    }

    /**
     * @return array{body: string, suggestion: ?string, post_id: ?int}|null
     */
    public static function take(): ?array
    {
        $draft = SessionStore::get(self::SESSION_KEY);
        SessionStore::remove(self::SESSION_KEY);

        if (!is_array($draft) || !isset($draft['body']) || !is_string($draft['body'])) {
            return null;
        }

        return [
            'body' => $draft['body'],
            'suggestion' => isset($draft['suggestion']) && is_string($draft['suggestion']) ? $draft['suggestion'] : null,
            'post_id' => isset($draft['post_id']) && is_int($draft['post_id']) ? $draft['post_id'] : null,
        ];
    }
}
