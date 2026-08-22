<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Repository;

/**
 * The three poll tables behind one class: the poll, its options, and the
 * ballots cast in it.
 *
 * Everything a feed page needs is batched across the whole page — polls,
 * options and tallies each come back in ONE query for every post on the
 * screen, never one per post. That is the same no-N+1 rule reactions and
 * replies already follow here, and it is what makes a poll affordable on
 * a card that already costs a handful of queries.
 *
 * A ballot is (poll, option, voter). The voter is an account or a member
 * depending on the poll's own vote_scope, written as one `voter_key`
 * string — see schema.sql for why one NOT NULL column beats two nullable
 * ones under a UNIQUE index.
 */
class PollRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function create(int $postId, string $question, string $voteScope, bool $allowMultiple, string $now): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO discussion_group_polls (post_id, question, vote_scope, allow_multiple, created_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$postId, $question, $voteScope, $allowMultiple ? 1 : 0, $now]);

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
     * @return array{id: int, post_id: int, question: string, vote_scope: string, allow_multiple: bool}|null
     */
    public function findByPostId(int $postId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, post_id, question, vote_scope, allow_multiple FROM discussion_group_polls WHERE post_id = ?'
        );
        $stmt->execute([$postId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * The polls of a whole page, keyed by post id.
     *
     * @param int[] $postIds
     * @return array<int, array{id: int, post_id: int, question: string, vote_scope: string, allow_multiple: bool}>
     */
    public function findForPosts(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id, post_id, question, vote_scope, allow_multiple FROM discussion_group_polls
             WHERE post_id IN ({$placeholders})"
        );
        $stmt->execute(array_values($postIds));

        $polls = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $polls[(int) $row['post_id']] = $this->hydrate($row);
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
     * How many ballots each option of these polls holds.
     *
     * @param int[] $pollIds
     * @return array<int, int> option id => ballot count
     */
    public function tallyForPolls(array $pollIds): array
    {
        if ($pollIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($pollIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT option_id, COUNT(*) AS votes FROM discussion_group_poll_ballots
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
     * How many DIFFERENT voters answered each poll — what "12 votes"
     * means on a card. Counted rather than derived from the tally,
     * because a multiple-answer poll has more ballots than voters and
     * summing the options would report a turnout nobody had.
     *
     * @param int[] $pollIds
     * @return array<int, int> poll id => voter count
     */
    public function voterCountsForPolls(array $pollIds): array
    {
        if ($pollIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($pollIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT poll_id, COUNT(DISTINCT voter_key) AS voters FROM discussion_group_poll_ballots
             WHERE poll_id IN ({$placeholders}) GROUP BY poll_id"
        );
        $stmt->execute(array_values($pollIds));

        $counts = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $counts[(int) $row['poll_id']] = (int) $row['voters'];
        }

        return $counts;
    }

    /**
     * Which options this caller's own voter keys answered, per poll.
     *
     * Several keys because one account can be several voters: its own
     * (an account-scoped poll) and one per member it is linked to (a
     * member-scoped one). The caller passes every key it could answer
     * with, and gets back what each of them chose — which is what the
     * card highlights and what the "au nom de qui ?" dialog reads to say
     * who has already answered.
     *
     * @param int[] $pollIds
     * @param string[] $voterKeys
     * @return array<int, array<string, int[]>> poll id => voter key => option ids
     */
    public function ownBallots(array $pollIds, array $voterKeys): array
    {
        if ($pollIds === [] || $voterKeys === []) {
            return [];
        }

        $pollPlaceholders = implode(',', array_fill(0, count($pollIds), '?'));
        $keyPlaceholders = implode(',', array_fill(0, count($voterKeys), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT poll_id, option_id, voter_key FROM discussion_group_poll_ballots
             WHERE poll_id IN ({$pollPlaceholders}) AND voter_key IN ({$keyPlaceholders})"
        );
        $stmt->execute(array_merge(array_values($pollIds), array_values($voterKeys)));

        $own = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $own[(int) $row['poll_id']][(string) $row['voter_key']][] = (int) $row['option_id'];
        }

        return $own;
    }

    /**
     * Records one answer, and — for a single-answer poll — drops whatever
     * that voter had answered before.
     *
     * Both halves run in ONE transaction: a change of mind that deleted
     * the old answer and then failed to write the new one would silently
     * remove somebody from the tally. The UNIQUE (option, voter_key)
     * index is still the actual enforcement against a double tap; hitting
     * it means the answer is already recorded, which is exactly the state
     * the caller wanted.
     */
    public function castBallot(
        int $pollId,
        int $optionId,
        string $voterKey,
        ?int $userAccountId,
        ?int $voterMemberId,
        bool $allowMultiple,
        string $now
    ): void {
        $this->pdo->beginTransaction();

        try {
            if (!$allowMultiple) {
                $stmt = $this->pdo->prepare(
                    'DELETE FROM discussion_group_poll_ballots WHERE poll_id = ? AND voter_key = ?'
                );
                $stmt->execute([$pollId, $voterKey]);
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO discussion_group_poll_ballots
                    (poll_id, option_id, voter_key, user_account_id, voter_member_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$pollId, $optionId, $voterKey, $userAccountId, $voterMemberId, $now]);
            $this->pdo->commit();
        } catch (\PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            // '23000' is the integrity-constraint-violation SQLSTATE, the
            // same string on both the MySQL and SQLite drivers: this
            // voter already holds this option, which is where they were
            // trying to get to.
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Takes one answer back — what a second tap on an option means in a
     * multiple-answer poll.
     */
    public function withdrawBallot(int $pollId, int $optionId, string $voterKey): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM discussion_group_poll_ballots WHERE poll_id = ? AND option_id = ? AND voter_key = ?'
        );
        $stmt->execute([$pollId, $optionId, $voterKey]);
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

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, post_id: int, question: string, vote_scope: string, allow_multiple: bool}
     */
    private function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'post_id' => (int) $row['post_id'],
            'question' => (string) $row['question'],
            'vote_scope' => (string) $row['vote_scope'],
            'allow_multiple' => (bool) $row['allow_multiple'],
        ];
    }
}
