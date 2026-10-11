<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Retro\Service;

use Core\Security\Role;
use Modules\Retro\Repository\Board;

/**
 * The one answer to « may this reader open this board? » (issue #833).
 *
 * A board's access mode (retro_boards.access_mode) decides three things at
 * once, and nothing else in the module decides any of them:
 *
 * | Mode         | Opens the board and all its routes | Sees its link in the calendar / ICS | Votes keyed by |
 * |--------------|------------------------------------|-------------------------------------|----------------|
 * | `animators`  | a session at intendant or above    | the same, while the board is open   | the account    |
 * | `identified` | any logged-in account, parents too | the same, while the board is open   | the account    |
 * | `public`     | anyone holding the link            | nobody, ever                        | the cookie     |
 *
 * Every /r/{token} route of Controller\RetroBoardController asks
 * decide() before doing anything, and BoardService::findLinkedBoardLink()
 * asks mayShowLinkTo(): the rule is written here once and never copied
 * into a controller.
 *
 * The token stays what it was — the address of the board — but it no
 * longer opens a board on its own: before this class, eight of the ten
 * routes answered anyone holding it, whatever the board's settings said.
 *
 * Hiding and restoring a comment stay the chef d'unité's, on top of this:
 * that is a moderation right, not access to the board.
 */
final class BoardAccess
{
    /** The reader may open the board. */
    public const ALLOWED = 'allowed';

    /** Nobody is logged in, and the board needs somebody to be. */
    public const LOGIN_REQUIRED = 'login_required';

    /** Somebody is logged in, below what the board needs. */
    public const FORBIDDEN = 'forbidden';

    /** The refusal a logged-in reader below the board's floor is shown. */
    public const FORBIDDEN_MESSAGE = "Cette rétrospective est réservée aux animateurs.";

    /** The refusal an anonymous reader's script is given. */
    public const LOGIN_MESSAGE = 'Connectez-vous pour participer à cette rétrospective.';

    /** One of ALLOWED, LOGIN_REQUIRED, FORBIDDEN. */
    public function decide(Board $board, Role $viewerRole, bool $authenticated): string
    {
        $floor = self::floor($board->accessMode);
        if ($floor === null) {
            return self::ALLOWED;
        }
        if (!$authenticated) {
            return self::LOGIN_REQUIRED;
        }

        return $viewerRole->hasAccess($floor) ? self::ALLOWED : self::FORBIDDEN;
    }

    /**
     * Whether the calendar and the personal ICS feed may carry this board's
     * link for this reader: only while the board is open, never for a
     * public board — whose link anybody may pass on, and which a calendar
     * would therefore publish to everyone who can read the event — and
     * only for a reader who could open it.
     */
    public function mayShowLinkTo(Board $board, Role $viewerRole): bool
    {
        $floor = self::floor($board->accessMode);
        if (!$board->isOpen() || $floor === null) {
            return false;
        }

        return $viewerRole !== Role::PUBLIC && $viewerRole->hasAccess($floor);
    }

    /**
     * Whether a vote is keyed by the voter's account rather than by the
     * anonymous cookie. Never stored: the key is only ever an input to the
     * one-way HMAC of retro_votes.voter_hash (schema.sql's header).
     */
    public function keysVotesByAccount(Board $board): bool
    {
        return self::floor($board->accessMode) !== null;
    }

    /** The lowest role that opens a board in $accessMode, or null for anyone. */
    private static function floor(string $accessMode): ?Role
    {
        return match ($accessMode) {
            'public' => null,
            'identified' => Role::IDENTIFIED,
            // An unknown value closes rather than opens: the most closed mode.
            default => Role::INTENDANT,
        };
    }
}
