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
 *                              be served on 127.0.0.1:<port>.
 *   teardown-db                Drop every table of the E2E database and
 *                              the database itself.
 *
 * Database credentials come from the environment (E2E_DB_HOST/PORT/NAME/
 * USER/PASSWORD), always set by scripts/e2e.sh — see its own header for
 * the defaults and the TEST_DB_* fallbacks.
 *
 * @see scripts/e2e.sh
 */

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
        e2e_provision($repoRoot, $instanceDir, $port);
        exit(0);

    case 'teardown-db':
        require_once $repoRoot . '/vendor/autoload.php';
        e2e_teardown_database();
        exit(0);

    default:
        fwrite(STDERR, "Unknown command: '{$command}'. See this file's header for the command list.\n");
        exit(1);
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

    e2e_copy_public($repoRoot . '/public', $instanceDir . '/public');
    symlink($repoRoot . '/public/assets', $instanceDir . '/public/assets');

    foreach (['core', 'modules', 'schema', 'vendor', 'composer.json', 'VERSION'] as $entry) {
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
        . "    'base_url' => 'http://127.0.0.1:" . $port . "',\n"
        . "];\n"
    );

    // --- Secrets: exactly the set SetupController writes at first install,
    // minus admin_email (no account is created — the scenario under test is
    // a public, logged-out page, and an account would mean storing a
    // personal email address in a throwaway fixture for no reason).
    $secretManager = new Core\Security\SecretManager(
        $instanceDir . '/storage/keys/master.key',
        $instanceDir . '/storage/config/secrets.enc'
    );
    $secretManager->generateMasterKey();

    $vapidKeys = Core\Notification\VapidKeyPairFactory::createValid();

    $secretManager->writeSecrets([
        'db_host' => $config['host'],
        'db_port' => $config['port'],
        'db_name' => $config['name'],
        'db_user' => $config['user'],
        'db_password' => $config['password'],
        'mail_mode' => 'smtp',
        'smtp_host' => 'localhost',
        'smtp_port' => 25,
        'smtp_user' => '',
        'smtp_password' => '',
        'encryption_key' => base64_encode(random_bytes(32)),
        'blind_index_key' => base64_encode(random_bytes(32)),
        'vapid_public_key' => $vapidKeys['publicKey'],
        'vapid_private_key' => $vapidKeys['privateKey'],
        'site_name' => 'Unité de test E2E',
        'short_name' => 'E2E',
        'base_url' => 'http://127.0.0.1:' . $port,
        'mail_from_address' => 'e2e@example.invalid',
        'mail_from_name' => 'Unité de test E2E',
        'dkim_selector' => 's2026',
        'dmarc_report_email' => 'e2e@example.invalid',
    ]);

    // --- Schema, through the application's own migration runner. ---
    //
    // The schema path must be spelled EXACTLY the way public/index.php
    // spells it, because MigrationRunner keys its "already migrated" flag
    // on a hash of the schema file *paths* it was handed, not only of
    // their contents. Hand it $repoRoot . '/schema/core.sql' and the flag
    // lands under a different key than the one index.php looks up — so
    // the very first browser request finds a migration pending, serves
    // the migration-progress page instead of the requested route, and the
    // test races that page's own JavaScript to a reload. index.php builds
    // it as __DIR__ . '/../schema/core.sql' from its own directory, and
    // PHP resolves __DIR__ through symlinks (which matters: mktemp -d
    // returns a path under a symlinked /var on macOS), hence realpath().
    $publicDir = realpath($instanceDir . '/public');
    if ($publicDir === false) {
        fwrite(STDERR, "E2E provisioning failed: instance public/ directory not found.\n");
        exit(1);
    }
    $schemaPath = $publicDir . '/../schema/core.sql';

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

    // Note for whoever wonders where the dumps went: MigrationRunner backs
    // the database up before migrating, into the REPOSITORY's storage/temp
    // (Core\Database\MigrationRunner::attemptBackup() anchors that
    // directory to its own file location, and the instance loads core/
    // from the repository — so an instance of its own cannot redirect it).
    // Those dumps are of the throwaway E2E database and are worthless the
    // moment the run ends; scripts/e2e.sh removes exactly the ones the run
    // created, and it does so around the whole run rather than here,
    // because the first HTTP request migrates too (every module enabled by
    // default applies its own schema.sql on first boot).
    $result = $migrationRunner()->migrate([$schemaPath]);

    if (!$result->complete) {
        fwrite(STDERR, "E2E provisioning failed: schema migration did not complete.\n");
        exit(1);
    }

    // Fail closed on the race described above rather than let the browser
    // test discover it as an intermittent "wrong page" failure: after
    // provisioning, the application must see no pending migration for the
    // exact path it will use.
    if ($migrationRunner()->isPending([$schemaPath])) {
        fwrite(
            STDERR,
            "E2E provisioning failed: a schema migration is still pending for {$schemaPath} — "
            . "the first request would serve the migration-progress page instead of the application.\n"
        );
        exit(1);
    }

    echo "E2E instance provisioned at {$instanceDir} (database '{$config['name']}', port {$port}).\n";
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
