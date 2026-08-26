<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Collector;

use Core\Service\DateInput;
use Core\Support\SupportCollectorContext;
use Core\Support\SupportCollectorInterface;
use Core\Export\TabularSpreadsheet;

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
        $byType = [];
        $byLevel = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $entry) {
            $type = self::asString($entry['event_type'] ?? null);
            $level = self::asString($entry['level'] ?? null);
            $byType[$type] = ($byType[$type] ?? 0) + 1;
            $byLevel[$level] = ($byLevel[$level] ?? 0) + 1;
            $rows[] = [
                self::localTimestamp(self::asString($entry['logged_at'] ?? null)),
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
            TabularSpreadsheet::build(
                [
                    'Horodatage (heure locale du serveur)',
                    'Compte utilisateur', 'Adresse IP', 'Catégorie', 'Type', 'Niveau', 'Description', 'Contexte',
                ],
                $rows,
                'Journal 48h'
            )
        );
        $context->addFileFromContent('event-journal-resume.txt', self::renderDigest($byType, $byLevel, count($rows)));
    }

    /**
     * A DB `DATETIME` carries no zone, and the journal's does not mean UTC
     * — it means whatever `date.timezone` was when the row was written. A
     * reader lining these up against `collection-status.json` (UTC) or a
     * server log has to know which, so every row says so itself rather
     * than relying on a header being read.
     */
    private static function localTimestamp(string $storedValue): string
    {
        if ($storedValue === '') {
            return '';
        }

        // Unparseable is still worth carrying: the raw value is the only
        // evidence of whatever wrote it.
        return DateInput::fromStorage($storedValue)?->format('Y-m-d H:i:sP') ?? $storedValue;
    }

    /**
     * What the 48 hours actually consist of, before anyone scrolls.
     *
     * The journal is dominated by whatever runs most often: on the archive
     * that prompted this file, two scheduled tasks were 475 of 884 rows,
     * and the three entries worth reading were somewhere underneath. A
     * reader who sees the shape first knows whether to scroll at all —
     * and a task suddenly accounting for a third of the journal is itself
     * the finding, which no amount of scrolling makes obvious.
     *
     * @param array<string, int> $byType
     * @param array<string, int> $byLevel
     */
    private static function renderDigest(array $byType, array $byLevel, int $total): string
    {
        arsort($byType);
        arsort($byLevel);

        $lines = [
            '# Journal des événements — résumé des ' . self::WINDOW_HOURS . ' dernières heures',
            '# Le détail ligne à ligne est dans event-journal.xlsx.',
            '',
            "Total : {$total} entrée(s)",
            '',
            '## Par niveau',
        ];
        foreach ($byLevel as $level => $count) {
            $lines[] = sprintf('%8d  %s', $count, $level === '' ? '(vide)' : $level);
        }

        $lines[] = '';
        $lines[] = '## Par type (du plus fréquent au plus rare)';
        foreach ($byType as $type => $count) {
            $share = $total > 0 ? sprintf(' (%d %%)', (int) round($count * 100 / $total)) : '';
            $lines[] = sprintf('%8d  %s%s', $count, $type === '' ? '(vide)' : $type, $share);
        }

        return implode("\n", $lines) . "\n";
    }

    private static function asString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
