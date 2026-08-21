<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Core\Member\MemberService;
use Modules\Groups\Repository\ReactionRepository;
use Modules\Groups\Support\Reactions;

/**
 * Who reacted to one post or reply, and with what — the dialog behind a
 * reaction tally's own click (Controller\ReactionController's `reactors`
 * actions). A read-only, one-item-at-a-time sibling of Service\
 * ReactionService: that class owns writing a reaction and the per-page
 * aggregate counts a feed needs; this one only ever runs for the single
 * item a dialog was just opened for, so it has no reason to batch across
 * a page the way ReactionService::forPosts()/forReplies() do.
 *
 * Names, not identities: unlike Service\PostAuthorResolver (a post's
 * author), a reaction carries no user_accounts id at all — only the
 * member_id it was recorded under — so there is no "account name" half to
 * add, and MemberService is the only lookup this needs.
 */
class ReactorListService
{
    public function __construct(
        private ReactionRepository $postReactions,
        private ReactionRepository $replyReactions,
        private MemberService $memberService
    ) {
    }

    /**
     * @return array<int, array{key: string, emoji: string, names: string[]}>
     */
    public function forPost(int $postId, int $scoutYearId): array
    {
        return $this->grouped($this->postReactions->listFor($postId), $scoutYearId);
    }

    /**
     * @return array<int, array{key: string, emoji: string, names: string[]}>
     */
    public function forReply(int $replyId, int $scoutYearId): array
    {
        return $this->grouped($this->replyReactions->listFor($replyId), $scoutYearId);
    }

    /**
     * @param array<int, array{member_id: int, reaction_key: string}> $rows
     * @return array<int, array{key: string, emoji: string, names: string[]}>
     */
    private function grouped(array $rows, int $scoutYearId): array
    {
        if ($rows === []) {
            return [];
        }

        $names = $this->memberService->findDisplayNamesByMemberIds(
            array_map(fn(array $row) => $row['member_id'], $rows),
            $scoutYearId
        );

        $namesByKey = [];
        foreach ($rows as $row) {
            // A member who has since left the unit has no display name
            // for this scout year any more — dropped silently, the same
            // way Service\PostAuthorResolver falls back for an author,
            // except here there is no account name to fall back to.
            $name = $names[$row['member_id']] ?? null;
            if ($name !== null) {
                $namesByKey[$row['reaction_key']][] = $name;
            }
        }

        $grouped = [];
        // Reactions::EMOJI's own insertion order, not $rows' chronological
        // one — the same fixed display order the tally and the picker
        // both already use.
        foreach (Reactions::EMOJI as $key => $emoji) {
            if (isset($namesByKey[$key])) {
                $grouped[] = ['key' => $key, 'emoji' => $emoji, 'names' => $namesByKey[$key]];
            }
        }

        return $grouped;
    }
}
