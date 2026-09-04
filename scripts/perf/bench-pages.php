<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

/**
 * Time every GET page of a running instance, logged in as one account.
 *
 * Usage: php scripts/perf/bench-pages.php <base-url> <email|-> <password> <urls-file> [runs=3]
 *
 * One warm-up request, then <runs> timed requests per path; prints the
 * median and minimum wall time, the response size and — when the MySQL
 * server is reachable with PERF_DB_HOST/PORT/USER/PASSWORD (defaulting to
 * TEST_DB_*) — the number of SQL statements the request issued, read off
 * `SHOW GLOBAL STATUS LIKE 'Questions'`. That count is only exact when
 * nothing else talks to the server (a php -S instance driven by this
 * script alone). Output is sorted slowest first.
 *
 * <urls-file>: one path per line, `#` comments allowed. See
 * docs/chantiers/CHANTIER-performance.md for the reference list.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
[$_, $base, $email, $password, $urlsFile] = $argv + [null, null, null, null, null];
$runs = (int) ($argv[5] ?? 3);
if ($base === null || $urlsFile === null || !is_file($urlsFile)) {
    fwrite(STDERR, "Usage: php scripts/perf/bench-pages.php <base-url> <email|-> <password> <urls-file> [runs]\n");
    exit(1);
}
$jar = tempnam(sys_get_temp_dir(), 'perf-jar');
$questions = null;
try {
    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%d',
            getenv('PERF_DB_HOST') ?: (getenv('TEST_DB_HOST') ?: '127.0.0.1'),
            (int) (getenv('PERF_DB_PORT') ?: (getenv('TEST_DB_PORT') ?: 3306)),
        ),
        getenv('PERF_DB_USER') ?: (getenv('TEST_DB_USER') ?: 'root'),
        getenv('PERF_DB_PASSWORD') ?: (getenv('TEST_DB_PASSWORD') ?: '')
    );
    $questions = static fn(): int => (int) $pdo->query("SHOW GLOBAL STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM)[1];
} catch (PDOException) {
    fwrite(STDERR, "SQL statement counter unavailable (no MySQL credentials); timings only.\n");
}

/** @return array{0: int, 1: int, 2: float, 3: string, 4: string} */
function perf_request(string $url, string $jar, ?string $json = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    if ($json !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
    }
    $body = (string) curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    return [
        (int) $info['http_code'],
        strlen($body),
        (float) $info['total_time'],
        $body,
        (string) ($info['redirect_url'] ?? ''),
    ];
}

if ($email !== '-') {
    [, , , $html] = perf_request($base . '/login', $jar);
    preg_match('/id="csrf-token" value="([^"]+)"/', $html, $m);
    [$code, , , $body] = perf_request($base . '/login/password', $jar, json_encode([
        '_csrf_token' => $m[1] ?? '', 'email' => $email, 'password' => $password, 'rgpd_consent' => true,
    ]));
    fwrite(STDERR, "login: $code $body\n");
}

$rows = [];
foreach (array_filter(array_map('trim', file($urlsFile))) as $path) {
    if ($path[0] === '#') {
        continue;
    }
    perf_request($base . $path, $jar);
    $times = [];
    $sql = 0;
    $code = 0;
    $bytes = 0;
    $redirect = '';
    for ($i = 0; $i < $runs; $i++) {
        $before = $questions !== null ? $questions() : 0;
        [$code, $bytes, $seconds, , $redirect] = perf_request($base . $path, $jar);
        // The status query itself is the one extra statement counted.
        $sql = $questions !== null ? $questions() - $before - 1 : 0;
        $times[] = $seconds * 1000;
    }
    sort($times);
    $rows[] = [$path, $code, $bytes, $times[intdiv(count($times), 2)], $times[0], $sql, $redirect];
    fwrite(STDERR, '.');
}
fwrite(STDERR, "\n");
usort($rows, static fn(array $a, array $b): int => $b[3] <=> $a[3]);
printf("%-48s %4s %8s %9s %9s %6s %s\n", 'path', 'code', 'bytes', 'median_ms', 'min_ms', 'sql', 'redirect');
foreach ($rows as $row) {
    printf("%-48s %4d %8d %9.1f %9.1f %6d %s\n", ...$row);
}
