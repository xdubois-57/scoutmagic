<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Database\Connection;
use Core\Database\MigrationRunner;
use Core\Database\SchemaFiles;
use Core\Database\SchemaComparator;
use Core\Database\SchemaIntrospector;
use Core\Database\SqlParser;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Exception\UserFacingMessage;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Mail\DkimManager;
use Core\Mail\DnsVerifier;
use Core\Mail\MailServiceFactory;
use Core\Photo\UnitLogoService;
use Core\Scheduler\CronHealth;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\Security\PasswordPolicy;
use Core\Security\SecretManager;
use Core\Security\SessionStore;
use Core\Statistics\InstallationDateService;
use Twig\Environment;

class SetupController extends AbstractController
{
    private ?SettingService $settingService = null;
    private ?JournalService $journalService = null;
    // Nullable/setter-injected like the two above: the first-run
    // instantiation of this controller (public/index.php, before
    // secrets.enc exists) happens before there's any database to build a
    // real UnitLogoService against — see index()'s own is_initialized
    // gating of the logo block, which never renders while this stays null.
    private ?UnitLogoService $unitLogoService = null;

    public function __construct(
        protected Environment $twig,
        private SecretManager $secretManager,
        private DkimManager $dkimManager,
        private string $schemaPath,
        private string $publicDir = ''
    ) {
    }

    public function setSettingService(SettingService $settingService): void
    {
        $this->settingService = $settingService;
    }

    public function setJournalService(JournalService $journalService): void
    {
        $this->journalService = $journalService;
    }

    public function setUnitLogoService(UnitLogoService $unitLogoService): void
    {
        $this->unitLogoService = $unitLogoService;
    }

    /**
     * GET /setup — render the setup form.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $isInitialized = $this->secretManager->isInitialized();

        if (!$isInitialized) {
            $gate = $this->checkTokenGate();
            if ($gate !== null) {
                return $gate;
            }
        }

        $currentValues = [];

        if ($isInitialized) {
            $secrets = $this->secretManager->readSecrets();

            // Non-secret settings may have been migrated to the settings table
            $setting = fn(string $key): string =>
                $this->settingService !== null
                    ? ($this->settingService->get($key) ?? ($secrets[$key] ?? ''))
                    : ($secrets[$key] ?? '');

            $currentValues = [
                'db_host' => $secrets['db_host'] ?? 'localhost',
                'db_port' => $secrets['db_port'] ?? 3306,
                'db_name' => $secrets['db_name'] ?? '',
                'db_user' => $secrets['db_user'] ?? '',
                'db_password' => '',
                'site_name' => $setting('site_name'),
                'short_name' => $setting('short_name'),
                'base_url' => $setting('base_url'),
                'mail_mode' => $secrets['mail_mode'] ?? 'smtp',
                'smtp_host' => $secrets['smtp_host'] ?? '',
                'smtp_port' => $secrets['smtp_port'] ?? 587,
                'smtp_user' => $secrets['smtp_user'] ?? '',
                'smtp_password' => '',
                'mail_from_address' => $setting('mail_from_address'),
                'mail_from_name' => $setting('mail_from_name'),
                'dkim_selector' => $setting('dkim_selector'),
                'dmarc_report_email' => $setting('dmarc_report_email'),
                'admin_email' => $secrets['admin_email'] ?? '',
                'admin_password' => '',
                'admin_password_confirm' => '',
            ];
        } else {
            $currentValues = ['base_url' => $this->resolveDefaultBaseUrl($request)];
        }

        $csrfToken = CsrfGuard::generateToken();

        return $this->render('setup/index.html.twig', [
            'is_initialized' => $isInitialized,
            'values' => $currentValues,
            'errors' => [],
            'csrf_token' => $csrfToken,
            'has_dkim_key' => $this->dkimManager->hasKey(),
            'dkim_public_key' => $this->dkimManager->hasKey() ? $this->dkimManager->getPublicKey() : null,
            'cron_script_path' => $this->publicDir !== '' ? rtrim($this->publicDir, '/') . '/cron.php' : null,
            // Built in ONE place (Core\Scheduler\CronHealth), shared with
            // the maintenance page's health block — the exact line an
            // operator copies must never exist in two spellings.
            'cron_crontab_line' => CronHealth::crontabLine($this->publicDir),
            'cron_state' => $this->cronHealth()->status()->state,
            // Only meaningful once initialized (see the template's own
            // is_initialized gate on the whole logo block) — false rather
            // than null when the service isn't wired yet, so the template
            // never has to special-case an undefined/null value.
            'unit_logo_is_custom' => $this->unitLogoService?->hasCustomLogo() ?? false,
        ]);
    }

    /**
     * POST /setup/test-db — AJAX: test database connection.
     *
     * @param array<string, string> $params
     */
    public function testDatabase(Request $request, array $params): Response
    {
        $gate = $this->denyUnlessTokenVerified();
        if ($gate !== null) {
            return $gate;
        }

        // setup.js reads `message`, not `error` — keep the key, unify the sentence.
        if (!CsrfGuard::validateRequest()) {
            return $this->json(['success' => false, 'message' => self::SESSION_EXPIRED_MESSAGE], 403);
        }

        $host = (string) $request->getBody('db_host', 'localhost');
        $port = (int) $request->getBody('db_port', 3306);
        $dbName = (string) $request->getBody('db_name', '');
        $user = (string) $request->getBody('db_user', '');
        $password = (string) $request->getBody('db_password', '');

        $connection = new Connection($host, $port, $dbName, $user, $password);
        $result = $connection->testConnection();

        if ($result !== true) {
            return $this->json(['success' => false, 'message' => $result]);
        }

        // A leftover database from a prior install (files replaced, DB
        // untouched) generates a brand-new encryption key against old
        // encrypted rows — a guaranteed DecryptionException down the line.
        // Only relevant for a genuinely first-time setup; an already-
        // initialized site's own database is never empty.
        $hasExistingTables = false;
        $tableCount = 0;
        if (!$this->secretManager->isInitialized()) {
            $tableCount = count((new SchemaIntrospector($connection->getPdo()))->getTables());
            $hasExistingTables = $tableCount > 0;
        }

        return $this->json([
            'success' => true,
            'message' => 'Connexion réussie',
            'has_existing_tables' => $hasExistingTables,
            'table_count' => $tableCount,
        ]);
    }

    /**
     * POST /setup/install-database — AJAX: test the connection, refuse (same
     * warning as testDatabase()) if the database already has tables, and
     * otherwise run the actual schema migration right here — separating
     * the potentially slow first schema creation (35+ CREATE TABLE
     * statements from scratch) from the much lighter final "Installer"
     * form submission, which used to bundle it together with secret
     * generation and admin-account creation in one opaque request. First-
     * time setup only; the already-initialized "update settings" flow
     * keeps using the lightweight testDatabase() above, which never
     * migrates just because a DB password was re-verified.
     *
     * @param array<string, string> $params
     */
    public function installDatabase(Request $request, array $params): Response
    {
        $gate = $this->denyUnlessTokenVerified();
        if ($gate !== null) {
            return $gate;
        }

        if ($this->secretManager->isInitialized()) {
            return $this->json(['success' => false, 'message' => 'Action indisponible : le site est déjà configuré.'],
                403);
        }
        // setup.js reads `message`, not `error` — keep the key, unify the sentence.
        if (!CsrfGuard::validateRequest()) {
            return $this->json(['success' => false, 'message' => self::SESSION_EXPIRED_MESSAGE], 403);
        }

        $host = (string) $request->getBody('db_host', 'localhost');
        $port = (int) $request->getBody('db_port', 3306);
        $dbName = (string) $request->getBody('db_name', '');
        $user = (string) $request->getBody('db_user', '');
        $password = (string) $request->getBody('db_password', '');

        $connection = new Connection($host, $port, $dbName, $user, $password);
        $result = $connection->testConnection();
        if ($result !== true) {
            return $this->json(['success' => false, 'message' => $result]);
        }

        $tableCount = count((new SchemaIntrospector($connection->getPdo()))->getTables());
        if ($tableCount > 0) {
            return $this->json([
                'success' => true,
                'has_existing_tables' => true,
                'table_count' => $tableCount,
            ]);
        }

        $runner = new MigrationRunner(
            $connection,
            new SchemaIntrospector($connection->getPdo()),
            new SchemaComparator(),
            new SqlParser()
        );
        $migrationResult = $runner->migrate($this->schemaFileSet());

        $this->journalService?->log(
            'core',
            'setup_database_installed',
            'security',
            'Base de données installée depuis l\'assistant de configuration',
            [
                'statements_executed' => count($migrationResult->executedStatements),
                'warnings' => count($migrationResult->warnings)
            ]
        );

        return $this->json([
            'success' => true,
            'has_existing_tables' => false,
            'migrated' => true,
            'table_count' => count((new SchemaIntrospector($connection->getPdo()))->getTables()),
            'statements_executed' => count($migrationResult->executedStatements),
            'warnings' => $migrationResult->warnings,
        ]);
    }

    /**
     * POST /setup/backup-and-empty-db — AJAX: dump the existing database
     * to a downloadable file, then drop every table so migration starts
     * from a genuinely clean state. First-time setup only — never reachable
     * once the site is initialized, so this can never touch a live
     * install's data.
     *
     * @param array<string, string> $params
     */
    public function backupAndEmptyDatabase(Request $request, array $params): Response
    {
        $gate = $this->denyUnlessTokenVerified();
        if ($gate !== null) {
            return $gate;
        }

        if ($this->secretManager->isInitialized()) {
            return $this->json(['success' => false, 'message' => 'Action indisponible : le site est déjà configuré.'],
                403);
        }
        // setup.js reads `message`, not `error` — keep the key, unify the sentence.
        if (!CsrfGuard::validateRequest()) {
            return $this->json(['success' => false, 'message' => self::SESSION_EXPIRED_MESSAGE], 403);
        }

        $host = (string) $request->getBody('db_host', 'localhost');
        $port = (int) $request->getBody('db_port', 3306);
        $dbName = (string) $request->getBody('db_name', '');
        $user = (string) $request->getBody('db_user', '');
        $password = (string) $request->getBody('db_password', '');
        $forceWithoutBackup = (string) $request->getBody('force_without_backup', '') === '1';

        $connection = new Connection($host, $port, $dbName, $user, $password);
        if ($connection->testConnection() !== true) {
            return $this->json(['success' => false, 'message' => 'La connexion à la base de données a échoué.']);
        }

        $basePath = rtrim(dirname(rtrim($this->publicDir, '/')), '/');
        $storagePath = $basePath . '/storage';

        $dumpPath = null;
        $backupError = null;
        try {
            $backupService = new \Core\Maintenance\BackupService($connection, $storagePath, $basePath);
            $dumpPath = $backupService->createDatabaseDump();

            // Personal-data columns in the dump are still encrypted at
            // rest (BackupService's own doc comment) — without the key
            // that encrypted them, they're permanently unreadable the
            // moment this reinstall's setup generates a fresh one.
            // Bundle the two files needed to decrypt them later
            // alongside the dump itself, so the backup is actually
            // restorable rather than just a pile of ciphertext.
            $masterKeyPath = $storagePath . '/keys/master.key';
            $secretsPath = $storagePath . '/config/secrets.enc';
            if (is_file($masterKeyPath) && is_file($secretsPath)) {
                $dumpPath = $this->bundleBackupWithEncryptionKey($dumpPath, $masterKeyPath, $secretsPath);
            }
        } catch (\Core\Maintenance\BackupException $e) {
            // BackupException is marked UserFacingException, so its own
            // sentence survives; the helper is still what stands here so an
            // empty message can never render as "Échec de la sauvegarde : ".
            $backupError = UserFacingMessage::from(
                $e,
                'la sauvegarde préalable n\'a pas pu être générée — vérifiez l\'espace disque et les droits '
                . 'd\'écriture sur storage/.'
            );
        }

        // The backup step failing must never leave the operator stuck with
        // no way to proceed — but emptying without a safety net is a
        // distinct, more dangerous action, so it's only ever taken when
        // explicitly confirmed, never silently.
        if ($backupError !== null && !$forceWithoutBackup) {
            return $this->json([
                'success' => false,
                'backup_failed' => true,
                'message' => 'Échec de la sauvegarde : ' . $backupError,
            ]);
        }

        $pdo = $connection->getPdo();
        $tables = (new SchemaIntrospector($pdo))->getTables();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        if ($dumpPath !== null) {
            SessionStore::set('setup_backup_download', basename($dumpPath));
        }

        $this->journalService?->log(
            'core',
            'setup_db_emptied',
            'security',
            $dumpPath !== null
                ? 'Base de données sauvegardée puis vidée avant réinstallation'
                : 'Base de données vidée avant réinstallation SANS sauvegarde (échec : ' . $backupError . ')',
            ['table_count' => count($tables)]
        );

        return $this->json([
            'success' => true,
            'table_count' => count($tables),
            'download_url' => $dumpPath !== null ? '/setup/download-backup' : null,
            'backup_skipped' => $dumpPath === null,
        ]);
    }

    /**
     * Wraps the plain .sql dump, master.key, and secrets.enc into a single
     * zip — the SQL alone is not restorable, since every personal-data
     * column in it is still an encrypted BLOB and the key that produced it
     * is about to be replaced by a fresh one generated later in this same
     * setup flow. Falls back to the plain .sql path if zipping fails for
     * any reason (e.g. no ZipArchive support) rather than losing the dump
     * that did succeed.
     */
    /**
     * The whole declared schema — core plus every module's — which is what
     * a fresh install must create. Anything left out here would instead be
     * created on the first page load after setup, by the migration-in-
     * progress page: correct, but a wizard that ends by handing the first
     * visitor a progress bar has not really finished installing.
     *
     * $schemaPath is still the constructor parameter: it names core.sql,
     * whose directory's parent is the installation root.
     *
     * @return array<string>
     */
    private function schemaFileSet(): array
    {
        return SchemaFiles::all(dirname(dirname($this->schemaPath)));
    }

    private function bundleBackupWithEncryptionKey(string $dumpPath, string $masterKeyPath, string $secretsPath): string
    {
        $zipPath = dirname($dumpPath) . '/' . pathinfo($dumpPath, PATHINFO_FILENAME) . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return $dumpPath;
        }

        $zip->addFile($dumpPath, 'database.sql');
        $zip->addFile($masterKeyPath, 'master.key');
        $zip->addFile($secretsPath, 'secrets.enc');
        $zip->close();
        @unlink($dumpPath);

        return $zipPath;
    }

    /**
     * GET /setup/download-backup — one-time download of the dump created
     * by backupAndEmptyDatabase(), then deleted. Gated on a session-stored
     * filename set only by that action, not on user-supplied input — safe
     * against path traversal / downloading an arbitrary file.
     *
     * @param array<string, string> $params
     */
    public function downloadBackup(Request $request, array $params): Response
    {
        $gate = $this->denyUnlessTokenVerified(false);
        if ($gate !== null) {
            return $gate;
        }

        $filename = SessionStore::get('setup_backup_download');
        if (!is_string($filename) || $filename === '') {
            return new Response('Not Found', 404);
        }

        $basePath = rtrim(dirname(rtrim($this->publicDir, '/')), '/');
        $path = $basePath . '/storage/maintenance/' . $filename;

        if (!is_file($path)) {
            SessionStore::remove('setup_backup_download');
            return new Response('Not Found', 404);
        }

        $content = (string) file_get_contents($path);
        @unlink($path);
        SessionStore::remove('setup_backup_download');

        // .zip when bundleBackupWithEncryptionKey() ran (dump + master.key
        // + secrets.enc together), plain .sql otherwise.
        $contentType = str_ends_with($filename, '.zip') ? 'application/zip' : 'application/sql';

        return (new Response($content))
            ->setHeader('Content-Type', $contentType)
            ->setHeader('Content-Disposition', 'attachment; filename="' . addslashes($filename) . '"')
            ->setHeader('Content-Length', (string) strlen($content));
    }

    /**
     * `Core\Scheduler\CronHealth` for this installation.
     *
     * `storage/` sits beside the document root in one hosting layout and
     * inside it in the other; `$this->publicDir` is the anchor that
     * resolves to the same project root in both (ARCHITECTURE.md §9), and
     * it is the same computation backupAndEmptyDatabase() already makes.
     * The setting service is null on a first run — there is no database
     * yet, which is exactly why the heartbeat file exists.
     */
    private function cronHealth(): CronHealth
    {
        $storagePath = $this->publicDir !== ''
            ? rtrim(dirname(rtrim($this->publicDir, '/')), '/') . '/storage'
            : '';

        return new CronHealth($storagePath, $this->settingService);
    }

    /**
     * GET /setup/cron-status — AJAX: does a real crontab actually run?
     *
     * Polled by the page every few seconds while the operator configures
     * the cron entry with their host, so that "je l'ai mise, est-ce que ça
     * marche ?" is answered on screen in under a minute instead of being
     * discovered weeks later through a background task that never ran.
     *
     * @param array<string, string> $params
     */
    public function cronStatus(Request $request, array $params): Response
    {
        $gate = $this->denyUnlessTokenVerified();
        if ($gate !== null) {
            return $gate;
        }

        $status = $this->cronHealth()->status();

        return $this->json([
            'state' => $status->state,
            'seconds_since_heartbeat' => $status->secondsSinceLastSeen(),
        ]);
    }

    /**
     * Re-render the wizard with what the operator actually typed.
     *
     * Both refusal paths in save() come back here rather than redirecting:
     * this form is long, and throwing a database password, an SMTP
     * password and an admin password away because one prerequisite is not
     * met yet is its own kind of failure.
     *
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function renderFormWith(array $values, array $errors, bool $isFirstRun): Response
    {
        return $this->render('setup/index.html.twig', [
            'is_initialized' => !$isFirstRun,
            'values' => $values,
            'errors' => $errors,
            'csrf_token' => CsrfGuard::generateToken(),
            'has_dkim_key' => $this->dkimManager->hasKey(),
            'dkim_public_key' => $this->dkimManager->hasKey() ? $this->dkimManager->getPublicKey() : null,
            'cron_script_path' => $this->publicDir !== '' ? rtrim($this->publicDir, '/') . '/cron.php' : null,
            'cron_crontab_line' => CronHealth::crontabLine($this->publicDir),
            'cron_state' => $this->cronHealth()->status()->state,
            'unit_logo_is_custom' => $this->unitLogoService?->hasCustomLogo() ?? false,
        ]);
    }

    /**
     * POST /setup/save — process the form.
     *
     * @param array<string, string> $params
     */
    public function save(Request $request, array $params): Response
    {
        $gate = $this->denyUnlessTokenVerified(false);
        if ($gate !== null) {
            return $gate;
        }

        if (($guard = $this->guardCsrf($request, '/setup')) !== null) {
            return $guard;
        }

        $isFirstRun = !$this->secretManager->isInitialized();

        // Collect and validate form data
        $data = $this->collectFormData($request);
        $errors = $this->validateFormData($data, $isFirstRun);

        if (!empty($errors)) {
            return $this->renderFormWith($data, $errors, $isFirstRun);
        }

        // The cron gate, and the asymmetry it deliberately carries.
        //
        // On a FIRST RUN a real crontab is a hard requirement, not advice:
        // the queue is the engine behind backups, updates, reminders and
        // every notification, and an install finished without one silently
        // does none of them. Verifying it here, once, while the operator is
        // still sitting in their host's control panel, is the only moment
        // it costs nothing — the reference installation ran for days on a
        // crontab entry that executed nothing at all (a bare script path
        // where the panel needed `php /htdocs/public/cron.php`), and no
        // page anywhere said so.
        //
        // On an ALREADY-CONFIGURED site this same page is « Installation &
        // serveur », the superadmin configuration page, and the check is a
        // WARNING only — see the health block it renders. Refusing to save
        // a database password or an SMTP host because the crontab happened
        // to miss three minutes (a host hiccup, a load spike, a pass that
        // overran) would break a live site's configuration page over a
        // transient, and would be a worse problem than the one being
        // prevented.
        if ($isFirstRun && !$this->cronHealth()->status()->isActive()) {
            FlashMessage::set(
                'error',
                'Aucune tâche cron n\'a encore été détectée. Configurez la ligne indiquée dans la section '
                . '« Tâche cron » chez votre hébergeur, attendez que l\'état passe au vert, puis relancez '
                . 'l\'installation.'
            );

            return $this->renderFormWith($data, [], $isFirstRun);
        }

        if ($isFirstRun) {
            return $this->handleFirstTimeSetup($data);
        }

        return $this->handleConfigUpdate($data, $request);
    }

    /**
     * GET /setup/dns — AJAX: check DNS records.
     *
     * @param array<string, string> $params
     */
    public function checkDns(Request $request, array $params): Response
    {
        $gate = $this->denyUnlessTokenVerified();
        if ($gate !== null) {
            return $gate;
        }

        $domain = (string) $request->getQuery('domain', '');
        $selector = (string) $request->getQuery('selector', '');
        $mode = (string) $request->getQuery('mode', 'smtp');
        $smtpHost = (string) $request->getQuery('smtp_host', '');

        if ($domain === '' || $selector === '') {
            return $this->json(['error' => 'Domain and selector are required.'], 400);
        }

        $hasDkimKey = $this->dkimManager->hasKey();

        $verifier = new DnsVerifier();
        $results = [
            'spf' => $verifier->checkSpf($domain, $mode, $smtpHost !== '' ? $smtpHost : null),
            // The DKIM DNS value can't be computed before a key pair
            // exists — there's nothing to publish yet, not even a
            // placeholder. key_missing lets the client show "generate the
            // key first" instead of a broken partial record.
            'dkim' => $hasDkimKey
                ? $verifier->checkDkim($domain, $selector, $this->dkimManager->getPublicKey())
                : ['exists' => false, 'expected' => null, 'actual' => null, 'key_missing' => true],
            'dmarc' => $verifier->checkDmarc($domain, (string) $request->getQuery('dmarc_email', '')),
        ];

        return $this->json($results);
    }

    /**
     * POST /setup/generate-dkim-key — AJAX: generate the DKIM key pair on
     * demand, ahead of finishing the rest of setup. DNS propagation can
     * take time, so operators need the public key value early to start
     * configuring it — the wizard used to only generate it as the very
     * last step of handleFirstTimeSetup(), which was too late to be useful
     * for the DNS check on this same page. Idempotent: never regenerates
     * an existing key (that would invalidate whatever DNS record was
     * already published), just returns its public value.
     *
     * @param array<string, string> $params
     */
    public function generateDkimKey(Request $request, array $params): Response
    {
        $gate = $this->denyUnlessTokenVerified();
        if ($gate !== null) {
            return $gate;
        }

        // setup.js reads `message`, not `error` — keep the key, unify the sentence.
        if (!CsrfGuard::validateRequest()) {
            return $this->json(['success' => false, 'message' => self::SESSION_EXPIRED_MESSAGE], 403);
        }

        try {
            if (!$this->dkimManager->hasKey()) {
                $this->dkimManager->generateKey();
            }

            return $this->json(['success' => true, 'public_key' => $this->dkimManager->getPublicKey()]);
        } catch (\Throwable $e) {
            // Whatever OpenSSL says here is English and technical.
            $this->journalService?->log(
                'core',
                'setup_dkim_generation_failed',
                'info',
                'Échec de la génération de la clé DKIM',
                ['error' => $e->getMessage()]
            );

            return $this->json(['success' => false, 'message' => UserFacingMessage::from(
                $e,
                'La clé DKIM n\'a pas pu être générée — vérifiez que l\'extension OpenSSL est active sur le '
                . 'serveur et que le dossier storage/ est accessible en écriture.'
            )], 500);
        }
    }

    /**
     * POST /setup/verify-token — checks a submitted token against token.php
     * (created via FTP by the bootstrap installer, or manually by the
     * operator). Session-based progressive lockout: there is no database
     * to back Core\Security\LoginThrottler's pattern yet at this point
     * (that's the whole reason this gate exists), so failures are tracked
     * per-session instead, with the same tiered escalation shape.
     *
     * @param array<string, string> $params
     */
    public function verifyToken(Request $request, array $params): Response
    {
        if ($this->secretManager->isInitialized()) {
            return $this->redirect('/setup');
        }
        if (($guard = $this->guardCsrf($request, '/setup')) !== null) {
            return $guard;
        }

        $lockedUntil = (int) SessionStore::get('setup_token_locked_until', 0);
        if ($lockedUntil > time()) {
            return $this->redirect('/setup');
        }

        $tokenPath = $this->findTokenFile();
        $expected = $tokenPath !== null ? $this->extractTokenValue((string) @file_get_contents($tokenPath)) : '';
        $submitted = trim((string) $request->getBody('token', ''));

        $valid = $tokenPath !== null && $expected !== '' && $submitted !== '' && hash_equals($expected, $submitted);

        if ($valid) {
            SessionStore::remove('setup_token_attempts', 'setup_token_locked_until', 'setup_token_error');
            SessionStore::set('setup_token_verified', true);
            $this->journalService?->log(
                'core', 'setup_token_verified', 'security', 'Jeton d\'installation vérifié avec succès',
                ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']
            );
            return $this->redirect('/setup');
        }

        $attempts = (int) SessionStore::get('setup_token_attempts', 0) + 1;
        SessionStore::set('setup_token_attempts', $attempts);
        SessionStore::set('setup_token_error', 'Jeton invalide.');

        if ($attempts >= 10) {
            SessionStore::set('setup_token_locked_until', time() + 86400);
            SessionStore::set('setup_token_error', 'Trop de tentatives — nouvel essai possible dans 24 heures.');
        } elseif ($attempts >= 8) {
            SessionStore::set('setup_token_locked_until', time() + 1800);
        } elseif ($attempts >= 6) {
            SessionStore::set('setup_token_locked_until', time() + 300);
        } elseif ($attempts >= 4) {
            SessionStore::set('setup_token_locked_until', time() + 60);
        }

        $this->journalService?->log(
            'core', 'setup_token_failed', 'security', 'Tentative de jeton d\'installation invalide',
            ['ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'attempts' => $attempts]
        );

        return $this->redirect('/setup');
    }

    /**
     * POST /setup/test-email — AJAX: send a test email.
     *
     * @param array<string, string> $params
     */
    public function testEmail(Request $request, array $params): Response
    {
        $gate = $this->denyUnlessTokenVerified();
        if ($gate !== null) {
            return $gate;
        }

        // setup.js reads `message`, not `error` — keep the key, unify the sentence.
        if (!CsrfGuard::validateRequest()) {
            return $this->json(['success' => false, 'message' => self::SESSION_EXPIRED_MESSAGE], 403);
        }

        $recipient = trim((string) $request->getBody('recipient', ''));

        if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['success' => false, 'message' => 'Adresse email invalide.']);
        }

        $secrets = $this->mailSecretsUnderTest($request);

        try {
            // **Le seul envoi du site qui doit partir pour de vrai, même
            // bac à sable armé** (ARCHITECTURE.md §8.63). Ce bouton
            // répond à « ma configuration SMTP fonctionne-t-elle ? » :
            // capturer sa réponse répondrait à une autre question, et y
            // répondrait faux — l'opérateur lirait « envoyé avec succès »
            // sans que rien n'ait été prouvé. D'où le transport `null`,
            // qui est ici une décision et non un oubli ; tout autre point
            // d'entrée passe par CaptureTransportFactory, et un test
            // épingle les deux moitiés de cette règle.
            $mailService = MailServiceFactory::create($secrets, $this->dkimManager, null, $this->journalService);
            $mailService->send(
                to: $recipient,
                subject: 'Email de test',
                bodyHtml: '<p>Ceci est un email de test envoyé depuis la page de configuration.</p><p>Si vous lisez '
                    . 'ceci, votre configuration SMTP fonctionne correctement.</p>',
                bodyText: "Ceci est un email de test envoyé depuis la page de configuration.\n\nSi vous lisez ceci, "
                    . "votre configuration SMTP fonctionne correctement."
            );

            return $this->json(['success' => true, 'message' => 'Email envoyé avec succès.']);
        } catch (\Throwable $e) {
            // The journal entry is MailService's own `mail_send_failed`,
            // written at the point of failure for every send on the site
            // — this one used to write a second entry of its own, with
            // PHPMailer's message unredacted, and an SMTP transcript can
            // echo back the credentials that were tried.
            //
            // What stays here is the answer on screen: MailException and
            // PHPMailer both speak English, and the setup page is read by
            // somebody who has not got the site working yet.
            return $this->json(['success' => false, 'message' => UserFacingMessage::from(
                $e,
                'L\'email de test n\'a pas pu être envoyé — vérifiez le serveur SMTP, le port, et les '
                . 'identifiants saisis ci-dessus.'
            )]);
        }
    }

    /**
     * The mail settings a test send should actually use: **what is in the
     * form**, falling back to what is stored for anything the form did
     * not send.
     *
     * The button underneath says « Teste les paramètres SMTP actuellement
     * saisis ci-dessus (pas encore besoin d'enregistrer) », and on an
     * already-initialised installation that was simply untrue: the whole
     * branch read `secrets.enc` and ignored the form, so changing an SMTP
     * user or password and pressing Test re-tested the OLD credentials —
     * with a green « Email envoyé avec succès » when the old ones still
     * worked, which is the worst possible answer. Testing before saving
     * is the entire point of the button; saving in order to test is how
     * a wrong password gets written down.
     *
     * Every field is pre-filled with the value in force, so an empty one
     * is a deliberate clear and travels as such. The password is the one
     * exception, and only because it is the one field that is NOT
     * pre-filled: empty means « keep the current one », exactly as the
     * label under it says and exactly as saving already behaves.
     *
     * The fallbacks are per key, not all-or-nothing: `short_name`,
     * `mail_from_address`, `mail_from_name` and `dkim_selector` live in
     * the settings table (migrated out of `secrets.enc`), and reading
     * them from the secrets alone is what once made `mail_from_address`
     * come out permanently empty — PHPMailer then refuses the send
     * outright with « Invalid address: (From): ».
     *
     * @return array<string, string>
     */
    private function mailSecretsUnderTest(Request $request): array
    {
        $stored = $this->secretManager->isInitialized() ? $this->secretManager->readSecrets() : [];

        $storedValue = function (string $key) use ($stored): string {
            if (in_array($key, ['short_name', 'mail_from_address', 'mail_from_name', 'dkim_selector'], true)) {
                $fromSettings = (string) ($this->settingService?->get($key) ?? '');
                if ($fromSettings !== '') {
                    return $fromSettings;
                }
            }

            return (string) ($stored[$key] ?? '');
        };

        $secrets = [];
        foreach ([
            'mail_mode',
            'smtp_host',
            'smtp_port',
            'smtp_user',
            'mail_from_address',
            'mail_from_name',
            'short_name',
            'dkim_selector',
        ] as $key) {
            $posted = $request->getBody($key);
            $secrets[$key] = $posted === null ? $storedValue($key) : (string) $posted;
        }

        $password = (string) $request->getBody('smtp_password', '');
        $secrets['smtp_password'] = $password !== '' ? $password : $storedValue('smtp_password');

        return $secrets;
    }

    /**
     * @param array<string, string> $data
     */
    private function handleFirstTimeSetup(array $data): Response
    {
        // Tracks whether THIS run created the master key. generateMasterKey()
        // refuses to overwrite an existing key and throws before creating
        // anything, so after a full reset (which preserves master.key while
        // deleting secrets.enc) this stays false — and cleanupFailedSetup()
        // must NOT delete that preserved key, or every older encrypted backup
        // becomes permanently unreadable (audit M11).
        $masterKeyCreatedThisRun = false;
        try {
            // Generate master key
            $this->secretManager->generateMasterKey();
            $masterKeyCreatedThisRun = true;

            // Generate encryption keys
            $encryptionKey = random_bytes(32);
            $blindIndexKey = random_bytes(32);

            // Generate VAPID keys (Web Push, Core\Notification) — same
            // "generated once at setup, lives in secrets.enc" treatment as
            // the encryption keys above. Validated (with retry) rather
            // than a bare createVapidKeys() call — see VapidKeyPairFactory.
            $vapidKeys = \Core\Notification\VapidKeyPairFactory::createValid();

            // Build secrets array
            $secrets = [
                'db_host' => $data['db_host'],
                'db_port' => (int) $data['db_port'],
                'db_name' => $data['db_name'],
                'db_user' => $data['db_user'],
                'db_password' => $data['db_password'],
                'mail_mode' => $data['mail_mode'],
                'smtp_host' => $data['smtp_host'],
                'smtp_port' => (int) $data['smtp_port'],
                'smtp_user' => $data['smtp_user'],
                'smtp_password' => $data['smtp_password'],
                'encryption_key' => base64_encode($encryptionKey),
                'blind_index_key' => base64_encode($blindIndexKey),
                'vapid_public_key' => $vapidKeys['publicKey'],
                'vapid_private_key' => $vapidKeys['privateKey'],
                // Non-secret settings stored temporarily; migrated to settings table on first boot
                'site_name' => $data['site_name'],
                'short_name' => $data['short_name'],
                'base_url' => $data['base_url'],
                'mail_from_address' => $data['mail_from_address'],
                'mail_from_name' => $data['mail_from_name'],
                'dkim_selector' => $data['dkim_selector'],
                'dmarc_report_email' => $data['dmarc_report_email'],
            ];

            $this->secretManager->writeSecrets($secrets);

            // Test DB connection with actual secrets
            $connection = new Connection(
                $secrets['db_host'],
                $secrets['db_port'],
                $secrets['db_name'],
                $secrets['db_user'],
                $secrets['db_password']
            );

            $testResult = $connection->testConnection();
            if ($testResult !== true) {
                $this->cleanupFailedSetup($masterKeyCreatedThisRun);
                // testConnection() now returns a complete French sentence of
                // its own (it used to return the raw PDO message, which this
                // line prefixed and displayed) — no prefix to add.
                FlashMessage::set('error', $testResult);
                return $this->redirect('/setup');
            }

            // Generate DKIM key — unless one was already generated ahead
            // of time via the "Générer maintenant" button (generateKey()
            // throws if a key already exists, since regenerating would
            // silently invalidate whatever DNS record the operator may
            // already have started configuring from that earlier key).
            if (!$this->dkimManager->hasKey()) {
                $this->dkimManager->generateKey();
            }

            // Run migration
            $introspector = new SchemaIntrospector($connection->getPdo());
            $runner = new MigrationRunner(
                $connection,
                $introspector,
                new SchemaComparator(),
                new SqlParser()
            );
            $runner->migrate($this->schemaFileSet());

            // Store admin email in secrets for auto-repair
            $secrets['admin_email'] = strtolower(trim($data['admin_email']));
            $this->secretManager->writeSecrets($secrets);

            // Create initial admin account (base64 keys decoded to match the boot sequence)
            $this->createAdminAccount($connection, $secrets['encryption_key'], $secrets['blind_index_key'],
                $data['admin_email'], $data['admin_password']);

            // Record the installation date now, while "now" is genuinely the
            // moment this site came into existence (Core\Statistics\
            // InstallationDateService). Waiting for the boot backfill would
            // work too, but only because the journal is still empty at this
            // point — recording it here keeps the date correct even if a
            // first request writes a journal entry before the backfill runs.
            $setupSettingService = new SettingService(new SettingRepository($connection->getPdo()));
            InstallationDateService::register($setupSettingService);
            (new InstallationDateService($setupSettingService, $connection->getPdo()))->ensureRecorded();

            // Honour the operator's usage-statistics choice straight away.
            // Registered here rather than waiting for the composition root's
            // own register() call, which would otherwise create the row with
            // its default (on) and silently ignore an operator who unchecked
            // the box.
            $setupSettingService->register('statistics_enabled', '1', 'boolean', 'Envoi automatique des statistiques '
                . 'd\'utilisation',
                'Autorise l\'envoi quotidien d\'un rapport d\'utilisation agrégé vers ScoutMagic. Le rapport contient '
                    . 'l\'adresse de ce site, jamais de donnée de membre. Géré depuis la page Support.',
                null, null, null, true, 280);
            $setupSettingService->setInternal('statistics_enabled', $data['statistics_enabled']);

            $tokenDeleted = $this->deleteTokenFileWithWarning();

            FlashMessage::set(
                $tokenDeleted ? 'success' : 'warning',
                $tokenDeleted
                    ? 'Installation terminée avec succès. Bienvenue !'
                    : 'Installation terminée avec succès, mais token.php n\'a pas pu être supprimé automatiquement — '
                        . 'retirez-le manuellement via FTP pour des raisons de sécurité.'
            );
            return $this->redirect('/');
        } catch (\Throwable $e) {
            $this->cleanupFailedSetup($masterKeyCreatedThisRun);
            // error_log() rather than the journal: what is being handled here
            // is most often the database itself refusing, and a journal write
            // would throw a second exception inside this catch block and turn
            // a flash message into a fatal.
            error_log('ScoutMagic setup failed: ' . $e->getMessage());
            FlashMessage::set('error', 'Erreur lors de l\'installation : ' . UserFacingMessage::from(
                $e,
                'l\'installation n\'a pas pu être terminée. Vérifiez les paramètres de la base de données et '
                . 'les droits d\'écriture sur storage/, puis réessayez.'
            ));
            return $this->redirect('/setup');
        }
    }

    /**
     * @param array<string, string> $data
     */
    private function handleConfigUpdate(array $data, Request $request): Response
    {
        try {
            $currentSecrets = $this->secretManager->readSecrets();

            // Merge new values (keep passwords if not provided)
            $currentSecrets['db_host'] = $data['db_host'];
            $currentSecrets['db_port'] = (int) $data['db_port'];
            $currentSecrets['db_name'] = $data['db_name'];
            $currentSecrets['db_user'] = $data['db_user'];
            if ($data['db_password'] !== '') {
                $currentSecrets['db_password'] = $data['db_password'];
            }
            $currentSecrets['mail_mode'] = $data['mail_mode'];
            $currentSecrets['smtp_host'] = $data['smtp_host'];
            $currentSecrets['smtp_port'] = (int) $data['smtp_port'];
            $currentSecrets['smtp_user'] = $data['smtp_user'];
            if ($data['smtp_password'] !== '') {
                $currentSecrets['smtp_password'] = $data['smtp_password'];
            }
            $this->secretManager->writeSecrets($currentSecrets);

            // Write non-secret settings to settings table
            if ($this->settingService !== null) {
                $nonSecretKeys = ['site_name', 'short_name', 'base_url', 'mail_from_address', 'mail_from_name',
                    'dkim_selector', 'dmarc_report_email'];
                foreach ($nonSecretKeys as $nsKey) {
                    if (isset($data[$nsKey])) {
                        try {
                            $this->settingService->set($nsKey, $data[$nsKey]);
                        } catch (\Throwable $e) {
                            // Setting may not be registered yet during initial setup
                        }
                    }
                }
                $this->settingService->clearCache();
            }

            // Regenerate DKIM key if requested
            if ($request->getBody('regenerate_dkim') === '1') {
                $this->dkimManager->deleteKey();
                $this->dkimManager->generateKey();
            }

            // Run migration
            $connection = new Connection(
                $currentSecrets['db_host'],
                (int) $currentSecrets['db_port'],
                $currentSecrets['db_name'],
                $currentSecrets['db_user'],
                $currentSecrets['db_password']
            );

            $testResult = $connection->testConnection();
            if ($testResult === true) {
                $introspector = new SchemaIntrospector($connection->getPdo());
                $runner = new MigrationRunner(
                    $connection,
                    $introspector,
                    new SchemaComparator(),
                    new SqlParser()
                );
                $runner->migrate($this->schemaFileSet());
            }

            // Create or update admin account if provided
            if ($data['admin_email'] !== '' && $data['admin_password'] !== '') {
                $this->upsertAdminAccount($connection, $currentSecrets, $data['admin_email'], $data['admin_password']);
            }

            $this->journalService?->log(
                'core', 'setup_completed', 'security', 'Configuration du site enregistrée',
                ['ip' => $_SERVER['REMOTE_ADDR'] ?? ''],
                AuthSession::getUserAccountId()
            );

            FlashMessage::set('success', 'Configuration enregistrée avec succès.');
            return $this->redirect('/setup');
        } catch (\Throwable $e) {
            // Same reasoning as handleFirstTimeSetup(): the failure being
            // reported is frequently the database, so the detail goes to the
            // PHP error log rather than through a journal INSERT.
            error_log('ScoutMagic setup configuration save failed: ' . $e->getMessage());
            FlashMessage::set('error', 'Erreur lors de la sauvegarde : ' . UserFacingMessage::from(
                $e,
                'la configuration n\'a pas pu être enregistrée. Vérifiez les paramètres de la base de données '
                . 'et les droits d\'écriture sur storage/, puis réessayez.'
            ));
            return $this->redirect('/setup');
        }
    }

    /**
     * @return array<string, string>
     */
    private function collectFormData(Request $request): array
    {
        return [
            'db_host' => trim((string) $request->getBody('db_host', 'localhost')),
            'db_port' => trim((string) $request->getBody('db_port', '3306')),
            'db_name' => trim((string) $request->getBody('db_name', '')),
            'db_user' => trim((string) $request->getBody('db_user', '')),
            'db_password' => (string) $request->getBody('db_password', ''),
            'site_name' => trim((string) $request->getBody('site_name', '')),
            'short_name' => trim((string) $request->getBody('short_name', '')),
            'base_url' => trim((string) $request->getBody('base_url', '')),
            'mail_mode' => (string) $request->getBody('mail_mode', 'smtp'),
            'smtp_host' => trim((string) $request->getBody('smtp_host', '')),
            'smtp_port' => trim((string) $request->getBody('smtp_port', '587')),
            'smtp_user' => trim((string) $request->getBody('smtp_user', '')),
            'smtp_password' => (string) $request->getBody('smtp_password', ''),
            'mail_from_address' => trim((string) $request->getBody('mail_from_address', '')),
            'mail_from_name' => trim((string) $request->getBody('mail_from_name', '')),
            'dkim_selector' => trim((string) $request->getBody('dkim_selector', '')),
            'dmarc_report_email' => trim((string) $request->getBody('dmarc_report_email', '')),
            'admin_email' => trim((string) $request->getBody('admin_email', '')),
            'admin_password' => (string) $request->getBody('admin_password', ''),
            'admin_password_confirm' => (string) $request->getBody('admin_password_confirm', ''),
            // Usage statistics switch, first install only (Core\Statistics,
            // ARCHITECTURE.md §8.47). An unchecked checkbox sends nothing at
            // all, so "absent" has to mean "off" — the default-on state lives
            // in the rendered form, not here.
            'statistics_enabled' => (string) $request->getBody('statistics_enabled', '') === '1' ? '1' : '0',
        ];
    }

    /**
     * @param array<string, string> $data
     * @return array<string, string>
     */
    private function validateFormData(array $data, bool $isFirstTime): array
    {
        $errors = [];

        // Database fields
        if ($data['db_host'] === '') {
            $errors['db_host'] = 'L\'hôte de la base de données est requis.';
        }
        if ($data['db_port'] === '' || (int) $data['db_port'] < 1 || (int) $data['db_port'] > 65535) {
            $errors['db_port'] = 'Le port doit être compris entre 1 et 65535.';
        }
        if ($data['db_name'] === '') {
            $errors['db_name'] = 'Le nom de la base de données est requis.';
        }
        if ($data['db_user'] === '') {
            $errors['db_user'] = 'L\'utilisateur de la base de données est requis.';
        }
        if ($isFirstTime && $data['db_password'] === '') {
            $errors['db_password'] = 'Le mot de passe de la base de données est requis.';
        }

        // General settings
        if ($data['site_name'] === '') {
            $errors['site_name'] = 'Le nom de l\'unité est requis.';
        }
        if ($data['short_name'] === '') {
            $errors['short_name'] = 'Le nom court est requis.';
        } elseif (!preg_match('/^[A-Za-z0-9]{1,5}$/', $data['short_name'])) {
            $errors['short_name'] = 'Le nom court doit contenir 1 à 5 caractères alphanumériques.';
        }
        if ($data['base_url'] === '') {
            $errors['base_url'] = 'L\'URL de base est requise.';
        } elseif (!filter_var($data['base_url'], FILTER_VALIDATE_URL)) {
            $errors['base_url'] = 'L\'URL de base n\'est pas valide.';
        }

        // Email settings
        if (!in_array($data['mail_mode'], ['smtp', 'local'], true)) {
            $errors['mail_mode'] = 'Le mode d\'envoi doit être SMTP ou Local.';
        }
        if ($data['mail_mode'] === 'smtp') {
            if ($data['smtp_host'] === '') {
                $errors['smtp_host'] = 'L\'hôte SMTP est requis en mode SMTP.';
            }
            if ($data['smtp_port'] === '' || (int) $data['smtp_port'] < 1 || (int) $data['smtp_port'] > 65535) {
                $errors['smtp_port'] = 'Le port SMTP doit être compris entre 1 et 65535.';
            }
            if ($data['smtp_user'] === '') {
                $errors['smtp_user'] = 'L\'utilisateur SMTP est requis en mode SMTP.';
            }
            if ($isFirstTime && $data['smtp_password'] === '') {
                $errors['smtp_password'] = 'Le mot de passe SMTP est requis en mode SMTP.';
            }
        }
        if ($data['mail_from_address'] === '') {
            $errors['mail_from_address'] = 'L\'adresse d\'expédition est requise.';
        } elseif (!filter_var($data['mail_from_address'], FILTER_VALIDATE_EMAIL)) {
            $errors['mail_from_address'] = 'L\'adresse d\'expédition n\'est pas valide.';
        }
        if ($data['mail_from_name'] === '') {
            $errors['mail_from_name'] = 'Le nom d\'expédition est requis.';
        }
        if ($data['dkim_selector'] === '') {
            $errors['dkim_selector'] = 'Le sélecteur DKIM est requis.';
        } elseif (!preg_match('/^[a-z0-9]+$/', $data['dkim_selector'])) {
            $errors['dkim_selector'] = 'Le sélecteur DKIM ne doit contenir que des lettres minuscules et des chiffres.';
        }
        // Optional: left empty, DNS guidance and any future real use fall
        // back to mail_from_address (see setup.js) — no need to force a
        // choice this early, and it stays editable later.
        if ($data['dmarc_report_email'] !== '' && !filter_var($data['dmarc_report_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['dmarc_report_email'] = 'L\'email pour les rapports DMARC n\'est pas valide.';
        }

        // Admin email and password
        if ($isFirstTime) {
            // Required on first-time setup
            if ($data['admin_email'] === '') {
                $errors['admin_email'] = 'L\'email administrateur est requis.';
            } elseif (!filter_var($data['admin_email'], FILTER_VALIDATE_EMAIL)) {
                $errors['admin_email'] = 'L\'email administrateur n\'est pas valide.';
            }
            if ($data['admin_password'] === '') {
                $errors['admin_password'] = 'Le mot de passe administrateur est requis.';
            } else {
                $violation = self::passwordPolicyError($data['admin_password'],
                    (string) ($data['admin_password_confirm'] ?? ''));
                if ($violation !== null) {
                    $errors['admin_password'] = $violation;
                }
            }
        } else {
            // Optional on config update — validate only if provided
            if ($data['admin_email'] !== '' && !filter_var($data['admin_email'], FILTER_VALIDATE_EMAIL)) {
                $errors['admin_email'] = 'L\'email administrateur n\'est pas valide.';
            }
            // Password is only required when changing admin email to a new address without existing account
            if ($data['admin_password'] !== '') {
                $violation = self::passwordPolicyError($data['admin_password'],
                    (string) ($data['admin_password_confirm'] ?? ''));
                if ($violation !== null) {
                    $errors['admin_password'] = $violation;
                }
            }
            if ($data['admin_password'] !== '' && $data['admin_email'] === '') {
                $errors['admin_email'] = 'L\'email administrateur est requis.';
            }
        }

        return $errors;
    }

    /**
     * The site's own password rules, applied to the super-admin's.
     *
     * This form used to accept eight characters of anything, on the one
     * screen that creates the account with the most authority on the whole
     * install — while the account page and the password-reset page both
     * enforced Core\Security\PasswordPolicy. Asking the policy rather than
     * re-stating it is what keeps the three from drifting again.
     *
     * @return string|null A French message, or null when the password is fine.
     */
    private static function passwordPolicyError(string $password, string $confirmation): ?string
    {
        $violations = PasswordPolicy::violations($password);
        if ($violations !== []) {
            $labels = array_map(
                static fn(string $rule) => mb_strtolower(PasswordPolicy::RULES[$rule] ?? $rule),
                $violations
            );

            return 'Le mot de passe ne respecte pas les règles : ' . implode(', ', $labels) . '.';
        }

        // Checked here as well as in the browser: a confirmation field only
        // the page enforces is a typo waiting to lock somebody out of their
        // own fresh install.
        if ($confirmation !== '' && $confirmation !== $password) {
            return 'Les deux mots de passe ne correspondent pas.';
        }

        return null;
    }

    private function createAdminAccount(
        Connection $connection,
        string $encryptionKey,
        string $blindIndexKey,
        string $email,
        string $password
    ): void
    {
        // $encryptionKey/$blindIndexKey are the base64-encoded values stored in
        // secrets.enc — decode to raw bytes exactly as the boot sequence does
        // (audit M1), so the admin row is encrypted with the same real key.
        $encryptionService = EncryptionService::fromEncodedKeys($encryptionKey, $blindIndexKey);
        $normalizedEmail = strtolower(trim($email));

        $emailEncrypted = $encryptionService->encrypt($normalizedEmail, 'user_accounts.email');
        $emailBlindIndex = $encryptionService->blindIndex($normalizedEmail, 'email');
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $pdo = $connection->getPdo();
        $stmt = $pdo->prepare(
            'INSERT INTO user_accounts (email_encrypted, email_blind_index, password_hash, is_super_admin) VALUES (?, '
                . '?, ?, TRUE)'
        );
        $stmt->execute([$emailEncrypted, $emailBlindIndex, $passwordHash]);
    }

    /**
     * Create or update the admin account during config update.
     *
     * @param array<string, mixed> $secrets
     */
    private function upsertAdminAccount(Connection $connection, array $secrets, string $email, string $password): void
    {
        $encryptionService = EncryptionService::fromEncodedKeys(
            (string) $secrets['encryption_key'],
            (string) $secrets['blind_index_key']
        );
        $normalizedEmail = strtolower(trim($email));
        $blindIndex = $encryptionService->blindIndex($normalizedEmail, 'email');
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $pdo = $connection->getPdo();

        // Check if account already exists
        $stmt = $pdo->prepare('SELECT id FROM user_accounts WHERE email_blind_index = ?');
        $stmt->execute([$blindIndex]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($existing !== false) {
            // Update password and ensure super admin
            $stmt = $pdo->prepare('UPDATE user_accounts SET password_hash = ?, is_super_admin = TRUE WHERE id = ?');
            $stmt->execute([$passwordHash, $existing['id']]);
        } else {
            // Create new admin account
            $emailEncrypted = $encryptionService->encrypt($normalizedEmail, 'user_accounts.email');
            $stmt = $pdo->prepare(
                'INSERT INTO user_accounts (email_encrypted, email_blind_index, password_hash, is_super_admin) VALUES '
                    . '(?, ?, ?, TRUE)'
            );
            $stmt->execute([$emailEncrypted, $blindIndex, $passwordHash]);
        }

        // Store admin email in secrets
        $secrets['admin_email'] = $normalizedEmail;
        $this->secretManager->writeSecrets($secrets);
    }

    private function cleanupFailedSetup(bool $masterKeyCreatedThisRun): void
    {
        // Remove generated files on failure
        $masterKeyPath = (new \ReflectionClass($this->secretManager))->getProperty('masterKeyPath');
        $secretsPath = (new \ReflectionClass($this->secretManager))->getProperty('secretsPath');

        $masterKey = $masterKeyPath->getValue($this->secretManager);
        $secrets = $secretsPath->getValue($this->secretManager);

        // Only delete the master key if THIS run created it. A key that
        // pre-existed (e.g. preserved across a full reset) is the sole means
        // of decrypting older backups — never destroy it just because setup
        // failed after finding it already there (audit M11).
        if ($masterKeyCreatedThisRun && file_exists($masterKey)) {
            @unlink($masterKey);
        }
        if (file_exists($secrets)) {
            @unlink($secrets);
        }
        $this->dkimManager->deleteKey();
    }

    /**
     * The same pre-init barrier as checkTokenGate(), for every OTHER setup
     * endpoint — the ones that actually read credentials, touch the
     * database or write secrets.enc.
     *
     * checkTokenGate() only ever guarded the GET /setup wizard page, which
     * left the write endpoints behind nothing but a CSRF token — and a CSRF
     * token is not an authentication barrier here: the gate screen itself
     * hands a valid one to any anonymous visitor (it has to, so the token
     * form can post). A stranger could therefore GET /setup, take the token
     * from the gate page, POST straight to /setup/save with their own admin
     * email and database, and own a freshly-deployed site outright, without
     * ever knowing what is in token.php.
     *
     * Returns null when the request may proceed, otherwise the refusal to
     * send back — as JSON for the AJAX endpoints, as a plain 403 body for
     * the form/file ones (matching each caller's own CSRF rejection).
     */
    private function denyUnlessTokenVerified(bool $asJson = true): ?Response
    {
        // Once initialized, /setup is an ordinary superadmin-only
        // configuration page (see the routes in public/index.php) — the
        // token file is long gone by then and RBAC is the real boundary.
        if ($this->secretManager->isInitialized()) {
            return null;
        }

        if (SessionStore::get('setup_token_verified', false) === true) {
            return null;
        }

        $this->journalService?->log(
            'core', 'setup_token_gate_blocked', 'security',
            'Accès à un point d\'entrée d\'installation sans jeton vérifié',
            ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']
        );

        if ($asJson) {
            return $this->json(
                ['success' => false, 'message' => 'Jeton d\'installation requis.'],
                403
            );
        }

        return (new Response('', 403))->setBody('Forbidden: installation token required.');
    }

    /**
     * Gates GET /setup while the site isn't initialized: no token.php,
     * no wizard — ever. Returns null when access is already granted this
     * session, otherwise a Response for whichever gate screen applies.
     */
    private function checkTokenGate(): ?Response
    {
        if (SessionStore::get('setup_token_verified', false) === true) {
            return null;
        }

        $tokenPath = $this->findTokenFile();

        if ($tokenPath === null) {
            $example = bin2hex(random_bytes(32));
            return $this->render('setup/token_gate.html.twig', [
                'state' => 'missing',
                'example_content' => "<?php /* TOKEN: {$example} */\n",
            ]);
        }

        $lockedUntil = (int) SessionStore::get('setup_token_locked_until', 0);
        if ($lockedUntil > time()) {
            return $this->render('setup/token_gate.html.twig', [
                'state' => 'locked',
                'retry_after' => $lockedUntil - time(),
            ]);
        }

        $error = SessionStore::get('setup_token_error');
        SessionStore::remove('setup_token_error');

        return $this->render('setup/token_gate.html.twig', [
            'state' => 'form',
            'csrf_token' => CsrfGuard::generateToken(),
            'error' => $error,
        ]);
    }

    /**
     * token.php always lives in the HTTP document root, which sits at a
     * different relative position from this controller depending on which
     * layout bootstrap.php chose (Layout A: same directory as index.php;
     * Layout B: one level above public/) — see bootstrap/bootstrap.php's
     * own layout-selection comments. Checking both fixed candidates is a
     * narrow exception scoped to this one gate file, not a general
     * path-resolution abstraction (ARCHITECTURE.md §9's governing
     * constraint is about not teaching the rest of the codebase about
     * hosting layouts, which this doesn't).
     *
     * @return string[]
     */
    private function candidateTokenPaths(): array
    {
        $candidates = [];
        if ($this->publicDir !== '') {
            $candidates[] = rtrim($this->publicDir, '/') . '/token.php';
            $candidates[] = dirname(rtrim($this->publicDir, '/')) . '/token.php';
        }

        return array_values(array_unique($candidates));
    }

    private function findTokenFile(): ?string
    {
        foreach ($this->candidateTokenPaths() as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private function extractTokenValue(string $fileContent): string
    {
        if (preg_match('/TOKEN:\s*([0-9a-f]+)/i', $fileContent, $matches) === 1) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Deletes token.php once first-time setup genuinely completes — not at
     * token verification (which only unlocks the form), matching
     * bootstrap.php's own framing of token.php as gating the whole wizard,
     * not just its first screen.
     */
    private function deleteTokenFileWithWarning(): bool
    {
        $tokenPath = $this->findTokenFile();
        if ($tokenPath === null) {
            return true;
        }

        if (@unlink($tokenPath)) {
            return true;
        }

        $this->journalService?->log(
            'core', 'setup_token_delete_failed', 'security',
            'Impossible de supprimer token.php après l\'installation — à retirer manuellement via FTP.',
            []
        );

        return false;
    }

    /**
     * First-time setup only: the site is almost always already reachable
     * at the URL the operator is filling in this form from (the whole
     * point of an FTP-uploaded installer is that DNS/hosting are already
     * pointed here) — HTTPS detection goes through Core\Http\
     * RequestScheme like every other call site. Still an ordinary
     * editable field, just pre-filled instead of blank.
     */
    private function resolveDefaultBaseUrl(Request $request): string
    {
        $host = (string) $request->getServer('HTTP_HOST', '');
        if ($host === '') {
            return '';
        }

        return ($request->isHttps() ? 'https://' : 'http://') . $host;
    }
}
