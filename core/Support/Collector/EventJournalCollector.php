<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Collector;

use Core\Service\DateInput;
use Core\Support\DiagnosticWindow;
use Core\Support\SupportCollectorContext;
use Core\Support\SupportCollectorInterface;
use Core\Export\TabularSpreadsheet;

/**
 * `event-journal.xlsx` — every `event_log` entry of the last seven days
 * ({@see DiagnosticWindow}), one column per real column of the model, never
 * a textual dump.
 *
 * Seven days rather than the 48 hours it used to be (issue #846): a
 * problem found on Friday is often the restore of Monday, and a journal
 * that had already let go of it left the diagnosis inconclusive. A
 * structured sheet rather than a text blob because the first thing anyone
 * does with a journal is sort and filter it.
 *
 * **What the installation still keeps is said, not assumed.** The journal
 * is purged after `journal_retention_days`; an installation keeping fewer
 * than seven has no week to give, and the digest says how far back it
 * actually goes rather than announcing a span the database no longer
 * holds.
 *
 * Read row by row rather than through `fetchAll()`, and bounded at
 * MAX_ROWS: a week of a journal flooded by a looping task (24 896 entries
 * in 48 hours on one installation, §8.5) must not exhaust the memory of
 * the job that is meant to diagnose it.
 *
 * The journal contains no personal data by construction (SECURITY.md §11 —
 * entries reference a member by id, never by name), but it does carry
 * source IP addresses and internal member/user ids: precisely what the
 * README's warning is about.
 */
class EventJournalCollector implements SupportCollectorInterface
{
    /**
     * A ceiling well above any healthy week, which a journal flooded by a
     * runaway task still reaches; the newest rows are the ones kept.
     */
    public const MAX_ROWS = 50000;

    /** What `public/cron.php` purges after when the setting is empty. */
    private const DEFAULT_RETENTION_DAYS = 730;

    public function name(): string
    {
        return 'event_journal';
    }

    public function collect(SupportCollectorContext $context): void
    {
        $stmt = $context->pdo()->prepare(
            'SELECT logged_at, user_account_id, ip_address, category, event_type, level, description, context
             FROM event_log
             WHERE logged_at >= ?
             ORDER BY logged_at DESC, id DESC'
        );
        $stmt->execute([DiagnosticWindow::cutoff()]);

        $rows = [];
        $byType = [];
        $byLevel = [];
        $total = 0;
        $oldest = null;
        while (($entry = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $total++;
            $type = self::asString($entry['event_type'] ?? null);
            $level = self::asString($entry['level'] ?? null);
            $byType[$type] = ($byType[$type] ?? 0) + 1;
            $byLevel[$level] = ($byLevel[$level] ?? 0) + 1;
            $oldest = self::asString($entry['logged_at'] ?? null);
            if (count($rows) >= self::MAX_ROWS) {
                continue;
            }
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
        $stmt->closeCursor();

        $retentionDays = (int) ($context->settings()->get('journal_retention_days') ?: self::DEFAULT_RETENTION_DAYS);
        $context->addNote($total . ' entrée(s) sur ' . DiagnosticWindow::label());
        if ($retentionDays < DiagnosticWindow::DAYS) {
            $context->addNote(
                'Le journal n\'est conservé que ' . $retentionDays . ' jour(s) sur cette installation'
            );
        }
        if ($total > count($rows)) {
            $context->addNote(
                'Détail tronqué aux ' . self::MAX_ROWS . ' entrées les plus récentes ; '
                . 'le résumé les compte toutes'
            );
        }
        $context->addFileFromContent(
            'event-journal.xlsx',
            TabularSpreadsheet::build(
                [
                    'Horodatage (heure locale du serveur)',
                    'Compte utilisateur', 'Adresse IP', 'Catégorie', 'Type', 'Niveau', 'Description', 'Contexte',
                ],
                $rows,
                'Journal ' . DiagnosticWindow::label()
            )
        );
        $context->addFileFromContent(
            'event-journal-resume.txt',
            self::renderDigest($byType, $byLevel, $total, count($rows), $oldest, $retentionDays)
        );
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
     * What the seven days actually consist of, before anyone scrolls.
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
    private static function renderDigest(
        array $byType,
        array $byLevel,
        int $total,
        int $detailed,
        ?string $oldest,
        int $retentionDays
    ): string {
        arsort($byType);
        arsort($byLevel);

        $lines = [
            '# Journal des événements — résumé des ' . DiagnosticWindow::label() . ' derniers',
            '# Le détail ligne à ligne est dans event-journal.xlsx.',
            '',
            "Total : {$total} entrée(s)",
        ];
        if ($oldest !== null && $oldest !== '') {
            $lines[] = 'Entrée la plus ancienne : ' . self::localTimestamp($oldest);
        }
        // A window the database no longer holds is not announced as one.
        if ($retentionDays < DiagnosticWindow::DAYS) {
            $lines[] = 'Ce site ne conserve son journal que ' . $retentionDays . ' jour(s) : '
                . 'au-delà, il n\'y a plus rien à exporter.';
        }
        if ($detailed < $total) {
            $lines[] = 'Le détail ne reprend que les ' . $detailed . ' entrées les plus récentes ; '
                . 'les comptes ci-dessous portent sur toutes.';
        }
        $lines[] = '';
        $lines[] = '## Par niveau';
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
