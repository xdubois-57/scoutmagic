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
 * "@Marie Dupont" in a message, turned into the people it names.
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
        private MemberService $memberService,
        private ?MemberIdentityService $identityService = null
    ) {
    }

    /**
     * What an "@" names: the HUMAN, by the account's own name, the way
     * every other name in this module is written
     * (Service\MemberIdentityService).
     *
     * "@Marie Dupont" rather than "@Akéla", and one entry per human
     * rather than one per membership — mentioning a parent of three no
     * longer means knowing which of the three totems to type, and the
     * notification goes to the same account either way
     * (GroupNotificationService::mentioned() already collapses an account
     * linked to several mentioned members into one).
     *
     * Only the account's OWN name is the token, never the parenthesised
     * memberships: those are shown in the autocomplete so the reader can
     * tell two people apart, but "@Marie Dupont (Akéla, Baloo)" in the
     * middle of a sentence is not a thing anybody would type or want to
     * read back.
     *
     * A member with no account keeps their own display name as the token,
     * exactly as before. Two accounts sharing a name in one group resolve
     * together — the same collision two identical totems have always had,
     * and erring towards notifying both.
     *
     * @param int[] $memberIds
     * @return array<string, array{member_ids: int[], label: string}> token => the humans behind it
     */
    private function humansFor(array $memberIds, int $scoutYearId): array
    {
        $identities = $this->identityService?->forMembers($memberIds, $scoutYearId);
        if ($identities === null) {
            // Constructed without the identity service (narrow unit
            // tests only — public/index.php always passes it): the old
            // membership-by-membership behaviour, unchanged.
            $humans = [];
            foreach ($this->memberService->findDisplayNamesByMemberIds(
                $memberIds,
                $scoutYearId
            ) as $memberId => $name) {
                $humans[$name] = ['member_ids' => [(int) $memberId], 'label' => $name];
            }

            return $humans;
        }

        $humans = [];
        foreach ($identities as $memberId => $identity) {
            $token = $identity['account_name'];
            if ($token === '') {
                continue;
            }

            if (!isset($humans[$token])) {
                $humans[$token] = ['member_ids' => [], 'label' => MemberIdentityService::label($identity)];
            }
            $humans[$token]['member_ids'][] = (int) $memberId;
        }

        return $humans;
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

        $humans = $this->humansFor($memberIds, $group->scoutYearId ?? $effectiveScoutYearId);

        // Longest first so a name that contains another is claimed whole.
        uksort($humans, static fn(string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        $remaining = $body;
        $found = [];
        $named = 0;
        foreach ($humans as $token => $human) {
            $needle = '@' . $token;
            if (mb_stripos($remaining, $needle) === false) {
                continue;
            }

            // Every membership that human holds in this group. They are
            // one person, so this is one mention — the cap counts people,
            // not rows, and a parent of three does not eat three of them.
            foreach ($human['member_ids'] as $memberId) {
                $found[] = $memberId;
            }
            $named++;

            // Blank out every occurrence of this name so a shorter name
            // nested inside it cannot match the same characters again.
            $remaining = str_ireplace($needle, str_repeat(' ', mb_strlen($needle)), $remaining);

            if ($named >= self::MAX_PER_MESSAGE) {
                break;
            }
        }

        return $found;
    }

    /**
     * The group's members matching what is being typed after an "@" —
     * the composer's autocomplete, capped at a handful of rows.
     *
     * Matched anywhere in the rendered name, not only at its start, so
     * "@dup" finds "Marie Dupont" the way somebody reaching for a surname
     * expects — and, since the memberships are part of that name now,
     * "@akela" still finds the parent behind Akéla.
     *
     * `label` is what the reader sees: the account, then its memberships,
     * which is what tells two people apart. `mention` is what gets
     * written into the message when the row is picked, and is the
     * account's name alone — resolve() reads exactly that back out of the
     * stored body. `id` is one of the memberships behind the row, carried
     * for callers that want it; nothing trusts it, since a mention is
     * always re-resolved server-side from the text.
     *
     * @return array<int, array{id: int, label: string, mention: string}>
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

        $humans = $this->humansFor($memberIds, $group->scoutYearId ?? $effectiveScoutYearId);

        $matches = [];
        foreach ($humans as $token => $human) {
            if (mb_stripos($human['label'], $query) === false) {
                continue;
            }

            $matches[] = [
                'id' => $human['member_ids'][0],
                'label' => $human['label'],
                'mention' => $token,
            ];
        }

        usort($matches, static fn(array $a, array $b) => strcoll($a['label'], $b['label']));

        return array_slice($matches, 0, $limit);
    }
}
