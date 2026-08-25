<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

/**
 * PHP half of scripts/dast.sh, the dynamic application security testing
 * harness — the same split as scripts/e2e-support.php serves for
 * scripts/e2e.sh, and for the same reason: everything that needs more
 * than a line of shell lives in the one interpreter this repository
 * already requires, so the harness gains no dependency of its own.
 *
 * Every subcommand is fail-closed. A security scan that reports success
 * because a step silently did nothing is worse than a scan that did not
 * run, so each of these says what went wrong and exits non-zero rather
 * than letting the run continue on an assumption.
 *
 * Subcommands (all invoked by scripts/dast.sh, never by hand):
 *   generate-cert <pem-path> <hostname>
 *   wait-url <url> <timeout-seconds>
 *   zap-plan-start <zap-base-url> <api-key> <container-plan-path>
 *   zap-plan-await-delay <zap-base-url> <api-key> <plan-id> <timeout-seconds>
 *   zap-plan-wait <zap-base-url> <api-key> <plan-id> <timeout-seconds>
 *   assert-sitemap <zap-base-url> <api-key> <site-url> <expectations-file>
 *   gate-alerts <zap-base-url> <api-key> <site-url> <threshold>
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "dast-support.php is a CLI script.\n");
    exit(1);
}

/**
 * The risk names ZAP reports, weakest first. "Informational" and "Low"
 * are recorded but never fail a run; SECURITY.md's DAST gate is Medium
 * and above (there is deliberately no baseline of accepted findings —
 * a finding is either fixed or filtered as a false positive, with the
 * reason written into the plan YAML).
 */
const DAST_RISK_ORDER = ['Informational', 'Low', 'Medium', 'High'];

function dast_fail(string $message): never
{
    fwrite(STDERR, "DAST: {$message}\n");
    exit(1);
}

/**
 * A plain HTTP GET with no proxy, no redirects followed blindly, and a
 * short timeout. Everything this script talks to is on loopback, and
 * routing a ZAP API call through this session's own outbound proxy
 * (HTTPS_PROXY is set in some environments) would simply hang.
 *
 * @return array{status: int, body: string}|null null on a transport failure
 */
function dast_http_get(string $url, int $timeoutSeconds = 30): ?array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeoutSeconds,
            'ignore_errors' => true,
            'follow_location' => 0,
        ],
        'ssl' => [
            // The instance serves a certificate generated for this run
            // and trusted by nothing. Verifying it would mean shipping a
            // trust store for a key that lives for the length of one
            // scan; the connection is to loopback either way.
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        return null;
    }

    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches)) {
            $status = (int) $matches[1];
        }
    }

    return ['status' => $status, 'body' => $body];
}

/**
 * Call a ZAP API endpoint and decode its JSON answer.
 *
 * @param array<string, string> $parameters
 * @return array<string, mixed>
 */
function dast_zap_api(string $baseUrl, string $apiKey, string $path, array $parameters = []): array
{
    $query = http_build_query(['apikey' => $apiKey] + $parameters);
    $url = rtrim($baseUrl, '/') . '/JSON/' . trim($path, '/') . '/?' . $query;

    $response = dast_http_get($url, 120);
    if ($response === null) {
        dast_fail("the ZAP API did not answer at {$baseUrl} (path {$path}).");
    }

    $decoded = json_decode($response['body'], true);
    if (!is_array($decoded)) {
        dast_fail("the ZAP API returned something that is not JSON for {$path}: " . substr($response['body'], 0, 200));
    }

    if (isset($decoded['code'])) {
        dast_fail("the ZAP API refused {$path}: {$decoded['code']} — " . (string) ($decoded['message'] ?? ''));
    }

    return $decoded;
}

/**
 * Write a self-signed certificate and its key into one PEM file, the
 * shape stream_socket_server()'s `local_cert` wants.
 *
 * PHP's own openssl extension rather than the `openssl` binary: the same
 * "one interpreter" reasoning that has scripts/e2e.sh generate its
 * per-run passwords through `php -r`. The key never leaves the run's
 * temporary directory and the certificate is valid for a day, because a
 * throwaway TLS identity that outlives the run it was made for is just
 * litter with a private key attached.
 */
function dast_generate_certificate(string $pemPath, string $hostname): void
{
    if (!extension_loaded('openssl')) {
        dast_fail("the 'openssl' PHP extension is required to generate the scan's certificate.");
    }

    $subject = [
        'countryName' => 'BE',
        'organizationName' => 'ScoutMagic DAST harness',
        'commonName' => $hostname,
    ];

    // The SAN is not decoration: every current browser ignores
    // commonName entirely, and Chromium would reject the certificate
    // outright without it — including with ignoreHTTPSErrors, which
    // suppresses the interstitial but not a malformed certificate.
    $configFile = tempnam(sys_get_temp_dir(), 'dast-openssl-');
    if ($configFile === false) {
        dast_fail('could not create a temporary OpenSSL configuration file.');
    }
    file_put_contents(
        $configFile,
        "[req]\ndistinguished_name = dn\n[dn]\n[v3_req]\n"
        . "basicConstraints = CA:FALSE\n"
        . "keyUsage = digitalSignature, keyEncipherment\n"
        . "extendedKeyUsage = serverAuth\n"
        . "subjectAltName = DNS:{$hostname}, DNS:localhost, IP:127.0.0.1\n"
    );

    $config = [
        'config' => $configFile,
        'digest_alg' => 'sha256',
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'x509_extensions' => 'v3_req',
    ];

    $privateKey = openssl_pkey_new($config);
    if ($privateKey === false) {
        unlink($configFile);
        dast_fail('could not generate a private key: ' . openssl_error_string());
    }

    $csr = openssl_csr_new($subject, $privateKey, $config);
    if ($csr === false) {
        unlink($configFile);
        dast_fail('could not generate a certificate request: ' . openssl_error_string());
    }

    $certificate = openssl_csr_sign($csr, null, $privateKey, 1, $config);
    if ($certificate === false) {
        unlink($configFile);
        dast_fail('could not self-sign the certificate: ' . openssl_error_string());
    }

    openssl_x509_export($certificate, $certificatePem);
    openssl_pkey_export($privateKey, $keyPem, null, $config);
    unlink($configFile);

    // 0600 before anything is written: the file holds a private key, and
    // a default-umask window is a window.
    touch($pemPath);
    chmod($pemPath, 0600);
    file_put_contents($pemPath, $certificatePem . $keyPem);
}

/**
 * Poll a URL until it answers or the deadline passes. Same shape and
 * same reasoning as e2e_wait_http() — never a fixed sleep.
 */
function dast_wait_url(string $url, int $timeoutSeconds): bool
{
    $deadline = microtime(true) + $timeoutSeconds;

    while (microtime(true) < $deadline) {
        $response = dast_http_get($url, 5);
        if ($response !== null && $response['status'] > 0 && $response['status'] < 500) {
            return true;
        }
        usleep(200_000);
    }

    return false;
}

/**
 * Ask ZAP to load and start an Automation Framework plan, and print the
 * plan id it hands back.
 *
 * Starting and waiting are two subcommands because the plan is
 * deliberately not run to completion in one go: its `delay` job blocks
 * until the browser suite has finished producing traffic, which happens
 * between the two calls. The plan is loaded from inside the ZAP
 * container, so the path here is the container's, not the host's.
 */
function dast_start_plan(string $baseUrl, string $apiKey, string $planPath): void
{
    $started = dast_zap_api($baseUrl, $apiKey, 'automation/action/runPlan', ['filePath' => $planPath]);
    $planId = (string) ($started['planId'] ?? '');
    if ($planId === '') {
        dast_fail('ZAP accepted the plan but returned no planId.');
    }

    echo $planId;
}

/**
 * Follow a running plan to completion, echoing what ZAP says as it says
 * it, and failing if the plan errors or never finishes.
 *
 * Polled rather than waited on blindly: a plan that hangs has to fail
 * this script rather than hold a release gate open for ever.
 */
function dast_wait_plan(string $baseUrl, string $apiKey, string $planId, int $timeoutSeconds): void
{
    $deadline = microtime(true) + $timeoutSeconds;
    $reported = [];

    while (microtime(true) < $deadline) {
        $progress = dast_zap_api($baseUrl, $apiKey, 'automation/view/planProgress', ['planId' => $planId]);

        foreach (['info', 'warn', 'error'] as $level) {
            foreach ((array) ($progress[$level] ?? []) as $line) {
                $key = $level . '|' . $line;
                if (!isset($reported[$key])) {
                    $reported[$key] = true;
                    echo "DAST: [zap {$level}] {$line}\n";
                }
            }
        }

        $errors = (array) ($progress['error'] ?? []);
        if (count($errors) > 0) {
            dast_fail('the ZAP automation plan reported an error: ' . implode('; ', $errors));
        }

        if (($progress['finished'] ?? '') !== '') {
            return;
        }

        usleep(500_000);
    }

    dast_fail("the ZAP automation plan did not finish within {$timeoutSeconds} s.");
}

/**
 * Wait until the running plan has reached its `delay` job — that is,
 * until every job before it (alert filters, passive-scan configuration)
 * has actually run.
 *
 * Sending traffic earlier would scan responses with the default
 * configuration and no alert filters in place, and an alert raised
 * before its filter exists stays raised. Polled on the plan's own
 * progress log rather than slept on, so a slow container delays the run
 * instead of corrupting it.
 */
function dast_await_delay_job(string $baseUrl, string $apiKey, string $planId, int $timeoutSeconds): void
{
    $deadline = microtime(true) + $timeoutSeconds;
    $reported = [];

    while (microtime(true) < $deadline) {
        $progress = dast_zap_api($baseUrl, $apiKey, 'automation/view/planProgress', ['planId' => $planId]);

        foreach ((array) ($progress['error'] ?? []) as $line) {
            dast_fail("the ZAP automation plan errored before the browser ran: {$line}");
        }

        foreach (['info', 'warn'] as $level) {
            foreach ((array) ($progress[$level] ?? []) as $line) {
                $key = $level . '|' . $line;
                if (!isset($reported[$key])) {
                    $reported[$key] = true;
                    echo "DAST: [zap {$level}] {$line}\n";
                }
                if (stripos((string) $line, 'delay') !== false) {
                    return;
                }
            }
        }

        usleep(250_000);
    }

    dast_fail("ZAP did not reach the plan's delay job within {$timeoutSeconds} s.");
}

/**
 * Assert the scan actually saw the application.
 *
 * This is the check the whole harness turns on. Chromium bypasses an
 * HTTP proxy for loopback addresses unless it is told not to
 * (`--proxy-bypass-list=<-loopback>`), and when that argument goes
 * missing every browser test still passes, ZAP records nothing, the
 * passive scanner finds no problems in the nothing it was given, and the
 * run reports a clean bill of health. That failure is completely silent,
 * which is why it is asserted here rather than assumed: an empty or
 * anonymous-only site map fails the run.
 *
 * The expectations file lists one path per line — paths a signed-in
 * session reaches and an anonymous visitor cannot — so "ZAP saw the site"
 * cannot be satisfied by the login page alone.
 */
function dast_assert_sitemap(string $baseUrl, string $apiKey, string $siteUrl, string $expectationsFile): void
{
    if (!is_file($expectationsFile)) {
        dast_fail("the site-map expectations file {$expectationsFile} does not exist.");
    }

    $expected = array_values(array_filter(array_map(
        static fn(string $line): string => trim($line),
        (array) file($expectationsFile)
    ), static fn(string $line): bool => $line !== '' && !str_starts_with($line, '#')));

    if (count($expected) === 0) {
        dast_fail("the site-map expectations file {$expectationsFile} lists nothing to check.");
    }

    $response = dast_zap_api($baseUrl, $apiKey, 'core/view/urls', ['baseurl' => $siteUrl]);
    $urls = array_map('strval', (array) ($response['urls'] ?? []));

    if (count($urls) === 0) {
        dast_fail(
            "ZAP's site map for {$siteUrl} is EMPTY — the browser did not proxy through it.\n"
            . "      The usual cause is Chromium bypassing the proxy for loopback: check that\n"
            . "      --proxy-bypass-list=<-loopback> reached launchOptions.args in\n"
            . '      tests/e2e/playwright.config.js.'
        );
    }

    $paths = [];
    foreach ($urls as $url) {
        $path = parse_url($url, PHP_URL_PATH);
        if (is_string($path)) {
            $paths[$path] = true;
        }
    }

    $missing = [];
    foreach ($expected as $expectedPath) {
        $found = false;
        foreach (array_keys($paths) as $path) {
            if ($path === $expectedPath || str_starts_with($path, rtrim($expectedPath, '/') . '/')) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $missing[] = $expectedPath;
        }
    }

    if (count($missing) > 0) {
        dast_fail(
            'ZAP recorded ' . count($urls) . " URLs for {$siteUrl}, but not these authenticated pages the\n"
            . "      browser suite visits: " . implode(', ', $missing) . "\n"
            . '      A site map holding only public pages means the scan never saw a signed-in session.'
        );
    }

    echo 'DAST: ZAP site map holds ' . count($urls) . ' URLs, including every expected authenticated page.' . "\n";
}

/**
 * Read the alerts ZAP raised, print them grouped by risk, and decide the
 * run's verdict: anything at or above the threshold fails.
 *
 * Alert filters have already been applied by the plan — a finding that
 * survives to here is one nobody has written down a reason to silence.
 */
function dast_gate_alerts(string $baseUrl, string $apiKey, string $siteUrl, string $threshold): never
{
    $thresholdIndex = array_search($threshold, DAST_RISK_ORDER, true);
    if ($thresholdIndex === false) {
        dast_fail("unknown risk threshold '{$threshold}' (expected one of " . implode(', ', DAST_RISK_ORDER) . ').');
    }

    $alerts = [];
    $start = 0;
    $pageSize = 500;

    // Paged rather than fetched whole: a scan of a site this size can
    // raise thousands of informational alerts, and ZAP streams the lot
    // into one JSON document otherwise.
    while (true) {
        $page = dast_zap_api($baseUrl, $apiKey, 'alert/view/alerts', [
            'baseurl' => $siteUrl,
            'start' => (string) $start,
            'count' => (string) $pageSize,
        ]);
        $batch = (array) ($page['alerts'] ?? []);
        foreach ($batch as $alert) {
            if (is_array($alert)) {
                $alerts[] = $alert;
            }
        }
        if (count($batch) < $pageSize) {
            break;
        }
        $start += $pageSize;
    }

    /** @var array<string, array<string, array{count: int, urls: list<string>}>> $grouped */
    $grouped = [];
    foreach ($alerts as $alert) {
        // ZAP writes the risk as "Medium (High)" — risk, then confidence.
        $risk = trim(explode('(', (string) ($alert['risk'] ?? 'Informational'))[0]);
        $name = (string) ($alert['alert'] ?? $alert['name'] ?? 'unnamed');
        $url = (string) ($alert['url'] ?? '');

        $grouped[$risk][$name] ??= ['count' => 0, 'urls' => []];
        $grouped[$risk][$name]['count']++;
        if (count($grouped[$risk][$name]['urls']) < 3 && $url !== '') {
            $grouped[$risk][$name]['urls'][] = $url;
        }
    }

    $failing = 0;
    echo "\nDAST: findings by risk\n";
    foreach (array_reverse(DAST_RISK_ORDER) as $risk) {
        $entries = $grouped[$risk] ?? [];
        if (count($entries) === 0) {
            continue;
        }

        $riskIndex = array_search($risk, DAST_RISK_ORDER, true);
        $blocking = is_int($riskIndex) && $riskIndex >= $thresholdIndex;

        echo '  ' . strtoupper($risk) . ($blocking ? ' (blocking)' : '') . "\n";
        foreach ($entries as $name => $entry) {
            $failing += $blocking ? $entry['count'] : 0;
            echo "    - {$name} ×{$entry['count']}\n";
            foreach ($entry['urls'] as $url) {
                echo "        {$url}\n";
            }
        }
    }

    if (count($alerts) === 0) {
        echo "  (none)\n";
    }

    echo "\n";
    if ($failing > 0) {
        fwrite(STDERR, "DAST: {$failing} finding(s) at or above '{$threshold}'. See the HTML report.\n");
        exit(1);
    }

    echo "DAST: no finding at or above '{$threshold}'.\n";
    exit(0);
}

$command = $argv[1] ?? '';

switch ($command) {
    case 'generate-cert':
        if (($argv[2] ?? '') === '' || ($argv[3] ?? '') === '') {
            dast_fail('usage: dast-support.php generate-cert <pem-path> <hostname>');
        }
        dast_generate_certificate($argv[2], $argv[3]);
        break;

    case 'wait-url':
        if (($argv[2] ?? '') === '') {
            dast_fail('usage: dast-support.php wait-url <url> <timeout-seconds>');
        }
        exit(dast_wait_url($argv[2], (int) ($argv[3] ?? 60)) ? 0 : 1);

    case 'zap-plan-start':
        if (($argv[2] ?? '') === '' || ($argv[3] ?? '') === '' || ($argv[4] ?? '') === '') {
            dast_fail('usage: dast-support.php zap-plan-start <zap-url> <api-key> <plan-path>');
        }
        dast_start_plan($argv[2], $argv[3], $argv[4]);
        break;

    case 'zap-plan-await-delay':
        if (($argv[2] ?? '') === '' || ($argv[3] ?? '') === '' || ($argv[4] ?? '') === '') {
            dast_fail('usage: dast-support.php zap-plan-await-delay <zap-url> <api-key> <plan-id> <timeout-seconds>');
        }
        dast_await_delay_job($argv[2], $argv[3], $argv[4], (int) ($argv[5] ?? 120));
        break;

    case 'zap-plan-wait':
        if (($argv[2] ?? '') === '' || ($argv[3] ?? '') === '' || ($argv[4] ?? '') === '') {
            dast_fail('usage: dast-support.php zap-plan-wait <zap-url> <api-key> <plan-id> <timeout-seconds>');
        }
        dast_wait_plan($argv[2], $argv[3], $argv[4], (int) ($argv[5] ?? 3600));
        break;

    case 'assert-sitemap':
        if (($argv[2] ?? '') === '' || ($argv[3] ?? '') === '' || ($argv[4] ?? '') === '' || ($argv[5] ?? '') === '') {
            dast_fail('usage: dast-support.php assert-sitemap <zap-url> <api-key> <site-url> <expectations-file>');
        }
        dast_assert_sitemap($argv[2], $argv[3], $argv[4], $argv[5]);
        break;

    case 'gate-alerts':
        if (($argv[2] ?? '') === '' || ($argv[3] ?? '') === '' || ($argv[4] ?? '') === '') {
            dast_fail('usage: dast-support.php gate-alerts <zap-url> <api-key> <site-url> <threshold>');
        }
        dast_gate_alerts($argv[2], $argv[3], $argv[4], $argv[5] ?? 'Medium');

    default:
        dast_fail("unknown subcommand '{$command}' — see this file's header for the list.");
}
