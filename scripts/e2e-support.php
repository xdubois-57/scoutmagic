<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

/**
 * Support commands for the end-to-end test harness (scripts/e2e.sh).
 *
 * These live in PHP rather than in the shell script for two reasons: they
 * need the application's own classes (Core\Security\SecretManager,
 * Core\Database\Connection, Core\Database\MigrationRunner — the E2E
 * install is provisioned with exactly the production code paths the setup
 * wizard uses, never a second, parallel schema/secrets implementation),
 * and the remaining bits (picking a free TCP port, polling a URL until it
 * answers) are portable across macOS and Linux for free in PHP while
 * being awkward and non-portable in shell.
 *
 * Commands:
 *   free-port                  Print an unused local TCP port.
 *   wait-http <url> <seconds>  Poll <url> until it answers (any HTTP
 *                              status, including 5xx — a booting server
 *                              that answers 500 is "up", and it is the
 *                              browser test's job to fail on that, not
 *                              this readiness probe's). Exit 0 as soon as
 *                              it does, 1 on timeout.
 *   provision <instance> <port>
 *                              Build the throwaway application instance
 *                              at <instance> and its database, ready to
 *                              be served on localhost:<port> (see
 *                              e2e_base_url() for why a name and not the
 *                              loopback address), with EVERY module the
 *                              repository ships activated — see
 *                              e2e_activate_all_modules() for why all of
 *                              them and not just the default three.
 *   teardown-db                Drop every table of the E2E database and
 *                              the database itself.
 *   merge-coverage <dir> <out> Fold every per-request coverage fragment
 *                              scripts/e2e-coverage-prepend.php wrote into
 *                              <dir> into one Clover report at <out>, for
 *                              SonarQube Cloud to read alongside PHPUnit's.
 *
 * Database credentials come from the environment (E2E_DB_HOST/PORT/NAME/
 * USER/PASSWORD), always set by scripts/e2e.sh — see its own header for
 * the defaults and the TEST_DB_* fallbacks.
 *
 * @see scripts/e2e.sh
 */

// Defined by tests/Core/System/E2eActivationOrderTest.php before it
// requires this file, exactly as tests/Bootstrap/BootstrapTest.php does
// with bootstrap/bootstrap.php: the pure helpers below are worth testing,
// and the command dispatcher must not run when they are.
if (!defined('E2E_SUPPORT_TEST')) {
    e2e_support_main($argv);
}

/**
 * Every side effect this file has: reads argv, writes to the streams,
 * exits. Nothing below it does any of the three except the provisioning
 * steps it calls.
 *
 * @param string[] $argv
 */
function e2e_support_main(array $argv): void
{
    if (PHP_SAPI !== 'cli') {
        fwrite(STDERR, "e2e-support.php is a CLI script.\n");
        exit(1);
    }

    $repoRoot = dirname(__DIR__);

    $command = $argv[1] ?? '';

    switch ($command) {
        case 'free-port':
            echo e2e_free_port(), "\n";
            exit(0);

        case 'wait-http':
            $url = $argv[2] ?? '';
            $timeoutSeconds = (int) ($argv[3] ?? 30);
            if ($url === '' || $timeoutSeconds <= 0) {
                fwrite(STDERR, "Usage: e2e-support.php wait-http <url> <timeout-seconds>\n");
                exit(1);
            }
            exit(e2e_wait_http($url, $timeoutSeconds) ? 0 : 1);

        case 'provision':
            $instanceDir = $argv[2] ?? '';
            $port = (int) ($argv[3] ?? 0);
            if ($instanceDir === '' || $port <= 0) {
                fwrite(STDERR, "Usage: e2e-support.php provision <instance-dir> <port>\n");
                exit(1);
            }
            require_once $repoRoot . '/vendor/autoload.php';
            e2e_apply_application_clock();
            e2e_provision($repoRoot, $instanceDir, $port);
            exit(0);

        case 'run-scheduler':
            $instanceDir = $argv[2] ?? '';
            if ($instanceDir === '' || !is_file($instanceDir . '/public/cron.php')) {
                fwrite(STDERR, "Usage: e2e-support.php run-scheduler <instance-dir>\n");
                exit(1);
            }
            exit(e2e_run_scheduler($instanceDir));

        case 'teardown-db':
            require_once $repoRoot . '/vendor/autoload.php';
            e2e_apply_application_clock();
            e2e_teardown_database();
            exit(0);

        case 'merge-coverage':
            $coverageDir = $argv[2] ?? '';
            $outputPath = $argv[3] ?? '';
            if ($coverageDir === '' || $outputPath === '') {
                fwrite(STDERR, "Usage: e2e-support.php merge-coverage <fragment-dir> <clover-output>\n");
                exit(1);
            }
            require_once $repoRoot . '/vendor/autoload.php';
            exit(e2e_merge_coverage($repoRoot, $coverageDir, $outputPath) ? 0 : 1);

        default:
            fwrite(STDERR, "Unknown command: '{$command}'. See this file's header for the command list.\n");
            exit(1);
    }
}

/**
 * Ask the operating system for an unused local TCP port by binding port 0
 * and reading back whatever was assigned, then releasing it immediately.
 * There is an unavoidable window between releasing it here and the PHP
 * server binding it — scripts/e2e.sh handles a lost race by retrying with
 * a fresh port rather than by pre-reserving one, which no portable shell
 * can do anyway.
 */
function e2e_free_port(): int
{
    $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($socket === false) {
        fwrite(STDERR, "Cannot allocate a local TCP port: {$errstr} ({$errno}).\n");
        exit(1);
    }

    $name = (string) stream_socket_get_name($socket, false);
    fclose($socket);

    $port = (int) substr($name, (int) strrpos($name, ':') + 1);
    if ($port <= 0) {
        fwrite(STDERR, "Cannot determine the allocated local TCP port (got '{$name}').\n");
        exit(1);
    }

    return $port;
}

/**
 * The address the throwaway instance answers on, and the one it is told
 * about itself (`base_url`, in config/app.php and in secrets.enc alike).
 *
 * `localhost`, deliberately, rather than the 127.0.0.1 the server binds:
 * WebAuthn's Relying Party ID is a DOMAIN, and Chrome refuses an
 * IP-literal one outright ("This is an invalid domain") — so on an
 * instance that calls itself http://127.0.0.1:<port>, public/index.php
 * derives rpId = "127.0.0.1" (see its Core\Security\WebAuthnService
 * wiring) and no passkey can be registered or used AT ALL, in any
 * browser. `localhost` is a domain name for that purpose, is a secure
 * context exactly like 127.0.0.1, and resolves to the very same loopback
 * address, so nothing else about the run changes:
 *
 *   - it is still not a public host, so Core\Statistics\StatisticsSender
 *     still refuses to report from it (isPublicHost() names 'localhost'
 *     explicitly, next to the IP-literal rule that covered it before);
 *   - Core\Statistics\DestinationMatcher still sees the instance as its
 *     own statistics destination, since both values are this same string;
 *   - it still resolves to a private address on a non-standard port, so
 *     Modules\Gallery\Service\OgScraperService still refuses to fetch
 *     from it (SECURITY.md §17) — the documented degradation
 *     tests/e2e/specs/groups-discussion.spec.js relies on is unchanged.
 *
 * One function rather than the literal repeated at each call site, so the
 * server, the provisioning, the settings and Playwright's baseURL can
 * never disagree about what the instance is called.
 *
 * The HOST is `localhost` unless E2E_BASE_HOST says otherwise, and only
 * scripts/dast.sh sets it, only on Docker Desktop. There the browser
 * reaches the instance as `host.docker.internal`, because ZAP resolves
 * every request from inside its container where `localhost` is the
 * container itself. An instance still calling ITSELF `localhost` then
 * builds absolute links — a magic-link email, a password-reset link, a
 * registration tracking link, a passkey's Relying Party ID — pointing at
 * a host ZAP cannot reach, and those scenarios fail while the rest pass.
 *
 * The three properties above were re-checked against that name before
 * this was allowed, because the concern is real and dast.sh records it:
 *
 *   - isPublicHost() already answers false for it, and not by accident:
 *     `.internal` is in its NON_PUBLIC_TLDS list beside `.local` and
 *     `.test`. A scanning bench cannot start reporting outward.
 *   - DestinationMatcher still sees the instance as its own destination,
 *     because `statistics_destination` is written from THIS function too,
 *     so both sides move together.
 *   - WebAuthn is unaffected: an rpId must be a domain rather than an IP
 *     literal, and this is one.
 *
 * The one real difference is OgScraperService: on `localhost` it refuses
 * because the name resolves to a private address, and under this name it
 * refuses because the name does not resolve from the server at all. The
 * documented degradation groups-discussion.spec.js relies on is the same
 * refusal either way, for a different stated reason.
 *
 * The scheme is `http` unless E2E_BASE_SCHEME says otherwise. Only
 * scripts/dast.sh sets it, to `https`: the security scan puts a TLS
 * terminator in front of `php -S` (scripts/dast-tls-proxy.php) because
 * the `Secure` cookie flag and Strict-Transport-Security are
 * unobservable over cleartext, and the instance has to be told the URL it
 * really answers on or every absolute link it builds points at a port
 * nothing is listening on. Unset — `npm run e2e` and every existing
 * caller — the value is byte-identical to what it always was.
 */
function e2e_base_url(int $port): string
{
    $scheme = ((string) getenv('E2E_BASE_SCHEME')) ?: 'http';
    if ($scheme !== 'http' && $scheme !== 'https') {
        fwrite(STDERR, "E2E: E2E_BASE_SCHEME must be 'http' or 'https', got '{$scheme}'.\n");
        exit(1);
    }

    $host = ((string) getenv('E2E_BASE_HOST')) ?: 'localhost';

    return $scheme . '://' . $host . ':' . $port;
}

/**
 * Whether the provisioned instance should believe `X-Forwarded-Proto`
 * (Core\Http\RequestScheme's opt-in, written into the instance's
 * config/app.php). Off unless E2E_TRUST_FORWARDED_PROTO says otherwise,
 * so `npm run e2e` is unaffected.
 */
function e2e_trust_forwarded_proto(): bool
{
    return ((string) getenv('E2E_TRUST_FORWARDED_PROTO')) === '1';
}

/**
 * Poll until the URL answers or the deadline passes — never a fixed
 * sleep. 100 ms between attempts: fast enough that a server that is
 * already up costs nothing, slow enough not to spin.
 */
function e2e_wait_http(string $url, int $timeoutSeconds): bool
{
    $deadline = microtime(true) + $timeoutSeconds;

    $context = stream_context_create([
        'http' => ['timeout' => 5, 'ignore_errors' => true, 'method' => 'GET'],
    ]);

    while (microtime(true) < $deadline) {
        $stream = @fopen($url, 'r', false, $context);
        if ($stream !== false) {
            fclose($stream);

            return true;
        }
        // $http_response_header is populated by the HTTP wrapper even when
        // fopen() itself returns false on a 4xx/5xx — that still means the
        // server accepted the connection and answered, which is all this
        // probe is asking about.
        if (isset($http_response_header) && $http_response_header !== []) {
            return true;
        }
        usleep(100_000);
    }

    return false;
}

/**
 * @return array{host: string, port: int, name: string, user: string, password: string}
 */
function e2e_database_config(): array
{
    $name = (string) getenv('E2E_DB_NAME');
    if ($name === '') {
        fwrite(STDERR, "E2E_DB_NAME is not set — run this through scripts/e2e.sh.\n");
        exit(1);
    }

    return [
        'host' => ((string) getenv('E2E_DB_HOST')) ?: '127.0.0.1',
        'port' => (int) (getenv('E2E_DB_PORT') ?: '3306'),
        'name' => $name,
        'user' => ((string) getenv('E2E_DB_USER')) ?: 'root',
        'password' => (string) getenv('E2E_DB_PASSWORD'),
    ];
}

/**
 * Server-level PDO connection (no database selected) — used to create and
 * drop the E2E database itself.
 */
function e2e_server_pdo(): PDO
{
    $config = e2e_database_config();

    return new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $config['host'], $config['port']),
        $config['user'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

/**
 * Build the throwaway application instance and its database.
 *
 * The instance is a directory that looks exactly like a real ScoutMagic
 * install to public/index.php, but owns its own storage/ and config/:
 *
 *   - public/          real copy of the repository's public/ (index.php's
 *                      __DIR__ must resolve inside the instance, which a
 *                      symlink would not do — PHP resolves __DIR__ through
 *                      symlinks — and __DIR__ is what anchors secrets.enc,
 *                      master.key, and the uploads directory);
 *                      public/assets/ is symlinked rather than copied,
 *                      since only static files are served from it;
 *   - core/, modules/, schema/, vendor/, composer.json, VERSION, ...
 *                      symlinks to the repository's own, so the code under
 *                      test is the repository's code, unmodified;
 *   - config/app.php   generated, pointing base_url at the local server;
 *   - storage/         empty, freshly created.
 *
 * This is what keeps `npm run e2e` from touching a developer's own local
 * install: their storage/keys/master.key and storage/config/secrets.enc
 * are never read, written, or overwritten, and the E2E database is a
 * separate database from the one PHPUnit's `database` group uses.
 *
 * The database itself is provisioned the way Core\Http\Controller\
 * SetupController::handleFirstTimeSetup() provisions a real install —
 * same SecretManager, same Connection, same MigrationRunner over
 * schema/core.sql — rather than through a parallel test-only schema
 * loader that could drift from it. Migrating up front (instead of letting
 * the first request's auto-migration do it) is also what keeps the first
 * page load deterministic: index.php serves its migration-progress page,
 * not the requested route, while a migration is pending.
 */
function e2e_provision(string $repoRoot, string $instanceDir, int $port): void
{
    $config = e2e_database_config();

    // --- Database: create it if needed, then empty it completely. ---
    $serverPdo = e2e_server_pdo();
    $serverPdo->exec(sprintf(
        'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        e2e_quote_identifier($config['name'])
    ));
    e2e_drop_all_tables();

    // --- Instance tree. ---
    if (is_dir($instanceDir)) {
        e2e_remove_tree($instanceDir);
    }
    e2e_mkdir($instanceDir);

    // --- Compiled Twig templates: dropped, every run. ---
    //
    // Not housekeeping — without this the browser tests can assert against
    // TEMPLATES THAT ARE NO LONGER IN THE REPOSITORY. The instance runs
    // with debug off (see config/app.php below, and its own comment for
    // why), so Twig caches compiled templates and `auto_reload` is off;
    // Core\View\TwigFactory keys that cache on VERSION alone, deliberately
    // (a production install upgrades by version, and its own docblock says
    // in-place .twig edits are what debug mode is for). It also anchors the
    // cache to the REPOSITORY rather than to the instance — it resolves the
    // directory from its own file location, and the instance loads core/
    // from the repository through a symlink — so every run shares one
    // cache, and a .twig edited since the last run is compiled once and
    // then never again until VERSION changes.
    //
    // Found the way these things are found: a change to
    // partials/post_card.html.twig rendered nothing different in the
    // browser, and the scenario asserting the change went green against
    // the previous version of the file.
    //
    // Safe to remove outright: it is derived data with no source of truth
    // of its own, regenerated on the next request that needs a template,
    // which is why it is not treated the way the stray migration backups
    // in scripts/e2e.sh are (those could be a developer's real dumps).
    e2e_remove_tree($repoRoot . '/storage/temp/twig_cache');

    e2e_copy_public($repoRoot . '/public', $instanceDir . '/public');
    symlink($repoRoot . '/public/assets', $instanceDir . '/public/assets');

    // docs/ is part of a real install (scripts/release.sh does not exclude
    // it, and asserts docs/help/ is in the artifact): the contextual help
    // reads docs/help/*.md at runtime (Core\Help\HelpRegistry), so an
    // instance without it silently loses every core help topic — the help
    // button then renders as a bare /aide link on pages a topic covers.
    foreach (['core', 'modules', 'schema', 'vendor', 'docs', 'composer.json', 'VERSION'] as $entry) {
        symlink($repoRoot . '/' . $entry, $instanceDir . '/' . $entry);
    }

    foreach (['keys', 'config', 'core', 'modules', 'temp'] as $storageSubdir) {
        e2e_mkdir($instanceDir . '/storage/' . $storageSubdir);
    }

    e2e_mkdir($instanceDir . '/config');
    file_put_contents(
        $instanceDir . '/config/app.php',
        "<?php\n\n"
        . "// Generated by scripts/e2e-support.php for one end-to-end run. Not a\n"
        . "// template: debug is off deliberately, so the instance behaves like a\n"
        . "// production install (Twig cache on, no error output in responses).\n"
        . "return [\n"
        . "    'debug' => false,\n"
        . "    'site_name' => 'Unité de test E2E',\n"
        . "    'base_url' => '" . e2e_base_url($port) . "',\n"
        // Off for `npm run e2e`, which is served in cleartext and must
        // keep behaving exactly as it always has. On only for
        // scripts/dast.sh, whose instance sits behind
        // scripts/dast-tls-proxy.php: that terminator sets
        // X-Forwarded-Proto and strips any client-supplied copy, which is
        // precisely the deployment shape SECURITY.md § 9 says the opt-in
        // is for. Without it the scan would see a site emitting neither
        // Secure cookies nor HSTS and would be right to say so.
        . "    'trust_forwarded_proto' => " . (e2e_trust_forwarded_proto() ? 'true' : 'false') . ",\n"
        . "];\n"
    );

    // --- Secrets: exactly the set SetupController writes at first install,
    // admin_email included. The address is admin@example.invalid — the
    // .invalid TLD is reserved by RFC 6761 precisely so it can never be a
    // real mailbox, so no personal data enters this throwaway fixture.
    // The matching super-admin account is created further down, the same
    // way the setup wizard creates it; scenarios that need an
    // authenticated admin (tests/e2e/specs/scout-year-transition.spec.js)
    // log in through the real login form with these credentials.
    $secretManager = new Core\Security\SecretManager(
        $instanceDir . '/storage/keys/master.key',
        $instanceDir . '/storage/config/secrets.enc'
    );
    $secretManager->generateMasterKey();

    $vapidKeys = Core\Notification\VapidKeyPairFactory::createValid();

    $adminEmail = e2e_admin_email();
    $encryptionKey = base64_encode(random_bytes(32));
    $blindIndexKey = base64_encode(random_bytes(32));

    $secretManager->writeSecrets([
        'db_host' => $config['host'],
        'db_port' => $config['port'],
        'db_name' => $config['name'],
        'db_user' => $config['user'],
        'db_password' => $config['password'],
        // 'local', not 'smtp': Core\Mail\MailService hands a local-mode
        // message to PHP's own mail(), which scripts/e2e.sh points at
        // scripts/e2e-maildrop.php through sendmail_path — so every mail
        // the run produces lands, whole, in a directory a scenario can
        // read (tests/e2e/support/maildrop.js). The previous 'smtp' to
        // localhost:25 had nothing listening: every send raised a
        // MailException, which is why no scenario could go through a
        // magic link, a password reset or a confirmation email. Nothing
        // is stubbed here — MailService, PHPMailer, the DKIM signing and
        // the templates all run exactly as they do in production; only
        // the transport's last hop is a file instead of a socket.
        'mail_mode' => 'local',
        'encryption_key' => $encryptionKey,
        'blind_index_key' => $blindIndexKey,
        // public/index.php self-heals a missing super-admin from this key
        // on every request (deleting and recreating the row, password and
        // all). Setting it means that production path runs for real in
        // every E2E scenario, and stays a no-op only as long as the
        // account below genuinely resolves through the same keys.
        'admin_email' => $adminEmail,
        'vapid_public_key' => $vapidKeys['publicKey'],
        'vapid_private_key' => $vapidKeys['privateKey'],
        'site_name' => 'Unité de test E2E',
        'short_name' => 'E2E',
        'base_url' => e2e_base_url($port),
        'mail_from_address' => 'e2e@example.invalid',
        'mail_from_name' => 'Unité de test E2E',
        'dkim_selector' => 's2026',
        'dmarc_report_email' => 'e2e@example.invalid',
    ]);

    // --- Schema, through the application's own migration runner. ---
    //
    // The WHOLE declared schema — core plus every module's — exactly as a
    // deploy does it (Core\Database\SchemaFiles). Provisioning used to
    // migrate core.sql only and let each module's tables appear when the
    // harness activated it; activation no longer runs any DDL, so this is
    // now the only thing that creates them.
    //
    // MigrationRunner canonicalises the paths it is keyed on, so this no
    // longer has to be spelled the way public/index.php spells it. It used
    // to: index.php builds `__DIR__ . '/../schema/core.sql'` while this
    // handed over `$repoRoot . '/schema/core.sql'`, the flag landed under
    // a different key than the one index.php looked up, and the very first
    // browser request found a migration pending, served the
    // migration-progress page instead of the requested route, and the test
    // raced that page's own JavaScript to a reload. realpath() here is now
    // for the symlink case alone (mktemp -d returns a path under a
    // symlinked /var on macOS).
    $instanceRoot = realpath($instanceDir);
    if ($instanceRoot === false || !is_dir($instanceRoot . '/public')) {
        fwrite(STDERR, "E2E provisioning failed: instance public/ directory not found.\n");
        exit(1);
    }
    $schemaFiles = Core\Database\SchemaFiles::all($instanceRoot);

    $connection = new Core\Database\Connection(
        $config['host'],
        $config['port'],
        $config['name'],
        $config['user'],
        $config['password']
    );

    $migrationRunner = static fn(): Core\Database\MigrationRunner => new Core\Database\MigrationRunner(
        $connection,
        new Core\Database\SchemaIntrospector($connection->getPdo()),
        new Core\Database\SchemaComparator(),
        new Core\Database\SqlParser()
    );

    // Driven to completion, not asked once.
    //
    // migrate() works to a time budget (MigrationRunner's
    // $timeBudgetSeconds, 20 by default) and returns complete: false when
    // it runs out — WITHOUT saving the schema hash, so the next call
    // resumes from the checkpoint instead of restarting. That is a normal,
    // resumable outcome by design, and it is how the migration-progress
    // page drives the same work: public/index.php calls migrate() once per
    // request and its script polls until `complete` comes back true.
    //
    // This caller used to ask exactly once and treat that outcome as a
    // fatal error, which made provisioning fail for the one reason the
    // budget exists to cause. It cost 3 failures in 72 samples of the DAST
    // job — by the last batch the ONLY remaining cause, 2 of 20 — because
    // that job runs MySQL, ZAP, a TLS terminator, PHP and Chromium on one
    // runner, and a first slice that fits in 20 s on an idle machine does
    // not always fit there. Nothing was wrong with the schema; the work
    // was simply not finished being asked for.
    //
    // The cap is on total wall clock rather than on a number of calls: a
    // slice that keeps making progress deserves another turn, and one that
    // does not is caught by the isPending() check below regardless. 300 s
    // is far beyond any real schema here (a full first migration runs in
    // seconds) and still bounded, so a genuinely stuck migration fails the
    // run instead of hanging it.
    $migrationDeadline = microtime(true) + 300.0;
    $slices = 0;
    do {
        $result = $migrationRunner()->migrate($schemaFiles);
        $slices++;
    } while (!$result->complete && microtime(true) < $migrationDeadline);

    if (!$result->complete) {
        fwrite(
            STDERR,
            "E2E provisioning failed: schema migration did not complete after {$slices} slice(s) "
            . "and 300 s. This is no longer a time-budget slice running out — that is resumed above — "
            . "so treat it as a migration that cannot finish.\n"
        );
        exit(1);
    }

    // Fail closed on the race described above rather than let the browser
    // test discover it as an intermittent "wrong page" failure: after
    // provisioning, the application must see no pending migration for the
    // exact path it will use.
    if ($migrationRunner()->isPending($schemaFiles)) {
        fwrite(
            STDERR,
            'E2E provisioning failed: a schema migration is still pending for ' . count($schemaFiles)
            . " schema file(s) — the first request would serve the migration-progress page instead "
            . "of the application.\n"
        );
        exit(1);
    }

    // --- Super-admin account, created the way the setup wizard creates it
    // (Core\Http\Controller\SetupController::createAdminAccount()): the
    // same EncryptionService, the same blind index, the same
    // password_hash() call, the same is_super_admin flag. A super-admin
    // resolves to the 'superadmin' role and is authorised to log in
    // without any matching member row (Core\Security\RoleResolver), which
    // is what lets a scenario reach `role_min: admin` pages on an
    // otherwise empty install.
    e2e_create_super_admin($connection, $encryptionKey, $blindIndexKey, $adminEmail, e2e_admin_password());

    // --- The one member fixture the suite needs, and no more. ---
    //
    // A super-admin alone cannot use the groups module at all: a
    // discussion group is written and read as a MEMBER, never as an
    // account (Modules\Groups\Service\GroupSessionContext::
    // $linkedMemberIds), and posting additionally requires the account to
    // carry a first and last name (GroupAccessService::canPost()'s
    // REASON_INCOMPLETE_PROFILE). Both are provisioned here, once, so the
    // scenario that drives the composer spends its time on the composer
    // rather than on re-importing a Desk export to obtain an identity.
    e2e_seed_member_for_admin($connection, $encryptionKey, $blindIndexKey, $adminEmail);

    // --- A second person, so the suite can cover what one person cannot.
    //
    // Three of this module's behaviours only exist between two members and
    // are unreachable with a single one: a comment being NEW to somebody
    // (your own never is), reporting (never offered on your own message),
    // and a moderator restoring what a report hid. An ordinary member —
    // no super-admin flag, no function, so Core\Security\RoleResolver
    // resolves them to `identified` — which is also the role most of a
    // unit actually has.
    e2e_seed_ordinary_member($connection, $encryptionKey, $blindIndexKey);

    // --- One section, with both of them in it. ---
    //
    // A section group is how most real groups come to exist (a scheduled
    // task creates one per section per year — Modules\Groups\Task\
    // EnsureSectionGroupsHandler), and its membership is DERIVED: resolved
    // per request from member_section_periods rather than materialised as
    // rows. That is the path worth exercising, and it is the only one that
    // puts two people in a group without a scenario having to invite one
    // through the interface first.
    e2e_seed_section_with_both_members($connection);

    // --- The super-admin is also the unit's chef d'unité. ---
    //
    // Several behaviours gate on a UNIT-CHIEF MEMBERSHIP, not on the
    // superadmin flag: Modules\Retro's moderation (hide/unhide a word) is
    // offered only to somebody whose MAIN function sits in the "Staff
    // d'U" section (MemberService::isUnitChief() — deliberately narrower
    // than Role::ADMIN, see RetroBoardController). In a real unit the
    // site's super-admin usually IS the chef d'unité, so the fixture
    // mirrors that instead of leaving those behaviours unreachable.
    e2e_seed_unit_chief_function_for_admin($connection);
    e2e_seed_mobile_for_admin($connection, $encryptionKey, $blindIndexKey);

    // --- One account per remaining rung of the role ladder. ---
    //
    // `identified` and `superadmin` are covered by the two above;
    // `intendant`, `chief` and `admin` are not, and a dynamic
    // authorization scan that cannot log in as a role proves nothing
    // about that role (scripts/dast.sh). They live in a hidden section of
    // their own so no existing scenario's fixture shape moves — see
    // e2e_seed_role_members() for the full reasoning, including why the
    // Staff d'U sweep leaves the admin's function where it is put.
    e2e_seed_role_members($connection, $encryptionKey, $blindIndexKey);

    // Fail closed if any of the five does not actually resolve to the
    // role it is supposed to carry: a fixture that silently degrades to
    // `identified` would produce a clean-looking, worthless matrix.
    e2e_assert_resolved_roles($connection, $encryptionKey, $blindIndexKey, $adminEmail);

    // --- Every module, activated the way an admin activates one. ---
    //
    // The throwaway instance is pointed at ITSELF as the statistics
    // destination, which is what makes it the receiver
    // (Core\Statistics\DestinationMatcher, ARCHITECTURE.md §8.49) and so
    // what makes the receiver-only support_dashboard module visible at
    // all — ModuleManager hides it everywhere else, and a module nobody
    // can see is a module whose wiring no run ever boots, which is the one
    // blind spot this harness exists to close. It costs nothing else:
    // Core\Statistics\StatisticsSender refuses to send to itself
    // ('self_destination'), and `localhost` is not a public host either
    // (isPublicHost() names it), so no run ever emits a report.
    //
    // Registered rather than updated because the row does not exist yet
    // (public/index.php registers it at boot, later than this): the insert
    // carries the value, and index.php's own register() call then only
    // refreshes default_value, leaving this one's value alone.
    $settingService = new Core\Config\SettingService(new Core\Config\SettingRepository($connection->getPdo()));
    $settingService->register(
        'statistics_destination',
        e2e_base_url($port),
        'url',
        'Destination des statistiques',
        "Adresse du site qui reçoit les rapports d'utilisation. Pointée sur cette instance elle-même "
        . "par le harnais E2E, pour que le module réservé au récepteur soit lui aussi câblé et testé.",
        null,
        null,
        null,
        true,
        281
    );

    e2e_warn_if_near_scout_year_boundary();

    // Pin the public scout year, so a run cannot be moved by the calendar
    // underneath it.
    //
    // Core\Config\ScoutYearService::labelForDate() starts a new scout year
    // the moment the month reaches September, and Core\Config\AppClock puts
    // the application on Europe/Brussels — so the year every fixture below
    // is seeded into changes at 00:00 Brussels time on 1 September. With
    // neither year setting configured, Core\ScoutYear\ScoutYearResolver
    // falls through to that date computation on every request, which means
    // a suite that starts on 31 August and finishes on 1 September seeds
    // its members, sections, on-call assignments, registrations and
    // bookings into 2025-2026 and then asks for them in 2026-2027 —
    // ensureYear() obligingly creates the new year, empty, and every
    // scout-year-scoped fixture simply is not there any more.
    //
    // Observed rather than imagined, 2026-08-31: CI was green at 20:43 and
    // 20:54 UTC, the 21:32 run lost the three specs that ran past 22:00
    // UTC (= midnight in Brussels), and a 20-sample probe started at 21:50
    // lost twelve specs each, all of them after the boundary — « element(s)
    // not found » on rows that had been seeded an hour earlier. Passing and
    // failing specs interleaved, so nothing was wedged and nothing was
    // slow: the ground had moved.
    //
    // Registered rather than set, for the same reason as
    // statistics_destination above: public/index.php registers this key at
    // boot, later than this, so the insert here carries the value and
    // index.php's own register() then only refreshes default_value.
    //
    // specs/scout-year-transition.spec.js still moves this year forward on
    // purpose, through the real admin page (Core\ScoutYear\
    // ScoutYearAdminService writes the same setting) — pinning it here is
    // the STARTING state that scenario expects, not a lock on it.
    $settingService->register(
        Core\ScoutYear\ScoutYearResolver::SETTING_PUBLIC_YEAR,
        (string) (new Core\Config\ScoutYearService($connection->getPdo()))->getCurrentYear()['id'],
        'number',
        'Année scoute publique (ID)',
        'Identifiant de l\'année scoute vue par tout le monde. Épinglée par le harnais E2E sur '
        . "l'année dans laquelle les fixtures sont semées, pour qu'un passage de minuit le 1er "
        . 'septembre ne la déplace pas en cours de route.',
        null,
        '^[0-9]+$',
        null,
        false,
        210
    );

    $activated = e2e_activate_all_modules(
        $repoRoot,
        $connection,
        $settingService,
        e2e_base_url($port)
    );

    echo "E2E instance provisioned at {$instanceDir} (database '{$config['name']}', port {$port}).\n";
    echo 'E2E: ' . count($activated) . ' modules activated: ' . implode(', ', $activated) . ".\n";
}

/**
 * Activate every module the repository ships, through the application's
 * own Core\Module\ModuleManager::activate() — the same call the admin
 * modules page makes, so each module's schema.sql, default settings,
 * registry row and journal entry all land exactly as they do on a real
 * install.
 *
 * Why all of them rather than the three `enabled_by_default` ones: the
 * E2E harness exists to answer "does the application boot and serve a
 * page", and public/index.php wires EVERY enabled module into the front
 * controller before it routes anything. A module left disabled here is a
 * block of that wiring no browser test ever executes — which is not
 * theoretical: a controller constructor whose type hint named a class
 * that did not exist took every route on the live site to HTTP 500, and
 * the E2E suite stayed green because the module concerned was not one of
 * the three (see Tests\Core\System\TypeHintResolutionTest, the unit-level
 * twin of this).
 *
 * "Every module" means every module this installation can SEE — which is
 * every directory under modules/, receiver-only ones included, because
 * e2e_provision() makes the instance the statistics receiver precisely so
 * that ModuleManager stops hiding them (see its own comment there).
 *
 * Fails closed: a module that cannot be activated aborts provisioning
 * with its own message, rather than quietly leaving the run with less
 * coverage than it claims.
 *
 * @return string[] the module ids activated, in the order they were
 */
function e2e_activate_all_modules(
    string $repoRoot,
    Core\Database\Connection $connection,
    Core\Config\SettingService $settingService,
    string $baseUrl
): array {
    $pdo = $connection->getPdo();
    $modulesDir = $repoRoot . '/modules';

    // The same question public/index.php asks on every request, through
    // the same resolver, rather than a hand-built profile: what the
    // application will decide on the first request is what decides the set
    // of modules discovered here. $baseUrl is passed in rather than read
    // from the settings table because index.php only copies it there out
    // of secrets.enc on that first request — later than this.
    $installationProfile = Core\Module\InstallationProfile::resolve(
        $baseUrl,
        (string) ($settingService->get('statistics_destination') ?? '')
    );

    $moduleManager = new Core\Module\ModuleManager(
        $modulesDir,
        $settingService,
        new Core\Cookie\CookieConsentService(),
        new Core\View\MenuBuilder(Core\Security\Role::SUPERADMIN),
        new Core\Module\ModuleRegistryRepository($pdo),
        new Core\Journal\JournalService(new Core\Journal\JournalRepository($pdo)),
        new Core\Http\Router(),
        null,
        new Core\Offline\OfflineWhitelist(),
        $installationProfile
    );

    // discoverModules() returns a LIST (sorted by the admin's own module
    // order), so the id comes from each manifest, never from the key.
    $requirements = [];
    foreach ($moduleManager->discoverModules() as $module) {
        if (!$module->presentOnDisk) {
            continue;
        }

        $moduleId = $module->manifest->id;

        if ($module->validationError !== null) {
            fwrite(
                STDERR,
                "E2E provisioning failed: module '{$moduleId}' has an invalid module.json — "
                    . "{$module->validationError}\n"
            );
            exit(1);
        }

        $requirements[$moduleId] = $module->manifest->requires;
    }

    $order = e2e_module_activation_order($requirements);
    if ($order === null) {
        fwrite(
            STDERR,
            "E2E provisioning failed: the modules' `requires` declarations do not form an installable order "
            . "(a cycle, or a module requiring one that is not on disk).\n"
        );
        exit(1);
    }

    foreach ($order as $moduleId) {
        try {
            // null activator: a system-initiated activation, exactly like
            // the enabled_by_default auto-activation index.php performs.
            $moduleManager->activate($moduleId, null);
        } catch (Throwable $e) {
            fwrite(
                STDERR,
                "E2E provisioning failed: module '{$moduleId}' could not be activated — {$e->getMessage()}\n"
            );
            exit(1);
        }
    }

    return $order;
}

/**
 * The order modules have to be activated in for every module's hard
 * dependencies to already be enabled when its turn comes —
 * Core\Module\ModuleManager::activate() refuses a module whose
 * requirements are unmet, so "all of them, alphabetically" is not good
 * enough (groups requires gallery).
 *
 * Alphabetical among the modules that are ready at each step, so the same
 * repository always produces the same order and a failure is reproducible.
 * Returns null when no order exists: a dependency cycle, or a module
 * requiring one that is not on disk.
 *
 * Pure, and tested as such — see tests/Core/System/E2eActivationOrderTest.php.
 *
 * @param array<string, string[]> $requirements module id => required module ids
 * @return string[]|null
 */
function e2e_module_activation_order(array $requirements): ?array
{
    $pending = $requirements;
    $order = [];

    while ($pending !== []) {
        $ready = [];
        foreach ($pending as $moduleId => $requires) {
            $unmet = array_filter($requires, static fn(string $required): bool => !in_array($required, $order, true));
            if ($unmet === []) {
                $ready[] = $moduleId;
            }
        }

        if ($ready === []) {
            return null;
        }

        sort($ready);
        foreach ($ready as $moduleId) {
            $order[] = $moduleId;
            unset($pending[$moduleId]);
        }
    }

    return $order;
}

/**
 * The E2E super-admin's address. Fixed rather than configurable: the
 * scenarios reference it only through E2E_ADMIN_EMAIL, and .invalid is
 * reserved by RFC 6761 so this can never reach a real mailbox.
 */
function e2e_admin_email(): string
{
    return ((string) getenv('E2E_ADMIN_EMAIL')) ?: 'admin@example.invalid';
}

/**
 * The E2E super-admin's password, generated fresh for every run by
 * scripts/e2e.sh and passed down through the environment — never a
 * literal in the repository, and never reused between runs (the database
 * holding its hash is dropped at teardown either way).
 */
function e2e_admin_password(): string
{
    $password = (string) getenv('E2E_ADMIN_PASSWORD');
    if ($password === '') {
        fwrite(STDERR, "E2E_ADMIN_PASSWORD is not set — run this through scripts/e2e.sh.\n");
        exit(1);
    }

    return $password;
}

/**
 * Mirrors Core\Http\Controller\SetupController::createAdminAccount() —
 * deliberately the same four lines rather than a call into it, because
 * that method is private to a controller whose constructor wants Twig, a
 * SecretManager and a DkimManager none of which exist here. Any drift in
 * how an account is stored would be caught immediately: the login in
 * tests/e2e/specs/scout-year-transition.spec.js goes through the real
 * Core\Security\AuthService, which reads it back.
 */
function e2e_create_super_admin(
    Core\Database\Connection $connection,
    string $encodedEncryptionKey,
    string $encodedBlindIndexKey,
    string $email,
    string $password
): void {
    // The keys arrive as the base64 strings written into secrets.enc, and
    // the application decodes them at boot (EncryptionService::
    // fromEncodedKeys, audit M1) — so this seeder must decode them the
    // same way. Passing the base64 strings to the raw-bytes constructor
    // derives a DIFFERENT key: the seeded row's blind index never matches
    // a login lookup, public/index.php's admin self-heal then replaces the
    // row without a password, and the E2E admin login fails.
    $encryptionService = Core\Security\EncryptionService::fromEncodedKeys($encodedEncryptionKey, $encodedBlindIndexKey);
    $normalizedEmail = strtolower(trim($email));

    $statement = $connection->getPdo()->prepare(
        'INSERT INTO user_accounts (email_encrypted, email_blind_index, password_hash, is_super_admin)'
        . ' VALUES (?, ?, ?, TRUE)'
    );
    $statement->execute([
        $encryptionService->encrypt($normalizedEmail, 'user_accounts.email'),
        $encryptionService->blindIndex($normalizedEmail, 'email'),
        password_hash($password, PASSWORD_DEFAULT),
    ]);
}

/**
 * Put this script on the same clock as the application it provisions.
 *
 * public/index.php calls Core\Config\AppClock::apply() as its first act
 * after the autoloader, which pins the process to Europe/Brussels. This
 * script never did, so it ran on PHP's default timezone — UTC on a CI
 * runner and on a stock macOS PHP alike. For twenty-two hours a day that
 * difference is invisible, because both clocks agree on the DATE.
 *
 * Between 22:00 and 24:00 UTC they do not, and once a year that
 * disagreement lands on the only date boundary this application treats as
 * a hard edge: a scout year starts on 1 September
 * (Core\Config\ScoutYearService::labelForDate()). Measured on this very
 * boundary, 2026-08-31 22:48 UTC:
 *
 *     provisioning saw : 2025-2026   (UTC, 2026-08-31)
 *     the served app saw: 2026-2027   (Europe/Brussels, 2026-09-01)
 *
 * So the harness seeded every member, function, section period and
 * booking into one scout year and the application then looked for them in
 * another. Seven specs failed at once, all of them member lookups coming
 * back empty — grantManagerBySearch() finding nobody to grant an asset
 * to, the mass-mail audience unable to see kaa@example.invalid,
 * /config/banner answering 403 to a super-admin whose own membership had
 * become invisible. Fast, hard failures on a suite that had been green
 * two hours earlier, and not one of them pointing at a clock.
 *
 * Applying the same clock is the fix, not a workaround: a harness that
 * seeds data for an application has no business disagreeing with it about
 * what day it is. The scout-year pin in e2e_provision() is the belt to
 * this one's braces — this makes the two agree at the start, that keeps
 * them agreeing if the run crosses midnight.
 */
function e2e_apply_application_clock(): void
{
    Core\Config\AppClock::apply();
}

/**
 * A fixture start date that lies inside the scout year the row belongs to.
 *
 * Every membership fixture below used to start « a month ago » outright.
 * For eleven months of the year that is inside the current scout year and
 * nobody notices. In September it is not: a scout year begins on the 1st
 * (Core\Config\ScoutYearService::labelForDate()), so on 1 September « a
 * month ago » is 1 August — the PREVIOUS year — while the row's own
 * scout_year_id points at the new one. The fixture then asserts something
 * incoherent: a membership that started before the year it belongs to.
 *
 * This is a coherence fix, and it is worth being precise about what it is
 * NOT: it was written while chasing the 2026-09-01 outage and it did not
 * fix it — re-running the seven failing specs with only this change left
 * all seven red. The cause was the clock (see
 * e2e_apply_application_clock() above). Keeping it anyway, because a
 * membership dated before the year it belongs to is wrong whatever else
 * is true, and a fixture that states something impossible is a bad
 * foundation for a test that asserts on it.
 *
 * Clamping to the year's own start_date keeps « a month ago » for the
 * other eleven months and says « the day the year opened » in September.
 *
 * @param array{id: int, label: string, start_date: string, end_date: string} $scoutYear
 */
function e2e_fixture_start_date(array $scoutYear): string
{
    // Both are Y-m-d, so a string comparison is a date comparison.
    return max(date('Y-m-d', strtotime('-1 month')), $scoutYear['start_date']);
}

/**
 * Warn when this run is about to cross the scout-year boundary.
 *
 * A scout year turns over the instant the month reaches September
 * (Core\Config\ScoutYearService::labelForDate()), on Belgian wall-clock
 * time (Core\Config\AppClock). Fixtures are seeded once, at provisioning,
 * into whatever year is current then — so a run that starts on 31 August
 * and finishes on 1 September asks for them in a year that did not exist
 * when they were written, and ensureYear() hands out a new empty one.
 *
 * The pinned `current_scout_year_id` above holds the line for everything
 * that resolves through Core\ScoutYear\ScoutYearResolver — login and role
 * resolution above all, which is what makes a member visible at all. It
 * cannot hold it for the call sites that ask
 * Core\Config\ScoutYearService::getCurrentYear() directly, and there are
 * around twenty of them across core and the modules.
 *
 * Hence a warning rather than a promise. What it buys is the hour this
 * cost the first time: without it the symptom is a dozen specs failing on
 * « element(s) not found » for rows seeded minutes earlier, with passing
 * specs interleaved so nothing looks wedged, on a commit that was green
 * an hour before — every signal pointing at a flake, and none at the
 * calendar.
 */
function e2e_warn_if_near_scout_year_boundary(?DateTimeImmutable $now = null): bool
{
    $now ??= new DateTimeImmutable('now', new DateTimeZone(Core\Config\AppClock::TIMEZONE));
    $now = $now->setTimezone(new DateTimeZone(Core\Config\AppClock::TIMEZONE));
    $year = (int) $now->format('n') >= 9 ? (int) $now->format('Y') + 1 : (int) $now->format('Y');
    $boundary = new DateTimeImmutable(sprintf('%d-09-01 00:00:00', $year), $now->getTimezone());

    $minutesAway = (int) round(($boundary->getTimestamp() - $now->getTimestamp()) / 60);
    if ($minutesAway > 60) {
        return false;
    }

    fwrite(STDERR, sprintf(
        "E2E WARNING: the scout year turns over in %d minute(s) (%s, %s).\n"
        . "            Fixtures are seeded into %s and a run crossing that instant will ask for\n"
        . "            them in the next year. Expect « element(s) not found » on scout-year-scoped\n"
        . "            rows, on specs that were green an hour ago. This is the calendar, not a flake.\n"
        . "            Re-run after %s for a result worth reading.\n",
        max($minutesAway, 0),
        $boundary->format('Y-m-d H:i'),
        Core\Config\AppClock::TIMEZONE,
        Core\Config\ScoutYearService::labelForDate($now),
        $boundary->format('H:i')
    ));

    return true;
}

/**
 * Give the throwaway super-admin a scout identity: a members row, a
 * member_years row for the current scout year carrying the SAME email
 * address as the account, and a first/last name on the account itself.
 *
 * Why this exists at all — the two things it unlocks, both of which are
 * real production rules and not test conveniences:
 *
 * - Core\Member\MemberService::getLinkedMembers() matches an account to
 *   its members by the blind index of the email address, for one scout
 *   year. Without a matching member_years row every module that acts "as
 *   a member" (groups first among them) refuses every write with
 *   "Aucun membre de ce groupe n'est associé à votre compte", admin or
 *   not — that bypass does not exist, on purpose.
 * - Modules\Groups\Service\GroupAccessService::canPost() additionally
 *   requires the account's own first and last name, because they
 *   accompany every message; an account without them is offered no
 *   composer at all.
 *
 * The names are invented and the address is the same @example.invalid
 * one the account already uses (RFC 6761 — never a real mailbox), so no
 * personal data enters the fixture. Everything lands in the throwaway
 * database that teardown drops.
 *
 * The row is written for the CURRENT scout year — the year
 * Core\ScoutYear\ScoutYearResolver resolves on a freshly provisioned
 * instance, since neither year setting is configured yet. A scenario that
 * moves the public year forward (specs/scout-year-transition.spec.js
 * does, deliberately) leaves this member behind in the previous one; that
 * is why the groups scenario runs before it, which Playwright's
 * alphabetical file ordering already guarantees.
 */
function e2e_seed_member_for_admin(
    Core\Database\Connection $connection,
    string $encodedEncryptionKey,
    string $encodedBlindIndexKey,
    string $email
): void {
    $pdo = $connection->getPdo();
    $encryptionService = Core\Security\EncryptionService::fromEncodedKeys($encodedEncryptionKey, $encodedBlindIndexKey);
    $normalizedEmail = strtolower(trim($email));
    $blindIndex = $encryptionService->blindIndex($normalizedEmail, 'email');

    $scoutYear = (new Core\Config\ScoutYearService($pdo))->getCurrentYear();
    $scoutYearId = $scoutYear['id'];

    $pdo->prepare('INSERT INTO members (desk_id) VALUES (?)')->execute(['E2E-ADMIN']);
    $memberId = (int) $pdo->lastInsertId();

    // is_active = 1 is not decoration: findAllByEmail() filters on it, so
    // an inactive row would link to nothing at all.
    $statement = $pdo->prepare(
        'INSERT INTO member_years'
        . ' (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, email_blind_index, '
        . 'is_active)'
        . ' VALUES (?, ?, ?, ?, ?, ?, 1)'
    );
    $statement->execute([
        $memberId,
        $scoutYearId,
        $encryptionService->encrypt('Baden', 'member_years.first_name'),
        $encryptionService->encrypt('Powell', 'member_years.last_name'),
        $encryptionService->encrypt($normalizedEmail, 'member_years.email'),
        $blindIndex,
    ]);

    // Through the repository rather than by hand: the account's names are
    // encrypted with their own contexts, and this is the one call site
    // that already knows which (Core\Security\UserAccountRepository::
    // updateProfile(), the same method the "Mon compte" page calls).
    $userAccountRepository = new Core\Security\UserAccountRepository($pdo, $encryptionService);
    $account = $userAccountRepository->findByEmail($normalizedEmail);
    if ($account === null) {
        fwrite(STDERR, "E2E provisioning failed: the super-admin account just created cannot be found back.\n");
        exit(1);
    }
    $userAccountRepository->updateProfile($account->id, 'Baden', 'Powell');
}

/**
 * A second, ordinary member with a password account of their own —
 * everything e2e_seed_member_for_admin() provisions for the super-admin,
 * minus the super-admin flag.
 *
 * Their address and names are invented and land in the same
 * @example.invalid domain (RFC 6761 — never a real mailbox); their
 * password is generated per run like the admin's and exported by
 * scripts/e2e.sh, so nothing password-shaped is ever committed.
 *
 * They are deliberately NOT a member of any group: a scenario that wants
 * them in one invites them through the real members page, which is the
 * only way somebody joins a group in this module (there is no directory,
 * no self-join and no join request).
 */
function e2e_seed_ordinary_member(
    Core\Database\Connection $connection,
    string $encodedEncryptionKey,
    string $encodedBlindIndexKey
): void {
    $pdo = $connection->getPdo();
    $encryptionService = Core\Security\EncryptionService::fromEncodedKeys($encodedEncryptionKey, $encodedBlindIndexKey);
    $email = e2e_member_email();
    $blindIndex = $encryptionService->blindIndex($email, 'email');
    $scoutYear = (new Core\Config\ScoutYearService($pdo))->getCurrentYear();
    $scoutYearId = $scoutYear['id'];

    $pdo->prepare('INSERT INTO members (desk_id) VALUES (?)')->execute(['E2E-MEMBER']);
    $memberId = (int) $pdo->lastInsertId();

    $statement = $pdo->prepare(
        'INSERT INTO member_years'
        . ' (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, email_blind_index, '
        . 'is_active)'
        . ' VALUES (?, ?, ?, ?, ?, ?, 1)'
    );
    $statement->execute([
        $memberId,
        $scoutYearId,
        $encryptionService->encrypt('Kaa', 'member_years.first_name'),
        $encryptionService->encrypt('Serpent', 'member_years.last_name'),
        $encryptionService->encrypt($email, 'member_years.email'),
        $blindIndex,
    ]);

    $userAccountRepository = new Core\Security\UserAccountRepository($pdo, $encryptionService);
    $account = $userAccountRepository->create($email, false);
    $userAccountRepository->updatePasswordHash($account->id, password_hash(e2e_member_password(), PASSWORD_DEFAULT));
    $userAccountRepository->updateProfile($account->id, 'Kaa', 'Serpent');
}

/**
 * One section, and a membership period in it for both seeded members, for
 * the current scout year.
 *
 * A period, not a `member_functions` row: Core\Member\
 * SectionMembershipRepository::hasAnyPeriod() is what
 * Modules\Groups\Service\GroupAccessService resolves derived membership
 * from, and a period is also what a real Desk import writes
 * (Core\Import\DeskImportService). Left open (end_date NULL), exactly as
 * an import leaves a member who is still in the section.
 *
 * Both also get a `member_functions` row, for a function whose `role` is
 * `identified` — the level Core\Security\RoleResolver already resolves
 * them to, so neither gains anything by it and "an ordinary member sees
 * this" never quietly becomes "an animator sees this". It is there
 * because a real Desk import always writes one, and because two features
 * read the section through functions rather than through periods:
 * Core\Member\SectionService::getSectionAnimes()/getSectionStaff(), which
 * is what Modules\Groups\Controller\GroupMemberController offers a
 * moderator as the people they may invite. Without it that list is empty
 * and no scenario can invite anybody
 * (tests/e2e/specs/groups-management.spec.js).
 *
 * Never a chief's, an admin's or an intendant's function: those are
 * exactly the roles getSectionStaff() selects on, and any of them would
 * also move the member's resolved role.
 *
 * The branch's `sort_order` is 10, not an arbitrary number: sort_order is
 * how this codebase recognises a branch as one of the federation's four
 * animés branches at all (Core\Member\MemberYearService::
 * branchForSortOrder(), `intdiv(sort_order, 10) - 1`). It used to be 1,
 * which resolves to NO branch — and a fixture with no recognised branch
 * silently emptied every screen built on one: the registration module's
 * capacity grid, its capacity-verification table and the public
 * "nés en…" availability grid all render zero rows against it, so no
 * browser ever exercised them. 10 makes it the first branch (Baladins,
 * 6–7 ans). Nothing else moves: both seeded members have no birth date,
 * so no age-derived count changes, and 10 still sorts ahead of the
 * hidden roles branch at 90.
 */
function e2e_seed_section_with_both_members(Core\Database\Connection $connection): void
{
    $pdo = $connection->getPdo();
    $scoutYear = (new Core\Config\ScoutYearService($pdo))->getCurrentYear();
    $scoutYearId = $scoutYear['id'];

    $pdo->prepare('INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, 10)')
        ->execute(['E2E-BR', 'Branche E2E']);
    $ageBranchId = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO sections (age_branch_id, desk_code, name, is_visible, is_active) VALUES (?, ?, ?, 1, 1)')
        ->execute([$ageBranchId, 'E2E-SEC', 'Meute E2E']);
    $sectionId = (int) $pdo->lastInsertId();

    $statement = $pdo->prepare(
        'INSERT INTO member_section_periods (member_id, section_id, scout_year_id, start_date, end_date)'
        . ' SELECT id, ?, ?, ?, NULL FROM members WHERE desk_id = ?'
    );
    foreach (['E2E-ADMIN', 'E2E-MEMBER'] as $deskId) {
        $statement->execute([$sectionId, $scoutYearId, e2e_fixture_start_date($scoutYear), $deskId]);
    }

    // role 'identified' — see this function's docblock: the function exists
    // so the two members are visible through SectionService, never to give
    // either of them anything RoleResolver would notice.
    $pdo->prepare('INSERT INTO functions (desk_code, label, role, confirmed) VALUES (?, ?, ?, 1)')
        ->execute(['E2E-FCT', 'Animé', Core\Security\Role::IDENTIFIED->value]);
    $functionId = (int) $pdo->lastInsertId();

    $functionStatement = $pdo->prepare(
        'INSERT INTO member_functions (member_year_id, function_id, section_id, age_branch_id, start_date, '
            . 'is_main_function)'
        . ' SELECT my.id, ?, ?, ?, ?, 1 FROM member_years my'
        . ' JOIN members m ON m.id = my.member_id'
        . ' WHERE m.desk_id = ? AND my.scout_year_id = ?'
    );
    foreach (['E2E-ADMIN', 'E2E-MEMBER'] as $deskId) {
        $functionStatement->execute([
            $functionId,
            $sectionId,
            $ageBranchId,
            e2e_fixture_start_date($scoutYear),
            $deskId,
            $scoutYearId,
        ]);
    }
}

/**
 * The three role-bearing accounts the harness provisions on top of the
 * two the browser suite already uses (the super-admin and the ordinary
 * member), so that every rung of Core\Security\Role is represented by a
 * real, password-authenticable login: `identified` (the ordinary member),
 * `intendant`, `chief`, `admin`, `superadmin`.
 *
 * They exist for the dynamic security scan (scripts/dast.sh), whose
 * authorization matrix replays the site map as each role in turn — a
 * matrix missing a role proves nothing about that role. Nothing in the
 * Playwright suite reads them, deliberately: an existing scenario's shape
 * must not change because a scan wanted another account.
 *
 * Pure and side-effect-free (env lookups aside), so the descriptor list
 * is unit-tested directly — see Tests\Core\System\E2eRoleAccountsTest.
 *
 * The function codes deliberately do NOT start with `E2E-FCT`, the code
 * e2e_seed_section_with_both_members() already uses. Config Desk labels
 * each role select "Rôle pour <desk code>", and Playwright's getByLabel()
 * matches on a SUBSTRING: `E2E-FCT-INT` made
 * tests/e2e/specs/config-desk.spec.js's `getByLabel('Rôle pour E2E-FCT')`
 * resolve to four elements and fail on strict mode. Pinned by
 * Tests\Core\System\E2eRoleAccountsTest.
 *
 * The ADMIN one's environment prefix is E2E_UNIT_ADMIN rather than
 * E2E_ADMIN: E2E_ADMIN_EMAIL/E2E_ADMIN_PASSWORD have named the
 * SUPER-admin since long before roles were provisioned, and quietly
 * changing whose credentials they carry would break every scenario and
 * every CI job that already reads them. `admin` is the role displayed as
 * "Chef d'Unité" (Core\Security\Role), hence "unit admin".
 *
 * @return list<array{
 *     key: string,
 *     env_prefix: string,
 *     default_email: string,
 *     desk_id: string,
 *     first_name: string,
 *     last_name: string,
 *     function_code: string,
 *     function_label: string,
 *     role: Core\Security\Role
 * }>
 */
function e2e_role_accounts(): array
{
    return [
        [
            'key' => 'intendant',
            'env_prefix' => 'E2E_INTENDANT',
            'default_email' => 'chil@example.invalid',
            'desk_id' => 'E2E-INTENDANT',
            'first_name' => 'Chil',
            'last_name' => 'Milan',
            'function_code' => 'E2E-ROLE-INT',
            'function_label' => 'Intendant',
            'role' => Core\Security\Role::INTENDANT,
        ],
        [
            'key' => 'chief',
            'env_prefix' => 'E2E_CHIEF',
            'default_email' => 'bagheera@example.invalid',
            'desk_id' => 'E2E-CHIEF',
            'first_name' => 'Bagheera',
            'last_name' => 'Panthere',
            'function_code' => 'E2E-ROLE-CHF',
            'function_label' => 'Chef de section',
            'role' => Core\Security\Role::CHIEF,
        ],
        [
            'key' => 'unit_admin',
            'env_prefix' => 'E2E_UNIT_ADMIN',
            'default_email' => 'akela@example.invalid',
            'desk_id' => 'E2E-UNIT-ADMIN',
            'first_name' => 'Akela',
            'last_name' => 'Loup',
            'function_code' => 'E2E-ROLE-ADM',
            'function_label' => "Équipier d'unité",
            'role' => Core\Security\Role::ADMIN,
        ],
    ];
}

/**
 * The Desk codes of the section and age branch the role accounts live in.
 *
 * Like the function codes in e2e_role_accounts(), these must not merely
 * be UNIQUE — they must not be a PREFIX of, or prefixed by, a code an
 * existing fixture already uses. Config Desk labels each control
 * "Nom de la section <desk code>" / "Rôle pour <desk code>", and
 * Playwright's getByLabel() matches on a substring, so `E2E-SEC-ROLES`
 * made tests/e2e/specs/config-desk.spec.js's own
 * `getByLabel('Nom de la section E2E-SEC')` resolve to two elements and
 * fail on strict mode. Pinned by Tests\Core\System\E2eRoleAccountsTest.
 *
 * @return array{section: string, age_branch: string}
 */
function e2e_role_fixture_codes(): array
{
    return [
        'section' => 'E2E-ROLES-SEC',
        'age_branch' => 'E2E-ROLES-BR',
    ];
}

/**
 * @param array{env_prefix: string, default_email: string, ...} $account
 */
function e2e_role_account_email(array $account): string
{
    return strtolower(trim(((string) getenv($account['env_prefix'] . '_EMAIL')) ?: $account['default_email']));
}

/**
 * @param array{env_prefix: string, ...} $account
 */
function e2e_role_account_password(array $account): string
{
    $variable = $account['env_prefix'] . '_PASSWORD';
    $password = (string) getenv($variable);
    if ($password === '') {
        fwrite(STDERR, "E2E provisioning failed: {$variable} is not set (scripts/e2e.sh generates it).\n");
        exit(1);
    }

    return $password;
}

/**
 * Provision the three role-bearing accounts of e2e_role_accounts(), each
 * with a members row, a member_years row for the current scout year, a
 * member_functions row pointing at a function whose `role` IS the target
 * role, and a password account of their own.
 *
 * ## Why their own section, and why it is hidden
 *
 * e2e_seed_section_with_both_members() deliberately avoids chief, admin
 * and intendant functions — its own docblock says why: those are exactly
 * the roles Core\Member\SectionService::getSectionStaff() selects on, so
 * putting these three in "Meute E2E" would change the trombinoscope, the
 * Staffs page and every staff count the existing scenarios assert on.
 * They therefore get a section of their own.
 *
 * That section is `is_visible = 0`: Core\View\SectionRepository filters
 * the public sections listing on visibility, so a hidden one adds no
 * block to the Staffs page or the trombinoscope. Nothing about RBAC reads
 * section visibility — Core\Security\RoleResolver resolves a role from
 * `member_functions` → `functions.role` and nothing else — so the scan's
 * matrix is unaffected by the section being hidden, while the browser
 * suite's fixture shape is left exactly as it was.
 *
 * ## The Staff d'U sweep, and why it leaves the admin alone
 *
 * Core\Member\UnitStaffSectionService::syncMembership() reassigns every
 * `admin`-role function to the STAFFDU section after a Desk import and
 * after every role change (Config Desk does exactly that, mid-run, in
 * tests/e2e/specs/config-desk.spec.js). It only claims functions with
 * `section_id IS NULL`, so the admin's function is created WITH this
 * section's id and the sweep passes over it — which is the rule working
 * as designed, not a workaround. That matters: the super-admin already
 * holds a main function in Staff d'U for the retro module's sake
 * (e2e_seed_unit_chief_function_for_admin(), and Core\Member\
 * MemberService::isUnitChief() reads it), and a second admin landing
 * there would change what tests/e2e/specs/retro-board.spec.js is offered.
 *
 * The three are given a `member_section_periods` row too, exactly like
 * the other two members, because that is what a real Desk import writes
 * and what derived group membership resolves from.
 */
function e2e_seed_role_members(
    Core\Database\Connection $connection,
    string $encodedEncryptionKey,
    string $encodedBlindIndexKey
): void {
    $pdo = $connection->getPdo();
    $encryptionService = Core\Security\EncryptionService::fromEncodedKeys($encodedEncryptionKey, $encodedBlindIndexKey);
    $scoutYear = (new Core\Config\ScoutYearService($pdo))->getCurrentYear();
    $scoutYearId = $scoutYear['id'];

    // sort_order 90 puts this branch last everywhere branches are
    // ordered, so nothing that reads "the first section" on a page picks
    // it up ahead of the fixture the browser suite already knows.
    $codes = e2e_role_fixture_codes();

    $pdo->prepare('INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, 90)')
        ->execute([$codes['age_branch'], 'Branche rôles E2E']);
    $ageBranchId = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO sections (age_branch_id, desk_code, name, is_visible, is_active) VALUES (?, ?, ?, 0, 1)')
        ->execute([$ageBranchId, $codes['section'], 'Staff rôles E2E']);
    $sectionId = (int) $pdo->lastInsertId();

    $userAccountRepository = new Core\Security\UserAccountRepository($pdo, $encryptionService);
    $startDate = e2e_fixture_start_date($scoutYear);

    foreach (e2e_role_accounts() as $account) {
        $email = e2e_role_account_email($account);
        $blindIndex = $encryptionService->blindIndex($email, 'email');

        $pdo->prepare('INSERT INTO members (desk_id) VALUES (?)')->execute([$account['desk_id']]);
        $memberId = (int) $pdo->lastInsertId();

        // is_active = 1 for the same reason as the other two seeders:
        // findAllByEmail() filters on it, and RoleResolver walks the rows
        // it returns.
        $statement = $pdo->prepare(
            'INSERT INTO member_years'
            . ' (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, '
            . 'email_blind_index, is_active)'
            . ' VALUES (?, ?, ?, ?, ?, ?, 1)'
        );
        $statement->execute([
            $memberId,
            $scoutYearId,
            $encryptionService->encrypt($account['first_name'], 'member_years.first_name'),
            $encryptionService->encrypt($account['last_name'], 'member_years.last_name'),
            $encryptionService->encrypt($email, 'member_years.email'),
            $blindIndex,
        ]);
        $memberYearId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO member_section_periods (member_id, section_id, scout_year_id, start_date, '
            . 'end_date) VALUES (?, ?, ?, ?, NULL)')
            ->execute([$memberId, $sectionId, $scoutYearId, $startDate]);

        // The role is carried by the FUNCTION, never by a flag on
        // user_accounts — RoleResolver reads functions.role and would
        // ignore anything written on the account (only is_super_admin
        // short-circuits it, and none of these three is one).
        $pdo->prepare('INSERT INTO functions (desk_code, label, role, confirmed) VALUES (?, ?, ?, 1)')
            ->execute([$account['function_code'], $account['function_label'], $account['role']->value]);
        $functionId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, age_branch_id, start_date, '
                . 'is_main_function)'
            . ' VALUES (?, ?, ?, ?, ?, 1)'
        )->execute([$memberYearId, $functionId, $sectionId, $ageBranchId, $startDate]);

        $userAccount = $userAccountRepository->create($email, false);
        $userAccountRepository->updatePasswordHash(
            $userAccount->id,
            password_hash(e2e_role_account_password($account), PASSWORD_DEFAULT)
        );
        $userAccountRepository->updateProfile($userAccount->id, $account['first_name'], $account['last_name']);
    }
}

/**
 * Ask the application's own Core\Security\RoleResolver what each of the
 * five provisioned accounts resolves to, and abort provisioning if any
 * answer is not the one the fixture is supposed to guarantee.
 *
 * This is not belt-and-braces. A role resolves per scout year through
 * `member_functions` → `functions.role`, so a member seeded against the
 * wrong `scout_year_id`, or a function row whose `role` never took,
 * silently resolves to `identified` — and a scan whose whole verdict is
 * "could role X reach page Y" would then report a clean authorization
 * matrix built entirely out of one role. Failing here, loudly, at
 * provisioning time, is the difference between a broken fixture and a
 * falsely reassuring security report.
 *
 * Built exactly the way public/index.php builds it, secondary-address
 * repository included, so what is asserted is what a real login resolves.
 */
function e2e_assert_resolved_roles(
    Core\Database\Connection $connection,
    string $encodedEncryptionKey,
    string $encodedBlindIndexKey,
    string $adminEmail
): void {
    $pdo = $connection->getPdo();
    $encryptionService = Core\Security\EncryptionService::fromEncodedKeys($encodedEncryptionKey, $encodedBlindIndexKey);
    $scoutYear = (new Core\Config\ScoutYearService($pdo))->getCurrentYear();
    $scoutYearId = $scoutYear['id'];

    $roleResolver = new Core\Security\RoleResolver(
        new Core\Import\MemberYearRepository($pdo),
        $encryptionService,
        $pdo,
        new Core\Member\MemberEmailRepository($pdo, $encryptionService)
    );

    $expected = [
        $adminEmail => Core\Security\Role::SUPERADMIN->value,
        e2e_member_email() => Core\Security\Role::IDENTIFIED->value,
    ];
    foreach (e2e_role_accounts() as $account) {
        $expected[e2e_role_account_email($account)] = $account['role']->value;
    }

    $failures = [];
    foreach ($expected as $email => $expectedRole) {
        $resolved = $roleResolver->resolve((string) $email, $scoutYearId);
        if ($resolved !== $expectedRole) {
            $failures[] = "{$email} resolves to '{$resolved}', expected '{$expectedRole}'";
        }
    }

    if (count($failures) > 0) {
        fwrite(
            STDERR,
            "E2E provisioning failed: the seeded accounts do not resolve to the roles they are meant to carry.\n  - "
            . implode("\n  - ", $failures) . "\n"
        );
        exit(1);
    }

    echo 'E2E: ' . count($expected) . " accounts provisioned, roles verified through RoleResolver.\n";
}

/**
 * Give the super-admin's member a MAIN function in the "Staff d'U"
 * section — what makes MemberService::isUnitChief() true for them.
 *
 * The section itself comes from the real Core\Member\
 * UnitStaffSectionService (the same code a Desk import runs), never a
 * hand-rolled copy of its rows. The 'Animé' function
 * e2e_seed_section_with_both_members() gave the admin stays, demoted to a
 * secondary function: the admin remains a member of the shared section
 * (their member_section_periods row is untouched), so every fixture the
 * groups scenarios rely on holds exactly as before. The account's
 * resolved role was already `superadmin` through its flag, so a
 * role-'admin' function moves nothing there.
 */
function e2e_seed_unit_chief_function_for_admin(Core\Database\Connection $connection): void
{
    $pdo = $connection->getPdo();
    $scoutYear = (new Core\Config\ScoutYearService($pdo))->getCurrentYear();
    $scoutYearId = $scoutYear['id'];

    $staffSectionId = (new Core\Member\UnitStaffSectionService($pdo))->ensureSection();
    $statement = $pdo->prepare('SELECT age_branch_id FROM sections WHERE id = ?');
    $statement->execute([$staffSectionId]);
    $staffBranchId = (int) $statement->fetchColumn();

    $pdo->prepare('INSERT INTO functions (desk_code, label, role, confirmed) VALUES (?, ?, ?, 1)')
        ->execute(['E2E-CDU', "Animateur d'unité", Core\Security\Role::ADMIN->value]);
    $functionId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        'UPDATE member_functions mf'
        . ' JOIN member_years my ON my.id = mf.member_year_id'
        . ' JOIN members m ON m.id = my.member_id'
        . ' SET mf.is_main_function = 0'
        . ' WHERE m.desk_id = ? AND my.scout_year_id = ?'
    )->execute(['E2E-ADMIN', $scoutYearId]);

    $pdo->prepare(
        'INSERT INTO member_functions (member_year_id, function_id, section_id, age_branch_id, start_date, '
            . 'is_main_function)'
        . ' SELECT my.id, ?, ?, ?, ?, 1 FROM member_years my'
        . ' JOIN members m ON m.id = my.member_id'
        . ' WHERE m.desk_id = ? AND my.scout_year_id = ?'
    )->execute([
        $functionId,
        $staffSectionId,
        $staffBranchId,
        e2e_fixture_start_date($scoutYear),
        'E2E-ADMIN',
        $scoutYearId,
    ]);
}

/**
 * Give the unit chief's member a mobile number. Modules\SosStaff only
 * offers the duty grid for Staff d'U members WITH a known mobile
 * (SosSettingsService::getStaffOptions()) — without one, the whole
 * on-call surface is unreachable. A reserved fictitious Belgian mobile,
 * encrypted with the same context MemberService reads it back with.
 */
function e2e_seed_mobile_for_admin(
    Core\Database\Connection $connection,
    string $encodedEncryptionKey,
    string $encodedBlindIndexKey
): void {
    $pdo = $connection->getPdo();
    $encryptionService = Core\Security\EncryptionService::fromEncodedKeys($encodedEncryptionKey, $encodedBlindIndexKey);
    $scoutYear = (new Core\Config\ScoutYearService($pdo))->getCurrentYear();
    $scoutYearId = $scoutYear['id'];

    $pdo->prepare(
        'UPDATE member_years my JOIN members m ON m.id = my.member_id'
        . ' SET my.mobile_encrypted = ?'
        . ' WHERE m.desk_id = ? AND my.scout_year_id = ?'
    )->execute([
        $encryptionService->encrypt('+32 470 00 00 01', 'member_years.mobile'),
        'E2E-ADMIN',
        $scoutYearId,
    ]);
}

/**
 * The ordinary member's address and password, both handed down by
 * scripts/e2e.sh exactly like the admin's — see e2e_admin_email() and
 * e2e_admin_password() for why neither is a literal in the repository.
 */
function e2e_member_email(): string
{
    return strtolower(trim(((string) getenv('E2E_MEMBER_EMAIL')) ?: 'kaa@example.invalid'));
}

function e2e_member_password(): string
{
    $password = (string) getenv('E2E_MEMBER_PASSWORD');
    if ($password === '') {
        fwrite(STDERR, "E2E provisioning failed: E2E_MEMBER_PASSWORD is not set (scripts/e2e.sh generates it).\n");
        exit(1);
    }

    return $password;
}

/**
 * Every application PHP file coverage should be reported for: the same
 * set phpstan.neon analyses and sonar.sources covers on the PHP side.
 *
 * public/index.php is the whole reason this exists — it is the
 * composition root no other suite executes at all. Enumerated file by
 * file because php-code-coverage's Filter takes files, not directories;
 * phpunit/php-file-iterator (its own dependency) does the walking, with
 * the same vendor/ exclusion phpstan.neon's excludePaths applies.
 *
 * @return list<string>
 */
function e2e_coverage_source_files(string $repoRoot): array
{
    $facade = new SebastianBergmann\FileIterator\Facade();

    /** @var list<string> $files */
    $files = $facade->getFilesAsArray(
        [$repoRoot . '/core', $repoRoot . '/modules'],
        '.php',
        '',
        [$repoRoot . '/vendor'],
    );

    foreach (['/public/index.php', '/public/cron.php'] as $entryPoint) {
        if (is_file($repoRoot . $entryPoint)) {
            $files[] = $repoRoot . $entryPoint;
        }
    }

    // modules/*/vendor/ — bundled third-party code, excluded from PHPStan
    // for the same reason and never this project's coverage to report.
    return array_values(array_filter(
        $files,
        static fn (string $file): bool => !str_contains($file, '/vendor/'),
    ));
}

/**
 * Fold every per-request coverage fragment into one Clover report.
 *
 * scripts/e2e-coverage-prepend.php writes one serialized pcov array per
 * HTTP request (one file each, because several executions write over a
 * run and a shared file would be a lost-update race). This merges them
 * and writes the Clover XML SonarQube Cloud consumes, listed in
 * sonar-project.properties' sonar.php.coverage.reportPaths next to
 * PHPUnit's own coverage.xml — Sonar unions the reports, so a line either
 * suite covered counts as covered.
 *
 * pcov's array is a plain file => line => status map, which
 * RawCodeCoverageData::fromLineCoverage() takes as-is — the very call
 * php-code-coverage's own PcovDriver makes. This process is a plain CLI
 * script, free to use the library the collector itself cannot touch (see
 * its docblock).
 *
 * Returns false, with a diagnostic, rather than throwing: the caller
 * (scripts/e2e.sh) runs this *after* the browser tests and must never let
 * a reporting problem rewrite a green run into a red one, nor a red one
 * into a green one.
 */
function e2e_merge_coverage(string $repoRoot, string $coverageDir, string $outputPath): bool
{
    if (!class_exists(SebastianBergmann\CodeCoverage\CodeCoverage::class)) {
        fwrite(STDERR, "E2E coverage: php-code-coverage is not installed (a --no-dev vendor/?), nothing merged.\n");

        return false;
    }

    /** @var list<string> $fragments */
    $fragments = array_values((array) glob($coverageDir . '/*.cov'));
    if ($fragments === []) {
        fwrite(STDERR, "E2E coverage: no fragments in {$coverageDir} — was the server started with the collector?\n");

        return false;
    }

    try {
        $filter = new SebastianBergmann\CodeCoverage\Filter();
        $filter->includeFiles(e2e_coverage_source_files($repoRoot));

        $coverage = new SebastianBergmann\CodeCoverage\CodeCoverage(
            (new SebastianBergmann\CodeCoverage\Driver\Selector())->forLineCoverage($filter),
            $filter
        );

        $appended = 0;
        foreach ($fragments as $index => $fragment) {
            $raw = @file_get_contents($fragment);
            if ($raw === false) {
                continue;
            }

            // allowed_classes false: these fragments are plain nested
            // arrays written moments ago by this repository's own
            // collector, into a directory this run created. Refusing
            // objects outright keeps that assumption enforced rather than
            // merely documented.
            $lines = @unserialize($raw, ['allowed_classes' => false]);
            if (!is_array($lines) || $lines === []) {
                continue;
            }

            $coverage->append(
                SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData::fromLineCoverage($lines),
                'e2e-request-' . $index
            );
            $appended++;
        }

        if ($appended === 0) {
            fwrite(STDERR, "E2E coverage: every fragment in {$coverageDir} was unreadable, nothing merged.\n");

            return false;
        }

        (new SebastianBergmann\CodeCoverage\Report\Clover())->process($coverage->getReport(), $outputPath);

        printf(
            "E2E coverage: %d request fragment(s) merged into %s (%d file(s) covered).\n",
            $appended,
            $outputPath,
            count($coverage->getData()->coveredFiles())
        );
    } catch (Throwable $e) {
        fwrite(STDERR, "E2E coverage: could not write {$outputPath} — {$e->getMessage()}\n");

        return false;
    }

    return true;
}

/**
 * Runs the throwaway instance's OWN cron entry point once, exactly as a
 * host's crontab would — same `public/cron.php`, same instance config,
 * same Core\Scheduler\SchedulerRunner.
 *
 * A scenario needs this whenever the feature it exercises finishes in a
 * background task rather than in the request: a gallery upload is stored
 * immediately but has no renditions until `gallery`/`process_photo` runs,
 * so "download this photo" cannot be tested at all without something
 * turning the queue. Nothing else turns it: `public/cron.php` is the one
 * engine an installation has, and this harness has no crontab, so a
 * scenario that needs the queue to have advanced calls this and gets
 * exactly one deterministic pass — which is better than a race against a
 * background mechanism either way.
 *
 * A subprocess rather than an include: cron.php is a top-level script that
 * builds its own container from the instance's config, and running it
 * inside this process would inherit the repository's own bootstrap
 * instead.
 */
function e2e_run_scheduler(string $instanceDir): int
{
    // The same maildrop redirection scripts/e2e.sh gives the web server
    // (its `-d sendmail_path=...` on `php -S`): a task handler that sends
    // mail — the mass-mail batch send above all — must land in the run's
    // mailbox too, not shell out to a /usr/sbin/sendmail this container
    // doesn't have. The inner quotes survive escapeshellarg on purpose:
    // sendmail_path is run through sh, and the repo path may hold spaces.
    $sendmailPath = 'sendmail_path=php \'' . dirname(__DIR__) . '/scripts/e2e-maildrop.php\'';
    $command = escapeshellarg(PHP_BINARY)
        . ' -d ' . escapeshellarg($sendmailPath)
        . ' ' . escapeshellarg($instanceDir . '/public/cron.php') . ' 2>&1';
    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);

    // Echoed rather than swallowed: when a task handler fails, its message
    // is the only clue the scenario that called this will ever get.
    foreach ($output as $line) {
        echo $line, "\n";
    }

    return $exitCode;
}

function e2e_teardown_database(): void
{
    $config = e2e_database_config();

    try {
        e2e_drop_all_tables();
        e2e_server_pdo()->exec(sprintf('DROP DATABASE IF EXISTS `%s`', e2e_quote_identifier($config['name'])));
    } catch (Throwable $e) {
        // Teardown runs from scripts/e2e.sh's cleanup trap, including after
        // a failure that may itself be "the database went away". Report it,
        // but never turn a test result into a teardown error.
        fwrite(STDERR, "WARNING: could not drop the E2E database '{$config['name']}': {$e->getMessage()}\n");
    }
}

/**
 * Same FOREIGN_KEY_CHECKS-off SHOW TABLES sweep the database-group PHPUnit
 * tests use (tests/Core/Database/MigrationRunnerTest.php) — a curated DROP
 * list drifts out of sync with the schema, an unconditional sweep cannot.
 */
function e2e_drop_all_tables(): void
{
    $config = e2e_database_config();

    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $config['name']),
        $config['user'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    /** @var list<string> $tables */
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $pdo->exec('DROP TABLE IF EXISTS `' . e2e_quote_identifier($table) . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

/**
 * MySQL identifiers are quoted with backticks; the only escaping needed
 * inside them is doubling a literal backtick. Database and table names
 * here come from the environment and from SHOW TABLES, never from user
 * input, but neither can be bound as a prepared-statement parameter.
 */
function e2e_quote_identifier(string $identifier): string
{
    return str_replace('`', '``', $identifier);
}

/**
 * Copy public/ into the instance, skipping assets/ (symlinked separately
 * by the caller — it is 2+ MB of static vendored CSS/JS/fonts that only
 * ever gets served as plain files).
 */
function e2e_copy_public(string $source, string $destination): void
{
    e2e_mkdir($destination);

    /** @var list<string> $entries */
    $entries = array_values(array_diff((array) scandir($source), ['.', '..', 'assets']));
    foreach ($entries as $entry) {
        $sourcePath = $source . '/' . $entry;
        $destinationPath = $destination . '/' . $entry;

        if (is_dir($sourcePath)) {
            e2e_copy_tree($sourcePath, $destinationPath);
            continue;
        }

        copy($sourcePath, $destinationPath);
    }
}

function e2e_copy_tree(string $source, string $destination): void
{
    e2e_mkdir($destination);

    /** @var list<string> $entries */
    $entries = array_values(array_diff((array) scandir($source), ['.', '..']));
    foreach ($entries as $entry) {
        $sourcePath = $source . '/' . $entry;
        $destinationPath = $destination . '/' . $entry;

        if (is_dir($sourcePath)) {
            e2e_copy_tree($sourcePath, $destinationPath);
            continue;
        }

        copy($sourcePath, $destinationPath);
    }
}

function e2e_remove_tree(string $path): void
{
    /** @var list<string> $entries */
    $entries = array_values(array_diff((array) scandir($path), ['.', '..']));
    foreach ($entries as $entry) {
        $entryPath = $path . '/' . $entry;

        // is_dir() follows symlinks; an instance's core/modules/vendor
        // symlinks point at the repository itself and must be unlinked,
        // never recursed into.
        if (is_link($entryPath)) {
            unlink($entryPath);
            continue;
        }

        if (is_dir($entryPath)) {
            e2e_remove_tree($entryPath);
            continue;
        }

        unlink($entryPath);
    }

    rmdir($path);
}

function e2e_mkdir(string $path): void
{
    if (is_dir($path)) {
        return;
    }

    if (!mkdir($path, 0o755, true) && !is_dir($path)) {
        fwrite(STDERR, "Cannot create directory: {$path}\n");
        exit(1);
    }
}
