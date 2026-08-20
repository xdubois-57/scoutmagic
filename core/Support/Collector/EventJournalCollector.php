<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Collector;

use Core\Support\SupportCollectorContext;
use Core\Support\SupportCollectorInterface;
use Core\Support\SupportSpreadsheet;

/**
 * `event-journal.xlsx` — every `event_log` entry from the last 48 hours,
 * one column per real column of the model, never a textual dump.
 *
 * 48 hours is the window in which "it broke this morning" is still true.
 * A structured sheet rather than a text blob because the first thing anyone
 * does with a journal is sort and filter it.
 *
 * The journal contains no personal data by construction (SECURITY.md §11 —
 * entries reference a member by id, never by name), but it does carry
 * source IP addresses and internal member/user ids: precisely what the
 * README's warning is about.
 */
class EventJournalCollector implements SupportCollectorInterface
{
    public const WINDOW_HOURS = 48;

    public function name(): string
    {
        return 'event_journal';
    }

    public function collect(SupportCollectorContext $context): void
    {
        $cutoff = (new \DateTimeImmutable('-' . self::WINDOW_HOURS . ' hours'))->format('Y-m-d H:i:s');

        $stmt = $context->pdo()->prepare(
            'SELECT logged_at, user_account_id, ip_address, category, event_type, level, description, context
             FROM event_log
             WHERE logged_at >= ?
             ORDER BY logged_at DESC, id DESC'
        );
        $stmt->execute([$cutoff]);

        $rows = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $entry) {
            $rows[] = [
                self::asString($entry['logged_at'] ?? null),
                self::asString($entry['user_account_id'] ?? null),
                self::asString($entry['ip_address'] ?? null),
                self::asString($entry['category'] ?? null),
                self::asString($entry['event_type'] ?? null),
                self::asString($entry['level'] ?? null),
                self::asString($entry['description'] ?? null),
                self::asString($entry['context'] ?? null),
            ];
        }

        $context->addNote(count($rows) . ' entrée(s) sur ' . self::WINDOW_HOURS . ' h');
        $context->addFileFromContent(
            'event-journal.xlsx',
            SupportSpreadsheet::build(
                ['Horodatage', 'Compte utilisateur', 'Adresse IP', 'Catégorie', 'Type', 'Niveau', 'Description', 'Contexte'],
                $rows,
                'Journal 48h'
            )
        );
    }

    private static function asString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
