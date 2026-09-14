<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Journal\JournalRepository;

/**
 * A journal that writes to an array.
 *
 * **It used to write nowhere, and that was a hole.** The controller
 * journals a security event on every outcome, and one rule about those
 * entries is a rule about personal data: the connected Google account is
 * the e-mail address of a real person, so it belongs in `secrets.enc`
 * and nowhere near a journal line that is read on screen and carried
 * into a diagnostic archive. A repository that discarded its arguments
 * asserted that rule by never looking — so the entries are kept, and the
 * test reads them.
 */
final class RecordingJournalRepository extends JournalRepository
{
    /** @var list<array{type: string, description: string, context: string}> */
    public array $entries = [];

    public function __construct()
    {
        parent::__construct(new \PDO('sqlite::memory:'));
    }

    public function insert(
        string $category,
        string $type,
        string $level,
        string $description,
        ?string $contextJson,
        ?int $userId,
        ?string $ipAddress = null
    ): void {
        $this->entries[] = ['type' => $type, 'description' => $description, 'context' => (string) $contextJson];
    }

    /** Everything one entry could show a human, in one string. */
    /**
     * How many entries of this type were recorded.
     *
     * {@see textOf()} answers `''` both for « the entry says nothing about
     * that » and for « there is no entry », and the two are opposite
     * findings: an assertion that some address does NOT appear in the
     * journal passes trivially once the code stops journalling at all.
     * Counting first is what keeps such an assertion about the entry
     * rather than about its absence.
     */
    public function countOf(string $type): int
    {
        return count(array_filter(
            $this->entries,
            static fn (array $entry): bool => $entry['type'] === $type
        ));
    }

    public function textOf(string $type): string
    {
        $text = '';
        foreach ($this->entries as $entry) {
            if ($entry['type'] === $type) {
                $text .= $entry['description'] . ' ' . $entry['context'];
            }
        }

        return $text;
    }
}
