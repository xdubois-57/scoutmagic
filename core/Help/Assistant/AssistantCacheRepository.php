<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Help\Assistant;

/**
 * Answers already produced, so the same question asked twice costs one
 * call instead of two.
 *
 * **The key is a fingerprint, never the question.** A SHA-256 of the
 * normalised question + the role + the application version: the row can
 * answer "have I seen this exact question" and nothing else, which is
 * what SECURITY.md §11 requires of free text a human typed. A stored
 * question would be a table of what the unit's chiefs worry about.
 *
 * **The role is in the key, not a filter on top of it.** Two roles asking
 * the same words are two different questions: the catalogue they were
 * answered from is not the same, so the answers are not interchangeable
 * and one must never be served to the other.
 *
 * **The application version is in the key too**, which makes invalidation
 * free: the corpus only changes at a release, so a release changes every
 * key at once and no purge, no version column and no cache-busting call
 * is needed anywhere. `Core\Help\HelpRegistry`'s own index cache is keyed
 * the same way, for the same reason.
 */
class AssistantCacheRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * The fingerprint of one question, for one role, on one version.
     *
     * Normalised so that spacing and case do not split the cache:
     * lower-cased, runs of whitespace collapsed. Deliberately NOT
     * accent-folded — « ou » and « où » are different questions, and a
     * cache is not a search.
     */
    public static function fingerprint(string $question, string $role, string $appVersion): string
    {
        $normalised = trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($question, 'UTF-8')));

        return hash('sha256', $normalised . "\0" . $role . "\0" . $appVersion);
    }

    /**
     * A previously produced answer, or null.
     *
     * @return array{answer: string, topic_ids: string[]}|null
     */
    public function find(string $fingerprint): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT answer, topic_ids FROM help_assistant_cache WHERE fingerprint = ?'
        );
        $stmt->execute([$fingerprint]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $ids = json_decode((string) $row['topic_ids'], true);

        return [
            'answer' => (string) $row['answer'],
            'topic_ids' => is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [],
        ];
    }

    /**
     * Stores an answer. A second write for the same fingerprint is a
     * no-op rather than an error: two people can ask the same question at
     * the same time, and neither request should fail because the other
     * won the race.
     *
     * @param string[] $topicIds
     */
    public function store(string $fingerprint, string $answer, array $topicIds): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO help_assistant_cache (fingerprint, answer, topic_ids, created_at) VALUES (?, ?, ?, ?)'
        );
        try {
            $stmt->execute([
                $fingerprint,
                $answer,
                (string) json_encode(array_values($topicIds)),
                (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\PDOException $e) {
            // A duplicate key is the race above and nothing else; any
            // other failure is a real one and belongs to the caller.
            if (!in_array($e->getCode(), ['23000', '23505'], true)) {
                throw $e;
            }
        }
    }

    /**
     * Deletes every row older than $beforeDatetime.
     *
     * The version in the fingerprint already makes a stale row
     * unreachable, so this is housekeeping rather than correctness: it
     * stops the table from carrying every question ever asked on every
     * version the installation has run.
     */
    public function deleteOlderThan(string $beforeDatetime): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM help_assistant_cache WHERE created_at < ?');
        $stmt->execute([$beforeDatetime]);

        return $stmt->rowCount();
    }
}
