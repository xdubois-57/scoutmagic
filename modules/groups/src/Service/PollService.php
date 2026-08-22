<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Modules\Groups\Repository\PollRepository;
use Modules\Groups\Support\Timestamps;

/**
 * Polls attached to posts: creating one, voting in one, and assembling
 * what a card has to render.
 *
 * Authorisation is the Controller's, as everywhere else in this module —
 * by the time anything here runs, the caller has been confirmed able to
 * read the group and (for a vote) to write in it. What this class owns is
 * the shape of a poll: at least two options, a bounded number of them,
 * and who counts as one voter.
 *
 * **Who counts as one voter is the poll's own choice**, made when it is
 * written:
 *
 * - `account` (the default) — one answer per identified login, the same
 *   identity that writes the messages. "Qui vient au week-end ?" asked of
 *   the people reading the group.
 * - `member` — one answer per member. What a parent of three needs for
 *   "quel enfant vient ?": the same login answers once per child, and
 *   says which child each answer is for.
 *
 * And how many answers each voter may give: one (the default, a change of
 * mind replacing the previous answer) or several (a second tap on an
 * option takes that one back).
 */
class PollService
{
    public const MIN_OPTIONS = 2;
    public const MAX_OPTIONS = 10;
    public const MAX_QUESTION_LENGTH = 300;
    public const MAX_OPTION_LENGTH = 150;

    public const SCOPE_ACCOUNT = 'account';
    public const SCOPE_MEMBER = 'member';

    public function __construct(private PollRepository $repository)
    {
    }

    /**
     * Trims and bounds what a composer submitted, or returns null when it
     * is not a poll at all.
     *
     * A single option is not a poll and neither is a question with none,
     * so both come back as null and the post is published without one —
     * rather than storing a half-poll nobody can answer. Blank rows are
     * dropped first: the composer always keeps one empty box at the end,
     * so submitting with it untouched is the ordinary way to make a poll
     * with exactly the options you typed.
     *
     * @param string[] $rawOptions
     * @return array{question: string, options: string[], vote_scope: string, allow_multiple: bool}|null
     */
    public function normalise(
        string $rawQuestion,
        array $rawOptions,
        string $rawScope = self::SCOPE_ACCOUNT,
        bool $allowMultiple = false
    ): ?array {
        $question = mb_substr(trim($rawQuestion), 0, self::MAX_QUESTION_LENGTH);
        if ($question === '') {
            return null;
        }

        $options = [];
        foreach ($rawOptions as $raw) {
            $label = mb_substr(trim((string) $raw), 0, self::MAX_OPTION_LENGTH);
            if ($label !== '') {
                $options[] = $label;
            }
        }
        $options = array_slice($options, 0, self::MAX_OPTIONS);

        if (count($options) < self::MIN_OPTIONS) {
            return null;
        }

        return [
            'question' => $question,
            'options' => $options,
            // Anything but the one word that means "per member" is the
            // default: a hand-made request cannot invent a third scope.
            'vote_scope' => $rawScope === self::SCOPE_MEMBER ? self::SCOPE_MEMBER : self::SCOPE_ACCOUNT,
            'allow_multiple' => $allowMultiple,
        ];
    }

    /**
     * @param array{question: string, options: string[], vote_scope: string, allow_multiple: bool} $poll
     *        from normalise()
     */
    public function attachTo(int $postId, array $poll): int
    {
        $pollId = $this->repository->create(
            $postId,
            $poll['question'],
            $poll['vote_scope'],
            $poll['allow_multiple'],
            Timestamps::now()
        );
        $this->repository->addOptions($pollId, $poll['options']);

        return $pollId;
    }

    /**
     * Records one answer to the poll on $postId, or takes it back.
     *
     * Both halves of the target are re-checked against the database
     * rather than trusted from the form: the poll must be the one that
     * post actually carries, and the option must be one of that poll's
     * own — otherwise a hand-made request could answer another post's
     * poll, or pick an option belonging to one.
     *
     * The voter is decided HERE, from the poll's own scope, never from
     * the request: an account-scoped poll records the account whatever
     * member id was posted, and a member-scoped one records a member the
     * caller has already been confirmed to be able to act for.
     *
     * @param int[] $allowedMemberIds the caller's own members in this group
     * @return bool false when there was nothing legitimate to record
     */
    public function vote(
        int $postId,
        int $optionId,
        ?int $userAccountId,
        int $requestedMemberId,
        array $allowedMemberIds
    ): bool {
        $poll = $this->repository->findByPostId($postId);
        if ($poll === null || $optionId <= 0 || !$this->repository->optionBelongsToPoll($optionId, $poll['id'])) {
            return false;
        }

        $voter = $this->voterFor($poll['vote_scope'], $userAccountId, $requestedMemberId, $allowedMemberIds);
        if ($voter === null) {
            return false;
        }

        $already = $this->repository->ownBallots([$poll['id']], [$voter['key']])[$poll['id']][$voter['key']] ?? [];

        // A second tap on an option a multiple-answer voter already holds
        // takes it back — the only way to unpick one, and the same
        // gesture that picked it. A single-answer poll has no such move:
        // there, tapping your own answer again is a no-op rather than a
        // way to end up having answered nothing by accident.
        if (in_array($optionId, $already, true)) {
            if ($poll['allow_multiple']) {
                $this->repository->withdrawBallot($poll['id'], $optionId, $voter['key']);
            }

            return true;
        }

        $this->repository->castBallot(
            $poll['id'],
            $optionId,
            $voter['key'],
            $voter['account_id'],
            $voter['member_id'],
            $poll['allow_multiple'],
            Timestamps::now()
        );

        return true;
    }

    /**
     * Everything a page of cards needs to render its polls, in a fixed
     * number of queries for the whole page rather than per post.
     *
     * @param int[] $postIds
     * @param int[] $memberIds this account's members in the group, for
     *        "what did I answer" and for the member-scoped picker
     * @return array<int, array{
     *     id: int,
     *     question: string,
     *     total: int,
     *     vote_scope: string,
     *     allow_multiple: bool,
     *     own_option_ids: int[],
     *     own_by_member: array<int, int[]>,
     *     options: array<int, array{id: int, label: string, votes: int, percent: int, is_own: bool}>
     * }> keyed by post id
     */
    public function forPosts(array $postIds, ?int $userAccountId, array $memberIds): array
    {
        $polls = $this->repository->findForPosts($postIds);
        if ($polls === []) {
            return [];
        }

        $pollIds = array_map(static fn(array $poll): int => $poll['id'], array_values($polls));
        $optionsByPoll = $this->repository->optionsForPolls($pollIds);
        $tally = $this->repository->tallyForPolls($pollIds);
        $voterCounts = $this->repository->voterCountsForPolls($pollIds);
        $ownBallots = $this->repository->ownBallots($pollIds, $this->voterKeysFor($userAccountId, $memberIds));

        $result = [];
        foreach ($polls as $postId => $poll) {
            $options = $optionsByPoll[$poll['id']] ?? [];
            $own = $ownBallots[$poll['id']] ?? [];

            // Only the keys this poll's own scope can be answered with:
            // an account-scoped poll must not light up an option because
            // one of this account's members answered a different poll's
            // question, and vice versa.
            $ownOptionIds = [];
            $ownByMember = [];
            foreach ($own as $voterKey => $optionIds) {
                if ($poll['vote_scope'] === self::SCOPE_ACCOUNT) {
                    if (str_starts_with($voterKey, 'a:')) {
                        $ownOptionIds = array_merge($ownOptionIds, $optionIds);
                    }
                    continue;
                }

                if (str_starts_with($voterKey, 'm:')) {
                    $ownOptionIds = array_merge($ownOptionIds, $optionIds);
                    $ownByMember[(int) substr($voterKey, 2)] = $optionIds;
                }
            }

            $result[$postId] = [
                'id' => $poll['id'],
                'question' => $poll['question'],
                // Voters, not ballots: a multiple-answer poll has more
                // answers than people, and "14 votes" on a poll eight
                // people answered would be a number nobody can act on.
                'total' => $voterCounts[$poll['id']] ?? 0,
                'vote_scope' => $poll['vote_scope'],
                'allow_multiple' => $poll['allow_multiple'],
                'own_option_ids' => $ownOptionIds,
                'own_by_member' => $ownByMember,
                'options' => array_map(
                    static function (array $option) use ($tally, $voterCounts, $poll, $ownOptionIds): array {
                        $votes = $tally[$option['id']] ?? 0;
                        $total = $voterCounts[$poll['id']] ?? 0;

                        return [
                            'id' => $option['id'],
                            'label' => $option['label'],
                            'votes' => $votes,
                            // Rounded here rather than in the template so
                            // the bar's width and the number beside it can
                            // never disagree. Out of VOTERS, so a
                            // multiple-answer poll reads "6 of the 8
                            // people who answered picked this" — which is
                            // the question a reader is asking — and the
                            // percentages legitimately add up past 100.
                            'percent' => $total > 0 ? min(100, (int) round($votes * 100 / $total)) : 0,
                            'is_own' => in_array($option['id'], $ownOptionIds, true),
                        ];
                    },
                    $options
                ),
            ];
        }

        return $result;
    }

    /**
     * Every key this caller could have answered with: their own account,
     * and each member they may act for. Which of them a given poll
     * actually reads is decided by that poll's scope (forPosts() above).
     *
     * @param int[] $memberIds
     * @return string[]
     */
    private function voterKeysFor(?int $userAccountId, array $memberIds): array
    {
        $keys = [];
        if ($userAccountId !== null) {
            $keys[] = 'a:' . $userAccountId;
        }
        foreach ($memberIds as $memberId) {
            $keys[] = 'm:' . $memberId;
        }

        return $keys;
    }

    /**
     * The one voter this answer is recorded as, or null when the caller
     * has no legitimate identity to answer with — an account-scoped poll
     * answered by a session with no account, or a member-scoped one
     * answered for somebody this caller may not act for.
     *
     * @param int[] $allowedMemberIds
     * @return array{key: string, account_id: ?int, member_id: ?int}|null
     */
    private function voterFor(
        string $scope,
        ?int $userAccountId,
        int $requestedMemberId,
        array $allowedMemberIds
    ): ?array {
        if ($scope === self::SCOPE_MEMBER) {
            // The requested member, but only if the caller really is one
            // of that member's people — otherwise the first of their own,
            // which is what a no-JS submit with no picker sends.
            $memberId = in_array($requestedMemberId, $allowedMemberIds, true)
                ? $requestedMemberId
                : ($allowedMemberIds[0] ?? 0);

            return $memberId === 0
                ? null
                : ['key' => 'm:' . $memberId, 'account_id' => $userAccountId, 'member_id' => $memberId];
        }

        return $userAccountId === null
            ? null
            : ['key' => 'a:' . $userAccountId, 'account_id' => $userAccountId, 'member_id' => null];
    }
}
