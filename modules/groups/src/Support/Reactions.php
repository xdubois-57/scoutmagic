<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Support;

/**
 * The fixed set of six reactions, and the only place the emoji characters
 * themselves exist.
 *
 * The database stores the KEY ('thumbs_up'), never the character ('👍') —
 * see discussion_group_post_reactions' own schema.sql comment for the two
 * reasons: it keeps the fixed set explicit and enforceable server-side,
 * and it sidesteps the utf8mb4-versus-utf8 column trap where a three-byte
 * column silently truncates or rejects a four-byte emoji.
 *
 * Deliberately not configurable and not extendable (module spec): the set
 * is a constant, there is no setting, no admin screen and no per-group
 * override. Anything that wants to render a reaction asks emoji() here.
 */
final class Reactions
{
    /**
     * Insertion order is display order — the picker renders them in this
     * order, and so does a post's reaction summary.
     *
     * @var array<string, string> key => emoji character
     */
    public const EMOJI = [
        'thumbs_up' => '👍',
        'heart' => '❤️',
        'joy' => '😂',
        'wow' => '😮',
        'sad' => '😢',
        'clap' => '👏',
    ];

    /**
     * The server-side guard every write goes through: a key the client
     * invented, an empty string, or a raw emoji character submitted
     * instead of a key all fail here (module spec — never trust the value
     * the client sends).
     */
    public static function isValid(string $key): bool
    {
        return isset(self::EMOJI[$key]);
    }

    /**
     * The character for a key, or the key itself as a last-resort label if
     * a row somehow predates a key being removed from the map — a stored
     * reaction must never be able to blank out a rendered page.
     */
    public static function emoji(string $key): string
    {
        return self::EMOJI[$key] ?? $key;
    }

    /**
     * @return string[] the six keys, in display order
     */
    public static function keys(): array
    {
        return array_keys(self::EMOJI);
    }
}
