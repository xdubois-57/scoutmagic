<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Core\Member\MemberService;
use Modules\Groups\Repository\DiscussionGroup;

/**
 * "@Akéla" in a message, turned into the member it names.
 *
 * Resolved from the STORED body, server-side, every time — never from a
 * list of ids the client sent alongside it. The composer's autocomplete
 * is a typing aid and nothing more: a request that claims "this message
 * mentions member 42" is a request to notify member 42, and there is no
 * reason to let a caller assert that directly. Reading it back out of
 * the text also means an edit, a paste, or a message typed with no
 * JavaScript at all resolves exactly the same way.
 *
 * The candidate list is the group's own current membership
 * (Service\GroupRecipientResolver::memberIdsFor()) and never wider: an
 * "@" naming somebody outside the group resolves to nobody, so a message
 * can neither notify nor confirm the existence of a member the writer
 * could not otherwise see.
 */
class MentionService
{
    /**
     * A ceiling on how many members one message may notify by name. Not
     * an anti-spam measure — a plain post already notifies the whole
     * group — but a bound on the work a single body can ask for, and a
     * signal that past a certain point the writer meant "everyone" and
     * should just have said it.
     */
    public const MAX_PER_MESSAGE = 10;

    public function __construct(
        private GroupRecipientResolver $recipientResolver,
        private MemberService $memberService
    ) {
    }

    /**
     * The member ids named in $body, in the order their names appear in
     * the group's member list.
     *
     * Longest name first, and each match blanks out the span it consumed:
     * with a "Marie" and a "Marie Dupont" both in the group, "@Marie
     * Dupont" must resolve to Marie Dupont alone, not to both of them
     * because the shorter name is a prefix of the longer one.
     *
     * @return int[]
     */
    public function resolve(DiscussionGroup $group, string $body, int $effectiveScoutYearId): array
    {
        if (!str_contains($body, '@')) {
            return [];
        }

        $memberIds = $this->recipientResolver->memberIdsFor($group, $effectiveScoutYearId);
        if ($memberIds === []) {
            return [];
        }

        $scoutYearId = $group->scoutYearId ?? $effectiveScoutYearId;
        $names = $this->memberService->findDisplayNamesByMemberIds($memberIds, $scoutYearId);

        // Longest first so a name that contains another is claimed whole.
        uasort($names, static fn(string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        $remaining = $body;
        $found = [];
        foreach ($names as $memberId => $name) {
            $needle = '@' . $name;
            $at = mb_stripos($remaining, $needle);
            if ($at === false) {
                continue;
            }

            $found[] = (int) $memberId;
            // Blank out every occurrence of this name so a shorter name
            // nested inside it cannot match the same characters again.
            $remaining = str_ireplace($needle, str_repeat(' ', mb_strlen($needle)), $remaining);

            if (count($found) >= self::MAX_PER_MESSAGE) {
                break;
            }
        }

        return $found;
    }

    /**
     * The group's members matching what is being typed after an "@" —
     * the composer's autocomplete, capped at a handful of rows.
     *
     * Matched on any word of the display name, not only its start, so
     * "@dup" finds "Marie Dupont" the way somebody reaching for a surname
     * expects.
     *
     * @return array<int, array{id: int, label: string}>
     */
    public function suggest(DiscussionGroup $group, string $query, int $effectiveScoutYearId, int $limit = 8): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $memberIds = $this->recipientResolver->memberIdsFor($group, $effectiveScoutYearId);
        if ($memberIds === []) {
            return [];
        }

        $names = $this->memberService->findDisplayNamesByMemberIds(
            $memberIds,
            $group->scoutYearId ?? $effectiveScoutYearId
        );

        $matches = [];
        foreach ($names as $memberId => $name) {
            if (mb_stripos($name, $query) !== false) {
                $matches[] = ['id' => (int) $memberId, 'label' => $name];
            }
        }

        usort($matches, static fn(array $a, array $b) => strcoll($a['label'], $b['label']));

        return array_slice($matches, 0, $limit);
    }
}
