<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

/**
 * Server-side breakdown of one page, from Core\Debug\RequestTimeline.
 *
 * Usage: php scripts/perf/request-timeline.php <base-url> <db-name> <admin-email> <password> <path> [runs=6]
 *
 * Requests <path>?debug=1 as an admin, which makes public/index.php journal
 * its timeline checkpoints (event_log, event_type debug_request_timeline),
 * then prints the median time spent between consecutive checkpoints, largest
 * first. The database is read with PERF_DB_* / TEST_DB_* credentials. Add
 * RequestTimeline::mark() calls to a throwaway instance's copy of index.php
 * to refine the segments — the composition root is the part of every
 * request no controller sees.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
[$_, $base, $db, $email, $password, $path] = $argv + array_fill(0, 6, null);
$runs = max(2, (int) ($argv[6] ?? 6));
if ($path === null) {
    fwrite(
        STDERR,
        "Usage: php scripts/perf/request-timeline.php <base-url> <db-name> <admin-email> <password> <path> [runs]\n",
    );
    exit(1);
}
$jar = tempnam(sys_get_temp_dir(), 'perf-jar');
function perf_request(string $url, string $jar, ?string $json = null): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    if ($json !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    $body = (string) curl_exec($ch);
    curl_close($ch);

    return $body;
}
$html = perf_request($base . '/login', $jar);
preg_match('/id="csrf-token" value="([^"]+)"/', $html, $m);
perf_request(
    $base . '/login/password',
    $jar,
    json_encode(['_csrf_token' => $m[1] ?? '', 'email' => $email, 'password' => $password, 'rgpd_consent' => true]),
);

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s',
        getenv('PERF_DB_HOST') ?: (getenv('TEST_DB_HOST') ?: '127.0.0.1'),
        (int) (getenv('PERF_DB_PORT') ?: (getenv('TEST_DB_PORT') ?: 3306)),
        $db,
    ),
    getenv('PERF_DB_USER') ?: (getenv('TEST_DB_USER') ?: 'root'),
    getenv('PERF_DB_PASSWORD') ?: (getenv('TEST_DB_PASSWORD') ?: ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$separator = str_contains($path, '?') ? '&' : '?';
$segments = [];
$statements = [];
for ($run = 0; $run < $runs; $run++) {
    $lastId = (int) $pdo->query('SELECT MAX(id) FROM event_log')->fetchColumn();
    perf_request($base . $path . $separator . 'debug=1', $jar);
    $row = $pdo->query(
        "SELECT * FROM event_log WHERE id > $lastId "
        . "AND event_type = 'debug_request_timeline' ORDER BY id DESC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        fwrite(STDERR, "No timeline journaled — is the account an admin, and is the path served by index.php?\n");
        exit(1);
    }
    $meta = null;
    foreach ($row as $value) {
        if (is_string($value) && str_starts_with($value, '{')) {
            $meta = json_decode($value, true);
        }
    }
    if ($run === 0) {
        continue; // warm-up
    }
    $previous = 0.0;
    $previousSql = 0;
    $previousLabel = 'start';
    foreach ($meta['timeline'] ?? [] as $entry) {
        $key = $previousLabel . ' -> ' . $entry['label'];
        $segments[$key][] = $entry['t_ms'] - $previous;
        $statements[$key][] = (int) ($entry['sql'] ?? 0) - $previousSql;
        $previous = $entry['t_ms'];
        $previousSql = (int) ($entry['sql'] ?? 0);
        $previousLabel = $entry['label'];
    }
}
$rows = [];
foreach ($segments as $label => $values) {
    sort($values);
    $counts = $statements[$label];
    sort($counts);
    $rows[] = [$label, $values[intdiv(count($values), 2)], $counts[intdiv(count($counts), 2)]];
}
usort($rows, static fn(array $a, array $b): int => $b[1] <=> $a[1]);
printf(
    "%s — %.1f ms, %d SQL statements to the last checkpoint (median of %d runs)\n",
    $path,
    array_sum(array_column($rows, 1)),
    array_sum(array_column($rows, 2)),
    $runs - 1,
);
printf("%9s %5s  %s\n", 'ms', 'sql', 'segment');
foreach ($rows as [$label, $ms, $sql]) {
    if ($ms >= 0.5 || $sql > 0) {
        printf("%6.1f ms %5d  %s\n", $ms, $sql, $label);
    }
}
