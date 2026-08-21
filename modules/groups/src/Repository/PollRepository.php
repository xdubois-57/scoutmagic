<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Repository;

/**
 * The three poll tables behind one class: the poll, its options, and its
 * votes.
 *
 * Everything a feed page needs is batched across the whole page — polls,
 * options and tallies each come back in ONE query for every post on the
 * screen, never one per post. That is the same no-N+1 rule reactions and
 * replies already follow here, and it is what makes a poll affordable on
 * a card that already costs a handful of queries.
 */
class PollRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function create(int $postId, string $question, string $now): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO discussion_group_polls (post_id, question, created_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([$postId, $question, $now]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param string[] $labels in the order the author typed them, which is
     *        the order they will always be shown in
     */
    public function addOptions(int $pollId, array $labels): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO discussion_group_poll_options (poll_id, label, position) VALUES (?, ?, ?)'
        );
        $position = 0;
        foreach ($labels as $label) {
            $stmt->execute([$pollId, $label, $position++]);
        }
    }

    /**
     * @return array{id: int, post_id: int, question: string}|null
     */
    public function findByPostId(int $postId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, post_id, question FROM discussion_group_polls WHERE post_id = ?');
        $stmt->execute([$postId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row)
            ? ['id' => (int) $row['id'], 'post_id' => (int) $row['post_id'], 'question' => (string) $row['question']]
            : null;
    }

    /**
     * The polls of a whole page, keyed by post id.
     *
     * @param int[] $postIds
     * @return array<int, array{id: int, post_id: int, question: string}>
     */
    public function findForPosts(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id, post_id, question FROM discussion_group_polls WHERE post_id IN ({$placeholders})"
        );
        $stmt->execute(array_values($postIds));

        $polls = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $polls[(int) $row['post_id']] = [
                'id' => (int) $row['id'],
                'post_id' => (int) $row['post_id'],
                'question' => (string) $row['question'],
            ];
        }

        return $polls;
    }

    /**
     * Every option of a set of polls, keyed by poll id and already in
     * display order.
     *
     * @param int[] $pollIds
     * @return array<int, array<int, array{id: int, label: string}>>
     */
    public function optionsForPolls(array $pollIds): array
    {
        if ($pollIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($pollIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id, poll_id, label FROM discussion_group_poll_options
             WHERE poll_id IN ({$placeholders}) ORDER BY poll_id, position, id"
        );
        $stmt->execute(array_values($pollIds));

        $options = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $options[(int) $row['poll_id']][] = ['id' => (int) $row['id'], 'label' => (string) $row['label']];
        }

        return $options;
    }

    /**
     * How many votes each option of these polls has.
     *
     * @param int[] $pollIds
     * @return array<int, int> option id => vote count
     */
    public function tallyForPolls(array $pollIds): array
    {
        if ($pollIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($pollIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT option_id, COUNT(*) AS votes FROM discussion_group_poll_votes
             WHERE poll_id IN ({$placeholders}) GROUP BY option_id"
        );
        $stmt->execute(array_values($pollIds));

        $tally = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $tally[(int) $row['option_id']] = (int) $row['votes'];
        }

        return $tally;
    }

    /**
     * Which option each of these polls was answered with by this account's
     * own member(s).
     *
     * Several member ids for the same reason every other per-member read
     * in this module takes several: an account linked to two members of
     * the same group has one answer to show, and whichever of its members
     * cast it is the one that counts.
     *
     * @param int[] $pollIds
     * @param int[] $memberIds
     * @return array<int, int> poll id => the option id this account chose
     */
    public function ownVotes(array $pollIds, array $memberIds): array
    {
        if ($pollIds === [] || $memberIds === []) {
            return [];
        }

        $pollPlaceholders = implode(',', array_fill(0, count($pollIds), '?'));
        $memberPlaceholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT poll_id, option_id FROM discussion_group_poll_votes
             WHERE poll_id IN ({$pollPlaceholders}) AND member_id IN ({$memberPlaceholders})"
        );
        $stmt->execute(array_merge(array_values($pollIds), array_values($memberIds)));

        $own = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $own[(int) $row['poll_id']] = (int) $row['option_id'];
        }

        return $own;
    }

    /**
     * Records (or changes) one member's answer.
     *
     * INSERT-then-UPDATE rather than a read-modify-write, the same shape
     * Repository\ReactionRepository::set() and Repository\
     * GroupReadRepository::markRead() use: two tabs, or a double tap,
     * genuinely race here, and the UNIQUE (poll, member) index is what
     * makes the loser fall through to the UPDATE instead of writing a
     * second vote.
     */
    public function vote(int $pollId, int $optionId, int $memberId, string $now): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO discussion_group_poll_votes (poll_id, option_id, member_id, created_at)
                 VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$pollId, $optionId, $memberId, $now]);
        } catch (\PDOException $e) {
            // '23000' is the integrity-constraint-violation SQLSTATE, and
            // it is the same string on both the MySQL and SQLite drivers.
            if ($e->getCode() !== '23000') {
                throw $e;
            }

            $stmt = $this->pdo->prepare(
                'UPDATE discussion_group_poll_votes SET option_id = ?, created_at = ?
                 WHERE poll_id = ? AND member_id = ?'
            );
            $stmt->execute([$optionId, $now, $pollId, $memberId]);
        }
    }

    /**
     * True when $optionId is one of $pollId's own options — checked
     * server-side on every vote, so an option id belonging to another
     * poll (or to nothing at all) can never be recorded.
     */
    public function optionBelongsToPoll(int $optionId, int $pollId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM discussion_group_poll_options WHERE id = ? AND poll_id = ?'
        );
        $stmt->execute([$optionId, $pollId]);

        return (int) $stmt->fetchColumn() > 0;
    }
}
