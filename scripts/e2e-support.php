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
 *                              be served on 127.0.0.1:<port>, with EVERY
 *                              module the repository ships activated —
 *                              see e2e_activate_all_modules() for why all
 *                              of them and not just the default three.
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
            e2e_provision($repoRoot, $instanceDir, $port);
            exit(0);

        case 'teardown-db':
            require_once $repoRoot . '/vendor/autoload.php';
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
        'mail_mode' => 'smtp',
        'smtp_host' => 'localhost',
        'smtp_port' => 25,
        'smtp_user' => '',
        'smtp_password' => '',
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
    // because the module activation below migrates too (every module
    // applies its own schema.sql as it is switched on).
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

    // --- Super-admin account, created the way the setup wizard creates it
    // (Core\Http\Controller\SetupController::createAdminAccount()): the
    // same EncryptionService, the same blind index, the same
    // password_hash() call, the same is_super_admin flag. A super-admin
    // resolves to the 'superadmin' role and is authorised to log in
    // without any matching member row (Core\Security\RoleResolver), which
    // is what lets a scenario reach `role_min: admin` pages on an
    // otherwise empty install.
    e2e_create_super_admin($connection, $encryptionKey, $blindIndexKey, $adminEmail, e2e_admin_password());

    // --- Every module, activated the way an admin activates one. ---
    $activated = e2e_activate_all_modules($repoRoot, $connection, $migrationRunner());

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
 * Fails closed: a module that cannot be activated aborts provisioning
 * with its own message, rather than quietly leaving the run with less
 * coverage than it claims.
 *
 * @return string[] the module ids activated, in the order they were
 */
function e2e_activate_all_modules(
    string $repoRoot,
    Core\Database\Connection $connection,
    Core\Database\MigrationRunner $migrationRunner
): array {
    $pdo = $connection->getPdo();
    $modulesDir = $repoRoot . '/modules';

    $moduleManager = new Core\Module\ModuleManager(
        $modulesDir,
        new Core\Config\SettingService(new Core\Config\SettingRepository($pdo)),
        new Core\Cookie\CookieConsentService(),
        new Core\View\MenuBuilder(Core\Security\Role::SUPERADMIN),
        new Core\Module\ModuleRegistryRepository($pdo),
        $migrationRunner,
        new Core\Journal\JournalService(new Core\Journal\JournalRepository($pdo)),
        new Core\Http\Router()
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
                "E2E provisioning failed: module '{$moduleId}' has an invalid module.json — {$module->validationError}\n"
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
            fwrite(STDERR, "E2E provisioning failed: module '{$moduleId}' could not be activated — {$e->getMessage()}\n");
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
