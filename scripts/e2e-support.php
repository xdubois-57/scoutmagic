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
    // ('self_destination'), and 127.0.0.1 is not a public host either, so
    // no run ever emits a report.
    //
    // Registered rather than updated because the row does not exist yet
    // (public/index.php registers it at boot, later than this): the insert
    // carries the value, and index.php's own register() call then only
    // refreshes default_value, leaving this one's value alone.
    $settingService = new Core\Config\SettingService(new Core\Config\SettingRepository($connection->getPdo()));
    $settingService->register(
        'statistics_destination',
        'http://127.0.0.1:' . $port,
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

    $activated = e2e_activate_all_modules(
        $repoRoot,
        $connection,
        $migrationRunner(),
        $settingService,
        'http://127.0.0.1:' . $port
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
    Core\Database\MigrationRunner $migrationRunner,
    Core\Config\SettingService $settingService,
    string $baseUrl
): array {
    $pdo = $connection->getPdo();
    $modulesDir = $repoRoot . '/modules';

    // The same question public/index.php asks on every request, through
    // the same matcher, rather than a hand-set boolean: what the
    // application will decide on the first request is what decides the set
    // of modules discovered here. $baseUrl is passed in rather than read
    // from the settings table because index.php only copies it there out
    // of secrets.enc on that first request — later than this.
    $isStatisticsReceiver = Core\Statistics\DestinationMatcher::isReceiver(
        $baseUrl,
        (string) ($settingService->get('statistics_destination') ?? '')
    );

    $moduleManager = new Core\Module\ModuleManager(
        $modulesDir,
        $settingService,
        new Core\Cookie\CookieConsentService(),
        new Core\View\MenuBuilder(Core\Security\Role::SUPERADMIN),
        new Core\Module\ModuleRegistryRepository($pdo),
        $migrationRunner,
        new Core\Journal\JournalService(new Core\Journal\JournalRepository($pdo)),
        new Core\Http\Router(),
        null,
        new Core\Offline\OfflineWhitelist(),
        $isStatisticsReceiver
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

    $scoutYearId = (new Core\Config\ScoutYearService($pdo))->getCurrentYear()['id'];

    $pdo->prepare('INSERT INTO members (desk_id) VALUES (?)')->execute(['E2E-ADMIN']);
    $memberId = (int) $pdo->lastInsertId();

    // is_active = 1 is not decoration: findAllByEmail() filters on it, so
    // an inactive row would link to nothing at all.
    $statement = $pdo->prepare(
        'INSERT INTO member_years'
        . ' (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, email_blind_index, is_active)'
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
    $scoutYearId = (new Core\Config\ScoutYearService($pdo))->getCurrentYear()['id'];

    $pdo->prepare('INSERT INTO members (desk_id) VALUES (?)')->execute(['E2E-MEMBER']);
    $memberId = (int) $pdo->lastInsertId();

    $statement = $pdo->prepare(
        'INSERT INTO member_years'
        . ' (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, email_blind_index, is_active)'
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
 * Deliberately no `member_functions` row, so neither member gains a role:
 * Core\Security\RoleResolver reads functions, and giving one a chief's
 * function would quietly turn "an ordinary member sees this" into "an
 * animator sees this" in every scenario that follows.
 */
function e2e_seed_section_with_both_members(Core\Database\Connection $connection): void
{
    $pdo = $connection->getPdo();
    $scoutYearId = (new Core\Config\ScoutYearService($pdo))->getCurrentYear()['id'];

    $pdo->prepare('INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, 1)')
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
        $statement->execute([$sectionId, $scoutYearId, date('Y-m-d', strtotime('-1 month')), $deskId]);
    }
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
