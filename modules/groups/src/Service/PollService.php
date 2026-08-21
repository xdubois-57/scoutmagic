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
 * and one answer per member that a second vote replaces rather than adds
 * to.
 */
class PollService
{
    public const MIN_OPTIONS = 2;
    public const MAX_OPTIONS = 10;
    public const MAX_QUESTION_LENGTH = 300;
    public const MAX_OPTION_LENGTH = 150;

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
     * dropped first: the composer offers a fixed number of boxes and
     * leaving the last ones empty is the ordinary way to make a two-option
     * poll.
     *
     * @param string[] $rawOptions
     * @return array{question: string, options: string[]}|null
     */
    public function normalise(string $rawQuestion, array $rawOptions): ?array
    {
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

        return count($options) >= self::MIN_OPTIONS ? ['question' => $question, 'options' => $options] : null;
    }

    /**
     * @param array{question: string, options: string[]} $poll from normalise()
     */
    public function attachTo(int $postId, array $poll): int
    {
        $pollId = $this->repository->create($postId, $poll['question'], Timestamps::now());
        $this->repository->addOptions($pollId, $poll['options']);

        return $pollId;
    }

    /**
     * Records one member's answer to the poll on $postId.
     *
     * Both halves are re-checked against the database rather than trusted
     * from the form: the poll must be the one that post actually carries,
     * and the option must be one of that poll's own — otherwise a
     * hand-made request could vote in another post's poll, or for an
     * option that belongs to one.
     *
     * @return bool false when there was nothing legitimate to record
     */
    public function vote(int $postId, int $optionId, int $memberId): bool
    {
        $poll = $this->repository->findByPostId($postId);
        if ($poll === null || $optionId <= 0 || !$this->repository->optionBelongsToPoll($optionId, $poll['id'])) {
            return false;
        }

        $this->repository->vote($poll['id'], $optionId, $memberId, Timestamps::now());

        return true;
    }

    /**
     * Everything a page of cards needs to render its polls, in four
     * queries for the whole page rather than four per post.
     *
     * @param int[] $postIds
     * @param int[] $memberIds this account's members, for "what did I answer"
     * @return array<int, array{
     *     id: int,
     *     question: string,
     *     total: int,
     *     own_option_id: ?int,
     *     options: array<int, array{id: int, label: string, votes: int, percent: int, is_own: bool}>
     * }> keyed by post id
     */
    public function forPosts(array $postIds, array $memberIds): array
    {
        $polls = $this->repository->findForPosts($postIds);
        if ($polls === []) {
            return [];
        }

        $pollIds = array_map(static fn(array $poll): int => $poll['id'], array_values($polls));
        $optionsByPoll = $this->repository->optionsForPolls($pollIds);
        $tally = $this->repository->tallyForPolls($pollIds);
        $ownVotes = $this->repository->ownVotes($pollIds, $memberIds);

        $result = [];
        foreach ($polls as $postId => $poll) {
            $options = $optionsByPoll[$poll['id']] ?? [];
            $total = 0;
            foreach ($options as $option) {
                $total += $tally[$option['id']] ?? 0;
            }

            $ownOptionId = $ownVotes[$poll['id']] ?? null;
            $result[$postId] = [
                'id' => $poll['id'],
                'question' => $poll['question'],
                'total' => $total,
                'own_option_id' => $ownOptionId,
                'options' => array_map(
                    static function (array $option) use ($tally, $total, $ownOptionId): array {
                        $votes = $tally[$option['id']] ?? 0;

                        return [
                            'id' => $option['id'],
                            'label' => $option['label'],
                            'votes' => $votes,
                            // Rounded here rather than in the template so
                            // the bar's width and the number beside it can
                            // never disagree. Zero total means zero
                            // percent, not a division by zero.
                            'percent' => $total > 0 ? (int) round($votes * 100 / $total) : 0,
                            'is_own' => $ownOptionId !== null && $ownOptionId === $option['id'],
                        ];
                    },
                    $options
                ),
            ];
        }

        return $result;
    }
}
