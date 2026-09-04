<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Collector;

use Core\Support\SupportCollectorContext;
use Core\Support\SupportCollectorInterface;

/**
 * The request timelines a measurement window recorded (Core\Debug\
 * MeasurementWindow, Core\Debug\RequestTimeline), as two files a support
 * reader can open without the site in front of them:
 *
 * - `request-timelines.csv`: one line per SEGMENT of every recorded
 *   request — the time and SQL statements between two consecutive
 *   checkpoints, which is how an N+1 or a slow module block is read off
 *   a timeline rather than off the code.
 * - `request-timelines-resume.txt`: one line per path, slowest first,
 *   with the count, the median and the worst total, the median statement
 *   count and the segment that costs the most. That page is what a
 *   ticket saying « le site est lent » is about; this line says where.
 *
 * Same 48-hour window as the event journal, from which these rows come
 * (`event_type = 'debug_request_timeline'`). The path is the only thing
 * about a page that is kept; never a query string, never any content.
 * A window that recorded nothing is said, not left as an empty file.
 *
 * @phpstan-type PathStats array{count: int, totals: list<float>, sql: list<int>, segments: array<string, list<float>>}
 * @phpstan-type Segment array{label: string, ms: float, sql: int, sql_ms: float, at_ms: float, sql_at: int,
 *     mem_mb: string}
 */
class RequestTimelinesCollector implements SupportCollectorInterface
{
    public const WINDOW_HOURS = EventJournalCollector::WINDOW_HOURS;

    public function name(): string
    {
        return 'request_timelines';
    }

    public function collect(SupportCollectorContext $context): void
    {
        $cutoff = (new \DateTimeImmutable('-' . self::WINDOW_HOURS . ' hours'))->format('Y-m-d H:i:s');
        $stmt = $context->pdo()->prepare(
            "SELECT logged_at, context FROM event_log
             WHERE event_type = 'debug_request_timeline' AND logged_at >= ?
             ORDER BY id"
        );
        $stmt->execute([$cutoff]);

        $rows = [];
        /** @var array<string, PathStats> $byPath */
        $byPath = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $entry) {
            $decoded = json_decode((string) ($entry['context'] ?? ''), true);
            if (!is_array($decoded) || !is_array($decoded['timeline'] ?? null)) {
                continue;
            }
            $method = self::asString($decoded['method'] ?? 'GET');
            $path = $context->redact(self::asString($decoded['path'] ?? '?'), 200);
            $role = self::asString($decoded['role'] ?? '');
            $loggedAt = self::asString($entry['logged_at'] ?? '');

            $segments = self::segments($decoded['timeline']);
            if ($segments === []) {
                continue;
            }
            $last = end($segments);
            $totalMs = $last['at_ms'];
            $totalSql = $last['sql_at'];

            foreach ($segments as $segment) {
                $rows[] = [
                    $loggedAt, $method, $path, $role,
                    self::ms($totalMs), $totalSql,
                    $segment['label'], self::ms($segment['ms']), $segment['sql'],
                    self::ms($segment['sql_ms']), $segment['mem_mb'],
                ];
            }

            $key = $method . ' ' . $path;
            $byPath[$key] ??= ['count' => 0, 'totals' => [], 'sql' => [], 'segments' => []];
            $byPath[$key]['count']++;
            $byPath[$key]['totals'][] = $totalMs;
            $byPath[$key]['sql'][] = $totalSql;
            foreach ($segments as $segment) {
                $byPath[$key]['segments'][$segment['label']][] = $segment['ms'];
            }
        }

        if ($rows === []) {
            $context->markUnavailable('no_measurement_recorded');

            return;
        }

        $header = [
            'horodatage', 'methode', 'chemin', 'role', 'total_ms', 'total_sql',
            'segment', 'segment_ms', 'segment_sql', 'segment_sql_ms', 'memoire_mo',
        ];
        $context->addFileFromContent('request-timelines.csv', self::csv(array_merge([$header], $rows)));
        $context->addFileFromContent('request-timelines-resume.txt', self::summary($byPath));
        $requests = array_sum(array_column($byPath, 'count'));
        $context->addNote(count($byPath) . ' chemin(s) mesuré(s), ' . $requests . ' requête(s)');
    }

    /**
     * The deltas between consecutive checkpoints. Entries written before
     * the SQL counters existed have no `sql` keys and count as zero.
     *
     * @param array<int, mixed> $timeline
     * @return list<Segment>
     */
    private static function segments(array $timeline): array
    {
        $segments = [];
        $previous = null;
        foreach ($timeline as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $at = (float) ($entry['t_ms'] ?? 0);
            $sqlAt = (int) ($entry['sql'] ?? 0);
            $sqlMsAt = (float) ($entry['sql_ms'] ?? 0);
            if ($previous !== null) {
                $segments[] = [
                    'label' => self::asString($entry['label'] ?? '?'),
                    'ms' => $at - $previous['at'],
                    'sql' => $sqlAt - $previous['sql'],
                    'sql_ms' => $sqlMsAt - $previous['sql_ms'],
                    'at_ms' => $at,
                    'sql_at' => $sqlAt,
                    'mem_mb' => self::asString($entry['mem_mb'] ?? ''),
                ];
            }
            $previous = ['at' => $at, 'sql' => $sqlAt, 'sql_ms' => $sqlMsAt];
        }

        return $segments;
    }

    /**
     * @param array<string, PathStats> $byPath
     */
    private static function summary(array $byPath): string
    {
        uasort(
            $byPath,
            static fn(array $a, array $b): int => self::median($b['totals']) <=> self::median($a['totals'])
        );

        $lines = [
            'Chronologies de requêtes enregistrées pendant une fenêtre de mesure ('
                . self::WINDOW_HOURS . ' dernières heures).',
            'Temps serveur uniquement : le réseau, le service worker et le rendu ne sont pas mesurés.',
            '',
        ];
        foreach ($byPath as $key => $stats) {
            $heaviestLabel = '';
            $heaviestMs = -1.0;
            foreach ($stats['segments'] as $label => $values) {
                $median = self::median($values);
                if ($median > $heaviestMs) {
                    $heaviestMs = $median;
                    $heaviestLabel = $label;
                }
            }
            $lines[] = sprintf(
                '%s — %d requête(s), médiane %s ms, maximum %s ms, %d instruction(s) SQL en médiane,'
                    . ' segment le plus lourd : %s (%s ms)',
                $key,
                $stats['count'],
                self::ms(self::median($stats['totals'])),
                self::ms((float) max($stats['totals'])),
                (int) round(self::median(array_map('floatval', $stats['sql']))),
                $heaviestLabel,
                self::ms($heaviestMs)
            );
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<int, array<int, string|int|float>> $rows
     */
    private static function csv(array $rows): string
    {
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            return '';
        }
        foreach ($rows as $row) {
            fputcsv($stream, $row, ';', '"', '\\', "\n");
        }
        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    /**
     * @param list<float> $values
     */
    private static function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    private static function ms(float $value): string
    {
        return number_format($value, 1, '.', '');
    }

    private static function asString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
