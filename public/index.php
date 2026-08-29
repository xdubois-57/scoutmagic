<?php

declare(strict_types=1);

// Marks this process as a real entry point, so the shared composition
// files it requires (public/scheduler-bootstrap.php) refuse to run when
// hit directly over HTTP.
define('SCOUTMAGIC_ENTRYPOINT', true);

$composerAutoloader = require_once __DIR__ . '/../vendor/autoload.php';

// The application's wall clock is Belgian, because its users are — and the
// database agrees with it, connection by connection. Read Core\Config\
// AppClock's docblock before touching either half: setting a local default
// timezone without the session-timezone alignment silently disarms every
// rate limiter in the tree. First thing after the autoloader, so even the
// error handler's own timestamps are on it.
\Core\Config\AppClock::apply();

// Arm the last-resort error handler before anything else runs — including
// the config load and the database connect, both of which can throw and
// would otherwise print a stack trace (with the DB password in a PDO frame)
// on a host with display_errors on. Re-armed with the real debug flag once
// AppConfig is available (see below).
\Core\Http\ErrorHandler::register(false);

// Self-healing safety net for the "Update from GitHub" auto-update path
// (Core\Maintenance\Task\InstallUpdateHandler), which copies tracked
// repository files over the live install but never runs `composer
// install`/`composer dump-autoload` — vendor/ is git-ignored, entirely
// outside its reach, and this app targets fully-managed hosting where a
// shell to run composer manually may not even exist. Without this, a new
// module's very first commit (which always adds a PSR-4 entry to
// composer.json) 500s with "Class not found" on every host whose vendor/
// predates that commit. See Core\System\ComposerAutoloadSync's own
// docblock for the full story.
\Core\System\ComposerAutoloadSync::apply($composerAutoloader, __DIR__ . '/../composer.json');

use Core\Badge\BadgeRepository;
use Core\Badge\BadgeService;
use Core\Badge\MemberBadgeRepository;
use Core\Config\AppConfig;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Cookie\CookieConsentService;
use Core\Database\Connection;
use Core\Database\MigrationRunner;
use Core\Database\SchemaComparator;
use Core\Database\SchemaIntrospector;
use Core\Database\SqlParser;
use Core\File\FileAccessGuard;
use Core\File\FileRepository;
use Core\File\UploadHandler;
use Core\Photo\AccountPhotoRepository;
use Core\Photo\AccountPhotoService;
use Core\Photo\ImageVariantProcessor;
use Core\Photo\ImageVariantService;
use Core\Photo\LandscapeImageProcessor;
use Core\Photo\MemberPhotoRepository;
use Core\Photo\MemberPhotoService;
use Core\Photo\SectionPhotoProcessor;
use Core\Photo\SectionPhotoRepository;
use Core\Photo\SectionPhotoService;
use Core\Config\ScoutYearService;
use Core\Http\Controller\AccountController;
use Core\Http\Controller\AuthController;
use Core\Http\Controller\PasswordResetController;
use Core\Http\Controller\ConfigGeneralController;
use Core\Http\Controller\ConfigModulesController;
use Core\Http\Controller\ConfigBadgesController;
use Core\Http\Controller\RgpdConfigController;
use Core\Http\Controller\FunctionsController;
use Core\Http\Controller\CookieController;
use Core\Http\Controller\ConfigModeController;
use Core\Http\Controller\EditableContentController;
use Core\Http\Controller\FileController;
use Core\Http\Controller\ImportController;
use Core\Http\Controller\JournalController;
use Core\Http\Controller\MemberController;
use Core\Http\Controller\MaintenanceController;
use Core\Http\Controller\OfflineController;
use Core\Http\Controller\PageController;
use Core\Http\Controller\PlaceholderController;
use Core\Http\Controller\PushSubscriptionController;
use Core\Http\Controller\ScheduledActionsController;
use Core\Http\Controller\ScoutYearController;
use Core\Http\Controller\SettingsController;
use Core\Http\Controller\SetupController;
use Core\Http\Controller\SupportController;
use Core\Http\Controller\ShortUrlController;
use Core\Http\Controller\StaffsController;
use Core\Http\Controller\UploadController;
use Core\Http\Controller\VersionController;
use Core\Pdf\PosterPdfService;
use Core\Url\ShortUrlRepository;
use Core\Url\ShortUrlService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Maintenance\BackupRepository;
use Core\Maintenance\BackupService;
use Core\Module\ModuleManager;
use Core\Module\ModuleRegistryRepository;
use Core\Notification\NotificationPreferenceRepository;
use Core\Notification\NotificationRepository;
use Core\Notification\NotificationService;
use Core\Notification\PushSubscriptionRepository;
use Core\Offline\OfflineWhitelist;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerRunner;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Import\AgeBranchRepository;
use Core\Import\DeskCsvParser;
use Core\Import\DeskImportService;
use Core\Import\FeeCategoryRepository;
use Core\Import\FunctionRepository;
use Core\Import\ImportJournalRepository;
use Core\Import\ImportSectionRepository;
use Core\Import\MappingResolver;
use Core\Import\MemberRepository;
use Core\Import\MemberYearRepository;
use Core\Member\Controller\MemberSearchController;
use Core\Member\Controller\TemporaryMemberController;
use Core\Member\MemberService;
use Core\Member\MemberYearService;
use Core\Member\Repository\MemberSearchRepository;
use Core\Member\Service\MemberSearchService;
use Core\Member\SectionService;
use Core\Member\UnitStaffSectionService;
use Core\ScoutYear\ScoutYearAdminService;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\RoleResolver;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Router;
use Core\Mail\DkimManager;
use Core\Mail\MailServiceFactory;
use Core\Security\AuthService;
use Core\Security\PasswordResetService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Core\Security\LoginThrottler;
use Core\Security\PasswordAuthMethod;
use Core\Security\Role;
use Core\Security\SecretManager;
use Core\Security\SessionManager;
use Core\Security\UserAccountRepository;
use Core\Security\WebAuthnCredentialRepository;
use Core\Security\WebAuthnService;
use Core\Notification\VapidKeyPairFactory;
use Minishlink\WebPush\WebPush;
use Twig\TwigFunction;
use Core\View\ConfigurationMode;
use Core\View\DynamicMenuRegistrar;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Core\View\RgpdContentService;
use Core\View\MenuBuilder;
use Core\View\SectionRepository;
use Core\View\TwigFactory;

// Load configuration
$config = new AppConfig(__DIR__ . '/../config/app.php');
\Core\Http\ErrorHandler::register($config->isDebug());

// Whether X-Forwarded-Proto may be believed. Configured here, once, before
// anything emits a cookie, a session or a security header — Core\Http\
// RequestScheme is the single source of truth every one of those consults.
\Core\Http\RequestScheme::setTrustForwardedProto((bool) $config->get('trust_forwarded_proto', false));

// Generate per-request CSP nonce
$cspNonce = base64_encode(random_bytes(16));

// Create Twig environment
$twig = TwigFactory::create(
    __DIR__ . '/../core/View/templates',
    $config->isDebug()
);
$twig->addGlobal('csp_nonce', $cspNonce);

// site_name will be set later from settings database

// Create SecretManager and check initialization
$secretManager = new SecretManager(
    __DIR__ . '/../storage/keys/master.key',
    __DIR__ . '/../storage/config/secrets.enc'
);

$dkimManager = new DkimManager(__DIR__ . '/../storage/keys');
$schemaPath = __DIR__ . '/../schema/core.sql';

// Create the request early to check the path
$request = Request::fromGlobals();
\Core\Debug\RequestTimeline::mark('request_parsed', ['path' => $request->getPath()]);

// A POST whose body exceeded post_max_size arrives with $_POST/$_FILES
// both silently emptied by PHP — caught here, before the rest of the
// bootstrap (session/settings aren't loaded yet), with a plain-HTML
// response so it never falls through to a confusing "invalid CSRF
// token" error further down the request lifecycle.
if (Request::isPostTooLarge()) {
    http_response_code(413);
    // Emit the same security header set as every routed response — an error
    // page is not an excuse to drop CSP/X-Frame-Options/nosniff (audit
    // hardening). This page has no inline script; its inline style attribute
    // is covered by the CSP's style-src-attr 'unsafe-inline' (an
    // attribute, not a <style> element — the two are separate directives
    // now, see Core\Http\Response::buildStyleSrc()).
    foreach ((new \Core\Http\Response(''))->getSecurityHeaders() as $hName => $hValue) {
        header("{$hName}: {$hValue}");
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>Fichier trop volumineux</title></head>'
        . '<body style="font-family:sans-serif;max-width:640px;margin:4rem auto;padding:0 1rem;">'
        . '<h1>Fichier trop volumineux</h1>'
        . '<p>Le fichier envoyé dépasse la taille maximale autorisée par le serveur. '
        . 'Réessayez avec un fichier plus petit, puis revenez en arrière dans votre navigateur pour ne pas perdre votre saisie.</p>'
        . '</body></html>';
    exit;
}

$isInitialized = $secretManager->isInitialized();
$isSetupRoute = str_starts_with($request->getPath(), '/setup');

// Start session for setup routes or when initialized
if ($isInitialized || $isSetupRoute) {
    \Core\Debug\RequestTimeline::mark('session_start_begin');
    SessionManager::start();
    \Core\Debug\RequestTimeline::mark('session_start_done');
}

if (!$isInitialized) {
    // Site not initialized: only allow /setup routes
    if (!$isSetupRoute) {
        // Don't redirect asset requests — return 404 for files with extensions
        if (preg_match('/\.\w{2,4}$/', $request->getPath())) {
            (new Response('', 404))->send();
            exit;
        }
        (new Response('', 302))->setHeader('Location', '/setup')->send();
        exit;
    }

    // Handle setup routes
    $setupController = new SetupController($twig, $secretManager, $dkimManager, $schemaPath, __DIR__);

    if ($request->getMethod() === 'GET' && $request->getPath() === '/setup') {
        $response = $setupController->index($request, []);
    } elseif ($request->getMethod() === 'POST' && $request->getPath() === '/setup/verify-token') {
        $response = $setupController->verifyToken($request, []);
    } elseif ($request->getMethod() === 'POST' && $request->getPath() === '/setup/test-db') {
        $response = $setupController->testDatabase($request, []);
    } elseif ($request->getMethod() === 'POST' && $request->getPath() === '/setup/install-database') {
        $response = $setupController->installDatabase($request, []);
    } elseif ($request->getMethod() === 'POST' && $request->getPath() === '/setup/backup-and-empty-db') {
        $response = $setupController->backupAndEmptyDatabase($request, []);
    } elseif ($request->getMethod() === 'GET' && $request->getPath() === '/setup/download-backup') {
        $response = $setupController->downloadBackup($request, []);
    } elseif ($request->getMethod() === 'POST' && $request->getPath() === '/setup/save') {
        $response = $setupController->save($request, []);
    } elseif ($request->getMethod() === 'GET' && $request->getPath() === '/setup/dns') {
        $response = $setupController->checkDns($request, []);
    } elseif ($request->getMethod() === 'POST' && $request->getPath() === '/setup/generate-dkim-key') {
        $response = $setupController->generateDkimKey($request, []);
    } elseif ($request->getMethod() === 'POST' && $request->getPath() === '/setup/test-email') {
        $response = $setupController->testEmail($request, []);
    } else {
        (new Response('', 302))->setHeader('Location', '/setup')->send();
        exit;
    }

    $response->setCspNonce($cspNonce);
    $response->send();
    exit;
}

// Load secrets and create services
$secrets = $secretManager->readSecrets();

// Self-heal VAPID keys (Web Push, Core\Notification) for installs that
// completed setup before this feature existed — SetupController also
// generates these for brand-new installs, making this a no-op there.
// Also self-heals a key pair that was persisted but never actually valid
// (observed in the wild: VAPID::createVapidKeys() intermittently produced
// a key VAPID::validate() itself rejects, taking down every page load via
// WebPush's constructor) — every future request repairs it automatically
// once this check ships, no manual intervention needed on an already-
// broken install.
if (!VapidKeyPairFactory::isValid(
    (string) ($secrets['vapid_public_key'] ?? ''),
    (string) ($secrets['vapid_private_key'] ?? '')
)) {
    $vapidKeys = VapidKeyPairFactory::createValid();
    $secrets['vapid_public_key'] = $vapidKeys['publicKey'];
    $secrets['vapid_private_key'] = $vapidKeys['privateKey'];
    $secretManager->writeSecrets($secrets);
}

// site_name from secrets used as fallback during settings migration
$siteName = $secrets['site_name'] ?? 'Unité scoute';

$connection = new Connection(
    $secrets['db_host'] ?? 'localhost',
    (int) ($secrets['db_port'] ?? 3306),
    $secrets['db_name'] ?? '',
    $secrets['db_user'] ?? '',
    $secrets['db_password'] ?? ''
);

$encryptionService = EncryptionService::fromEncodedKeys(
    (string) ($secrets['encryption_key'] ?? ''),
    (string) ($secrets['blind_index_key'] ?? '')
);

// Release the session file lock before the heavy work (database
// connection, schema migration, service initialization). PHP's file-based
// sessions hold an exclusive lock for the entire script lifetime;
// migration alone can take 20+ seconds on a first run, and every other
// request from the same user (another tab, a background fetch, the next
// click) would block at session_start() for that entire duration.
// session_write_close() flushes $_SESSION to disk and releases the lock,
// but $_SESSION stays readable in memory — reads (AuthSession::getRole(),
// etc.) still work for the rest of this request. The handful of actions
// that WRITE to the session (login, logout, preview-year) call
// session_start() again inside their own controller before writing.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// One-time cleanup ahead of the notification-centre schema upgrade (Lot
// 2): notifications/push_subscriptions gain new NOT NULL columns
// (type_id, endpoint_blind_index) and their title/body/endpoint move from
// plaintext to encrypted BLOB. Pre-existing rows can't be retrofitted with
// a type_id, and re-reading their plaintext content through the new
// decrypt-on-read path would fail — but both tables are purely ephemeral
// operational state (a push subscription re-establishes itself on the
// device's next visit; a notification history entry has no legal-record
// value), so they're truncated once, before the schema change below is
// applied, rather than migrated in place. Raw PDO because this runs
// before SettingService exists yet.
\Core\Debug\RequestTimeline::mark('db_connect_begin');
$preMigrationPdo = $connection->getPdo();
\Core\Debug\RequestTimeline::mark('db_connect_done');
$notificationsV2Migrated = (bool) $preMigrationPdo->query(
    "SELECT 1 FROM settings WHERE module_id IS NULL AND setting_key = 'notifications_v2_migrated'"
)->fetchColumn();
if (!$notificationsV2Migrated) {
    $preMigrationPdo->exec('TRUNCATE TABLE notifications');
    $preMigrationPdo->exec('TRUNCATE TABLE push_subscriptions');
    $preMigrationPdo->prepare(
        'INSERT INTO settings (module_id, setting_key, setting_value, default_value, setting_type, label, description, editable, sort_order)
         VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        'notifications_v2_migrated', '1', '0', 'boolean',
        'Migration notifications v2 effectuée',
        'Indique si le nettoyage ponctuel des anciennes notifications/souscriptions push (passage au format chiffré) a été effectué.',
        0, 999,
    ]);
}

// Auto-migrate: apply any pending schema changes from core.sql — via short,
// foreground-driven sub-steps rather than inline on every page load. A
// schema change on a database with many tables can take well beyond a
// single request's max_execution_time to fully diff+apply; previously that
// meant every request silently retried the whole (uncompleted, uncached)
// migration from scratch, which is exactly what made the site fall over
// after the first iteration's schema change shipped. Now: cheaply check
// whether a migration is pending (one hash comparison, no real work) and,
// if so, either serve one short chunk of it (the migration-step endpoint
// below, polled by the page's own JS) or show the progress page instead of
// routing normally — never do migration work inline on a normal page load.
\Core\Debug\RequestTimeline::mark('migration_pending_check_begin');
$migrationRunner = new MigrationRunner(
    $connection,
    new SchemaIntrospector($connection->getPdo()),
    new SchemaComparator(),
    new SqlParser()
);
$migrationIsPending = $migrationRunner->isPending([$schemaPath]);
\Core\Debug\RequestTimeline::mark('migration_pending_check_done', ['pending' => $migrationIsPending]);

if ($migrationIsPending) {
    $migrationStepPath = '/api/system/migration-step';

    if ($request->getMethod() === 'POST' && $request->getPath() === $migrationStepPath) {
        // This endpoint runs live DDL and is reachable before any session,
        // CSRF token or routing exists (for the whole upgrade window), so it
        // has no session-bound CSRF token to check. Require instead a custom
        // request header that only the progress page's own fetch() below sets
        // (audit M12): a cross-site page cannot set a custom header on a
        // simple request without a CORS preflight this endpoint never grants,
        // so a forged cross-origin POST is refused here. Same technique as a
        // classic X-Requested-With guard; it stops the browser-driven CSRF
        // vector without needing any server-side state mid-migration.
        if ($request->getServer('HTTP_X_SCOUTMAGIC_MIGRATION', '') !== '1') {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'forbidden']);
            exit;
        }

        // Short, foreground-safe time budget: this endpoint is called
        // repeatedly in a fast loop by the progress page below, not once
        // per page load, so each call must return quickly rather than
        // spending a full background-task-sized turn.
        $stepRunner = new MigrationRunner(
            $connection,
            new SchemaIntrospector($connection->getPdo()),
            new SchemaComparator(),
            new SqlParser(),
            5
        );
        $stepResult = $stepRunner->migrate([$schemaPath]);
        \Core\Debug\RequestTimeline::mark('migration_step_done', [
            'complete' => $stepResult->complete,
            'progress' => $stepResult->progressFraction,
            'executed_statements' => count($stepResult->executedStatements),
            'warnings' => count($stepResult->warnings),
        ]);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'complete' => $stepResult->complete,
            'progress' => round($stepResult->progressFraction, 3),
        ]);
        exit;
    }

    // Any other request while a migration is pending: a self-contained
    // page (no external CSS/JS/asset requests, so it never depends on the
    // rest of the app being routable) whose script drives the actual
    // migration work via short calls to the endpoint above, updating a
    // progress bar as it goes. Closing the tab is safe — MigrationRunner
    // persists progress after every unit of work, so the next visit (from
    // anyone) resumes exactly where this one left off instead of
    // restarting.
    // Emit the full security header set even for this pre-routing page (audit
    // hardening). It carries an inline <script> AND an inline <style>, so
    // build a nonce-based CSP and tag both with it — script-src has never
    // allowed 'unsafe-inline' here, and style-src-elem stopped allowing it
    // too (Core\Http\Response::buildStyleSrc()). This page may not load an
    // external stylesheet, by design: it renders while the file tree is
    // being replaced.
    $migrationNonce = base64_encode(random_bytes(16));
    foreach ((new \Core\Http\Response(''))->setCspNonce($migrationNonce)->getSecurityHeaders() as $hName => $hValue) {
        header("{$hName}: {$hValue}");
    }
    header('Content-Type: text/html; charset=utf-8');
    echo str_replace('__CSP_NONCE__', $migrationNonce, <<<'HTML'
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mise à jour en cours…</title>
<style nonce="__CSP_NONCE__">
  :root { color-scheme: light dark; }
  body { font-family: system-ui, -apple-system, sans-serif; max-width: 32rem; margin: 4rem auto; padding: 0 1.5rem; text-align: center; }
  h1 { font-size: 1.25rem; }
  .bar-track { background: rgba(127, 127, 127, 0.25); border-radius: 999px; height: 0.75rem; overflow: hidden; margin: 1.5rem 0; }
  .bar-fill { background: #2f6f4f; height: 100%; width: 0%; transition: width 0.4s ease; }
  p.hint { opacity: 0.7; font-size: 0.9rem; }
</style>
</head>
<body>
<h1>Mise à jour du site en cours…</h1>
<p>Merci de patienter, cette page se rechargera automatiquement une fois la mise à jour terminée. Vous pouvez aussi fermer cet onglet : la mise à jour reprendra à l'endroit où elle s'est arrêtée lors de votre prochaine visite.</p>
<div class="bar-track"><div class="bar-fill" id="bar"></div></div>
<p class="hint" id="hint">Démarrage…</p>
<script nonce="__CSP_NONCE__">
(function () {
  var bar = document.getElementById('bar');
  var hint = document.getElementById('hint');

  function step() {
    fetch('/api/system/migration-step', { method: 'POST', headers: { 'X-ScoutMagic-Migration': '1' } })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        var percent = Math.round((data.progress || 0) * 100);
        bar.style.width = percent + '%';
        hint.textContent = percent + ' %';
        if (data.complete) {
          window.location.reload();
        } else {
          setTimeout(step, 250);
        }
      })
      .catch(function () {
        setTimeout(step, 2000);
      });
  }

  step();
})();
</script>
</body>
</html>
HTML);
    exit;
}

$pdo = $connection->getPdo();

// Auto-repair admin account if broken (e.g. created with wrong key format)
if (!empty($secrets['admin_email'])) {
    $userAccountRepo = new UserAccountRepository($connection->getPdo(), $encryptionService);
    $adminUser = $userAccountRepo->findByEmail($secrets['admin_email']);
    if ($adminUser === null) {
        // Delete any broken admin rows and recreate with correct keys
        $connection->getPdo()->exec('DELETE FROM user_accounts WHERE is_super_admin = TRUE');
        $userAccountRepo->create($secrets['admin_email'], true);
    }
}

// Create Setting service and register core settings
$settingRepo = new SettingRepository($pdo);
$settingService = new SettingService($settingRepo);

$settingService->register('site_name', $siteName, 'text', 'Nom de l\'unité',
    'Nom complet de l\'unité, affiché dans le header et le titre du site.');
$settingService->register('short_name', '', 'text', 'Nom court',
    'Identifiant court (5 caractères maximum), utilisé comme préfixe du sujet de tous les emails, par exemple [25SV].',
    null, '^[A-Za-z0-9]{0,5}$', null, true, 20);
$settingService->register('base_url', '', 'url', 'URL de base',
    'Adresse complète du site (ex. https://www.unite-exemple.be). Utilisée pour générer les liens dans les emails.',
    null, null, null, true, 30);
$settingService->register('mail_from_address', '', 'email', 'Email d\'expédition',
    'Adresse email affichée comme expéditeur pour tous les emails envoyés par le site.',
    null, null, null, true, 40);
$settingService->register('mail_from_name', '', 'text', 'Nom d\'expédition',
    'Nom affiché comme expéditeur, en complément de l\'adresse email.',
    null, null, null, true, 50);
$settingService->register('dkim_selector', 's2026', 'text', 'Sélecteur DKIM',
    'Identifiant technique de la clé DKIM, présent dans l\'enregistrement DNS correspondant.',
    null, '^[a-z0-9]+$', null, true, 60);
$settingService->register('dmarc_report_email', '', 'email', 'Email rapports DMARC',
    'Adresse à laquelle les fournisseurs de messagerie envoient un résumé périodique des emails reçus au nom du domaine.',
    null, null, null, true, 70);
$settingService->register('contact_email', '', 'email', 'Email de contact',
    'Adresse email affichée sur la page Contact.',
    null, null, null, true, 80);
$settingService->register('site_version', '0.0.0', 'text', 'Version du site',
    'Version actuelle du site. Mise à jour automatiquement lors des releases.',
    null, null, null, false, 90);
$settingService->register('journal_retention_days', '730', 'number', 'Rétention du journal (jours)',
    'Durée de conservation des entrées du journal d\'événements. Les entrées plus anciennes sont automatiquement supprimées.',
    null, '^[1-9][0-9]*$', null, true, 100);
$settingService->register('update_github_owner', 'xdubois-57', 'text', 'Propriétaire du dépôt GitHub (mises à jour)',
    'Compte/organisation GitHub du dépôt publiant les releases de mise à jour. À modifier uniquement si l\'unité utilise son propre fork.',
    null, null, null, true, 110);
$settingService->register('update_github_repo', 'scoutmagic', 'text', 'Dépôt GitHub (mises à jour)',
    'Nom du dépôt GitHub publiant les releases de mise à jour.',
    null, null, null, true, 111);
$settingService->register('update_latest_version', '', 'text', 'Dernière version connue',
    'Version publiée la plus récente trouvée lors de la dernière vérification. Géré automatiquement.',
    null, null, null, false, 112);
$settingService->register('update_checked_at', '', 'text', 'Dernière vérification de mise à jour',
    'Horodatage de la dernière vérification de mise à jour effectuée. Géré automatiquement.',
    null, null, null, false, 113);
$settingService->register('update_release_notes', '', 'textarea', 'Notes de version (dernière release)',
    'Contenu des notes de version de la dernière release GitHub connue. Géré automatiquement.',
    null, null, null, false, 114);
$settingService->register('update_release_html_url', '', 'url', 'URL des notes de version',
    'Lien vers la page GitHub de la dernière release connue. Géré automatiquement.',
    null, null, null, false, 115);
$settingService->register('update_download_url', '', 'url', 'URL de téléchargement de la mise à jour',
    'Lien vers l\'archive de la dernière release connue. Géré automatiquement.',
    null, null, null, false, 116);
$settingService->register('update_dependencies_changed', '0', 'boolean', 'Dépendances modifiées (dernière release)',
    'Indique si composer.lock a changé entre la version installée et la dernière release connue. Géré automatiquement.',
    null, null, null, false, 117);
$settingService->register('installed_version_notes', '', 'textarea', 'Notes de version (version installée)',
    'Notes de version (ou message de commit en mode développement) de la version actuellement installée. Mis en cache et rafraîchi automatiquement lorsque la version installée change. Géré automatiquement.',
    null, null, null, false, 999);
$settingService->register('installed_version_notes_url', '', 'url', 'URL des notes de version (version installée)',
    'Lien vers la page GitHub (release ou commit) correspondant à la version installée. Géré automatiquement.',
    null, null, null, false, 999);
$settingService->register('installed_version_notes_for', '', 'text', 'Version des notes ci-dessus',
    'Version pour laquelle installed_version_notes a été mis en cache — sert uniquement à détecter qu\'un rafraîchissement est nécessaire après une mise à jour. Géré automatiquement.',
    null, null, null, false, 999);
$settingService->register('backup_auto_frequency', 'monthly', 'select', 'Fréquence des sauvegardes automatiques',
    'Fréquence à laquelle une sauvegarde complète du site (base de données et fichiers, sans la galerie photo) est générée automatiquement en arrière-plan. « Aucune » désactive la sauvegarde automatique.',
    null, null, ['none', 'daily', 'weekly', 'biweekly', 'monthly'], true, 118);
$settingService->register('backup_auto_last_run', '', 'text', 'Dernière sauvegarde automatique',
    'Horodatage de la dernière sauvegarde automatique effectuée avec succès. Géré automatiquement.',
    null, null, null, false, 119);
// The following 5 settings are managed exclusively from the "Mises à jour
// automatiques" section of Configuration > Maintenance (Core\Http\
// Controller\MaintenanceController) — deliberately excluded from the
// generic Configuration > Réglages page's grouped rendering
// (Core\Http\Controller\SettingsController::EXCLUDED_FROM_GENERIC_PAGE),
// since that page's plain editable-row UI has no room for the semver
// explainer / webhook status block this feature needs. auto_update_level's
// 'dev' option folds what used to be a separate danger-zone "Mode
// développement" toggle into this same radio group.
$settingService->register('auto_update_enabled', '1', 'boolean', 'Mises à jour automatiques activées',
    'Active l\'installation automatique des mises à jour selon les préférences ci-dessous.',
    null, null, null, true, 120);
$settingService->register('auto_update_level', 'minor', 'select', 'Niveau de version autorisé',
    'Types de versions installés automatiquement (patch, mineure, majeure, développement).',
    null, null, ['patch', 'minor', 'major', 'dev'], true, 121);
$settingService->register('auto_update_day', 'monday', 'select', 'Jour d\'installation automatique',
    'Jour de la semaine auquel une mise à jour disponible est installée automatiquement.',
    null, null, ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], true, 122);
$settingService->register('auto_update_time', '03:00', 'text', 'Heure d\'installation automatique',
    'Heure (HH:MM) à laquelle une mise à jour disponible est installée automatiquement.',
    null, '^([01]\d|2[0-3]):[0-5]\d$', null, true, 123);
$settingService->register('dev_update_branch', 'main', 'text', 'Branche de développement',
    'Branche GitHub surveillée pour l\'installation immédiate en mode développement.',
    null, null, null, true, 125);
$settingService->register('auto_update_last_push_at', '', 'text', 'Dernière poussée GitHub traitée',
    'Horodatage de la dernière poussée GitHub reçue et effectivement examinée par le webhook. Géré automatiquement.',
    null, null, null, false, 126);
$settingService->register('auto_update_last_push_result', '', 'text', 'Résultat de la dernière poussée',
    'Ce que le webhook a fait de cette poussée : « ok » si l\'installation a été programmée, sinon la raison du rejet. Géré automatiquement.',
    null, null, null, false, 127);
$settingService->register('scheduler_last_run', '0', 'number', 'Dernier passage du planificateur',
    'Horodatage Unix du dernier passage du planificateur de tâches. Géré automatiquement.',
    null, null, null, false, 200);
$settingService->register('current_scout_year_id', '0', 'number', 'Année scoute publique (ID)',
    'Identifiant de l\'année scoute vue par tout le monde. Gérée depuis la page « Année scoute ».',
    null, '^[0-9]+$', null, false, 210);
$settingService->register('staff_scout_year_id', '0', 'number', 'Année scoute du staff (ID)',
    'Identifiant de l\'année scoute vue par les chefs et intendants. 0 si aucune. Gérée depuis la page « Année scoute ».',
    null, '^[0-9]+$', null, false, 220);
$settingService->register('rgpd_generation_mode', 'default', 'select', 'Mode de génération RGPD',
    'Mode de génération du contenu de la page RGPD publique.',
    null, null, ['default', 'custom', 'ai'], false, 230);
$settingService->register('rgpd_custom_prompt', '', 'textarea', 'Prompt RGPD personnalisé',
    'Instructions pour la génération IA du contenu RGPD.',
    null, null, null, false, 240);
// Section documents (Core\Member\SectionDocumentOwnershipChecker /
// SectionMembershipRepository::hasPeriodCovering()) — the calendar date
// within a scout year used to decide "who was active in which section
// that year" for document access, deliberately independent from
// Core\Member\MemberYearService::getEffectiveAge()'s own scout-year
// offset concept (module addendum: never touch that logic). Combined
// with a scout year's start calendar year, e.g. default '30-09' + 2025 =
// 2025-09-30.
$settingService->register('section_document_reference_date', '30-09', 'text', 'Date de référence — documents de section',
    'Jour et mois (JJ-MM) utilisés pour déterminer qui était actif dans quelle section une année scoute donnée, pour l\'accès aux documents de section.',
    null, '^(0[1-9]|[12]\d|3[01])-(0[1-9]|1[0-2])$', null, true, 250);
// Scout year transition (Core\ScoutYear\ScoutYearTransitionService) — the
// day the "Année scoute" page starts presenting the Desk encoding phase as
// the current one. A signpost and nothing else: it labels the phases and
// says which one the calendar is in, and it never enables, disables or
// triggers anything. specifications.md §16.4 removed date-driven
// transitions deliberately (a computed date cannot be told "not yet" by
// the registration veto), and this parameter does not bring them back.
$settingService->register('scout_year_desk_encoding_date', '08-15', 'text', 'Bascule vers l\'encodage dans Desk',
    'Jour et mois (MM-JJ) à partir desquels la page « Année scoute » présente l\'encodage dans Desk comme la période en cours — jamais d\'année à indiquer, la même configuration se répète d\'une année scoute à l\'autre. Purement indicatif : cette date n\'active, ne bloque et ne déclenche jamais rien.',
    null, '^(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])$', null, true, 252);
$settingService->register('section_document_compression_enabled', '1', 'boolean', 'Compression des documents PDF de section',
    'Compresse automatiquement les documents PDF de section en arrière-plan après leur ajout, si un outil de compression est disponible sur le serveur.',
    null, null, null, true, 251);
$settingService->register('section_document_compression_quality', \Core\Pdf\PdfCompressor::QUALITY_BALANCED, 'select', 'Qualité de compression — documents de section',
    'Niveau de compression appliqué aux documents PDF de section.',
    null, null, [\Core\Pdf\PdfCompressor::QUALITY_MIN_SIZE, \Core\Pdf\PdfCompressor::QUALITY_BALANCED, \Core\Pdf\PdfCompressor::QUALITY_HIGH], true, 252);
$settingService->register('section_document_compression_backend', \Core\Pdf\PdfCompressor::BACKEND_NONE, 'text', 'Outil de compression PDF détecté',
    'Outil de compression PDF détecté automatiquement sur le serveur (ghostscript, qpdf, pdftocairo, ou none). Lecture seule — mis à jour automatiquement.',
    null, null, null, false, 253);
$settingService->register('section_document_oversize_warning_mb', '5', 'number', 'Seuil d\'avertissement — gros document de section',
    'Taille (Mo) à partir de laquelle un avertissement s\'affiche avant l\'ajout d\'un document, uniquement lorsqu\'aucun outil de compression n\'est disponible sur le serveur.',
    null, '^[1-9][0-9]*$', null, true, 254);
// Installable PWA (Lot 1) — theme_color/background_color feed both the
// manifest and the maskable/icon-180 icons' own opaque backdrop
// (Core\Photo\UnitLogoProcessor::flattenOpaque()), so a re-uploaded logo
// always matches whatever the unit has configured, never a hardcoded
// color (ARCHITECTURE §1). background_color changes also trigger
// Core\Photo\UnitLogoService::rederiveFromSource() from SettingsController
// — those two icons are re-baked immediately, not just future uploads.
$settingService->register('pwa_theme_color', '#0d6efd', 'color', 'Couleur du thème (PWA)',
    'Couleur de la barre d\'état/du thème lorsque le site est installé comme application.',
    null, null, null, true, 255);
$settingService->register('pwa_background_color', '#ffffff', 'color', 'Couleur de fond (PWA)',
    'Couleur de fond affichée pendant le chargement de l\'application installée, et derrière l\'icône adaptative (maskable).',
    null, null, null, true, 256);
$settingService->register('pwa_icon_version', '1', 'number', 'Version de l\'icône PWA',
    'Incrémentée automatiquement à chaque nouvel envoi de logo — sert à invalider le cache navigateur/OS de l\'icône. Lecture seule.',
    null, null, null, false, 257);

// Notification centre (Lot 2) — global defaults, overridable per account
// for quiet hours (Core\Security\UserAccountRepository::
// updateNotificationSettings()) via "Mon compte".
$settingService->register('notifications_quiet_hours_start', '22:00', 'text', 'Heures calmes — début',
    'Heure à partir de laquelle une notification push est retardée jusqu\'à la fin de la période calme (format HH:MM). Chaque membre peut définir ses propres heures dans "Mon compte".',
    null, '^([01]\d|2[0-3]):[0-5]\d$', null, true, 258);
$settingService->register('notifications_quiet_hours_end', '07:00', 'text', 'Heures calmes — fin',
    'Heure à laquelle les notifications push retardées par la période calme sont envoyées (format HH:MM).',
    null, '^([01]\d|2[0-3]):[0-5]\d$', null, true, 259);
$settingService->register('notifications_retention_days', '90', 'number', 'Conservation des notifications (jours)',
    'Une notification lue est supprimée automatiquement après ce délai. Une notification non lue n\'est jamais supprimée.',
    null, null, null, true, 260);

// Desk import retention, in scout years (Core\Import\ImportRetentionService,
// SECURITY.md §13). In seasons rather than in a number of imports on
// purpose: `fees` needs November's roster snapshot for the deposit invoice
// and February's for the settlement, and a count would quietly drop
// November after half a dozen ordinary re-imports.
$settingService->register(\Core\Import\ImportRetentionService::SETTING_KEY,
    (string) \Core\Import\ImportRetentionService::DEFAULT_YEARS, 'number',
    'Conservation des imports Desk (années scoutes)',
    'Nombre d\'années scoutes pendant lesquelles un import Desk est conservé — sa ligne, son fichier CSV chiffré et l\'instantané du roster qu\'il a figé. La valeur 2 conserve l\'année en cours et la précédente. Au-delà, la saison entière est supprimée définitivement.',
    null, null, null, true, 261);

// Offline content caching (Lot 3) — how old a cached copy of a
// whitelisted page (Core\Offline\OfflineWhitelist) may be before the
// service worker refuses to serve it and falls back to the offline page
// instead. Passed to public/sw.js via postMessage — never hardcoded
// there (see base.html.twig).
$settingService->register('offline_cache_staleness_days', '30', 'number', 'Péremption du contenu hors ligne (jours)',
    'Au-delà de ce délai, une page mise en cache pour la consultation hors ligne n\'est plus affichée — la page hors ligne générique est montrée à la place plutôt qu\'un contenu obsolète.',
    null, null, null, true, 261);

// Core\Security\HumanCheck — generic anti-bot protection for public forms
// submitted by a non-identified session (ARCHITECTURE.md §8). Applies to
// every integration point (magic-link request, news module public form
// responses, and any future module reusing the component) at once.
$settingService->register('human_check_min_delay_seconds', '3', 'number', 'Délai minimum avant soumission (secondes)',
    'Une soumission de formulaire public reçue moins de X secondes après son affichage est rejetée (probable robot). Une valeur trop élevée finit par rejeter de vrais visiteurs.',
    null, '^\d+$', null, true, 270);
$settingService->register('human_check_form_validity_seconds', '14400', 'number', 'Durée de validité d\'un formulaire (secondes)',
    'Au-delà de ce délai après son affichage, un formulaire public est considéré expiré et sa soumission est rejetée — évite qu\'un onglet resté ouvert très longtemps soit rejoué indéfiniment.',
    null, '^\d+$', null, true, 271);
$settingService->register('human_check_rate_limit_window_minutes', '10', 'number', 'Fenêtre de limitation par IP (minutes)',
    'Taille de la fenêtre glissante utilisée pour compter les soumissions de formulaires publics par adresse IP.',
    null, '^\d+$', null, true, 272);
$settingService->register('human_check_rate_limit_max_attempts', '5', 'number', 'Soumissions maximum par IP',
    'Nombre maximum de soumissions de formulaires publics autorisées pour une même adresse IP dans la fenêtre de limitation, au-delà duquel les soumissions suivantes sont rejetées.',
    null, '^\d+$', null, true, 273);

// Usage statistics and support package (Core\Statistics, Core\Support —
// ARCHITECTURE.md §8.47/§8.48). All five are deliberately kept out of the
// generic Configuration > Réglages page (Core\Http\Controller\
// SettingsController::EXCLUDED_FROM_GENERIC_PAGE, same treatment as the
// auto-update settings) — they are managed from the dedicated Support
// page, which pairs the switch with the plain-language explanation of what
// leaves the site, something a plain editable row cannot carry.
$settingService->register('statistics_enabled', '1', 'boolean', 'Envoi automatique des statistiques d\'utilisation',
    'Autorise l\'envoi quotidien d\'un rapport d\'utilisation agrégé vers ScoutMagic. Le rapport contient l\'adresse de ce site, jamais de donnée de membre. Géré depuis la page Support.',
    null, null, null, true, 280);
// Rendered by no page at all — not by the Support page (Core\Http\Controller\
// SupportController::index() says why) and not by the generic Réglages page
// (SettingsController::EXCLUDED_FROM_GENERIC_PAGE) — because where the reports
// go is a project-level fact, not a per-unit choice. Declared `editable =
// false` to say the same thing in the row itself; on an installation that
// already has the row, the exclusion list is what does the work, since
// SettingRepository::upsert() only ever refreshes `default_value`.
$settingService->register('statistics_destination', 'https://www.scoutmagic.be', 'url', 'Destination des statistiques',
    'Adresse du site qui reçoit les rapports d\'utilisation. Fait de niveau projet, modifiable uniquement lors du déploiement d\'une installation réceptrice.',
    null, null, null, false, 281);
$settingService->register('statistics_installation_id', '', 'text', 'Identifiant de cette installation',
    'Identifiant aléatoire attribué une seule fois à cette installation pour reconnaître ses rapports d\'utilisation. Il ne dérive d\'aucune donnée personnelle.',
    null, null, null, false, 282);
$settingService->register('support_email', 'support@scoutmagic.be', 'email', 'Adresse du support ScoutMagic',
    'Adresse à laquelle envoyer une archive de support. Affichée sur la page Support.',
    null, null, null, false, 283);
// Send-state bookkeeping, written by the daily task and shown read-only on
// the Support page. The failure reason is a short, redacted code — never a
// raw server response and never the authentication secret.
$settingService->register('statistics_last_success_at', '', 'text', 'Dernier envoi de statistiques réussi',
    'Horodatage du dernier rapport d\'utilisation transmis avec succès. Renseigné automatiquement.',
    null, null, null, false, 285);
$settingService->register('statistics_last_failure_at', '', 'text', 'Dernier échec d\'envoi de statistiques',
    'Horodatage de la dernière tentative d\'envoi ayant échoué ou ayant été sautée. Renseigné automatiquement.',
    null, null, null, false, 286);
$settingService->register('statistics_last_failure_reason', '', 'text', 'Motif du dernier échec d\'envoi',
    'Motif court du dernier échec ou saut d\'envoi des statistiques. Renseigné automatiquement.',
    null, null, null, false, 287);
// Support package bookkeeping (Core\Support, ARCHITECTURE.md §8.48) — one
// package is ever kept, so two settings replace what would be a one-row table.
$settingService->register('support_package_file_id', '', 'text', 'Paquet de support disponible',
    'Identifiant du fichier de l\'archive de support actuellement conservée. Renseigné automatiquement.',
    null, null, null, false, 288);
$settingService->register('support_package_generated_at', '', 'text', 'Date de génération du paquet de support',
    'Horodatage de génération de l\'archive de support conservée, utilisé pour sa purge automatique. Renseigné automatiquement.',
    null, null, null, false, 289);
// `installed_at` declares itself (Core\Statistics\InstallationDateService::
// register()) because SetupController writes it before this file has ever
// run — see that method's own comment. Backfilled here once for every
// installation that predates the setting; strictly idempotent, it only
// ever writes while the value is still empty.
\Core\Statistics\InstallationDateService::register($settingService);
(new \Core\Statistics\InstallationDateService($settingService, $pdo))->ensureRecorded();

// Migrate non-secret settings from secrets.enc to settings table (one-time)
if ($settingService->get('settings_migrated') !== '1') {
    $settingService->register('settings_migrated', '0', 'boolean', 'Migration effectuée',
        'Indique si la migration des paramètres depuis secrets.enc a été effectuée.',
        null, null, null, false, 999);

    $migrateKeys = ['site_name', 'short_name', 'base_url', 'mail_from_address', 'mail_from_name', 'dkim_selector', 'dmarc_report_email'];
    foreach ($migrateKeys as $mKey) {
        if (!empty($secrets[$mKey]) && ($settingService->get($mKey) === '' || $settingService->get($mKey) === null)) {
            $settingRepo->updateValue(null, $mKey, $secrets[$mKey]);
        }
    }
    // Also migrate contact_email from mail_from_address if not set
    if (!empty($secrets['mail_from_address']) && ($settingService->get('contact_email') === '' || $settingService->get('contact_email') === null)) {
        $settingRepo->updateValue(null, 'contact_email', $secrets['mail_from_address']);
    }

    // Mark migration done
    $settingRepo->updateValue(null, 'settings_migrated', '1');
    $settingService->clearCache();

    // Remove non-secret keys from secrets.enc
    $secretKeysToKeep = ['db_host', 'db_port', 'db_name', 'db_user', 'db_password', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_password', 'mail_mode', 'encryption_key', 'blind_index_key', 'admin_email'];
    $cleanedSecrets = [];
    foreach ($secretKeysToKeep as $sk) {
        if (isset($secrets[$sk])) {
            $cleanedSecrets[$sk] = $secrets[$sk];
        }
    }
    $secretManager->writeSecrets($cleanedSecrets);
    $secrets = $cleanedSecrets;
}

// Create Journal service
$journalRepo = new JournalRepository($pdo);
$journalService = new JournalService($journalRepo);

// Per-entity change history (Core\Audit, ARCHITECTURE.md §8.66) — the
// timeline a module renders on an entity's own page, distinct from the
// journal above: that one is the installation's administrative log and
// forbids personal data, this one holds the values themselves and
// encrypts every one of them.
//
// The access resolver starts EMPTY and denies every entity type. Each
// module that records history registers its own checker further down,
// inside the block that already knows whether that module is enabled —
// core cannot answer "may this visitor read this camp" and must not
// guess.
$auditRepository = new \Core\Audit\AuditRepository($pdo, $encryptionService);
$auditService = new \Core\Audit\AuditService($auditRepository);
$auditAccessResolver = new \Core\Audit\AuditAccessResolver();

// Debug timeline (?debug=1): a first, immediately-visible entry as soon as
// journal logging becomes possible — this is the earliest point in the
// request DB/session/settings/journal all exist, so it's also the
// earliest point authorization (session-only, no DB needed) can gate a
// write, well before routing/controller dispatch. Written eagerly, not
// deferred to the end-of-request summary, specifically so a debug run
// against /admin/journal itself shows up on the very page load that
// triggered it, rather than only becoming visible on a second reload.
if (\Core\Debug\RequestTimeline::isActive() && \Core\Security\AuthSession::isAuthenticated()
    && in_array(\Core\Security\AuthSession::getRole(), ['admin', 'superadmin'], true)
) {
    \Core\Debug\RequestTimeline::mark('debug_active_confirmed');
    // Carries the timeline-so-far (through migration, the single most
    // expensive step) rather than just method/path — if the rest of the
    // request (controller dispatch, poor-man's-cron tail) never finishes
    // because PHP's execution-time limit kills the script first, this is
    // the only record of what happened. The end-of-request "Chronologie"
    // entry below still fires normally and carries the complete picture
    // when the request does finish.
    $journalService->log(
        'core',
        'debug_request_hit',
        'info',
        'Requête en cours (?debug=1) : ' . $request->getMethod() . ' ' . $request->getPath(),
        [
            'method' => $request->getMethod(),
            'path' => $request->getPath(),
            'timeline_so_far' => \Core\Debug\RequestTimeline::getEntries(),
        ],
        \Core\Security\AuthSession::getUserAccountId()
    );
}

// Create Scheduler service
$schedulerRepo = new SchedulerRepository($pdo);
// cachePendingRearms: the composition root makes ~20 rearm() probes per
// request; the cache answers them from one query. Task handlers build
// their own fresh SchedulerService and must never receive this instance
// (see SchedulerService::__construct()).
$schedulerService = new SchedulerService($schedulerRepo, cachePendingRearms: true);
$schedulerRunner = new SchedulerRunner($schedulerRepo, $journalService);

// Register param() Twig function — reads from settings database
$twig->addFunction(new TwigFunction('param', function (string $key, ?string $moduleId = null) use ($settingService): string {
    return (string) ($settingService->get($key, $moduleId) ?? '');
}));

// Set site_name global from settings (used extensively in templates)
$twig->addGlobal('site_name', (string) ($settingService->get('site_name') ?: 'Unité scoute'));

// Installable PWA (Lot 1) — the service worker's cache name derives from
// this same value client-side (base.html.twig's registration script), so
// a VERSION bump after a GitHub release install (§8.17) is the entire
// update/cache-busting mechanism: a new query string on /sw.js makes the
// browser see a new worker, which purges every cache not matching it.
$twig->addGlobal('app_version', \Core\Maintenance\VersionFile::read(dirname(__DIR__)));
$twig->addGlobal('pwa_theme_color', (string) ($settingService->get('pwa_theme_color') ?: '#0d6efd'));

// Create MailService — short_name, mail_from_address, mail_from_name and
// dkim_selector all live in the settings table (migrated out of
// secrets.enc, see the one-time migration below), so each must be merged
// back in here: $secrets[...] is permanently empty/stale on any install
// that already ran that migration, which silently emptied the "[XX]"
// subject prefix (short_name) and, worse, the From address on every email
// (magic links, mass mail, test emails, confirmation links) — PHPMailer
// rejects an empty From outright ("Invalid address: (From): "), so this
// wasn't a "no SMTP configured" situation, it never even tried to connect.
foreach (['short_name', 'mail_from_address', 'mail_from_name', 'dkim_selector'] as $mailSecretKey) {
    $secrets[$mailSecretKey] = (string) ($settingService->get($mailSecretKey) ?: ($secrets[$mailSecretKey] ?? ''));
}
// Which named flags hold for THIS installation (ARCHITECTURE.md §8.49)?
// Decided from base_url, never from the Host header, and resolved here
// through the single resolver every entry point calls so ModuleManager
// receives an already-built profile rather than learning what a statistics
// destination or a reference host is.
$installationProfile = \Core\Module\InstallationProfile::resolve(
    (string) ($settingService->get('base_url') ?? ''),
    (string) ($settingService->get('statistics_destination') ?? '')
);

// The mail sandbox (ARCHITECTURE.md §8.63). Outgoing mail is captured
// instead of sent only when the installation profile carries
// reference_installation or local_installation, the test_tools module is
// enabled, AND its arm switch is on — the factory owns that decision, and
// returns null (keep sending) for every other installation. Resolved here
// because MailService is built long before modules load, which is also why
// a forged module_registry row alone can never capture anything.
$mailCaptureTransport = \Modules\TestTools\Mail\CaptureTransportFactory::forInstallation(
    $installationProfile,
    new ModuleRegistryRepository($pdo),
    $settingService,
    $pdo,
    $encryptionService,
    dirname(__DIR__) . '/storage'
);

$mailService = MailServiceFactory::create($secrets, $dkimManager, $mailCaptureTransport);

// Create NotificationService (Web Push, Core\Notification) — VAPID subject
// must be a mailto: or an https URL, never empty, hence the fallback chain
// down to a hardcoded URL for a freshly-setup site with no contact email yet.
$vapidSubjectEmail = (string) ($settingService->get('contact_email') ?: $settingService->get('mail_from_address') ?: '');
$vapidSubject = $vapidSubjectEmail !== '' ? 'mailto:' . $vapidSubjectEmail : (string) ($settingService->get('base_url') ?: 'https://localhost');
// The self-heal above keeps this from ever failing in practice, but
// WebPush's constructor validates VAPID eagerly and throws on any
// invalid config — belt-and-braces so push notifications being broken
// can never take the entire site down again, whatever the cause.
try {
    $webPush = new WebPush(
        ['VAPID' => [
            'subject' => $vapidSubject,
            'publicKey' => (string) ($secrets['vapid_public_key'] ?? ''),
            'privateKey' => (string) ($secrets['vapid_private_key'] ?? ''),
        ]],
        [],
        // Bound the outbound push request (audit M4): a slow or unreachable
        // endpoint must not hold a request/worker open indefinitely. WebPush
        // takes timeouts on the PSR-18 client instance, not in its options.
        new \GuzzleHttp\Client(['connect_timeout' => 5, 'timeout' => 10])
    );
} catch (\Throwable $e) {
    $webPush = null;
    $journalService->log(
        'core', 'vapid_construction_failed', 'info',
        'Configuration VAPID invalide : notifications push désactivées pour cette requête',
        ['message' => $e->getMessage()]
    );
}
$pushSubscriptionRepo = new PushSubscriptionRepository($pdo, $encryptionService);
$notificationRepo = new NotificationRepository($pdo, $encryptionService);
$notificationPreferenceRepo = new NotificationPreferenceRepository($pdo);
// $notificationService itself is constructed further below, once
// $roleResolver/$scoutYearService exist (dispatch()'s role_min re-check
// needs both) — see that construction's own comment.

// Create AuthService
$authService = new AuthService(
    $connection,
    $encryptionService,
    $mailService,
    $twig,
    (string) $settingService->get('base_url'),
    (string) $settingService->get('site_name')
);

// Create PasswordResetService ("Mot de passe oublié" flow)
$passwordResetService = new PasswordResetService(
    $connection,
    $encryptionService,
    $mailService,
    $twig,
    (string) $settingService->get('base_url'),
    (string) $settingService->get('site_name')
);

// Create generic short-URL service (Core\Url) and A4 poster PDF service
// (Core\Pdf) — not module-specific, see schema/core.sql's short_urls table
// doc comment.
$shortUrlRepository = new ShortUrlRepository($pdo, $encryptionService);
$shortUrlService = new ShortUrlService($shortUrlRepository);
$posterPdfService = new PosterPdfService();

// Create cookie consent service
$cookieConsentService = new CookieConsentService();

// Create editable content service
$editableContentRepo = new EditableContentRepository($pdo);
$editableContentService = new EditableContentService($editableContentRepo);
$sectionRepository = new SectionRepository($pdo);

// Create import-related services
$scoutYearService = new ScoutYearService($pdo);
$functionRepo = new FunctionRepository($pdo);
$ageBranchRepo = new AgeBranchRepository($pdo);
$importSectionRepo = new ImportSectionRepository($pdo);
$feeCategoryRepo = new FeeCategoryRepository($pdo);
$memberRepo = new MemberRepository($pdo);
$memberYearRepo = new MemberYearRepository($pdo);
$importJournalRepo = new ImportJournalRepository($pdo);
$userAccountRepo = new UserAccountRepository($pdo, $encryptionService);
$mappingResolver = new MappingResolver($functionRepo, $ageBranchRepo, $importSectionRepo, $feeCategoryRepo);
$csvParser = new DeskCsvParser();
$unitStaffSectionService = new UnitStaffSectionService($pdo);
$sectionMembershipRepository = new \Core\Member\SectionMembershipRepository($pdo);
$sectionMembershipService = new \Core\Member\SectionMembershipService($sectionMembershipRepository, $scoutYearService);

// One-time seed for installs where member_section_periods was added after
// member/function data already existed — see SectionMembershipService::
// backfillFromFunctions()'s own docblock. Without this, the member page's
// "Documents de section" box stays empty for every existing member until
// their section changes on a future Desk import.
if ($settingService->get('member_section_periods_backfilled') !== '1') {
    $settingService->register('member_section_periods_backfilled', '0', 'boolean', 'Historique de sections reconstitué',
        'Indique si l\'historique d\'appartenance aux sections a été reconstitué depuis les fonctions existantes.',
        null, null, null, false, 999);

    $sectionMembershipService->backfillFromFunctions();

    $settingRepo->updateValue(null, 'member_section_periods_backfilled', '1');
    $settingService->clearCache();
}

$sectionDocumentRepository = new \Core\Member\SectionDocumentRepository($pdo);
// Multi-email support per member (Core\Member\MemberEmailService) — built
// before $roleResolver so login-by-email resolution can also match a
// currently-valid secondary address (see RoleResolver's own docblock).
$memberEmailRepository = new \Core\Member\MemberEmailRepository($pdo, $encryptionService);

// "Which logins can read a notification addressed to this member?" — the
// Desk address plus every confirmed secondary one, resolved in one place
// so a module can never answer it differently (Core\Member\
// MemberAccountResolver).
$memberAccountResolver = new \Core\Member\MemberAccountResolver(
    $memberYearRepo, $memberEmailRepository, $userAccountRepo, $encryptionService
);
$roleResolver = new RoleResolver($memberYearRepo, $encryptionService, $pdo, $memberEmailRepository);

// Notification centre (Lot 2) — built here, not alongside $webPush/
// $pushSubscriptionRepo above, because dispatch()'s role_min re-check
// needs $roleResolver and $scoutYearService, both only available from
// this point on.
$notificationService = new NotificationService(
    $notificationRepo,
    $pushSubscriptionRepo,
    $notificationPreferenceRepo,
    $webPush,
    $settingService,
    $journalService,
    $schedulerService,
    $userAccountRepo,
    $roleResolver,
    $scoutYearService
);

// The one session-aware temporary-member resolver (ARCHITECTURE.md §8.42).
// Constructed here, in the composition root, because it is the only place
// with a session to read: MemberService itself never touches $_SESSION.
$temporaryMemberProvider = new \Core\Member\SessionTemporaryMemberProvider();
$memberService = new MemberService($memberYearRepo, $encryptionService, $connection, $temporaryMemberProvider, $memberEmailRepository);
$memberYearService = new MemberYearService();
$memberSearchService = new MemberSearchService(new MemberSearchRepository($connection, $encryptionService), $scoutYearService);
// "Won't be back next scout year" marking (ARCHITECTURE.md §8) — a plain
// fact about a member_year, not inscriptions-specific, so it lives here at
// core level even though the registration module's own "Départs" page
// (below, once that module's block is reached) was its first consumer;
// Core\Http\Controller\MemberController's "/members/{id}/departure" AJAX
// endpoint (admin member-search page) is the other, always-available one.
$departureService = new \Core\Member\DepartureService(new \Core\Member\DepartureRepository($pdo, $encryptionService), $journalService);
// Badges — transversal roles assignable to chiefs (Core\Badge). Global
// concept configured once (Édition du site), assignment scoped per
// member_year (Staffs page), displayed on the trombinoscope.
$badgeRepository = new BadgeRepository($pdo);
$memberBadgeRepository = new MemberBadgeRepository($pdo);

$sectionService = new SectionService($connection, $encryptionService, $memberBadgeRepository);
$badgeService = new BadgeService($badgeRepository, $memberBadgeRepository, $sectionService);

// "Which sections is this account an animateur of" (ARCHITECTURE.md
// §8.33) — a core service, built once here and shared by every consumer
// (the Staffs page and its documents, the registration module's Départs
// page). Never a second instance: each construction is another chance to
// pass a different set of dependencies (one built without
// $memberEmailRepository silently staffs fewer sections), and this
// question must have exactly one answer site-wide.
$sectionStaffAuthorizationService = new \Core\Member\SectionStaffAuthorizationService(
    $connection, $encryptionService, $sectionService, $memberEmailRepository
);

// Member page (Espace membres) "Documents privés" storage — see
// Core\Member\MemberDocumentService.
$memberDocumentService = new \Core\Member\MemberDocumentService(new \Core\Member\MemberDocumentRepository($pdo));

// Member page "Adresses email" — multi-email support per member (Core\
// Member\MemberEmailService). $memberEmailRepository was already built
// above, before $roleResolver.
$memberEmailService = new \Core\Member\MemberEmailService(
    $memberEmailRepository, $mailService, $twig, $journalService, $sectionService, $memberService, $scoutYearService,
    (string) $settingService->get('base_url'), (string) ($settingService->get('site_name') ?: 'Unité scoute')
);

// Scout year resolution (public / staff / session-preview priority)
$scoutYearResolver = new ScoutYearResolver($scoutYearService, $settingService, $memberYearRepo);

$scoutYearAdminService = new ScoutYearAdminService($settingService);

// "Membres par section" (core, role_min intendant) — read-only roster of
// every section's animateurs/intendants/animés, with a generic
// year-over-year movement classification (Core\Member\Movement, reusable
// beyond this one page) and an exhaustive, role-gated Excel export
// (Core\Member\Export, also reusable beyond this one page).
$memberMovementRepository = new \Core\Member\Movement\MemberMovementRepository($pdo);
$memberMovementClassifier = new \Core\Member\Movement\MemberMovementClassifierService($memberMovementRepository, $scoutYearService);
$sectionRosterRepository = new \Core\Member\SectionRosterRepository($pdo);
$sectionRosterService = new \Core\Member\SectionRosterService($sectionRosterRepository, $encryptionService, $memberEmailRepository, $memberMovementClassifier);
$memberExportRowBuilder = new \Core\Member\Export\MemberExportRowBuilder($sectionRosterRepository, $sectionService, $scoutYearService, $encryptionService, $memberEmailRepository, $memberMovementClassifier);
$memberExportService = new \Core\Member\Export\MemberExportService();

// Create file services
$storagePath = dirname(__DIR__) . '/storage';
$fileRepository = new FileRepository($pdo);
// The shared "attached document" invariant (Core\File\AttachedFileRemover):
// row first, bytes second, bytes only when the module owns them and nobody
// else points at them. Camps and Locations both use this one instance.
$attachedFileRemover = new \Core\File\AttachedFileRemover($fileRepository, $storagePath);
$uploadHandler = new UploadHandler($fileRepository, $storagePath);
$encryptedFileStorageService = new \Core\File\EncryptedFileStorageService($fileRepository, $encryptionService, $storagePath);

// The Desk import, its roster-replacement barrier and its retention.
// Built here rather than beside the other import repositories above
// because it needs two things that only exist from this point on:
// $scoutYearResolver, so the barrier knows which scout year the site
// actually resolves access against (an import into a year prepared in
// advance takes nobody's access away), and $encryptedFileStorageService,
// which is how the consumed CSV is kept.
$rosterSnapshotRepository = new \Core\Import\RosterSnapshotRepository($pdo);
$importDiffCalculator = new \Core\Import\ImportDiffCalculator($rosterSnapshotRepository);
$duplicateMemberRepository = new \Core\Member\Duplicate\DuplicateMemberRepository($pdo, $encryptionService);
$duplicateMemberDetector = new \Core\Member\Duplicate\DuplicateMemberDetector($duplicateMemberRepository, $encryptionService);
$memberMergeService = new \Core\Member\Duplicate\MemberMergeService($pdo, $duplicateMemberRepository, $journalService);
$rosterReplacementGuard = new \Core\Import\RosterReplacementGuard(
    new \Core\Import\RosterComparisonRepository($pdo),
    $scoutYearResolver
);
$importService = new DeskImportService(
    $pdo, $encryptionService, $csvParser, $mappingResolver,
    $memberRepo, $memberYearRepo, $importJournalRepo, $userAccountRepo, $unitStaffSectionService,
    $sectionMembershipService, $rosterReplacementGuard, $journalService, $rosterSnapshotRepository,
    $encryptedFileStorageService, $importDiffCalculator, $duplicateMemberDetector
);
$importReportPresenter = new \Core\Import\ImportReportPresenter(
    new \Core\Import\ImportReportRepository($pdo, $encryptionService)
);
$importRetentionService = new \Core\Import\ImportRetentionService(
    $pdo, $importJournalRepo, $rosterSnapshotRepository, $fileRepository,
    $scoutYearService, $settingService, $journalService, $storagePath
);

// Image variant pipeline (thumb/md derivatives of the core photo contexts
// — member_photo, section_photo, editable_image, age_branch_logo). Siblings
// of the stored original on disk, no `files` row of their own — see
// Core\Photo\ImageVariantService's own docblock.
$imageVariantService = new ImageVariantService($fileRepository, new ImageVariantProcessor(), $storagePath);
$sectionDocumentService = new \Core\Member\SectionDocumentService(
    $sectionDocumentRepository, $sectionMembershipRepository, $encryptedFileStorageService, $fileRepository,
    $sectionService, $scoutYearService, $journalService, $schedulerService, $settingService,
    new \Core\Pdf\PdfCompressor($storagePath . '/temp')
);

// Unit logo (favicon, PWA icons, footer logo — originally Lot 1's PWA-only
// icon set, widened later) — override storage lives under
// storage/core/logo/, deliberately outside the files table (see
// Core\Photo\UnitLogoService's own docblock); the shipped defaults ship
// under public/assets/img/pwa/ (kept at its original path — still exactly
// what it says, the defaults used before/absent any upload).
$unitLogoService = new \Core\Photo\UnitLogoService(
    new \Core\Photo\UnitLogoProcessor(),
    $settingService,
    $storagePath . '/core/logo',
    dirname(__DIR__) . '/public/assets/img/pwa'
);
$twig->addGlobal('pwa_icon_version', $unitLogoService->currentVersion());
$twig->addGlobal('unit_logo_available', $unitLogoService->resolveIconContent('64') !== null);

// Create backup service (Configuration > Maintenance)
$backupRepository = new BackupRepository($pdo);
$backupService = new BackupService($connection, $storagePath, dirname($storagePath));
$updateHistoryRepository = new \Core\Maintenance\UpdateHistoryRepository($pdo);

// Core "photo per person per year" component (ARCHITECTURE.md §8) — see
// Core\Photo\MemberPhotoService.
$memberPhotoService = new MemberPhotoService(new MemberPhotoRepository($pdo));

// The other half of the same idea, for the other half of this codebase's
// identity model: the photo of an identified LOGIN, set from "Mon
// compte". Not scout-year-scoped — a login is a person, not a membership
// (Core\Photo\AccountPhotoService). Given the file repository and the
// storage path so replacing a photo deletes the one it replaces.
$accountPhotoService = new AccountPhotoService(
    new AccountPhotoRepository($pdo),
    $fileRepository,
    $storagePath
);

// Same "one per year, fall back to the most recent earlier one" component
// as above, keyed by section instead of member — the Staffs page's group
// photo of a section's chiefs. See Core\Photo\SectionPhotoService.
$sectionPhotoRepository = new SectionPhotoRepository($pdo);
$sectionPhotoService = new SectionPhotoService($sectionPhotoRepository);
$sectionPhotoProcessor = new SectionPhotoProcessor();

// Generic, reusable-anywhere "editable image" landscape crop (home page
// hero, and any future editable_image() use) — see Core\Photo\
// LandscapeImageProcessor for the ratio/width rationale.
$landscapeImageProcessor = new LandscapeImageProcessor();

// Role labels in French
$roleLabelMap = [
    'public' => 'Public',
    'identified' => 'Animé',
    'intendant' => 'Intendant',
    'chief' => 'Chef',
    'admin' => 'Chef d\'Unité',
    'superadmin' => 'Administrateur',
];

// Re-check an existing session against current data BEFORE anything reads
// the role from it (Core\Security\SessionRevalidator): a password change
// revokes sessions issued earlier, and the effective role is re-resolved so
// a demotion doesn't wait out the 30-day session cookie. Uses the current
// PUBLIC year deliberately — the same basis AuthController::resolveRole()
// used to grant the role at login, and unlike $effectiveScoutYear below it
// doesn't itself depend on the role we are about to validate.
$sessionRevalidator = new \Core\Security\SessionRevalidator($userAccountRepo, $roleResolver);
$sessionRevalidator->setJournalService($journalService);
$sessionRevalidator->revalidate(static fn(): int => (int) $scoutYearResolver->getCurrentPublicYear()['id']);

// Set Twig globals for auth state (after session is started)
$currentRole = AuthSession::getRole();
$twig->addGlobal('is_authenticated', AuthSession::isAuthenticated());
$twig->addGlobal('current_user_email', AuthSession::getEmail());
$twig->addGlobal('current_user_role', $currentRole);

// Resolve the scout year in effect for this request (may be a preview/staff override).
$effectiveScoutYear = $scoutYearResolver->getEffectiveYear(
    ScoutYearSession::getPreviewId(),
    Role::fromString($currentRole)
);

// Update user display name based on linked members
$displayName = AuthSession::getEmail() ?? '';
$memberCount = 0;
$linkedMembers = [];
$temporaryMemberName = null;
if (AuthSession::isAuthenticated()) {
    $linkedMembers = $memberService->getLinkedMembers(
        AuthSession::getEmail(),
        $effectiveScoutYear->id
    );
    if (count($linkedMembers) > 0) {
        $primaryMember = MemberService::getHighestRoleMember($linkedMembers);
        $displayName = $primaryMember !== null ? $primaryMember->getDisplayName() : $displayName;
        $memberCount = count($linkedMembers);
    }

    // A temporary member (ARCHITECTURE.md §8.42) takes over the header
    // identity outright, rather than going through getHighestRoleMember():
    // an animé carries no function at all, so the "highest role" rule would
    // always keep showing the admin's own member and the override would be
    // invisible in the one place it most needs to be legible. The banner
    // right below the nav is what stops that from reading as "you are
    // logged in as this person".
    $temporaryMemberYearId = $temporaryMemberProvider->resolveMemberYearId(AuthSession::getEmail() ?? '');
    foreach ($linkedMembers as $member) {
        if ($member->memberYearId === $temporaryMemberYearId) {
            $displayName = $member->getDisplayName();
            $temporaryMemberName = $member->getDisplayName();
            break;
        }
    }
}

// Built here (not earlier, alongside $fileRepository) because the
// owner-scoping check (Core\File\FileAccessGuard) needs $linkedMembers,
// which isn't resolved until this point — see the class docblock for why
// there's deliberately no chief/admin bypass on that check.
$sectionDocumentOwnershipChecker = new \Core\Member\SectionDocumentOwnershipChecker(
    $sectionDocumentRepository, $sectionMembershipRepository, $scoutYearService, $settingService
);

// The registry FileAccessGuard consults for a file carrying owner_type.
// Core's own checker is registered here, where its dependencies (and
// $linkedMembers) exist; a module contributes its own by appending to
// this array from inside its own getEnabledModuleIds() block further down
// (ARCHITECTURE.md §7.4). The guard itself is therefore NOT built here —
// it is immutable by design, so its registry has to be complete first,
// and module blocks only run after loadEnabledModules(). See where
// FileController is wired, at the end of this file.
$fileOwnershipCheckers = [$sectionDocumentOwnershipChecker, new \Core\Import\DeskImportFileOwnershipChecker()];

// The registry Core\Attention\AttentionService consults for the
// attention-points page — same shape and same reason as
// $fileOwnershipCheckers above: core seeds its own contributor here, a
// module appends its own from inside its getEnabledModuleIds() block
// further down (ARCHITECTURE.md §7.4), and the service itself is built
// only once every module block has run. Unlike the Desk-import listener,
// these run at DISPLAY time and their exceptions are caught: a module
// that is wrong about the unit must never break a page.
$attentionProviders = [
    new \Core\Attention\CoreAttentionProvider(new \Core\Attention\CoreAttentionRepository($pdo)),
    new \Core\Member\Duplicate\DuplicateAttentionProvider(
        new \Core\Member\Duplicate\DuplicateMemberRepository($pdo, $encryptionService)
    ),
];

// The registry Modules\Gallery\Service\DelegatedAlbumAccessRegistry
// consults for a delegated album — gallery's own equivalent of
// $fileOwnershipCheckers above, one level up. Gallery contributes no
// checker of its own (it only hosts delegated albums, never owns one), so
// this starts empty; a module that hosts one through
// Api\DelegatedAlbumManager appends its own checker from inside its own
// getEnabledModuleIds() block. Consumed only by
// Controller\GalleryController::serveMedia(), constructed at the end of
// this file for the exact same ordering reason as Core\File\FileAccessGuard
// above: every module block that might append to this array runs before
// that point.
$galleryDelegatedAlbumAccessCheckers = [];

// The read-only twin of the array above, consumed the same way and at the
// same point: it tells gallery's storage-administration page what to CALL a
// delegated album, so one can be listed and moved without gallery ever
// learning what a discussion group is (Api\DelegatedAlbumDescriber).
$galleryDelegatedAlbumDescribers = [];

// Snapshot taken here rather than at the guard's construction site:
// $linkedMembers is re-resolved further down for the Espace membres menu,
// and the guard's owner-scoping must not depend on which of the two
// resolutions happens to be the current one by the time it is built.
$linkedMemberIds = array_map(fn($m) => $m->memberId, $linkedMembers);

$twig->addGlobal('current_user_display_name', $displayName);
// Which account the nav's avatar stands for — the login's own photo is
// keyed on it (partials/account_avatar.html.twig, person_avatar()). 0 for
// a visitor who is not identified, which resolves to no photo and no
// initials circle is drawn for them anyway.
$twig->addGlobal('current_user_account_id', AuthSession::getUserAccountId() ?? 0);
// What the avatar's INITIALS come from, which is not always what the nav
// writes next to it: the header shows a member's display name (a totem,
// "Akéla"), and a temporary member takes it over entirely (§8.42) — while
// the circle stands for the person logged in. Their own first and last
// name when the account carries them, and the header's name otherwise,
// so this never falls back to two letters of an email address unless
// there is genuinely nothing else.
$currentAccountForAvatar = AuthSession::isAuthenticated()
    ? $userAccountRepo->findById((int) AuthSession::getUserAccountId())
    : null;
$currentAccountName = $currentAccountForAvatar === null
    ? ''
    : trim(($currentAccountForAvatar->firstName ?? '') . ' ' . ($currentAccountForAvatar->lastName ?? ''));
$twig->addGlobal('current_user_avatar_name', $currentAccountName !== '' ? $currentAccountName : $displayName);
$twig->addGlobal('current_user_member_count', $memberCount);
// Server-rendered on page load — public/assets/js/notification-badge.js
// refreshes it live afterward (60s poll + immediate on an incoming push).
$unreadNotificationsCount = AuthSession::isAuthenticated()
    ? $notificationRepo->countUnread((int) AuthSession::getUserAccountId())
    : 0;
$twig->addGlobal('unread_notifications_count', $unreadNotificationsCount);
// Feeds partials/notification_dropdown.html.twig's preview — the five
// most recent unread, not one: the panel announces the count right above
// them, and a panel saying "3 notifications non lues" over a single row
// reads as two of them having gone missing. Only fetched when there's
// actually something pending, same "cheap enough, nothing to gain from
// unconditional" precedent as elsewhere in this bootstrap (see e.g.
// $linkedMembers above).
$twig->addGlobal(
    'latest_notifications',
    $unreadNotificationsCount > 0
        ? $notificationRepo->findRecentUnread((int) AuthSession::getUserAccountId(), 5)
        : []
);
$twig->addGlobal('current_user_role_label', $roleLabelMap[$currentRole] ?? 'Public');
$twig->addGlobal('current_path', $request->getPath());
$twig->addGlobal('config_mode', ConfigurationMode::isActive());
// Drives the "vous agissez au nom de" banner in base.html.twig. Null
// whenever no temporary member is active, or when one is set but does not
// resolve against the year currently in effect (ARCHITECTURE.md §8.42).
$twig->addGlobal('temporary_member_name', $temporaryMemberName);
$twig->addGlobal('effective_scout_year', $effectiveScoutYear->label);
$twig->addGlobal('effective_scout_year_id', $effectiveScoutYear->id);
$twig->addGlobal('is_year_overridden', $effectiveScoutYear->isOverridden());
$twig->addGlobal('year_override_type', $effectiveScoutYear->overrideType);
$twig->addGlobal('_editable_content_service', $editableContentService);
$twig->addGlobal('_member_photo_service', $memberPhotoService);
$twig->addGlobal('_account_photo_service', $accountPhotoService);
$twig->addGlobal('_section_photo_service', $sectionPhotoService);
$twig->addGlobal('cookie_consent_given', $cookieConsentService->hasConsented());
$twig->addGlobal('vapid_public_key', (string) ($secrets['vapid_public_key'] ?? ''));

// Offline content caching (Lot 3) — single server-side source of truth
// for both public/sw.js (routing decisions) and offline-nav.js (greying
// out unavailable links), delivered to the page as JSON and handed to
// the service worker via postMessage (base.html.twig) — never
// hardcoded/duplicated client-side. accountScope is what the cache name
// is namespaced by, so a different member logging in on the same device
// never inherits the previous one's cache (offline-cache.js). The
// 'offline_whitelist' global itself is set further below, once every
// enabled module has had a chance to register its own offline pages
// (loadEnabledModules()) — see that block's own comment for why.
$twig->addGlobal('offline_cache_staleness_days', (int) $settingService->get('offline_cache_staleness_days', null, '30'));
$twig->addGlobal('offline_functional_consent', $cookieConsentService->isAllowed('functional'));
$twig->addGlobal(
    'offline_account_scope',
    AuthSession::isAuthenticated() ? (string) AuthSession::getUserAccountId() : 'guest'
);

// Build menu
$menuBuilder = new MenuBuilder(Role::fromString($currentRole));
// Applies every enabled module's Core\Module\MenuEntryProvider late in this
// file (once module services exist) and re-derives the active-page
// highlight for what they added — see the class docblock for why that
// re-derivation cannot happen in the first pass below.
$dynamicMenuRegistrar = new DynamicMenuRegistrar();

// Register core pages in menus
$menuBuilder->addPage(MenuBuilder::MENU_NOTRE_UNITE, 'Accueil', '/', 'public', 10, false, null, MenuBuilder::SORT_GROUP_CORE, 'bi-house');
$menuBuilder->addPage(MenuBuilder::MENU_NOTRE_UNITE, 'Contact', '/contact', 'public', 20, false, null, MenuBuilder::SORT_GROUP_CORE, 'bi-envelope');
$menuBuilder->addPage(MenuBuilder::MENU_NOTRE_UNITE, 'Sections', '/sections', 'public', 30, false, null, MenuBuilder::SORT_GROUP_CORE, 'bi-diagram-3');
$menuBuilder->addPage(MenuBuilder::MENU_NOTRE_UNITE, 'Protection des données', '/rgpd', 'public', 40, false, null, MenuBuilder::SORT_GROUP_CORE, 'bi-shield-check');
$menuBuilder->addPage(MenuBuilder::MENU_ESPACE_CHEFS, 'Staffs', '/chefs/staffs', 'intendant', 10, false, null, MenuBuilder::SORT_GROUP_CORE, 'bi-people-fill', null, 'ma_section');
$menuBuilder->addPage(MenuBuilder::MENU_ESPACE_CHEFS, 'Membres par section', '/chefs/membres', 'intendant', 11, false, null, MenuBuilder::SORT_GROUP_CORE, 'bi-list-ul', null, 'ma_section');
// Édition du site — shrunk to just the configuration-mode toggle,
// moved here from the Configuration menu and widened from superadmin to
// admin (see /config-mode/activate|deactivate's own role_min and
// Core\View\ConfigurationMode, widened the same way) so every chief
// d'unité, not only a superadmin, can edit site content. First in this
// menu (order 10) — the most-used entry for a chief d'unité.
$menuBuilder->addPage(MenuBuilder::MENU_ESPACE_ADMIN, 'Édition du site', '/config/general', 'admin', 10, false, null, MenuBuilder::SORT_GROUP_CORE, 'bi-pencil-square', null, 'contenu');
$menuBuilder->addPage(MenuBuilder::MENU_ESPACE_ADMIN, 'Import Desk', '/admin/import', 'admin', 20, false, null, MenuBuilder::SORT_GROUP_CORE, 'bi-cloud-arrow-down', null, 'membres_annee');
$menuBuilder->addPage(MenuBuilder::MENU_ESPACE_ADMIN, "Points d'attention", '/admin/points-attention', 'admin', 21, false, null, MenuBuilder::SORT_GROUP_CORE, 'bi-exclamation-triangle', null, 'membres_annee');
$menuBuilder->addPage(MenuBuilder::MENU_ESPACE_ADMIN, 'Membres', '/admin/members', 'admin', 30, false, null, MenuBuilder::SORT_GROUP_CORE, 'bi-person-lines-fill', null, 'membres_annee');
$menuBuilder->addPage(MenuBuilder::MENU_ESPACE_ADMIN, 'Année scoute', '/admin/scout-year', 'admin', 40, false, null, MenuBuilder::SORT_GROUP_CORE, 'bi-calendar-range', null, 'membres_annee');
$menuBuilder->addPage(MenuBuilder::MENU_ESPACE_ADMIN, 'Journal', '/admin/journal', 'admin', 50, false, null, MenuBuilder::SORT_GROUP_CORE, 'bi-journal-text', null, 'suivi');
// Installation & serveur first (order 5, ahead of Modules/Badges below) —
// the most-used entry for a superadmin; the rest of this menu keeps its
// existing relative order.
$menuBuilder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Installation & serveur', '/setup', 'superadmin', 5, false, null, MenuBuilder::SORT_GROUP_CORE, 'bi-sliders', null, 'site');
$menuBuilder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Modules', '/config/modules', 'superadmin', 10, false, null, MenuBuilder::SORT_GROUP_CORE, 'bi-puzzle', null, 'site');
$menuBuilder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Badges', '/config/badges', 'superadmin', 12, false, null, MenuBuilder::SORT_GROUP_CORE, 'bi-award', null, 'unite_donnees');
$menuBuilder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Desk', '/config/functions', 'superadmin', 20, false, null, MenuBuilder::SORT_GROUP_CORE, 'bi-diagram-2', null, 'unite_donnees');
$menuBuilder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Réglages', '/config/settings', 'superadmin', 30, false, null, MenuBuilder::SORT_GROUP_CORE, 'bi-gear-wide-connected', null, 'site');
$menuBuilder->addPage(MenuBuilder::MENU_CONFIGURATION, 'RGPD', '/config/rgpd', 'superadmin', 35, false, null, MenuBuilder::SORT_GROUP_CORE, 'bi-shield-lock', null, 'unite_donnees');
$menuBuilder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Actions planifiées', '/config/scheduled', 'superadmin', 40, false, null, MenuBuilder::SORT_GROUP_CORE, 'bi-clock-history', null, 'exploitation');
$menuBuilder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Maintenance', '/config/maintenance', 'admin', 45, false, null, MenuBuilder::SORT_GROUP_CORE, 'bi-tools', null, 'exploitation');
$menuBuilder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Notifications', '/config/notifications', 'superadmin', 46, false, null, MenuBuilder::SORT_GROUP_CORE, 'bi-bell', null, 'exploitation');
$menuBuilder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Support', '/config/support', 'superadmin', 47, false, null, MenuBuilder::SORT_GROUP_CORE, 'bi-life-preserver', null, 'exploitation');
// order 10, not a leftover "after the separator" number — SORT_GROUP_CORE
// (addPage()'s default) already sorts this after the dynamic member
// entries/empty-state placeholder above regardless of the numeric order,
// and it's currently the only core static page in this menu.
$menuBuilder->addPage(MenuBuilder::MENU_ESPACE_ANIMES, 'Notifications', '/notifications', 'identified', 10, false, null, MenuBuilder::SORT_GROUP_CORE, 'bi-bell', null, 'pages');

// Create router early so ModuleManager can register routes
$router = new Router();

// Offline whitelist (Core\Offline\OfflineWhitelist) — single shared
// instance so module-declared pages (registered by ModuleManager below,
// as modules load) are visible both to the Twig global built later and to
// FrontController's ETag logic (§8.25).
$offlineWhitelist = new OfflineWhitelist();

// Contextual help (Core\Help, ARCHITECTURE.md §8.64) — same single-shared-
// instance reasoning as $offlineWhitelist just above: core topics live in
// docs/help/, module topics are registered by ModuleManager as each
// enabled module loads, and HelpService (built on top, below) is the one
// role-filtering consumer.
// With the serialized-index cache §8.64 planned for a 100+ topic corpus:
// keyed on installed version + active modules, disabled on 'dev' builds
// (see HelpRegistry's constructor). Saves ~100 file opens per GET.
$helpRegistry = new \Core\Help\HelpRegistry(
    dirname(__DIR__) . '/docs/help',
    new \Core\Help\HelpFrontMatterParser(),
    dirname(__DIR__) . '/storage/core/help',
    \Core\Maintenance\VersionFile::read(dirname(__DIR__))
);
$helpService = new \Core\Help\HelpService($helpRegistry);

// Create ModuleManager (modules loaded after core routes are registered)
$modulesDir = __DIR__ . '/../modules';
$moduleRegistryRepo = new ModuleRegistryRepository($pdo);

$moduleManager = new ModuleManager(
    $modulesDir,
    $settingService,
    $cookieConsentService,
    $menuBuilder,
    $moduleRegistryRepo,
    $migrationRunner,
    $journalService,
    $router,
    $notificationService,
    $offlineWhitelist,
    $installationProfile,
    $helpRegistry,
    // Manifest cache (mtime-keyed): saves re-reading and re-validating
    // every module.json on every request.
    dirname(__DIR__) . '/storage/temp'
);

// Usage statistics (Core\Statistics, ARCHITECTURE.md §8.47). Built here
// because the payload needs the ModuleManager above (module list and
// versions) and the MailService built earlier (transport mode, and whether
// mail is configured — never the credentials themselves).
$installationIdentityService = new \Core\Statistics\InstallationIdentityService(
    $settingService,
    $secretManager,
    $journalService
);
$statisticsPayloadBuilder = new \Core\Statistics\StatisticsPayloadBuilder(
    $settingService,
    $pdo,
    $installationIdentityService,
    dirname(__DIR__),
    $moduleManager,
    $mailService
);

// The same sender the daily task builds from its TaskContext (Core\Statistics\
// StatisticsServiceFactory), built here for the one thing that cannot wait for
// a scheduler run: the "envoyer un rapport de test" button on Configuration >
// Support. Constructing it opens nothing — no secret is read and no socket is
// touched until sendTest() is actually called.
$statisticsSender = new \Core\Statistics\StatisticsSender(
    $settingService,
    $statisticsPayloadBuilder,
    $installationIdentityService,
    new \Core\Statistics\StreamStatisticsTransport(),
    $journalService,
    $pdo,
    \Core\Maintenance\VersionFile::read(dirname(__DIR__))
);

// The scheduler's whole wiring — ModuleManager, TaskContext (with the
// optional cross-module capabilities handlers reach through
// getOptional()), every core handler, the hand-registered module handler
// and the recurring-task seeds — lives in ONE shared file called
// identically here and in public/cron.php. Hand-maintaining two copies is
// exactly how create_backup once ended up missing from cron.php (§8.17)
// and how rental's reminders ran without Finance under a real crontab.
require_once __DIR__ . '/scheduler-bootstrap.php';
scoutmagic_bootstrap_scheduler(
    $schedulerRunner,
    $schedulerService,
    $moduleManager,
    $connection,
    $encryptionService,
    $mailService,
    $journalService,
    $settingService,
    $userAccountRepo,
    $storagePath,
    $notificationService
);

// Bootstrap the recurring automatic backup — Task\AutoBackupHandler
// re-schedules itself at the end of every run (same pattern as
// Modules\LlmConnector\Task\RefreshModelsHandler's weekly refresh, since
// Core\Scheduler has no first-class recurring-task concept), but the very
// first occurrence needs an initial nudge.
$schedulerService->rearm('core', 'auto_backup', 'auto', new DateTimeImmutable());

// Same bootstrap for the notification retention purge (Core\Notification\
// Task\PurgeNotificationsHandler).
$schedulerService->rearm('core', 'purge_notifications', \Core\Notification\Task\PurgeNotificationsHandler::REFERENCE, new DateTimeImmutable());

// Same bootstrap for the daily stable-channel update check
// (Core\Maintenance\Task\CheckStableUpdateHandler) — the very first
// occurrence runs immediately, then it self-reschedules for 01:00 +
// jitter every day after that.
$schedulerService->rearm('core', 'check_stable_update', 'daily', new DateTimeImmutable());

// Same bootstrap for the human-check rate-limit purge (Core\Security\
// HumanCheck\Task\PurgeHumanCheckRateLimitsHandler).
$schedulerService->rearm('core', 'purge_human_check_rate_limits', \Core\Security\HumanCheck\Task\PurgeHumanCheckRateLimitsHandler::REFERENCE, new DateTimeImmutable());

// Same bootstrap for the daily usage-statistics report (Core\Statistics\
// Task\SendStatisticsHandler). The very first occurrence runs immediately;
// every guard it can trip (reporting disabled, non-public host, this site
// IS the receiver) is checked inside the handler, so seeding it here costs
// nothing on an installation that will never actually report.
$schedulerService->rearm('core', \Core\Statistics\Task\SendStatisticsHandler::TASK_KEY, \Core\Statistics\Task\SendStatisticsHandler::REFERENCE, new DateTimeImmutable());

// Same bootstrap for the support-package retention purge (Core\Support\
// Task\PurgeSupportPackagesHandler) — the archive is the most sensitive
// artefact this codebase produces on demand, so the purge must be running
// from the first boot, not from the first generation.
$schedulerService->rearm('core', \Core\Support\Task\PurgeSupportPackagesHandler::TASK_KEY, \Core\Support\Task\PurgeSupportPackagesHandler::REFERENCE, new DateTimeImmutable());

// Same bootstrap for the Desk-import retention purge (Core\Import\Task\
// PurgeImportsHandler). It must run even if nobody imports any more: a
// retention hung off the next import would keep its RGPD promise only
// while the unit keeps importing, and a unit that stops importing is
// exactly the one whose kept CSVs should stop being kept.
$schedulerService->rearm('core', \Core\Import\Task\PurgeImportsHandler::TASK_KEY, \Core\Import\Task\PurgeImportsHandler::REFERENCE, new DateTimeImmutable());

// Add dynamic member entries to Espace membres — group: SORT_GROUP_DYNAMIC keeps
// these (and the empty-state placeholder below) sorted ahead of every core
// static page and every module page in this menu regardless of numeric
// `order` (Core\View\MenuBuilder::buildPages() sorts by group first). No
// separator/magic-number order needed anymore to keep them there.
if (AuthSession::isAuthenticated()) {
    $linkedMembers = $memberService->getLinkedMembers(
        AuthSession::getEmail(),
        $effectiveScoutYear->id
    );

    foreach ($linkedMembers as $index => $member) {
        $menuBuilder->addPage(
            MenuBuilder::MENU_ESPACE_ANIMES,
            $member->getDisplayName(),
            '/members/' . $member->memberYearId,
            'identified',
            10 + $index,  // order: members first
            true,          // isDynamic = true (renders with the avatar-circle styling)
            $member->getMainSectionName(),  // subtitle
            MenuBuilder::SORT_GROUP_DYNAMIC,
            null,
            // The persistent member id, never member_years.id: the avatar
            // draws this member's photo for the year in effect, and
            // Core\Photo\MemberPhotoService is keyed on members.id.
            $member->memberId,
            'mes_membres'
        );
    }

    // Empty state message when no members are linked — conceptually the
    // same "dynamic member list" slot (hence SORT_GROUP_DYNAMIC), but isDynamic
    // stays false so it renders as a plain line, not a two-letter avatar
    // bubble carved out of this whole sentence.
    if (count($linkedMembers) === 0) {
        $menuBuilder->addPage(
            MenuBuilder::MENU_ESPACE_ANIMES,
            'Aucun membre associé à votre compte pour l\'année ' . $effectiveScoutYear->label,
            '#',
            'identified',
            10,
            false,
            null,
            MenuBuilder::SORT_GROUP_DYNAMIC,
            null,
            null,
            'mes_membres'
        );
    }
}

// Register core routes
// Public pages
$router->addRoute('GET', '/', PageController::class, 'home', 'public', ['label' => 'Accueil', 'parents' => []]);
$router->addRoute('GET', '/contact', PageController::class, 'contact', 'public', ['label' => 'Contact', 'parents' => [MenuBuilder::labelFor(MenuBuilder::MENU_NOTRE_UNITE)]]);
$router->addRoute('GET', '/sections', PageController::class, 'sections', 'public', ['label' => 'Sections', 'parents' => [MenuBuilder::labelFor(MenuBuilder::MENU_NOTRE_UNITE)]]);
$router->addRoute('GET', '/rgpd', PageController::class, 'rgpd', 'public', ['label' => 'Protection des données', 'parents' => [MenuBuilder::labelFor(MenuBuilder::MENU_NOTRE_UNITE)]]);

// Auth routes
$router->addRoute('GET', '/login', AuthController::class, 'login', 'public', ['label' => 'Connexion', 'parents' => []]);
$router->addRoute('POST', '/login/magic-link', AuthController::class, 'requestMagicLink', 'public');
$router->addRoute('POST', '/login/password', AuthController::class, 'loginWithPassword', 'public');
$router->addRoute('GET', '/login/passkey/options', AuthController::class, 'passkeyOptions', 'public');
$router->addRoute('POST', '/login/passkey/verify', AuthController::class, 'passkeyVerify', 'public');
$router->addRoute('GET', '/auth/verify', AuthController::class, 'verifyMagicLink', 'public', ['label' => 'Connexion', 'parents' => []]);
$router->addRoute('GET', '/auth/poll/{id}', AuthController::class, 'pollMagicLink', 'public');
$router->addRoute('POST', '/logout', AuthController::class, 'logout', 'identified');

// Password reset ("Mot de passe oublié")
$router->addRoute('POST', '/password-reset/request', PasswordResetController::class, 'request', 'public');
$router->addRoute('GET', '/password-reset/{id}', PasswordResetController::class, 'show', 'public', ['label' => 'Nouveau mot de passe', 'parents' => []]);
$router->addRoute('POST', '/password-reset/{id}/check', PasswordResetController::class, 'check', 'public');
$router->addRoute('POST', '/password-reset/{id}', PasswordResetController::class, 'submit', 'public');

// Account routes
$router->addRoute('GET', '/account', AccountController::class, 'index', 'identified', ['label' => 'Mon compte', 'parents' => []]);
$router->addRoute('POST', '/account/profile', AccountController::class, 'updateProfile', 'identified');
$router->addRoute('POST', '/account/password', AccountController::class, 'updatePassword', 'identified');
$router->addRoute('GET', '/account/passkey/register-options', AccountController::class, 'passkeyRegisterOptions', 'identified');
$router->addRoute('POST', '/account/passkey/register', AccountController::class, 'passkeyRegister', 'identified');
$router->addRoute('POST', '/account/passkey/delete', AccountController::class, 'passkeyDelete', 'identified');
$router->addRoute('POST', '/account/photo/delete', AccountController::class, 'deletePhoto', 'identified');
$router->addRoute('POST', '/api/push-subscription', PushSubscriptionController::class, 'subscribe', 'identified');
$router->addRoute('DELETE', '/api/push-subscription', PushSubscriptionController::class, 'unsubscribe', 'identified');

// Notification centre (Core\Notification, Lot 2)
$router->addRoute('GET', '/notifications', \Core\Http\Controller\NotificationController::class, 'index', 'identified', ['label' => 'Notifications', 'parents' => [MenuBuilder::labelFor(MenuBuilder::MENU_ESPACE_ANIMES)]]);
$router->addRoute('POST', '/notifications/{id}/read', \Core\Http\Controller\NotificationController::class, 'markRead', 'identified');
$router->addRoute('POST', '/notifications/mark-all-read', \Core\Http\Controller\NotificationController::class, 'markAllRead', 'identified');
$router->addRoute('GET', '/api/notifications/unread-count', \Core\Http\Controller\NotificationController::class, 'unreadCount', 'identified');
$router->addRoute('GET', '/notifications/preferences', \Core\Http\Controller\NotificationPreferenceController::class, 'index', 'identified');
$router->addRoute('POST', '/notifications/preferences', \Core\Http\Controller\NotificationPreferenceController::class, 'updateChannel', 'identified');
$router->addRoute('POST', '/notifications/quiet-hours', \Core\Http\Controller\NotificationPreferenceController::class, 'updateAccountSettings', 'identified');
$router->addRoute('GET', '/config/notifications', \Core\Http\Controller\NotificationConfigController::class, 'index', 'superadmin', ['label' => 'Notifications', 'parents' => [MenuBuilder::labelFor(MenuBuilder::MENU_CONFIGURATION)]]);
$router->addRoute('POST', '/config/notifications/rotate-vapid', \Core\Http\Controller\NotificationConfigController::class, 'rotateVapid', 'superadmin');
$router->addRoute('POST', '/config/notifications/test', \Core\Http\Controller\NotificationConfigController::class, 'sendTest', 'superadmin');

// Member pages
$router->addRoute('GET', '/members/{id}', MemberController::class, 'show', 'identified', ['label' => 'Membre', 'parents' => [MenuBuilder::labelFor(MenuBuilder::MENU_ESPACE_ANIMES)]]);
$router->addRoute('POST', '/members/{id}/scout-year-offset', MemberController::class, 'updateScoutYearOffset', 'chief');
$router->addRoute('POST', '/members/{id}/departure', MemberController::class, 'updateDeparture', 'admin');
// Member page "Adresses email" — self-service only, no chief/admin route
// exists for this (Core\Http\Controller\MemberEmailAddressController
// re-verifies self access on every action regardless of role_min).
$router->addRoute('POST', '/members/{id}/emails', \Core\Http\Controller\MemberEmailAddressController::class, 'add', 'identified');
$router->addRoute('POST', '/members/{id}/emails/{email_id}/resend', \Core\Http\Controller\MemberEmailAddressController::class, 'resend', 'identified');
$router->addRoute('POST', '/members/{id}/emails/{email_id}/reactivate', \Core\Http\Controller\MemberEmailAddressController::class, 'reactivate', 'identified');
$router->addRoute('POST', '/members/{id}/emails/{email_id}/delete', \Core\Http\Controller\MemberEmailAddressController::class, 'delete', 'identified');
// The confirmation link's target — public, unauthenticated, same reasoning
// as /auth/verify and /password-reset/{id}. The GET only renders a confirm
// page (prefetch-safe); the POST behind its button is what confirms.
$router->addRoute('GET', '/members/emails/confirm/{id}', \Core\Http\Controller\MemberEmailAddressController::class, 'confirm', 'public');
$router->addRoute('POST', '/members/emails/confirm/{id}', \Core\Http\Controller\MemberEmailAddressController::class, 'confirmPost', 'public');
// The email-detail route (/members/{id}/emails/{recipient_id}) is
// registered inside the mass_mail module block below (module-owned data,
// core only ever links to it) — it doesn't exist at all when mass_mail is
// disabled.

// Configuration mode — widened from superadmin to admin (chief d'unité)
// alongside the /config/general toggle page's own menu move; the real
// enforcement point is Core\View\ConfigurationMode::activate()/isActive(),
// widened the same way — this route_min only gets an admin session in the
// door before that.
$router->addRoute('POST', '/config-mode/activate', ConfigModeController::class, 'activate', 'admin');
$router->addRoute('POST', '/config-mode/deactivate', ConfigModeController::class, 'deactivate', 'admin');

// Editable content API — both routes are role_min: admin, deliberately
// less strict than the Configuration menu. Neither is authorized by being
// in that menu: the authorization comes from the HOST page, and every host
// of either endpoint is already an admin page.
//   - /api/editable-content backs configuration mode's in-place editing,
//     whose real enforcement point is Core\View\ConfigurationMode::
//     isActive() — widened to admin when the toggle moved to "Espace chefs
//     d'U" (see /config-mode/activate above). Left at superadmin, this
//     route 403'd precisely the chief d'unité the toggle had just been
//     opened to, on save.
//   - /api/rich-text-content backs partials/rich_text_field.html.twig on
//     admin pages that manage their own rich-text items (banner config,
//     registration config, the leadership module's unit note) — all of
//     them role_min: admin pages of the Espace chefs d'U menu.
// A chief, one level below, is still refused by the RBAC guard on both.
$router->addRoute('POST', '/api/editable-content', EditableContentController::class, 'update', 'admin');
$router->addRoute('POST', '/api/rich-text-content', EditableContentController::class, 'updateField', 'admin');

// Contextual help (Core\Help, ARCHITECTURE.md §8.64). Both routes are
// role_min: public — HelpService's own role filter is the per-topic gate
// (a below-role topic 404s exactly like an unknown id). `parents` stays
// empty on purpose: the help belongs to no menu, and a `parents` entry
// that matches no MenuBuilder label renders as dead text (design.md §7.3).
$router->addRoute('GET', '/aide', \Core\Http\Controller\HelpController::class, 'index', 'public', ['label' => 'Aide', 'parents' => []]);
$router->addRoute('GET', '/aide/{topic}', \Core\Http\Controller\HelpController::class, 'show', 'public', ['label' => 'Aide', 'parents' => [], 'ancestors' => [['label' => 'Aide', 'path' => '/aide']]]);

// Cookie consent
$router->addRoute('GET', '/cookies', CookieController::class, 'preferences', 'public', ['label' => 'Préférences cookies', 'parents' => []]);
$router->addRoute('POST', '/cookies/save', CookieController::class, 'save', 'public');
$router->addRoute('POST', '/cookies/accept-all', CookieController::class, 'acceptAll', 'public');
$router->addRoute('POST', '/cookies/reject-all', CookieController::class, 'rejectAll', 'public');

// File serving
$router->addRoute('GET', '/files/{id}', FileController::class, 'serve', 'public');
$router->addRoute('GET', '/files/{id}/thumbnail', FileController::class, 'thumbnail', 'public');
// Registered after the literal /thumbnail route above so that path stays
// reachable — Router::resolve() matches in registration order and a
// {variant} wildcard would otherwise happily swallow it too. Serves a
// pre-generated derivative (Core\Photo\ImageVariantService) through the
// same FileAccessGuard/journal path as serve() — see
// Core\Http\Controller\FileController::variant()'s own docblock.
$router->addRoute('GET', '/files/{id}/{variant}', FileController::class, 'variant', 'public');
// Offline mode pre-download manifest — every page/image URL it lists is
// guarded exactly like any other request once actually fetched; this
// endpoint itself only lists candidates. role_min: public — see
// Core\Http\Controller\OfflineController::manifest()'s own docblock for
// why floating this above the content's own self-limiting role filter
// would add nothing.
$router->addRoute('GET', '/api/offline/manifest', OfflineController::class, 'manifest', 'public');

// Pages after the first of an entity's change history (Core\Audit). The
// 'chief' here is a floor, not the decision: Core\Audit\
// AuditAccessResolver asks the owning module whether this visitor may
// read THIS entity, and refuses any entity type nobody registered.
$router->addRoute('GET', '/api/audit/{entity_type}/{entity_id}', \Core\Http\Controller\AuditController::class, 'page', 'chief');
// Deployment/version check — see Core\Http\Controller\VersionController's
// own docblock for why role_min is deliberately public here.
$router->addRoute('GET', '/api/version', VersionController::class, 'index', 'public');

// Generic short-URL redirector (Core\Url)
$router->addRoute('GET', '/s/{code}', ShortUrlController::class, 'resolve', 'public');

// File upload — role_min is deliberately loosened to `identified` so a
// member can upload their own photo from the member page outside
// configuration mode; UploadController::isUploadAuthorized() is the real
// authorization boundary per context (still effectively superadmin-only
// for every context except member_photo — see that method's docblock).
$router->addRoute('GET', '/upload', UploadController::class, 'index', 'identified', ['label' => 'Envoyer un fichier', 'parents' => []]);
$router->addRoute('POST', '/upload', UploadController::class, 'store', 'identified');

// Installable PWA (Lot 1) — all public: a manifest, its icons, and the
// offline fallback are all fetched with no session at all (an installed
// app's home-screen icon lookup, or a navigation with no network).
// /pwa/icon-{size}.png also now serves the unit-logo feature's favicon
// PNG sizes (16/32/48) and footer logo (64), not just the original four
// PWA sizes — same route, same "no session" reasoning, kept unrenamed
// since only base.html.twig/the manifest response ever reference it (see
// Core\Http\Controller\PwaController::icon()'s own docblock).
$router->addRoute('GET', '/manifest.webmanifest', \Core\Http\Controller\PwaController::class, 'manifest', 'public');
$router->addRoute('GET', '/pwa/icon-{size}.png', \Core\Http\Controller\PwaController::class, 'icon', 'public');
$router->addRoute('GET', '/favicon.ico', \Core\Http\Controller\PwaController::class, 'favicon', 'public');
$router->addRoute('GET', '/offline', \Core\Http\Controller\PwaController::class, 'offline', 'public');

// Setup routes (admin, but bypassed when not initialized)
$router->addRoute('GET', '/setup', SetupController::class, 'index', 'superadmin', ['label' => 'Installation & serveur', 'parents' => [MenuBuilder::labelFor(MenuBuilder::MENU_CONFIGURATION)]]);
$router->addRoute('POST', '/setup/verify-token', SetupController::class, 'verifyToken', 'superadmin');
$router->addRoute('POST', '/setup/test-db', SetupController::class, 'testDatabase', 'superadmin');
$router->addRoute('POST', '/setup/install-database', SetupController::class, 'installDatabase', 'superadmin');
$router->addRoute('POST', '/setup/backup-and-empty-db', SetupController::class, 'backupAndEmptyDatabase', 'superadmin');
$router->addRoute('GET', '/setup/download-backup', SetupController::class, 'downloadBackup', 'superadmin');
$router->addRoute('POST', '/setup/save', SetupController::class, 'save', 'superadmin');
$router->addRoute('GET', '/setup/dns', SetupController::class, 'checkDns', 'superadmin');
$router->addRoute('POST', '/setup/test-email', SetupController::class, 'testEmail', 'superadmin');
$router->addRoute('POST', '/setup/generate-dkim-key', SetupController::class, 'generateDkimKey', 'superadmin');

// Import
$router->addRoute('GET', '/admin/import', ImportController::class, 'index', 'admin', ['label' => 'Import Desk', 'parents' => [MenuBuilder::labelFor(MenuBuilder::MENU_ESPACE_ADMIN)]]);
$router->addRoute('POST', '/admin/import', ImportController::class, 'import', 'admin');
$router->addRoute('GET', '/admin/import/historique', ImportController::class, 'history', 'admin', ['label' => 'Historique des imports', 'parents' => [MenuBuilder::labelFor(MenuBuilder::MENU_ESPACE_ADMIN)], 'ancestors' => [['label' => 'Import Desk', 'path' => '/admin/import']]]);
$router->addRoute('GET', '/admin/import/{id}/rapport', ImportController::class, 'report', 'admin', ['label' => "Rapport d'import", 'parents' => [MenuBuilder::labelFor(MenuBuilder::MENU_ESPACE_ADMIN)], 'ancestors' => [['label' => 'Import Desk', 'path' => '/admin/import'], ['label' => 'Historique des imports', 'path' => '/admin/import/historique']]]);
$router->addRoute('GET', '/admin/points-attention', \Core\Http\Controller\AttentionController::class, 'index', 'admin', ['label' => "Points d'attention", 'parents' => [MenuBuilder::labelFor(MenuBuilder::MENU_ESPACE_ADMIN)]]);
$router->addRoute('GET', '/admin/doublons', \Core\Http\Controller\DuplicateMemberController::class, 'index', 'admin', ['label' => 'Fiches en double', 'parents' => [MenuBuilder::labelFor(MenuBuilder::MENU_ESPACE_ADMIN)], 'ancestors' => [['label' => "Points d'attention", 'path' => '/admin/points-attention']]]);
$router->addRoute('POST', '/admin/doublons/{id}/fusionner', \Core\Http\Controller\DuplicateMemberController::class, 'merge', 'admin');
$router->addRoute('POST', '/admin/doublons/{id}/distinctes', \Core\Http\Controller\DuplicateMemberController::class, 'markDistinct', 'admin');

// Journal
$router->addRoute('GET', '/admin/journal', JournalController::class, 'index', 'admin', ['label' => 'Journal', 'parents' => [MenuBuilder::labelFor(MenuBuilder::MENU_ESPACE_ADMIN)]]);

// Scout year navigation and transition
$router->addRoute('GET', '/admin/members', MemberSearchController::class, 'index', 'admin', ['label' => 'Membres', 'parents' => [MenuBuilder::labelFor(MenuBuilder::MENU_ESPACE_ADMIN)]]);
$router->addRoute('GET', '/admin/members/export', MemberSearchController::class, 'export', 'admin');
// Declared after /export on purpose. {id} only ever matches digits
// (Router::placeholderPattern), so "export" could not be read as one —
// but registration order is what the reader checks first, and the
// neighbouring temporary-access routes below already depend on it.
$router->addRoute('GET', '/admin/members/{id}', MemberSearchController::class, 'show', 'admin', ['label' => 'Membre', 'parents' => [MenuBuilder::labelFor(MenuBuilder::MENU_ESPACE_ADMIN)], 'ancestors' => [['label' => 'Membres', 'path' => '/admin/members']]]);
// Temporary member override (ARCHITECTURE.md §8.42). The static "remove"
// path is registered BEFORE the parameterised "add" one: Router::resolve()
// is first-match-wins and both patterns are four segments deep, so
// registration order is what keeps /admin/members/temporary-access/remove
// from being read as {id} = "temporary-access".
// Notes internes on a member's page (role_min: admin — the page's own
// floor; only the Staff d'Unité and the superadmin reach these). The
// fourth segment is the literal "notes", so none of these collides with
// the temporary-access routes below whatever the registration order.
$router->addRoute('POST', '/admin/members/{id}/notes', MemberSearchController::class, 'addNote', 'admin');
$router->addRoute('POST', '/admin/members/{id}/notes/{note_id}', MemberSearchController::class, 'updateNote', 'admin');
$router->addRoute('POST', '/admin/members/{id}/notes/{note_id}/delete', MemberSearchController::class, 'deleteNote', 'admin');
$router->addRoute('POST', '/admin/members/temporary-access/remove', TemporaryMemberController::class, 'remove', 'admin');
$router->addRoute('POST', '/admin/members/{id}/temporary-access', TemporaryMemberController::class, 'add', 'admin');
$router->addRoute('GET', '/admin/scout-year', ScoutYearController::class, 'index', 'admin', ['label' => 'Année scoute', 'parents' => [MenuBuilder::labelFor(MenuBuilder::MENU_ESPACE_ADMIN)]]);
$router->addRoute('POST', '/admin/scout-year/preview', ScoutYearController::class, 'preview', 'admin');
$router->addRoute('POST', '/admin/scout-year/clear-preview', ScoutYearController::class, 'clearPreview', 'admin');
$router->addRoute('POST', '/admin/scout-year/activate-staff', ScoutYearController::class, 'activateStaff', 'admin');
$router->addRoute('POST', '/admin/scout-year/deactivate-staff', ScoutYearController::class, 'deactivateStaff', 'admin');
$router->addRoute('POST', '/admin/scout-year/activate-public', ScoutYearController::class, 'activatePublic', 'admin');
$router->addRoute('POST', '/admin/scout-year/step', ScoutYearController::class, 'toggleStep', 'admin');

// Settings
$router->addRoute('GET', '/config/settings', SettingsController::class, 'index', 'superadmin', ['label' => 'Réglages', 'parents' => [MenuBuilder::labelFor(MenuBuilder::MENU_CONFIGURATION)]]);
$router->addRoute('POST', '/config/settings/update', SettingsController::class, 'update', 'superadmin');
$router->addRoute('POST', '/config/settings/logo-delete', SettingsController::class, 'deleteLogo', 'superadmin');
$router->addRoute('POST', '/config/settings/logo-notify-ios', SettingsController::class, 'notifyIosLogoUpdate', 'superadmin');

// Support (Core\Statistics, Core\Support — ARCHITECTURE.md §8.47/§8.48)
$router->addRoute('GET', '/config/support', SupportController::class, 'index', 'superadmin', ['label' => 'Support', 'parents' => [MenuBuilder::labelFor(MenuBuilder::MENU_CONFIGURATION)]]);
$router->addRoute('POST', '/config/support/statistics', SupportController::class, 'saveStatistics', 'superadmin');
$router->addRoute('POST', '/config/support/statistics/test', SupportController::class, 'sendTestStatistics', 'superadmin');
$router->addRoute('POST', '/config/support/package', SupportController::class, 'generatePackage', 'superadmin');
$router->addRoute('GET', '/api/support/package-status/{id}', SupportController::class, 'packageStatus', 'superadmin');

// Scheduled actions
$router->addRoute('GET', '/config/scheduled', ScheduledActionsController::class, 'index', 'superadmin', ['label' => 'Actions planifiées', 'parents' => [MenuBuilder::labelFor(MenuBuilder::MENU_CONFIGURATION)]]);
$router->addRoute('GET', '/config/maintenance', MaintenanceController::class, 'index', 'admin', ['label' => 'Maintenance', 'parents' => [MenuBuilder::labelFor(MenuBuilder::MENU_CONFIGURATION)]]);
$router->addRoute('POST', '/config/maintenance/backup/database', MaintenanceController::class, 'createDatabaseBackup', 'admin');
$router->addRoute('POST', '/config/maintenance/backup/full', MaintenanceController::class, 'createFullBackup', 'admin');
$router->addRoute('POST', '/config/maintenance/backup/auto-frequency', MaintenanceController::class, 'updateAutoBackupFrequency', 'admin');
$router->addRoute('GET', '/api/maintenance/backup-status/{id}', MaintenanceController::class, 'backupStatus', 'admin');
$router->addRoute('POST', '/config/maintenance/update/install', MaintenanceController::class, 'installUpdate', 'superadmin');
$router->addRoute('POST', '/config/maintenance/update/check-now', MaintenanceController::class, 'checkForUpdatesNow', 'admin');
$router->addRoute('GET', '/api/maintenance/update-status/{id}', MaintenanceController::class, 'updateStatus', 'admin');
$router->addRoute('POST', '/config/maintenance/reset/settings', MaintenanceController::class, 'resetSettings', 'superadmin');
$router->addRoute('POST', '/config/maintenance/reset/full', MaintenanceController::class, 'fullReset', 'superadmin');
$router->addRoute('POST', '/config/maintenance/reset/restore', MaintenanceController::class, 'restoreBackup', 'superadmin');
$router->addRoute('POST', '/config/maintenance/restore-upload-chunk', MaintenanceController::class, 'restoreUploadChunk', 'superadmin');
$router->addRoute('GET', '/api/maintenance/reset-status/{id}', MaintenanceController::class, 'resetStatus', 'admin');
$router->addRoute('POST', '/config/maintenance/auto-update/save', MaintenanceController::class, 'saveAutoUpdatePreferences', 'admin');
$router->addRoute('POST', '/api/maintenance/webhook-secret', MaintenanceController::class, 'generateWebhookSecret', 'admin');
// The only public, CSRF-free route in the codebase — GitHub is a machine
// caller with no session; the HMAC-SHA256 signature (Core\Maintenance\
// GitHubWebhookService::verifySignature()) is what authenticates it
// instead. See Core\Http\Controller\WebhookController's own docblock.
$router->addRoute('POST', '/api/webhook/github', \Core\Http\Controller\WebhookController::class, 'github', 'public');

// Édition du site — shrunk to just the configuration-mode toggle
// (module registry and badges split out below); moved to "Espace chefs d'U"
// in the menu (see addPage() above) and widened to admin, same as the
// /config-mode/* routes it links to — URL kept unchanged, nothing forces it
// to change.
$router->addRoute('GET', '/config/general', ConfigGeneralController::class, 'index', 'admin', ['label' => 'Édition du site', 'parents' => [MenuBuilder::labelFor(MenuBuilder::MENU_ESPACE_ADMIN)]]);

// Configuration > Modules — module registry (split out of Configuration
// générale, ARCHITECTURE §7.1). Stays superadmin, in the Configuration menu.
$router->addRoute('GET', '/config/modules', ConfigModulesController::class, 'index', 'superadmin', ['label' => 'Modules', 'parents' => [MenuBuilder::labelFor(MenuBuilder::MENU_CONFIGURATION)]]);
$router->addRoute('POST', '/config/modules/toggle', ConfigModulesController::class, 'toggleModule', 'superadmin');
$router->addRoute('POST', '/config/modules/reorder', ConfigModulesController::class, 'reorderModules', 'superadmin');

// Configuration > Badges — badge registry (split out of Configuration
// générale, ARCHITECTURE §8.11). Stays superadmin, in the Configuration menu.
$router->addRoute('GET', '/config/badges', ConfigBadgesController::class, 'index', 'superadmin', ['label' => 'Badges', 'parents' => [MenuBuilder::labelFor(MenuBuilder::MENU_CONFIGURATION)]]);
$router->addRoute('POST', '/config/badges/add', ConfigBadgesController::class, 'addBadge', 'superadmin');
$router->addRoute('POST', '/config/badges/update', ConfigBadgesController::class, 'updateBadge', 'superadmin');
$router->addRoute('POST', '/config/badges/toggle-active', ConfigBadgesController::class, 'toggleBadgeActive', 'superadmin');
$router->addRoute('POST', '/config/badges/delete', ConfigBadgesController::class, 'deleteBadge', 'superadmin');

// RGPD configuration
$router->addRoute('GET', '/config/rgpd', RgpdConfigController::class, 'index', 'superadmin', ['label' => 'RGPD', 'parents' => [MenuBuilder::labelFor(MenuBuilder::MENU_CONFIGURATION)]]);
$router->addRoute('POST', '/config/rgpd/save', RgpdConfigController::class, 'save', 'superadmin');
$router->addRoute('POST', '/config/rgpd/generate', RgpdConfigController::class, 'generate', 'superadmin');
$router->addRoute('POST', '/config/rgpd/reset', RgpdConfigController::class, 'reset', 'superadmin');

// Staffs
$router->addRoute('GET', '/chefs/staffs', StaffsController::class, 'index', 'intendant', ['label' => 'Staffs', 'parents' => [MenuBuilder::labelFor(MenuBuilder::MENU_ESPACE_CHEFS)]]);
$router->addRoute('POST', '/chefs/staffs/badge-toggle', StaffsController::class, 'toggleBadge', 'chief');
$router->addRoute('GET', '/chefs/membres', \Core\Http\Controller\SectionRosterController::class, 'index', 'intendant', ['label' => 'Membres par section', 'parents' => [MenuBuilder::labelFor(MenuBuilder::MENU_ESPACE_CHEFS)]]);
$router->addRoute('GET', '/chefs/membres/export', \Core\Http\Controller\SectionRosterController::class, 'export', 'intendant');
$router->addRoute('POST', '/chefs/staffs/documents', \Core\Http\Controller\SectionDocumentController::class, 'add', 'chief');
$router->addRoute('POST', '/chefs/staffs/documents/reorder', \Core\Http\Controller\SectionDocumentController::class, 'reorder', 'chief');
$router->addRoute('POST', '/chefs/staffs/documents/delete', \Core\Http\Controller\SectionDocumentController::class, 'delete', 'chief');
$router->addRoute('POST', '/chefs/staffs/documents/{id}', \Core\Http\Controller\SectionDocumentController::class, 'update', 'chief');

// Functions configuration
$router->addRoute('GET', '/config/functions', FunctionsController::class, 'index', 'superadmin', ['label' => 'Desk', 'parents' => [MenuBuilder::labelFor(MenuBuilder::MENU_CONFIGURATION)]]);
$router->addRoute('POST', '/config/functions/update', FunctionsController::class, 'update', 'superadmin');
$router->addRoute('POST', '/config/functions/flags', FunctionsController::class, 'updateFlags', 'superadmin');
$router->addRoute('POST', '/config/functions/section-name', FunctionsController::class, 'updateSectionName', 'superadmin');
$router->addRoute('POST', '/config/functions/section-email', FunctionsController::class, 'updateSectionEmail', 'superadmin');
$router->addRoute('POST', '/config/functions/section-visibility', FunctionsController::class, 'updateSectionVisibility', 'superadmin');
$router->addRoute('POST', '/config/functions/section-color', FunctionsController::class, 'updateSectionColor', 'superadmin');
$router->addRoute('POST', '/config/functions/branch-url', FunctionsController::class, 'updateBranchUrl', 'superadmin');

// Load enabled modules (routes registered AFTER core routes so core takes priority)
$moduleManager->loadEnabledModules();

// Desk-import listeners (Core\Import\DeskImportListener, ARCHITECTURE.md
// §7.4) — a module reconciling its own references to members.id at the end
// of an import. $importService was built far above, before $moduleManager
// existed, so it is rebuilt here rather than gaining a forward reference;
// every ImportController registration happens later in this file and so
// picks up this instance, including the registration module's own
// re-registration.
$deskImportListeners = [];
if (in_array('rental', $moduleManager->getEnabledModuleIds(), true)) {
    $deskImportListeners[] = new \Modules\Rental\Service\RentalDeskImportListener(
        new \Modules\Rental\Repository\RentalAssetManagerRepository($pdo),
        $journalService
    );
}
if ($deskImportListeners !== []) {
    $importService = new DeskImportService(
        $pdo, $encryptionService, $csvParser, $mappingResolver,
        $memberRepo, $memberYearRepo, $importJournalRepo, $userAccountRepo, $unitStaffSectionService,
        $sectionMembershipService, $rosterReplacementGuard, $journalService, $rosterSnapshotRepository,
        $encryptedFileStorageService, $importDiffCalculator, $duplicateMemberDetector, $deskImportListeners
    );
}

// Register module template namespaces in Twig
$twigLoader = $twig->getLoader();
if ($twigLoader instanceof \Twig\Loader\FilesystemLoader) {
    foreach ($moduleManager->getEnabledModuleIds() as $moduleId) {
        $viewsPath = $modulesDir . '/' . $moduleId . '/views';
        if (is_dir($viewsPath)) {
            $twigLoader->addPath($viewsPath, $moduleId);
        }
    }
}

// Build menus (after module pages are registered)
$menus = $menuBuilder->build();
$twig->addGlobal('menus', $menus);

// Offline whitelist (Core\Offline\OfflineWhitelist) — built only now, once
// every enabled module has registered its own offline pages via
// loadEnabledModules() above, and filtered by the current viewer's
// effective role so a logged-out visitor is never handed a chief-only
// path like /previsions or /chiefs/stats (and the client never tries to
// cache a page it will only ever get a 403 for).
$twig->addGlobal('offline_whitelist', $offlineWhitelist->getEntriesForRole(Role::fromString($currentRole)));

// Determine the active menu section AND which specific page button should
// be highlighted from the current path. A page's own sub-routes (e.g.
// finance's /finance/movements, /finance/receipts — registered with an
// empty label precisely so they don't get their own menu button, see
// ModuleManager's "if route label !== ''" gate) never appear in $menu
// as a page in their own right, so an exact-match-only comparison used to
// lose the highlight entirely once you drilled into one of them. Longest
// registered page.url that is either an exact match or a genuine path-
// segment prefix of the current path wins — this keeps a module's own
// top-level page (e.g. "Finances") highlighted while browsing any of its
// sub-pages, in every module, not just finance.
$currentPath = $request->getPath();
$activeMenuId = '';
$activePageUrl = '';
$bestMatchLength = -1;
foreach ($menus as $menu) {
    foreach ($menu['pages'] as $page) {
        $pageUrl = $page['url'];
        if ($pageUrl === '') {
            continue;
        }
        $isExact = $pageUrl === $currentPath;
        $isPrefix = !$page['isDynamic'] && $pageUrl !== '/' && str_starts_with($currentPath, $pageUrl . '/');
        if (($isExact || $isPrefix) && strlen($pageUrl) > $bestMatchLength) {
            $activeMenuId = $menu['id'];
            $activePageUrl = $pageUrl;
            $bestMatchLength = strlen($pageUrl);
        }
    }
}
$twig->addGlobal('active_menu_id', $activeMenuId);
$twig->addGlobal('active_page_url', $activePageUrl);

// RGPD content service (may use LLM if module is active). Each module's
// effective sub-processors reach it through the Core\Module\
// SubProcessorProvider hook (§7.4), registered from the module's own
// block below — core never reads a module's tables for the RGPD page.
$llmConnectorForRgpd = null;
$llmSubProcessorProvider = null;
if (in_array('llm_connector', $moduleManager->getEnabledModuleIds(), true)) {
    $llmProviderRepoForRgpd = new \Modules\LlmConnector\Repository\ProviderRepository($pdo, $encryptionService);
    $llmModelRepoForRgpd = new \Modules\LlmConnector\Repository\ProviderModelRepository($pdo);
    $llmConnectorForRgpd = new \Modules\LlmConnector\Service\LlmConnectorService($llmProviderRepoForRgpd, $llmModelRepoForRgpd, $journalService);
    $llmSubProcessorProvider = new \Modules\LlmConnector\Service\LlmSubProcessorService($llmProviderRepoForRgpd, $llmModelRepoForRgpd);
}
$rgpdContentService = new RgpdContentService($moduleManager, $settingService, $llmConnectorForRgpd);
if ($llmSubProcessorProvider !== null) {
    $rgpdContentService->addSubProcessorProvider($llmSubProcessorProvider);
}

// Household size and the fee category it implies (ARCHITECTURE.md §8.34).
// A core service, built once here rather than inside the registration
// module's own block: it was assembled in there because that module was
// its first caller, which made a CORE service disappear the moment an
// optional module was switched off. The module still contributes its
// accepted/encoded requests to the PROJECTED count, through the same
// nullable Api provider as before (ARCHITECTURE.md §7.5) — null when it is
// disabled, and the service degrades to counting members alone.
$householdRegistrationCount = null;
if (in_array('registration', $moduleManager->getEnabledModuleIds(), true)) {
    $householdRegistrationCount = new \Modules\Registration\Service\HouseholdRegistrationCountService(
        new \Modules\Registration\Repository\RegistrationRequestRepository($pdo, $encryptionService)
    );
}
$feeEstimationService = new \Core\Member\FeeEstimationService(
    new \Core\Member\FeeEstimationRepository($pdo),
    $encryptionService,
    $householdRegistrationCount
);
// Its twin, and core for the same reason: the two counts a household has
// (what Desk holds, what it will hold) are not the fees module's notion,
// they are the roster's. Built here so a module can consume it without
// owning it.
$householdService = new \Core\Member\Household\HouseholdService(
    new \Core\Member\Household\HouseholdRepository($pdo),
    $encryptionService,
    $householdRegistrationCount
);

// Handle the request
$maintenanceGate = new \Core\Maintenance\MaintenanceGate($updateHistoryRepository);
$frontController = new FrontController($router, $twig, $config, $offlineWhitelist, $maintenanceGate, $helpService);

// Entity change history pages (Core\Audit) — registered here rather than
// next to its route, because $frontController does not exist yet at the
// point the routes are declared.
$frontController->registerController(
    \Core\Http\Controller\AuditController::class,
    new \Core\Http\Controller\AuditController($twig, $auditService, $auditAccessResolver)
);

// Contextual help pages (Core\Http\Controller\HelpController) — needs the
// HelpService built next to the registry above, after every enabled
// module had its chance to register topics.
$frontController->registerController(
    \Core\Http\Controller\HelpController::class,
    new \Core\Http\Controller\HelpController($twig, $helpService)
);

// Optional dependency on the trombinoscope module (ARCHITECTURE.md §7.4)
// for the Sections page's "responsable" name — set below only when
// 'trombinoscope' is enabled; every PageController re-registration after
// that block reuses this variable, exactly like $bannerService and
// $newsArticleService just below.
$sectionResponsableProvider = null;

// The other two optional home-page hook providers (ARCHITECTURE.md
// §7.4), each set further down only when its own module is enabled.
// Declared here for the same reason as the line above: the
// PageController re-registrations in news' and groups' blocks reuse
// whichever ones are real, so no hook is lost when several modules are
// active — and they must be readable whether or not those blocks ran.
$bannerService = null;
$newsArticleService = null;

// The homepage's "il reste quelque chose à payer" band and the member
// page's payment block (ARCHITECTURE.md §8.85), both set in finance's
// block below and both null when that module is disabled — the band and
// the block then simply do not render.
$homePaymentDueProvider = null;
$memberPaymentProvider = null;

// The admin member page's "demande d'inscription d'origine" line
// (ARCHITECTURE.md §7.4), set in registration's block below and null when
// that module is disabled — the line then simply does not render, which
// is also what a member who never came through a request gets.
$memberRegistrationOriginProvider = null;

// Optional dependency on the calendar module (ARCHITECTURE.md §7.5) for
// the member page's "next upcoming event" (§3) — set below only when
// 'calendar' is enabled, same pattern as $sectionResponsableProvider
// above.
$calendarEventLookup = null;

// Optional dependency on the leadership module (ARCHITECTURE.md §7.5) for
// the member page's own "Mon parcours de formation" card (§6bis) — set in
// that module's block below, same pattern as the two above.
$formationPathProvider = null;

// The admin member page's « parcours » blocks (ARCHITECTURE.md §7.4),
// set in the camps and groups blocks below and null when either module is
// disabled — the block is then not built at all. The leadership half of
// that parcours uses $formationPathProvider above, unchanged: the same
// hook now feeds two pages.
$memberCampStayProvider = null;
$memberDiscussionGroupProvider = null;

// Optional dependency on the finance module (ARCHITECTURE.md §7.5) for
// keeping a document as a receipt on one of the unit's accounts — set in
// finance's own block below. The fees module's federation invoice is the
// first consumer: with finance disabled the checkbox is simply not
// offered, the PDF is not kept, and the verification works the same.
$expenseReceiptProvider = null;

// Baseline MemberPageService (core deps only) — re-registered further
// down, once mass_mail/gallery/trombinoscope/calendar/leadership
// availability is known, exactly like MemberController itself.
$memberPageService = new \Core\Member\MemberPageService(
    $sectionService, $memberService, $badgeRepository, $memberBadgeRepository, $ageBranchRepo, $memberDocumentService, $memberEmailService,
    $sectionDocumentService
);

// Register controllers with dependencies
$frontController->registerController(PageController::class, new PageController($twig, $editableContentService, $sectionRepository, $settingService, $rgpdContentService, $sectionService, $unitStaffSectionService, $scoutYearService));
$frontController->registerController(CookieController::class, new CookieController($twig, $cookieConsentService));
$setupController = new SetupController($twig, $secretManager, $dkimManager, $schemaPath, __DIR__);
$setupController->setSettingService($settingService);
$setupController->setJournalService($journalService);
$setupController->setUnitLogoService($unitLogoService);
$frontController->registerController(SetupController::class, $setupController);
// Build auth dependencies
$authService->setJournalService($journalService);
$passwordResetService->setJournalService($journalService);
$loginThrottler = new LoginThrottler($connection, $encryptionService);
$passwordAuthMethod = new PasswordAuthMethod($userAccountRepo, $encryptionService, $loginThrottler);
$passwordAuthMethod->setJournalService($journalService);
$humanCheckService = new \Core\Security\HumanCheck\HumanCheckService(
    $encryptionService,
    new \Core\Security\HumanCheck\HumanCheckRateLimitRepository($pdo),
    $settingService,
    $journalService
);
$webAuthnCredentialRepo = new WebAuthnCredentialRepository($pdo);
$webAuthnBaseUrl = (string) ($settingService->get('base_url') ?: 'https://localhost');
$webAuthnService = new WebAuthnService(
    $webAuthnCredentialRepo,
    $userAccountRepo,
    parse_url($webAuthnBaseUrl, PHP_URL_HOST) ?: 'localhost',
    (string) ($settingService->get('site_name') ?: 'Unité scoute'),
    $webAuthnBaseUrl
);

$authController = new AuthController($twig, $authService, $roleResolver, $scoutYearResolver, $cookieConsentService);
$authController->setPasswordAuth($passwordAuthMethod);
$authController->setWebAuthnService($webAuthnService);
$authController->setHumanCheck($humanCheckService);
$frontController->registerController(AuthController::class, $authController);
$frontController->registerController(AccountController::class, new AccountController($twig, $userAccountRepo, $webAuthnCredentialRepo, $webAuthnService, $accountPhotoService));
$frontController->registerController(PushSubscriptionController::class, new PushSubscriptionController($twig, $notificationService, $journalService));

$frontController->registerController(
    \Core\Http\Controller\NotificationController::class,
    new \Core\Http\Controller\NotificationController($twig, $notificationRepo)
);
$frontController->registerController(
    \Core\Http\Controller\NotificationPreferenceController::class,
    new \Core\Http\Controller\NotificationPreferenceController(
        $twig,
        $notificationService,
        $notificationPreferenceRepo,
        $userAccountRepo,
        $roleResolver,
        $scoutYearService
    )
);
$frontController->registerController(
    \Core\Http\Controller\NotificationConfigController::class,
    new \Core\Http\Controller\NotificationConfigController(
        $twig,
        $notificationService,
        $settingService,
        $journalService,
        $secretManager,
        $pdo
    )
);
$frontController->registerController(MaintenanceController::class, new MaintenanceController(
    $twig, $backupService, $backupRepository, $fileRepository, $updateHistoryRepository, $schedulerService, $moduleManager, $encryptionService, $journalService, $settingService, $storagePath, $secretManager
));
$frontController->registerController(VersionController::class, new VersionController($twig, $storagePath));
$githubWebhookService = new \Core\Maintenance\GitHubWebhookService(
    $settingService, $schedulerService, $updateHistoryRepository, $journalService, dirname($storagePath)
);
$frontController->registerController(\Core\Http\Controller\WebhookController::class, new \Core\Http\Controller\WebhookController(
    $twig, $githubWebhookService, $secretManager, $journalService
));
$passwordResetController = new PasswordResetController($twig, $passwordResetService);
$passwordResetController->setHumanCheck($humanCheckService);
$frontController->registerController(PasswordResetController::class, $passwordResetController);
$frontController->registerController(ShortUrlController::class, new ShortUrlController($twig, $shortUrlService));
$frontController->registerController(ImportController::class, new ImportController($twig, $importService, $scoutYearResolver, $importJournalRepo, $functionRepo, $importRetentionService, $rosterSnapshotRepository, $fileRepository, $userAccountRepo, $importReportPresenter, $storagePath, $registrationReconciliation ?? null));
$frontController->registerController(MemberController::class, new MemberController($twig, $memberService, $memberYearService, $journalService, $memberPageService, $departureService));
$frontController->registerController(
    \Core\Http\Controller\MemberEmailAddressController::class,
    new \Core\Http\Controller\MemberEmailAddressController($twig, $memberEmailService, $memberService)
);
$frontController->registerController(StaffsController::class, new StaffsController(
    $twig, $sectionService, $memberService, $scoutYearResolver, $journalService, $badgeService,
    $unitStaffSectionService, $sectionDocumentService, $settingService, $sectionStaffAuthorizationService
));
$frontController->registerController(\Core\Http\Controller\SectionRosterController::class, new \Core\Http\Controller\SectionRosterController(
    $twig, $sectionService, $sectionRosterService, $memberExportRowBuilder, $memberExportService, $scoutYearResolver, $journalService
));
$frontController->registerController(\Core\Http\Controller\SectionDocumentController::class, new \Core\Http\Controller\SectionDocumentController(
    $twig, $sectionDocumentService, $sectionStaffAuthorizationService, $scoutYearResolver, $journalService
));
$frontController->registerController(ConfigModeController::class, new ConfigModeController($twig));
$editableContentController = new EditableContentController($twig, $editableContentService);
$editableContentController->setJournalService($journalService);
$frontController->registerController(EditableContentController::class, $editableContentController);
// FileController (and the FileAccessGuard it consumes) is registered at
// the end of this file instead of here — see the comment there.
// staffDirectoryProvider is wired for real inside the trombinoscope block
// below (re-registered there, same Core\Module\SectionResponsableProvider
// precedent as PageController) — null here degrades to "no trombinoscope
// photos in the manifest" when that module is disabled.
$offlineManifestService = new \Core\Offline\OfflineManifestService(
    $offlineWhitelist, $memberService, $memberPhotoService, $sectionPhotoService, $sectionService,
    $unitStaffSectionService, $scoutYearService, $editableContentService, $ageBranchRepo, null,
    $temporaryMemberProvider
);
$offlineController = new OfflineController($twig, $offlineManifestService);
$frontController->registerController(OfflineController::class, $offlineController);
$photoIngestionService = new \Core\Photo\PhotoIngestionService(
    $uploadHandler,
    $editableContentService,
    $memberPhotoService,
    $sectionPhotoService,
    $sectionPhotoProcessor,
    $landscapeImageProcessor,
    $ageBranchRepo,
    $unitLogoService,
    $imageVariantService,
    $accountPhotoService
);
$uploadController = new UploadController($twig, $photoIngestionService, $memberService);
$uploadController->setJournalService($journalService);
$frontController->registerController(UploadController::class, $uploadController);
$frontController->registerController(\Core\Http\Controller\PwaController::class, new \Core\Http\Controller\PwaController($twig, $settingService, $unitLogoService));
$frontController->registerController(JournalController::class, new JournalController($twig, $journalRepo, $userAccountRepo));
$frontController->registerController(TemporaryMemberController::class, new TemporaryMemberController($twig, $memberSearchService, $scoutYearResolver, $journalService));
$frontController->registerController(SettingsController::class, new SettingsController($twig, $settingService, $journalService, $unitLogoService, $notificationService, $userAccountRepo));
$frontController->registerController(SupportController::class, new SupportController(
    $twig,
    $settingService,
    $journalService,
    $statisticsPayloadBuilder,
    $schedulerService,
    $statisticsSender
));
$frontController->registerController(ScheduledActionsController::class, new ScheduledActionsController($twig, $schedulerRepo));
$frontController->registerController(ConfigGeneralController::class, new ConfigGeneralController($twig));
$frontController->registerController(ConfigModulesController::class, new ConfigModulesController($twig, $moduleManager, $journalService));
$frontController->registerController(ConfigBadgesController::class, new ConfigBadgesController($twig, $badgeService, $journalService));
$frontController->registerController(FunctionsController::class, new FunctionsController($twig, $functionRepo, $journalService, $sectionService, $unitStaffSectionService, $scoutYearResolver, $badgeService, $ageBranchRepo));
$frontController->registerController(PlaceholderController::class, new PlaceholderController($twig));

// Module controllers with dependencies (only wired when the module is enabled).
if (in_array('member_stats', $moduleManager->getEnabledModuleIds(), true)) {
    $memberStatsService = new \Modules\MemberStats\Service\MemberStatsService(
        new \Modules\MemberStats\Repository\MemberStatsRepository($connection, $encryptionService),
        $sectionService,
        $memberYearService
    );
    $frontController->registerController(
        \Modules\MemberStats\Controller\MemberStatsController::class,
        new \Modules\MemberStats\Controller\MemberStatsController($twig, $memberStatsService, $scoutYearResolver)
    );
}

if (in_array('trombinoscope', $moduleManager->getEnabledModuleIds(), true)) {
    // Re-registers FunctionsController with the trombinoscope function-flags
    // hook (Core\Module\FunctionFlagsProvider) so the Config Desk page can
    // expose the "responsable" checkbox — core never depends on the module
    // directly, only on the interface it implements.
    $trombinoscopeFunctionFlagsService = new \Modules\Trombinoscope\Service\FunctionFlagsService(
        new \Modules\Trombinoscope\Repository\FunctionFlagsRepository($pdo)
    );
    $frontController->registerController(
        FunctionsController::class,
        new FunctionsController($twig, $functionRepo, $journalService, $sectionService, $unitStaffSectionService, $scoutYearResolver, $badgeService, $ageBranchRepo, $trombinoscopeFunctionFlagsService)
    );

    $trombinoscopeService = new \Modules\Trombinoscope\Service\TrombinoscopeService(
        new \Modules\Trombinoscope\Repository\TrombinoscopeRepository($connection),
        $sectionService
    );
    $frontController->registerController(
        \Modules\Trombinoscope\Controller\TrombinoscopeController::class,
        new \Modules\Trombinoscope\Controller\TrombinoscopeController($twig, $sectionService, $trombinoscopeService, $scoutYearResolver)
    );

    // Re-registers PageController with the real section-responsable
    // provider (Core\Module\SectionResponsableProvider) — same core-hook
    // precedent as the banner/news blocks below (ARCHITECTURE.md §7.4).
    $sectionResponsableProvider = $trombinoscopeService;
    $frontController->registerController(
        PageController::class,
        new PageController($twig, $editableContentService, $sectionRepository, $settingService, $rgpdContentService, $sectionService, $unitStaffSectionService, $scoutYearService, null, null, $sectionResponsableProvider)
    );

    // Re-registers OfflineController with the real staff directory
    // (Core\Module\StaffDirectoryProvider) — same core-hook precedent as
    // $sectionResponsableProvider just above.
    $frontController->registerController(
        OfflineController::class,
        new OfflineController($twig, new \Core\Offline\OfflineManifestService(
            $offlineWhitelist, $memberService, $memberPhotoService, $sectionPhotoService, $sectionService,
            $unitStaffSectionService, $scoutYearService, $editableContentService, $ageBranchRepo, $trombinoscopeService,
            $temporaryMemberProvider
        ))
    );
}

// Virtual-event providers (Modules\Calendar\Api\
// VirtualEventProviderInterface, ARCHITECTURE.md §7.6) — the "a module
// extended by another module" direction, which is neither §7.4 (core
// extended by a module) nor §7.5 (a module consuming another's read API).
//
// **This is where the rental ↔ calendar circularity is broken.** `rental`
// consumes `calendar` (it needs the list of calendars to publish onto) and
// `calendar` consumes `rental` (the provider), so neither can be built
// after the other. The registry is created EMPTY here, handed to the
// calendar's controllers below, and appended to from the rental block
// further down — the controllers hold the registry object rather than a
// snapshot of its contents, so a provider registered later still reaches
// them. Same shape as $fileOwnershipCheckers, one level up.
//
// Null when `calendar` is disabled: rental then has nothing to publish
// onto and never builds a provider, which is the clean degradation in that
// direction.
$calendarVirtualEventRegistry = null;

// The two calendar collaborators other modules consume, seeded null and
// assigned inside the block below — same convention as
// $financeExpectedReceivableForOthers above. Declaring them here rather
// than reaching for $calendarService from inside another module's block is
// what keeps every consumer provably defined.
$calendarServiceForOthers = null;
$calendarIcsBuilderForOthers = null;

if (in_array('calendar', $moduleManager->getEnabledModuleIds(), true)) {
    $calendarVirtualEventRegistry = new \Modules\Calendar\Service\VirtualEventRegistry();
    $calendarRepo = new \Modules\Calendar\Repository\CalendarRepository($pdo, $encryptionService);
    $calendarEventRepo = new \Modules\Calendar\Repository\CalendarEventRepository($pdo);
    $calendarPersonalTokenRepo = new \Modules\Calendar\Repository\CalendarPersonalTokenRepository($pdo, $encryptionService);
    $calendarUnitFeedTokenRepo = new \Modules\Calendar\Repository\CalendarUnitFeedTokenRepository($pdo, $encryptionService);

    // Api\ScoutYearEventCountProvider (ARCHITECTURE.md §7.5) — read by the
    // "Année scoute" workflow to tell whether this year's éphémérides have
    // been encoded. Left null below when this module is off, which is what
    // makes that workflow drop its "encoder les éphémérides" step.
    $calendarScoutYearEventCount = new \Modules\Calendar\Service\ScoutYearEventCountService($calendarEventRepo);

    $calendarService = new \Modules\Calendar\Service\CalendarService(
        $calendarRepo, $calendarEventRepo, $sectionService, $calendarUnitFeedTokenRepo
    );
    $calendarNotificationService = new \Modules\Calendar\Service\CalendarNotificationService(
        $schedulerService, $settingService, $calendarService, $calendarEventRepo, $notificationService, $userAccountRepo
    );
    $calendarEventService = new \Modules\Calendar\Service\CalendarEventService(
        $calendarEventRepo, $calendarService, $calendarNotificationService
    );
    $calendarPersonalFeedService = new \Modules\Calendar\Service\PersonalFeedService(
        $calendarPersonalTokenRepo, $calendarService, $calendarEventRepo,
        $roleResolver, $memberService, $userAccountRepo, $sectionService
    );
    $calendarPickerService = new \Modules\Calendar\Service\CalendarPickerService(
        $calendarService, $calendarPersonalFeedService
    );
    $monthGridBuilder = new \Core\View\MonthGrid\MonthGridBuilder();
    $calendarIcsBuilder = new \Modules\Calendar\Service\IcsBuilder();

    $calendarServiceForOthers = $calendarService;
    $calendarIcsBuilderForOthers = $calendarIcsBuilder;

    $frontController->registerController(
        \Modules\Calendar\Controller\CalendarPublicController::class,
        new \Modules\Calendar\Controller\CalendarPublicController(
            $twig, $calendarService, $calendarPickerService, $monthGridBuilder, $calendarPersonalFeedService,
            $calendarIcsBuilder, $scoutYearResolver, $journalService, $calendarVirtualEventRegistry
        )
    );
    $frontController->registerController(
        \Modules\Calendar\Controller\CalendarChiefController::class,
        new \Modules\Calendar\Controller\CalendarChiefController(
            $twig, $calendarService, $calendarPickerService, $monthGridBuilder, $calendarEventService,
            $sectionService, $memberService, $scoutYearResolver, $journalService, $settingService, $moduleManager,
            $sectionStaffAuthorizationService
        )
    );
    $frontController->registerController(
        \Modules\Calendar\Controller\CalendarConfigController::class,
        new \Modules\Calendar\Controller\CalendarConfigController(
            $twig, $calendarService, $sectionService, $settingService, $journalService, $calendarNotificationService
        )
    );
}

if (in_array('sos_staff', $moduleManager->getEnabledModuleIds(), true)) {
    $sosProviderCredentialRepo = new \Modules\SosStaff\Repository\ProviderCredentialRepository($pdo, $encryptionService);
    $sosSettingsRepo = new \Modules\SosStaff\Repository\SosSettingsRepository($pdo);
    $sosExcludedSectionRepo = new \Modules\SosStaff\Repository\ExcludedSectionRepository($pdo);
    $sosOnCallRepo = new \Modules\SosStaff\Repository\OnCallRepository($pdo);
    $sosCalendarSyncRepo = new \Modules\SosStaff\Repository\CalendarSyncRepository($pdo);

    // Optional dependency on the trombinoscope module — the default
    // number's auto-resolution falls back to the first Staff d'U roster
    // member (Service\SosSettingsService) when trombinoscope is disabled or
    // no "responsable" is flagged, so this is never a hard requirement.
    $sosTrombinoscopeRepo = in_array('trombinoscope', $moduleManager->getEnabledModuleIds(), true)
        ? new \Modules\Trombinoscope\Repository\TrombinoscopeRepository($connection)
        : null;

    $sosProviderConfigService = new \Modules\SosStaff\Service\ProviderConfigService($sosProviderCredentialRepo);
    $sosSettingsService = new \Modules\SosStaff\Service\SosSettingsService(
        $sosExcludedSectionRepo, $sosSettingsRepo, $sectionService, $memberYearRepo, $unitStaffSectionService,
        $settingService, $sosTrombinoscopeRepo
    );
    $sosOnCallService = new \Modules\SosStaff\Service\OnCallService($sosOnCallRepo, $schedulerService, $sosSettingsService);
    $sosRedirectService = new \Modules\SosStaff\Service\RedirectService(
        $sosProviderConfigService, $sosSettingsService, $memberService, $userAccountRepo, $mailService, $journalService, $twig
    );

    // Optional dependency on the calendar module (module spec §5) — sync
    // and the admin page's section-activity columns both no-op gracefully
    // when it's disabled, per Service\CalendarSyncService's own contract.
    $sosCalendarService = in_array('calendar', $moduleManager->getEnabledModuleIds(), true) ? $calendarService : null;
    $sosCalendarEventService = in_array('calendar', $moduleManager->getEnabledModuleIds(), true) ? $calendarEventService : null;
    $sosCalendarSyncService = new \Modules\SosStaff\Service\CalendarSyncService(
        $sosCalendarSyncRepo, $sosOnCallRepo, $memberService, $sosCalendarService, $sosCalendarEventService
    );

    $frontController->registerController(
        \Modules\SosStaff\Controller\SosConfigController::class,
        new \Modules\SosStaff\Controller\SosConfigController(
            $twig, $sosProviderConfigService, $sosSettingsService, $sectionService, $journalService
        )
    );
    $frontController->registerController(
        \Modules\SosStaff\Controller\SosAdminController::class,
        new \Modules\SosStaff\Controller\SosAdminController(
            $twig, $sosProviderConfigService, $sosSettingsService, $sosOnCallService, $sosRedirectService,
            $sosCalendarSyncService, $sectionService, $schedulerService, $scoutYearResolver, $journalService,
            $sosCalendarService
        )
    );
}

if (in_array('banner', $moduleManager->getEnabledModuleIds(), true)) {
    $bannerRepo = new \Modules\Banner\Repository\BannerRepository($pdo);
    $bannerService = new \Modules\Banner\Service\BannerService($bannerRepo, $editableContentService);

    $frontController->registerController(
        \Modules\Banner\Controller\BannerConfigController::class,
        new \Modules\Banner\Controller\BannerConfigController($twig, $bannerService, $journalService, $memberService, $scoutYearService)
    );

    // Re-registers PageController with the real banner provider — same
    // core-hook precedent as FunctionsController/trombinoscope above
    // (ARCHITECTURE.md §7.4): core never depends on the module directly.
    $frontController->registerController(
        PageController::class,
        new PageController($twig, $editableContentService, $sectionRepository, $settingService, $rgpdContentService, $sectionService, $unitStaffSectionService, $scoutYearService, $bannerService, null, $sectionResponsableProvider)
    );
}

if (in_array('llm_connector', $moduleManager->getEnabledModuleIds(), true)) {
    $llmProviderRepo = new \Modules\LlmConnector\Repository\ProviderRepository($pdo, $encryptionService);
    $llmModelRepo = new \Modules\LlmConnector\Repository\ProviderModelRepository($pdo);

    $frontController->registerController(
        \Modules\LlmConnector\Controller\ConfigController::class,
        new \Modules\LlmConnector\Controller\ConfigController(
            $twig,
            $llmProviderRepo,
            $llmModelRepo,
            new \Modules\LlmConnector\Service\OcrModelSelector(),
            $schedulerService,
            $journalService
        )
    );
}

// Inbound mail (§7). The message-consumer registry — the ARCHITECTURE.md
// §7.6 pattern — now lives entirely on the scheduler path: it is built,
// with every enabled module's consumer, inside the sync handler's lazy
// factory in public/scheduler-bootstrap.php, since nothing on the web
// path ever reads it.
//
// Null when `inbound_mail` is disabled: a consumer module then shows no
// communications, which is the clean degradation in that direction.
$inboundMailForOthers = null;

if (in_array('inbound_mail', $moduleManager->getEnabledModuleIds(), true)) {
    $inboundMailboxRepository = new \Modules\InboundMail\Repository\InboundMailboxRepository($pdo, $encryptionService);
    $inboundMessageRepository = new \Modules\InboundMail\Repository\InboundMessageRepository($pdo, $encryptionService);

    $inboundMailForOthers = new \Modules\InboundMail\Service\InboundMailService(
        $inboundMessageRepository,
        $inboundMailboxRepository,
        new \Core\File\FileRepository($pdo)
    );

    $frontController->registerController(
        \Modules\InboundMail\Controller\InboundMailConfigController::class,
        new \Modules\InboundMail\Controller\InboundMailConfigController(
            $twig,
            new \Modules\InboundMail\Service\MailboxAdminService(
                $inboundMailboxRepository,
                new \Modules\InboundMail\Service\MailboxClientFactory(),
                new \Modules\InboundMail\Service\MailboxErrorFormatter()
            ),
            $journalService
        )
    );

    // The polling task and the consumer registry it needs are wired in
    // public/scheduler-bootstrap.php (shared by both entry points), as a
    // lazy factory — so the three-module consumer graph is only ever
    // assembled when a sync task is actually due, never on a page view.
}

// Optional dependency on the finance module (ARCHITECTURE.md §7.5) — set
// below only when 'finance' is enabled; every consumer (e.g. the news
// module's payment feature) takes these as nullable constructor deps and
// degrades to "feature simply unavailable" when they stay null.
$financeStructuredCommunicationForOthers = null;
$financeExpectedReceivableForOthers = null;
$financeSepaQrCodeForOthers = null;
$financeAccountForOthers = null;

if (in_array('finance', $moduleManager->getEnabledModuleIds(), true)) {
    $financeFiscalYearRepo = new \Modules\Finance\Repository\FiscalYearRepository($pdo, $scoutYearService);
    $financeAccountRepo = new \Modules\Finance\Repository\AccountRepository($pdo, $encryptionService);
    $financeCategoryRepo = new \Modules\Finance\Repository\CategoryRepository($pdo);
    $financeCategoryRuleRepo = new \Modules\Finance\Repository\CategoryRuleRepository($pdo);
    $financeTransactionRepo = new \Modules\Finance\Repository\TransactionRepository($pdo, $encryptionService);
    $financeCheckpointRepo = new \Modules\Finance\Repository\BalanceCheckpointRepository($pdo);
    $financeStatementImportRepo = new \Modules\Finance\Repository\StatementImportRepository($pdo);
    $financeAttachmentRepo = new \Modules\Finance\Repository\AttachmentRepository($pdo, $encryptionService);
    $financeTransactionAttachmentRepo = new \Modules\Finance\Repository\TransactionAttachmentRepository($pdo);

    $financeBalanceService = new \Modules\Finance\Service\BalanceService($financeCheckpointRepo, $financeTransactionRepo);
    $financeAccountTransferCategoryService = new \Modules\Finance\Service\AccountTransferCategoryService(
        $financeCategoryRepo, $financeCategoryRuleRepo, $financeTransactionRepo
    );
    // "Which sections is this session the treasurer of" (ARCHITECTURE.md
    // §8.69). The rule is pure and takes members.id values so the receipts
    // file guard can reuse it; the per-request answer is wrapped once here
    // — $linkedMemberIds and the effective scout year are the composition
    // root's to know, and a Service must not go looking for them. Lazy, so
    // a page load that never touches finance pays nothing for it.
    $financeTreasurerScopeService = new \Modules\Finance\Service\TreasurerScopeService(
        $connection, $badgeRepository, $memberBadgeRepository
    );
    $financeTreasurerScope = \Modules\Finance\Service\TreasurerScope::forSession(
        $financeTreasurerScopeService, $linkedMemberIds, $effectiveScoutYear->id
    );
    $financeAccountVisibility = new \Modules\Finance\Service\AccountVisibility($financeTreasurerScope);
    $financeService = new \Modules\Finance\Service\FinanceService(
        $financeAccountRepo, $financeCategoryRepo, $financeFiscalYearRepo, $sectionService, $financeTransactionRepo, $financeBalanceService,
        $settingService, $financeCategoryRuleRepo, $financeAccountTransferCategoryService, $financeAccountVisibility
    );
    $financeRuleEngine = new \Modules\Finance\Service\CategoryRuleEngine($financeTransactionRepo, $financeCategoryRuleRepo);
    $financeParserFactory = new \Modules\Finance\Parser\BankStatementParserFactory();
    // Optional dependency on the llm_connector module (ARCHITECTURE.md
    // §7.5), same instance reused for RGPD content generation above —
    // the AI-assisted matching fallback degrades to rule-based-only
    // whenever it's null/unavailable.
    $financeReceiptMatchingService = new \Modules\Finance\Service\ReceiptMatchingService(
        $financeAttachmentRepo, $financeTransactionRepo, $financeTransactionAttachmentRepo, $journalService, $llmConnectorForRgpd
    );

    // Optional dependency on the llm_connector module (ARCHITECTURE.md
    // §7.5), same reused instance — the config page's AI categorization
    // rule is simply never shown/reachable whenever it's null. Also an
    // optional dependency on the calendar module (nullable, reuses the
    // instances built above) — the AI prompt simply omits the "nearby
    // section calendar events" section when calendar is disabled.
    $financeAiSuggestionRepo = new \Modules\Finance\Repository\AiCategorySuggestionRepository($pdo);
    $financeAiCategorizationService = new \Modules\Finance\Service\AiCategorizationService(
        $llmConnectorForRgpd, $financeCategoryRepo, $financeAiSuggestionRepo, $journalService,
        $financeAccountRepo, $financeTransactionAttachmentRepo, $financeAttachmentRepo,
        // The calendar's PUBLISHED read Api (§7.5) — the null-seeded
        // handle from the calendar block above implements it; null with
        // calendar disabled, and the AI prompt simply omits the "nearby
        // section calendar events" hints.
        $calendarServiceForOthers
    );
    $financeBulkCategorizationService = new \Modules\Finance\Service\BulkCategorizationService(
        $financeTransactionRepo, $financeRuleEngine, $financeAiCategorizationService, $settingService, $schedulerService
    );

    // Who paid what, written down (ARCHITECTURE.md §8.81). Declared here,
    // above the import service, because a bank import's whole point is to
    // let these rows be written in the same request; the public
    // cross-module API further down reads through the very same instance,
    // so no two parts of the application can disagree about a status.
    $financeExpectedReceivableRepo = new \Modules\Finance\Repository\ExpectedReceivableRepository($pdo, $encryptionService);
    $financeAllocationRepo = new \Modules\Finance\Repository\ReceivableAllocationRepository($pdo);
    $financeAllocationService = new \Modules\Finance\Service\ReceivableAllocationService(
        $financeExpectedReceivableRepo, $financeAllocationRepo, $financeTransactionRepo,
        $financeAccountRepo, $financeAccountVisibility
    );

    $financeImportService = new \Modules\Finance\Service\ImportService(
        $pdo, $encryptionService, $financeParserFactory, $financeTransactionRepo, $financeCheckpointRepo,
        $financeStatementImportRepo, $financeFiscalYearRepo, $financeRuleEngine, $financeBalanceService, $financeReceiptMatchingService,
        $financeBulkCategorizationService, $financeAllocationService
    );
    $financeEncryptedFileStorage = new \Core\File\EncryptedFileStorageService($fileRepository, $encryptionService, $storagePath);
    $financeReceiptService = new \Modules\Finance\Service\ReceiptService(
        $financeAttachmentRepo, $financeAccountRepo, $financeTransactionAttachmentRepo, $financeEncryptedFileStorage,
        $financeTransactionRepo, $settingService
    );

    // What another module reaches this one through (Api\ExpenseReceiptInterface,
    // ARCHITECTURE.md §7.5). It adds no storage path of its own — the
    // ReceiptService above does everything — and builds the authorization
    // itself from the actor its caller names, rather than accepting a
    // decision a consumer could have granted itself.
    $expenseReceiptProvider = new \Modules\Finance\Service\ExpenseReceiptService(
        $financeAccountRepo, $financeTreasurerScopeService, $financeReceiptService, $effectiveScoutYear->id
    );

    // A receipt's FILE follows its account's rule too (ARCHITECTURE.md
    // §8.70): role_min alone is a hierarchical floor and cannot say "the
    // Louveteaux section", so without this the screen would be narrowed
    // and a direct /files/{id} would not be.
    $fileOwnershipCheckers[] = new \Modules\Finance\File\FinanceAccountOwnershipChecker(
        $financeAccountRepo, $financeTreasurerScopeService, $effectiveScoutYear->id
    );

    // Every receipt stored before that checker existed carries no owner
    // pair, and would stay reachable by its direct link — which is the
    // hole itself. Called here rather than from the finance configuration
    // page: a backfill that only runs for units whose superadmin happens
    // to open the right screen is not a backfill. Guarded by a settings
    // flag, and SettingService caches settings once per request, so every
    // run after the first costs one array lookup and no query.
    $financeReceiptService->ensureReceiptFileOwnership();
    // Optional dependency on the llm_connector module (ARCHITECTURE.md
    // §7.5) — reuses the same LlmConnectorInterface instance already
    // built for RGPD content generation above; extraction is skipped
    // gracefully whenever it's null/unavailable.
    $financeReceiptExtractionService = new \Modules\Finance\Service\ReceiptExtractionService($schedulerService, $llmConnectorForRgpd);
    $financeFirstReceiptResolver = new \Modules\Finance\Service\FirstReceiptResolver($financeTransactionAttachmentRepo, $financeAttachmentRepo);

    // Built here rather than next to its own controller a few hundred
    // lines down: the dashboard's "À rapprocher" tile reads the same
    // counts, and a second way of counting them would be a second answer
    // waiting to disagree with the screen it links to.
    $financeReconciliationService = new \Modules\Finance\Service\ReconciliationService(
        $financeExpectedReceivableRepo,
        $financeAllocationRepo,
        $financeTransactionRepo,
        $financeAccountRepo,
        $financeAccountVisibility,
        $financeAllocationService,
        $memberService,
        $householdService
    );

    $frontController->registerController(
        \Modules\Finance\Controller\DashboardController::class,
        new \Modules\Finance\Controller\DashboardController(
            $twig, $financeService, $financeBalanceService, $financeTransactionRepo, $financeReceiptService,
            $financeCategoryRepo, $financeAttachmentRepo, $financeTransactionAttachmentRepo, $financeStatementImportRepo,
            $financeFirstReceiptResolver, $financeReconciliationService, $scoutYearService
        )
    );
    $frontController->registerController(
        \Modules\Finance\Controller\MovementController::class,
        new \Modules\Finance\Controller\MovementController(
            $twig, $financeService, $financeTransactionRepo, $financeCategoryRepo, $financeFiscalYearRepo,
            $financeAttachmentRepo, $financeTransactionAttachmentRepo, $financeReceiptService, $financeReceiptExtractionService,
            $financeFirstReceiptResolver, $journalService
        )
    );
    $frontController->registerController(
        \Modules\Finance\Controller\ImportController::class,
        new \Modules\Finance\Controller\ImportController($twig, $financeService, $financeImportService, $financeParserFactory, $financeCheckpointRepo)
    );
    $frontController->registerController(
        \Modules\Finance\Controller\ReceiptController::class,
        new \Modules\Finance\Controller\ReceiptController(
            $twig, $financeAttachmentRepo, $financeTransactionAttachmentRepo, $financeTransactionRepo, $financeService,
            $financeReceiptService, $financeReceiptExtractionService, $financeFirstReceiptResolver, $journalService
        )
    );
    $frontController->registerController(
        \Modules\Finance\Controller\ConfigController::class,
        new \Modules\Finance\Controller\ConfigController($twig, $financeService, $schedulerService)
    );
    $frontController->registerController(
        \Modules\Finance\Controller\ConfigAccountController::class,
        new \Modules\Finance\Controller\ConfigAccountController(
            $twig, $financeService, $sectionService, $financeAttachmentRepo, $fileRepository, $journalService
        )
    );
    $frontController->registerController(
        \Modules\Finance\Controller\ConfigCategoryController::class,
        new \Modules\Finance\Controller\ConfigCategoryController(
            $twig, $financeService, $financeCategoryRuleRepo, $journalService, $financeAiSuggestionRepo,
            $financeBulkCategorizationService, $financeTransactionRepo, $llmConnectorForRgpd !== null
        )
    );
    $frontController->registerController(
        \Modules\Finance\Controller\ConfigRuleController::class,
        new \Modules\Finance\Controller\ConfigRuleController(
            $twig, $financeCategoryRuleRepo, $financeCategoryRepo, $financeRuleEngine, $journalService, $financeService, $financeBulkCategorizationService
        )
    );
    $frontController->registerController(
        \Modules\Finance\Controller\ConfigDangerController::class,
        new \Modules\Finance\Controller\ConfigDangerController(
            $twig, $financeTransactionRepo, $financeCheckpointRepo, $financeAttachmentRepo, $journalService
        )
    );

    // Public API implementations (ARCHITECTURE.md §7.5) — instantiated
    // here so other modules (news) can consume them as nullable deps.
    $financeStructuredCommunicationForOthers = new \Modules\Finance\Service\StructuredCommunicationService($financeExpectedReceivableRepo);
    $financeExpectedReceivableForOthers = new \Modules\Finance\Service\ExpectedReceivableService($financeExpectedReceivableRepo, $financeAllocationService);
    $financeSepaQrCodeForOthers = new \Modules\Finance\Service\SepaQrCodeService();
    $financeAccountForOthers = new \Modules\Finance\Service\FinanceAccountService($financeAccountRepo);

    $financeReceivablesOverviewService = new \Modules\Finance\Service\ReceivablesOverviewService(
        $financeExpectedReceivableRepo,
        $financeExpectedReceivableForOthers,
        $financeAccountRepo,
        $financeAccountVisibility
    );
    $frontController->registerController(
        \Modules\Finance\Controller\ReceivablesController::class,
        new \Modules\Finance\Controller\ReceivablesController($twig, $financeReceivablesOverviewService)
    );

    // The QR of one receivable, served to a mail client by an
    // unguessable derived token (ARCHITECTURE.md §8.84): an image in an
    // e-mail is fetched by a program that has no session and never will.
    $financeQrTokenService = new \Modules\Finance\Service\ReceivableQrTokenService($encryptionService);

    // Payment campaigns (ARCHITECTURE.md §8.82). The import resolves a
    // spreadsheet line to a person through the identifier the site's own
    // member export produces, and through nothing else — hence the plain
    // members lookup rather than any name-matching helper.
    $financeCampaignRepo = new \Modules\Finance\Repository\CampaignRepository($pdo);
    $financeCampaignRowRepo = new \Modules\Finance\Repository\CampaignRowRepository($pdo, $encryptionService);
    $financeCampaignService = new \Modules\Finance\Service\CampaignService(
        $pdo,
        $financeCampaignRepo,
        $financeCampaignRowRepo,
        new \Modules\Finance\Service\CampaignImportService(new \Modules\Finance\Repository\MemberLookupRepository($pdo)),
        $financeExpectedReceivableForOthers,
        $financeStructuredCommunicationForOthers,
        $financeAccountRepo,
        $financeAccountVisibility,
        $financeEncryptedFileStorage,
        $journalService
    );
    $financeCampaignOverviewService = new \Modules\Finance\Service\CampaignOverviewService(
        $financeCampaignRepo,
        $financeCampaignRowRepo,
        $financeExpectedReceivableRepo,
        $financeAllocationService,
        $financeAccountRepo,
        $financeAccountVisibility,
        $memberService,
        $userAccountRepo
    );
    // Everything the campaign controller needs, closed over here where
    // the finance services live — but NOT registered here. Its reminder
    // draft is an optional dependency on mass_mail (ARCHITECTURE.md
    // §7.5), and in a straight-line script that provider does not exist
    // yet; the closure is called a few hundred lines down, once it does.
    $financeCampaignControllerFactory = static fn(?\Modules\MassMail\Api\MassMailDraftInterface $draft):
        \Modules\Finance\Controller\CampaignController => new \Modules\Finance\Controller\CampaignController(
            $twig,
            $financeCampaignService,
            $financeCampaignOverviewService,
            new \Modules\Finance\Service\CampaignExportService(),
            new \Modules\Finance\Service\CampaignReminderService(
                $financeCampaignRowRepo,
                $financeExpectedReceivableRepo,
                $financeAllocationService,
                $financeAccountRepo,
                $memberService,
                $financeQrTokenService,
                (string) $settingService->get('base_url'),
                $draft
            ),
            new \Modules\Finance\Service\CampaignNotificationService(
                $financeCampaignRowRepo,
                $financeExpectedReceivableRepo,
                $financeAllocationService,
                $memberAccountResolver,
                $memberService,
                $memberYearRepo,
                $notificationService
            ),
            $financeService,
            $financeAllocationService,
            $scoutYearService
        );

    // The family side (ARCHITECTURE.md §8.85): the payment block on a
    // member's own page and the homepage band summarising a whole
    // family's open demands. One service for both, because they are one
    // question asked at two scales.
    $financeFamilyPaymentService = new \Modules\Finance\Api\FamilyPaymentService(
        $financeExpectedReceivableRepo,
        $financeAllocationService,
        $financeAccountRepo,
        $financeCampaignRowRepo,
        $financeCampaignRepo,
        $financeStatementImportRepo,
        $financeQrTokenService,
        $memberService,
        $scoutYearResolver,
        (string) $settingService->get('base_url')
    );
    $homePaymentDueProvider = $financeFamilyPaymentService;
    $memberPaymentProvider = $financeFamilyPaymentService;

    // Re-registers PageController with the payment band's provider —
    // same core-hook precedent, and the same "reuse whatever the earlier
    // blocks already set" rule, as the banner/news/groups blocks
    // (ARCHITECTURE.md §7.4). news' and groups' own re-registrations run
    // after this one and carry $homePaymentDueProvider forward, so no
    // hook is lost whichever combination is enabled.
    $frontController->registerController(
        PageController::class,
        new PageController(
            $twig, $editableContentService, $sectionRepository, $settingService, $rgpdContentService,
            $sectionService, $unitStaffSectionService, $scoutYearService,
            in_array('banner', $moduleManager->getEnabledModuleIds(), true) ? $bannerService : null,
            $newsArticleService,
            $sectionResponsableProvider,
            null,
            $homePaymentDueProvider
        )
    );

    $frontController->registerController(
        \Modules\Finance\Controller\ReceivableQrController::class,
        new \Modules\Finance\Controller\ReceivableQrController(
            $twig,
            $financeExpectedReceivableRepo,
            $financeAccountRepo,
            $financeAllocationService,
            $financeQrTokenService,
            $financeSepaQrCodeForOthers
        )
    );

    // « Rapprochement » (ARCHITECTURE.md §8.83) — the four situations the
    // automatic matching cannot settle on its own. The QR generator is
    // the module's own, and the page degrades to the payment details in
    // text rather than a fatal if it is ever absent.
    $frontController->registerController(
        \Modules\Finance\Controller\ReconciliationController::class,
        new \Modules\Finance\Controller\ReconciliationController(
            $twig,
            $financeReconciliationService,
            $financeAllocationService,
            $financeExpectedReceivableRepo,
            $financeService,
            $memberService,
            $scoutYearService,
            $financeSepaQrCodeForOthers
        )
    );

    // "Outils" (ARCHITECTURE.md §8.73). The QR generator is handed the
    // module's own SepaQrCodeService — the same instance every other
    // consumer gets — and the page degrades to a message rather than a
    // fatal if it is ever absent.
    $frontController->registerController(
        \Modules\Finance\Controller\ToolsController::class,
        new \Modules\Finance\Controller\ToolsController(
            $twig, $financeService, $financeExpectedReceivableRepo, $journalService, $financeSepaQrCodeForOthers
        )
    );
}

// Optional dependency on the mass_mail module (ARCHITECTURE.md §7.5) —
// seeded null so a consumer guards on `!== null` rather than assuming it
// exists, and set below only when the module is enabled. Today's consumer
// is the news form's "Écrire aux répondants" button, which simply does not
// appear without it.
$massMailDraftForOthers = null;

if (in_array('mass_mail', $moduleManager->getEnabledModuleIds(), true)) {
    $massMailListRepo = new \Modules\MassMail\Repository\MailingListRepository($pdo);
    $massMailResolutionRepo = new \Modules\MassMail\Repository\MemberResolutionRepository($pdo, $encryptionService);
    $massMailEmailRepo = new \Modules\MassMail\Repository\EmailRepository($pdo);
    $massMailRecipientRepo = new \Modules\MassMail\Repository\RecipientRepository($pdo, $encryptionService);
    $massMailAttachmentRepo = new \Modules\MassMail\Repository\EmailAttachmentRepository($pdo);
    $massMailFunctionRepo = new \Core\Import\FunctionRepository($pdo);
    $massMailAudienceRepo = new \Modules\MassMail\Repository\AudienceRepository($pdo, $encryptionService);
    $massMailSuppressedRepo = new \Modules\MassMail\Repository\SuppressedAddressRepository($pdo);
    $massMailMergeRenderer = new \Modules\MassMail\Service\MergeRenderer();
    $massMailAudienceImportService = new \Modules\MassMail\Service\AudienceImportService(
        $massMailAudienceRepo, $massMailResolutionRepo, $journalService
    );

    $massMailListService = new \Modules\MassMail\Service\MailingListService(
        $massMailListRepo, $massMailResolutionRepo, $sectionService, $massMailFunctionRepo
    );
    $massMailAccessService = new \Modules\MassMail\Service\MassMailAccessService($memberService, $sectionService);
    $massMailService = new \Modules\MassMail\Service\MassMailService(
        $massMailEmailRepo, $massMailRecipientRepo, $massMailAttachmentRepo, $fileRepository,
        $massMailListService, $memberService, $memberEmailService, $sectionService, $mailService, $schedulerService, $journalService,
        new \Core\Security\HtmlSanitizer(), $scoutYearService, $importJournalRepo, $storagePath,
        $massMailAudienceRepo, $massMailResolutionRepo, $massMailSuppressedRepo, $massMailMergeRenderer
    );

    $massMailDraftForOthers = new \Modules\MassMail\Service\MergeDraftService(
        $massMailService, $massMailAudienceRepo, $massMailAccessService, $memberService, $sectionService, $scoutYearService
    );

    $frontController->registerController(
        \Modules\MassMail\Controller\MassMailController::class,
        new \Modules\MassMail\Controller\MassMailController(
            $twig, $massMailService, $massMailListService, $massMailAccessService, $memberService, $sectionService,
            $scoutYearService, $importJournalRepo, $settingService, $uploadHandler, $fileRepository,
            $massMailAudienceImportService
        )
    );

    // Bootstrap the daily mail-merge audience retention purge (Task\
    // PurgeMergeAudiencesHandler self-reschedules afterwards — same
    // pattern as registration's purge_registration_requests below).
    $schedulerService->rearm('mass_mail', 'purge_merge_audiences', 'daily', new DateTimeImmutable());
    $frontController->registerController(
        \Modules\MassMail\Controller\ConfigController::class,
        new \Modules\MassMail\Controller\ConfigController($twig, $massMailListService, $settingService)
    );

    // The member page's "view as sent" email detail route
    // (/members/{id}/emails/{recipient_id}, module.json) — lives here, not
    // in Core\Http\Controller\MemberController, since the content is
    // entirely mass_mail's own data (ARCHITECTURE.md §8.22).
    $frontController->registerController(
        \Modules\MassMail\Controller\MemberEmailController::class,
        new \Modules\MassMail\Controller\MemberEmailController(
            $twig, $memberService, new \Modules\MassMail\Service\MassMailQueryService($massMailRecipientRepo)
        )
    );

    // One-click unsubscribe (module addendum, RFC 8058) — public, no
    // session, token-authenticated (see the controller's own docblock).
    $frontController->registerController(
        \Modules\MassMail\Controller\UnsubscribeController::class,
        new \Modules\MassMail\Controller\UnsubscribeController($twig, $massMailRecipientRepo, $memberEmailService, $massMailSuppressedRepo)
    );

    // MemberController is re-registered once, with every optional
    // provider (mass_mail included), in the combined block further down
    // — see the comment there for why.
}

// Payment campaigns (ARCHITECTURE.md §8.82), registered here rather than
// in the finance block: the reminder draft is an optional dependency on
// mass_mail (§7.5) and $massMailDraftForOthers only exists once that
// block has run. It is null when mass_mail is disabled, which is exactly
// the graceful degradation the pattern asks for — the button disappears
// and the campaigns work unchanged.
if (isset($financeCampaignControllerFactory)) {
    $frontController->registerController(
        \Modules\Finance\Controller\CampaignController::class,
        $financeCampaignControllerFactory($massMailDraftForOthers)
    );
}

if (in_array('news', $moduleManager->getEnabledModuleIds(), true)) {
    $newsArticleRepo = new \Modules\News\Repository\ArticleRepository($pdo);
    $newsFormRepo = new \Modules\News\Repository\FormRepository($pdo);
    $newsFieldRepo = new \Modules\News\Repository\FormFieldRepository($pdo);
    $newsResponseRepo = new \Modules\News\Repository\FormResponseRepository($pdo, $encryptionService);

    $newsArticleService = new \Modules\News\Service\ArticleService(
        $newsArticleRepo, $newsFormRepo, $editableContentService, $shortUrlService, $financeExpectedReceivableForOthers
    );
    $newsFormService = new \Modules\News\Service\FormService($newsFormRepo, $newsFieldRepo, $newsArticleService);
    // Optional dependency on the finance module (ARCHITECTURE.md §7.5) —
    // the whole payment feature (price fields, SEPA QR, receivables)
    // simply disappears when finance is disabled, since every one of
    // these four is null in that case.
    $newsResponseService = new \Modules\News\Service\ResponseService(
        $newsResponseRepo, $roleResolver, $sectionService, $mailService, $twig, $shortUrlService,
        (string) ($settingService->get('base_url') ?: ''), (string) ($settingService->get('site_name') ?: 'Unité scoute'),
        $financeStructuredCommunicationForOthers, $financeExpectedReceivableForOthers, $financeSepaQrCodeForOthers, $financeAccountForOthers,
        $journalService
    );
    // Optional dependency on the llm_connector module (ARCHITECTURE.md
    // §7.5), same reused instance as RGPD content generation above — the
    // "Générer avec l'IA" button is simply hidden when it's unavailable.
    $newsSeoKeywordService = new \Modules\News\Service\SeoKeywordService($llmConnectorForRgpd);

    $frontController->registerController(
        \Modules\News\Controller\NewsController::class,
        new \Modules\News\Controller\NewsController(
            $twig, $newsArticleService, $newsFormService, $newsResponseService, $newsSeoKeywordService,
            $posterPdfService, $scoutYearService, $settingService, $schedulerService, $userAccountRepo,
            $memberService, $sectionService, $uploadHandler, $fileRepository, $storagePath, $journalService,
            $financeAccountForOthers, $humanCheckService, $imageVariantService
        )
    );

    // One-shot backfill of thumb/md derivatives for article images uploaded
    // before the news module generated variants at upload — the templates
    // render /files/{id}/thumb|md and FileController::variant() never falls
    // back to the original, so pre-existing images would 404 without it.
    // The non-editable flag (finance's own `…_seeded` runtime-flag pattern)
    // keeps this to a settings-cache read on every later request; the
    // handler flips it once the pass has completed.
    if ($settingService->get(\Modules\News\Task\GenerateImageVariantsHandler::DONE_FLAG, 'news') !== '1') {
        $settingService->register(
            \Modules\News\Task\GenerateImageVariantsHandler::DONE_FLAG,
            '0',
            'boolean',
            'Variantes d\'images générées',
            'Indicateur interne : le rattrapage des miniatures d\'images des actualités a été effectué.',
            'news',
            null,
            null,
            false
        );
        $schedulerService->rearm(
            'news',
            \Modules\News\Task\GenerateImageVariantsHandler::TASK_KEY,
            \Modules\News\Task\GenerateImageVariantsHandler::REFERENCE,
            new DateTimeImmutable()
        );
    }
    $frontController->registerController(
        \Modules\News\Controller\FormController::class,
        new \Modules\News\Controller\FormController(
            $twig, $newsArticleService, $newsFormService, $newsResponseService, $scoutYearService, $journalService,
            $financeExpectedReceivableForOthers, $humanCheckService, $massMailDraftForOthers
        )
    );

    // Re-registers PageController with the real news provider — same
    // core-hook precedent as the banner block above (ARCHITECTURE.md §7.4).
    // Reuses $bannerService/$sectionResponsableProvider if those modules
    // were also enabled above, so no hook is lost when several are active.
    $frontController->registerController(
        PageController::class,
        new PageController(
            $twig, $editableContentService, $sectionRepository, $settingService, $rgpdContentService,
            $sectionService, $unitStaffSectionService, $scoutYearService,
            in_array('banner', $moduleManager->getEnabledModuleIds(), true) ? $bannerService : null,
            $newsArticleService,
            $sectionResponsableProvider,
            null,
            $homePaymentDueProvider
        )
    );
}

if (in_array('gallery', $moduleManager->getEnabledModuleIds(), true)) {
    $galleryAlbumRepo = new \Modules\Gallery\Repository\AlbumRepository($pdo);
    $galleryMediaRepo = new \Modules\Gallery\Repository\MediaRepository($pdo);
    // Legacy singleton (pre-multi-location) — only read from now on, by
    // Service\StorageLocationService::ensureLegacyLocationBackfilled(), to
    // carry an existing installation's S3 secret into the new per-location
    // gallery_storage_locations table the very first time it runs.
    $galleryS3SecretRepo = new \Modules\Gallery\Repository\S3SecretRepository($pdo, $encryptionService);
    $galleryStorageLocationRepo = new \Modules\Gallery\Repository\StorageLocationRepository($pdo, $encryptionService);
    // The gallery's S3 storage as declared sub-processors (§7.4) — the
    // module's own reading of its own tables, so the RGPD prompt states
    // exactly what is configured without core touching this repository.
    $rgpdContentService->addSubProcessorProvider(
        new \Modules\Gallery\Service\GalleryStorageSubProcessorService($galleryStorageLocationRepo)
    );

    $galleryAccessService = new \Modules\Gallery\Service\GalleryAccessService($memberService, $sectionService, $scoutYearService);
    $galleryOgScraperService = new \Modules\Gallery\Service\OgScraperService();
    // Api\LinkPreviewFetcher's only implementation (SECURITY.md §17) —
    // built unconditionally, like Api\DelegatedAlbumManager just below,
    // ready for a future module's block to consume; groups (first
    // consumer) is the only one that does so today.
    $galleryLinkPreviewCacheRepo = new \Modules\Gallery\Repository\LinkPreviewCacheRepository($pdo);
    $galleryLinkPreviewFetcher = new \Modules\Gallery\Service\LinkPreviewService($galleryOgScraperService, $galleryLinkPreviewCacheRepo);
    $galleryStorageBackendFactory = new \Modules\Gallery\Service\Storage\StorageBackendFactory($galleryStorageLocationRepo, $storagePath);
    $galleryFfmpegAvailability = new \Modules\Gallery\Service\FfmpegAvailability();
    // Optional dependency on the llm_connector module (ARCHITECTURE.md
    // §7.5), same reused instance as RGPD content generation above — the
    // "Expliquer avec l'IA" button is simply hidden when it's unavailable.
    $galleryS3ErrorExplainerService = new \Modules\Gallery\Service\S3ErrorExplainerService($llmConnectorForRgpd);
    // Reclaims the `files` row + bytes behind a media's staging original and
    // an external album's cached og:image once nothing references them.
    $galleryStoredFileCleaner = new \Modules\Gallery\Service\StoredFileCleaner($fileRepository, $storagePath);
    $galleryStorageLocationService = new \Modules\Gallery\Service\StorageLocationService(
        $galleryStorageLocationRepo, $galleryAlbumRepo, $galleryStorageBackendFactory, $settingService,
        $galleryS3SecretRepo, $storagePath
    );

    $galleryAlbumService = new \Modules\Gallery\Service\AlbumService(
        $galleryAlbumRepo, $galleryMediaRepo, $galleryAccessService, $galleryOgScraperService,
        $galleryStorageBackendFactory, $galleryStorageLocationRepo, $galleryStorageLocationService,
        $scoutYearService, $settingService, $schedulerService, $uploadHandler,
        $notificationService, $userAccountRepo, $galleryStoredFileCleaner
    );
    $galleryMediaService = new \Modules\Gallery\Service\MediaService(
        $galleryMediaRepo, $galleryAlbumRepo, $uploadHandler, $schedulerService, $settingService,
        $galleryAccessService, $galleryStorageBackendFactory, $galleryStorageLocationService,
        $galleryFfmpegAvailability, $galleryStoredFileCleaner
    );

    // Controller\GalleryController is NOT registered here — see the end
    // of this file, where $galleryDelegatedAlbumAccessCheckers is
    // guaranteed complete. Api\DelegatedAlbumManager's concrete
    // implementation has no such ordering constraint (nothing it does
    // consults that registry), so it is built here like any other gallery
    // service, ready for a future module's block to consume — no consumer
    // exists yet.
    $galleryDelegatedAlbumManager = new \Modules\Gallery\Service\DelegatedAlbumService(
        $galleryAlbumRepo, $galleryMediaRepo, $galleryMediaService, $galleryStorageLocationRepo,
        $galleryStorageLocationService, $galleryStorageBackendFactory, $scoutYearService
    );

    $frontController->registerController(
        \Modules\Gallery\Controller\GalleryChiefController::class,
        new \Modules\Gallery\Controller\GalleryChiefController(
            $twig, $galleryAlbumService, $galleryMediaService, $galleryMediaRepo, $galleryAccessService,
            $sectionService, $settingService, $galleryStorageLocationRepo, $galleryStorageLocationService,
            new \Core\File\ChunkedUploadStore($storagePath), $scoutYearService
        )
    );
    // GalleryConfigController is NOT registered here — see the late block
    // at the end of this file. It needs the describer registry, and every
    // module that contributes to it runs below this point, exactly like
    // GalleryController and its access registry.
    $frontController->registerController(
        \Modules\Gallery\Controller\GalleryStorageLocationController::class,
        new \Modules\Gallery\Controller\GalleryStorageLocationController(
            $twig, $galleryStorageLocationRepo, $galleryStorageLocationService, $journalService,
            $galleryS3ErrorExplainerService
        )
    );
}

if (in_array('groups', $moduleManager->getEnabledModuleIds(), true)) {
    $groupsGroupRepo = new \Modules\Groups\Repository\GroupRepository($pdo);
    $groupsSectionRepo = new \Modules\Groups\Repository\GroupSectionRepository($pdo);
    $groupsMemberRepo = new \Modules\Groups\Repository\GroupMemberRepository($pdo);
    $groupsAccessService = new \Modules\Groups\Service\GroupAccessService(
        $groupsMemberRepo, $groupsSectionRepo, $sectionMembershipRepository
    );
    $groupsService = new \Modules\Groups\Service\GroupService($groupsGroupRepo, $groupsSectionRepo, $groupsMemberRepo);
    // Which groups a member belongs to, for the admin member page's
    // « parcours » (ARCHITECTURE.md §7.4). Explicit memberships only,
    // and membership only — never a post, never a count of them.
    $memberDiscussionGroupProvider = new \Modules\Groups\Service\MemberDiscussionGroupService(
        $groupsMemberRepo, $groupsGroupRepo
    );
    // Per-member "last time I opened this group": drives the unread badge
    // on the group list, the home page's own activity card, and a post's
    // "vu par" list — all three read the same single mark, written once
    // per group page view by Service\GroupReadStateService.
    $groupsReadRepo = new \Modules\Groups\Repository\GroupReadRepository($pdo);
    $groupsListService = new \Modules\Groups\Service\GroupListService(
        $groupsGroupRepo, $groupsSectionRepo, $groupsMemberRepo, $sectionMembershipRepository, $groupsReadRepo
    );
    $groupsReadStateService = new \Modules\Groups\Service\GroupReadStateService(
        $groupsReadRepo, $groupsAccessService
    );
    $groupsContextFactory = new \Modules\Groups\Service\GroupSessionContextFactory(
        $memberService, $userAccountRepo, $scoutYearResolver
    );

    $groupsPostRepo = new \Modules\Groups\Repository\PostRepository($pdo);
    $groupsActivityService = new \Modules\Groups\Service\GroupActivityService($groupsGroupRepo, $groupsPostRepo);

    // Flood protection and the a-priori AI check (prompt 9). The
    // moderation service is an OPTIONAL llm_connector consumer
    // (ARCHITECTURE.md §7.5): $llmConnectorForRgpd is already null when
    // that module is disabled, and Service\ModerationService degrades to
    // "unavailable", which means every post is published unmoderated
    // rather than refused. `groups` deliberately does NOT reuse retro's
    // own moderation service — see Service\ModerationService's docblock.
    $groupsRateLimitService = new \Modules\Groups\Service\RateLimitService(
        new \Modules\Groups\Repository\RateLimitRepository($pdo)
    );
    $groupsModerationService = new \Modules\Groups\Service\ModerationService($settingService, $llmConnectorForRgpd);

    $groupsPostService = new \Modules\Groups\Service\PostService(
        $groupsPostRepo, $groupsActivityService, $groupsRateLimitService, $groupsModerationService
    );

    // gallery is a hard dependency (module.json's requires) — this block
    // only ever runs when it is enabled, so $galleryDelegatedAlbumManager
    // (built unconditionally inside gallery's own block above) is always
    // available here; no nullable optional-dependency handling. The
    // assert() below is a static-analysis narrowing hint only (PHPStan
    // cannot see across the two independent `if` blocks) — same
    // precedent as the \assert() calls already in DelegatedAlbumService
    // and GalleryController.
    \assert(isset($galleryDelegatedAlbumManager, $galleryLinkPreviewFetcher));
    $groupsPostMediaRepo = new \Modules\Groups\Repository\PostMediaRepository($pdo);
    $groupsReplyRepo = new \Modules\Groups\Repository\ReplyRepository($pdo);
    // The reply repository is needed here, not only by the reply stack
    // below: the group gallery has to drop the media of hidden REPLIES as
    // well as of hidden posts, or the very photo that got something hidden
    // stays one click away on another page.
    $groupsPostMediaService = new \Modules\Groups\Service\PostMediaService(
        $galleryDelegatedAlbumManager, $groupsPostMediaRepo, $groupsGroupRepo, $groupsReplyRepo
    );

    $groupsPostLinkRepo = new \Modules\Groups\Repository\PostLinkRepository($pdo);
    $groupsLinkFetchLogRepo = new \Modules\Groups\Repository\LinkFetchLogRepository($pdo);
    $groupsLinkFetchThrottleService = new \Modules\Groups\Service\LinkFetchThrottleService($groupsLinkFetchLogRepo);
    $groupsPostLinkService = new \Modules\Groups\Service\PostLinkService(
        $galleryLinkPreviewFetcher, $groupsLinkFetchThrottleService, $groupsPostLinkRepo,
        $uploadHandler, $fileRepository, $storagePath
    );

    // Replies and reactions (prompt 8). The two reaction repositories are
    // the same class over its two tables — see Repository\ReactionRepository
    // for why one class serves both, and modules/groups/schema.sql for why
    // there are two tables rather than one polymorphic one.
    $groupsReplyService = new \Modules\Groups\Service\ReplyService(
        $groupsReplyRepo, $groupsActivityService, $groupsPostMediaService,
        $groupsRateLimitService, $groupsModerationService
    );
    $groupsReactionService = new \Modules\Groups\Service\ReactionService(
        \Modules\Groups\Repository\ReactionRepository::forPosts($pdo),
        \Modules\Groups\Repository\ReactionRepository::forReplies($pdo),
        $groupsActivityService
    );
    // How this module names a person, on every one of its surfaces: the
    // account first, its memberships in parentheses. One instance, shared
    // by everything below that shows a name, because it memoises what it
    // resolves for the request (Service\MemberIdentityService).
    $groupsIdentityService = new \Modules\Groups\Service\MemberIdentityService(
        $userAccountRepo,
        $memberYearRepo,
        $memberService
    );
    // "Who reacted, and with what" — the dialog behind a reaction tally's
    // own click. A separate, read-only service from $groupsReactionService
    // above (Service\ReactorListService's own docblock explains why).
    $groupsReactorListService = new \Modules\Groups\Service\ReactorListService(
        \Modules\Groups\Repository\ReactionRepository::forPosts($pdo),
        \Modules\Groups\Repository\ReactionRepository::forReplies($pdo),
        $groupsIdentityService
    );
    $groupsAuthorResolver = new \Modules\Groups\Service\PostAuthorResolver($groupsIdentityService);
    // One group per visible, active section per scout year (prompt 11).
    // Injected into the list controller so the page itself heals a
    // missing group, exactly as Core\Badge\BadgeService's own sync does
    // — the nightly Task\EnsureSectionGroupsHandler runs the same
    // idempotent service, and there is deliberately no core hook into the
    // Desk import.
    $groupsSectionGroupSync = new \Modules\Groups\Service\SectionGroupSyncService(
        $sectionService, $groupsGroupRepo, $groupsSectionRepo
    );

    // Leaving, reopening and the creation quota (prompt 12).
    $groupsMembershipService = new \Modules\Groups\Service\GroupMembershipService(
        $groupsGroupRepo, $groupsMemberRepo, $settingService, $journalService
    );

    // Notifications (prompt 10). The recipient resolver reads membership
    // group-first (the reverse of GroupAccessService's account-first
    // read) and resolves members to accounts through the same blind index
    // that backs login — no new lookup, no address decrypted on the way.
    // $roleResolver/$scoutYearService are what let it recognise a site
    // admin, which is a derived role and not a stored flag.
    $groupsRecipientResolver = new \Modules\Groups\Service\GroupRecipientResolver(
        $groupsMemberRepo,
        $groupsSectionRepo,
        $sectionMembershipRepository,
        $memberYearRepo,
        $memberEmailRepository,
        $userAccountRepo,
        $encryptionService,
        $roleResolver,
        $scoutYearService
    );
    // A moderator row granted before the flag named a login binds itself
    // to the one account behind that member, when there is exactly one
    // (Service\ModeratorBindingService). Runs from the group list's own
    // page load and from the nightly ensure task — the same self-healing
    // spot as the section-group sync beside it.
    $groupsModeratorBinding = new \Modules\Groups\Service\ModeratorBindingService(
        $groupsMemberRepo,
        $groupsRecipientResolver
    );
    $groupsNotificationService = new \Modules\Groups\Service\GroupNotificationService(
        $groupsRecipientResolver,
        new \Modules\Groups\Service\ReactionNoticeThrottle(
            \Modules\Groups\Repository\ReactionNoticeRepository::forPosts($pdo),
            \Modules\Groups\Repository\ReactionNoticeRepository::forReplies($pdo),
            $settingService
        ),
        $notificationService
    );

    // Reporting and auto-hiding (prompt 9): two report tables behind one
    // class, the same shape as the two reaction ones above.
    $groupsReportService = new \Modules\Groups\Service\ReportService(
        \Modules\Groups\Repository\ReportRepository::forPosts($pdo),
        \Modules\Groups\Repository\ReportRepository::forReplies($pdo),
        $groupsPostRepo,
        $groupsReplyRepo,
        $settingService,
        $journalService
    );
    $groupsReplyPresenter = new \Modules\Groups\Service\ReplyPresenter(
        $groupsAuthorResolver, $groupsReplyService, $groupsReactionService, $groupsReportService
    );

    $groupsPollService = new \Modules\Groups\Service\PollService(
        new \Modules\Groups\Repository\PollRepository($pdo)
    );
    $groupsSeenByService = new \Modules\Groups\Service\SeenByService($groupsReadStateService, $groupsIdentityService);
    $groupsMentionService = new \Modules\Groups\Service\MentionService($groupsRecipientResolver, $memberService, $groupsIdentityService);

    // Group files are readable only by the group's own members —
    // ARCHITECTURE.md §8.3's owner_type registry, appended here so it
    // reaches FileAccessGuard, which is built after every module block.
    $groupsFileOwnershipChecker = new \Modules\Groups\File\GroupFileOwnershipChecker(
        $groupsGroupRepo, $groupsAccessService, $scoutYearResolver
    );
    $fileOwnershipCheckers[] = $groupsFileOwnershipChecker;

    // The gallery-side twin of the checker above, appended to gallery's
    // OWN registry (Service\DelegatedAlbumAccessRegistry — a SEPARATE
    // registry from Core\File\FileAccessGuard's, see gallery prompt 5) so
    // a group's delegated album is readable by exactly the same members.
    // $galleryDelegatedAlbumAccessCheckers is seeded near the top of this
    // file and only consumed at the very end, once every module block
    // that might append to it — this one included — has run.
    $galleryDelegatedAlbumAccessCheckers[] = new \Modules\Groups\Gallery\GroupDelegatedAlbumAccessChecker(
        $groupsFileOwnershipChecker
    );

    // And the name gallery's storage-administration page shows for that
    // same album — "Groupe Chefs d'unité" rather than "discussion_group #7".
    // Read-only and separate on purpose: naming an album for an
    // administrator is not the same permission as opening it.
    $galleryDelegatedAlbumDescribers[] = new \Modules\Groups\Gallery\GroupDelegatedAlbumDescriber(
        $groupsFileOwnershipChecker,
        $groupsGroupRepo
    );

    // GroupController and PostController are the two that carry groups'
    // optional "ce message parle de la réunion de samedi" link, and
    // calendar's lookup does not exist yet: its block runs later in this
    // file, because it needs the retro lookup whose block runs after this
    // one. So their wiring lives in this closure and is called twice —
    // once here with no event service, which is also exactly what a
    // calendar-disabled install keeps for good (ARCHITECTURE.md §7.5's
    // "works with the other module switched off"), and once more from the
    // calendar block at the end of this file, with the real lookup.
    //
    // One construction site rather than two copies: a constructor
    // argument added to either controller can no longer be added to the
    // early wiring and forgotten in the late one, which would have
    // broken calendar-enabled installs only.
    $groupsRegisterEventAwareControllers = function (
        ?\Modules\Groups\Service\PostEventService $eventService
    ) use (
        $frontController, $twig, $groupsGroupRepo, $groupsPostRepo, $groupsAuthorResolver,
        $groupsPostService, $groupsPostMediaService, $groupsPostLinkRepo, $groupsPostLinkService,
        $groupsReplyRepo, $groupsReplyPresenter, $groupsReplyService, $groupsReactionService,
        $groupsReportService, $groupsReadStateService, $groupsPollService, $groupsListService,
        $groupsAccessService, $groupsService, $groupsContextFactory, $sectionService,
        $groupsSectionGroupSync, $groupsModeratorBinding, $groupsMembershipService,
        $settingService, $groupsNotificationService, $groupsSeenByService, $groupsMentionService,
        $groupsIdentityService, $groupsRecipientResolver
    ): void {
        $feedService = new \Modules\Groups\Service\GroupFeedService(
            $groupsPostRepo,
            $groupsAuthorResolver,
            $groupsPostService,
            $groupsPostMediaService,
            $groupsPostLinkRepo,
            $groupsReplyRepo,
            $groupsReplyPresenter,
            $groupsReactionService,
            $groupsReportService,
            $groupsReadStateService,
            $eventService,
            $groupsPollService
        );
        $frontController->registerController(
            \Modules\Groups\Controller\GroupController::class,
            new \Modules\Groups\Controller\GroupController(
                $twig, $groupsGroupRepo, $groupsListService, $groupsAccessService, $groupsService,
                $groupsContextFactory, $sectionService, $feedService, $groupsPostMediaService,
                $groupsPostRepo, $groupsSectionGroupSync, $groupsModeratorBinding, $groupsMembershipService,
                $settingService, $groupsReadStateService, $eventService, $groupsIdentityService,
                $groupsReportService, $groupsPostService
            )
        );
        // The moderator's reports page renders the same post cards as the
        // feed, so it needs the same feed service — which is why it is
        // registered here rather than above: the one built inside this
        // closure is the only one that knows about the calendar module
        // (§7.5), and a card rendered without it silently loses its
        // event line.
        $frontController->registerController(
            \Modules\Groups\Controller\ReportController::class,
            new \Modules\Groups\Controller\ReportController(
                $twig, $groupsGroupRepo, $groupsPostRepo, $groupsReplyRepo, $groupsAccessService,
                $groupsReportService, $groupsContextFactory, $groupsNotificationService,
                $groupsRecipientResolver, $feedService, $groupsPostService
            )
        );
        $frontController->registerController(
            \Modules\Groups\Controller\PostController::class,
            new \Modules\Groups\Controller\PostController(
                $twig, $groupsGroupRepo, $groupsPostRepo, $groupsAccessService, $feedService,
                $groupsPostService, $groupsContextFactory, $groupsPostMediaService, $groupsPostLinkService,
                $groupsReplyService, $groupsReportService,
                $groupsNotificationService, $groupsSeenByService, $groupsMentionService, $eventService,
                $groupsPollService, $groupsIdentityService
            )
        );
    };
    $groupsRegisterEventAwareControllers(null);

    // Re-registers PageController with the groups activity hook — same
    // core-hook precedent as the banner/news/trombinoscope blocks above
    // (ARCHITECTURE.md §7.4), and the same "reuse whatever the earlier
    // blocks already set" rule, so enabling groups never silently drops
    // another module's homepage contribution. This block runs after all
    // three of them, so each variable is either the real provider or the
    // null it was initialised to.
    $frontController->registerController(
        PageController::class,
        new PageController(
            $twig, $editableContentService, $sectionRepository, $settingService, $rgpdContentService,
            $sectionService, $unitStaffSectionService, $scoutYearService,
            in_array('banner', $moduleManager->getEnabledModuleIds(), true) ? $bannerService : null,
            in_array('news', $moduleManager->getEnabledModuleIds(), true) ? $newsArticleService : null,
            $sectionResponsableProvider,
            new \Modules\Groups\Api\HomeActivityService(
                $groupsListService,
                $groupsContextFactory,
                $groupsReadRepo,
                $groupsPostRepo,
                $groupsReplyRepo,
                \Modules\Groups\Repository\ReactionRepository::forPosts($pdo),
                \Modules\Groups\Repository\ReactionRepository::forReplies($pdo),
                $notificationRepo
            ),
            $homePaymentDueProvider
        )
    );
    $frontController->registerController(
        \Modules\Groups\Controller\ReplyController::class,
        new \Modules\Groups\Controller\ReplyController(
            $twig, $groupsGroupRepo, $groupsPostRepo, $groupsReplyRepo, $groupsAccessService,
            $groupsReplyService, $groupsReplyPresenter, $groupsPostMediaService, $groupsContextFactory,
            $groupsReportService, $groupsNotificationService, $groupsMentionService
        )
    );
    $frontController->registerController(
        \Modules\Groups\Controller\ReactionController::class,
        new \Modules\Groups\Controller\ReactionController(
            $twig, $groupsGroupRepo, $groupsPostRepo, $groupsReplyRepo, $groupsAccessService,
            $groupsReactionService, $groupsContextFactory, $groupsNotificationService,
            $groupsReactorListService
        )
    );
    $frontController->registerController(
        \Modules\Groups\Controller\GroupMemberController::class,
        new \Modules\Groups\Controller\GroupMemberController(
            $twig, $groupsGroupRepo, $groupsMemberRepo, $groupsSectionRepo, $groupsAccessService,
            $groupsService, $groupsContextFactory, $sectionService,
            $groupsMembershipService, $groupsIdentityService,
            $groupsRecipientResolver, $userAccountRepo, $groupsNotificationService
        )
    );
}

// Modules\SupportDashboard — the statistics receiver (ARCHITECTURE.md
// §8.49). Only ever discovered on the receiving installation, so this block
// is dead code everywhere else by construction.
if (in_array('support_dashboard', $moduleManager->getEnabledModuleIds(), true)) {
    $supportInstallationRepo = new \Modules\SupportDashboard\Repository\SupportInstallationRepository($pdo);
    $supportRateLimitRepo = new \Modules\SupportDashboard\Repository\SupportReportRateLimitRepository($pdo);
    $supportMonthlyAggregateRepo = new \Modules\SupportDashboard\Repository\SupportMonthlyAggregateRepository($pdo);

    $frontController->registerController(
        \Modules\SupportDashboard\Controller\SupportDashboardController::class,
        new \Modules\SupportDashboard\Controller\SupportDashboardController(
            $twig,
            new \Modules\SupportDashboard\Service\SupportDashboardService(
                $supportInstallationRepo,
                $settingService,
                $supportMonthlyAggregateRepo
            ),
            $journalService
        )
    );

    $frontController->registerController(
        \Modules\SupportDashboard\Controller\StatisticsIntakeController::class,
        new \Modules\SupportDashboard\Controller\StatisticsIntakeController(
            $twig,
            new \Modules\SupportDashboard\Service\StatisticsIntakeService(
                $supportInstallationRepo,
                $supportRateLimitRepo,
                $encryptionService,
                $journalService,
                $supportMonthlyAggregateRepo
            )
        )
    );

    // Every one of this module's self-rescheduling daily tasks needs its
    // FIRST occurrence seeded here: declaring a handler in module.json only
    // teaches SchedulerRunner how to run the task, it never queues one, and
    // a task that is never queued reschedules itself never. Two of the
    // three were missing, which silently disabled retention entirely and
    // left support_monthly_aggregates permanently empty — the whole of
    // ARCHITECTURE.md §8.51 — with nothing anywhere saying so.
    // Tests\Modules\SupportDashboard\ModuleSchedulingTest now fails if this
    // list ever drifts from module.json's `scheduled_tasks` again.
    foreach ([
        // Rate-limit rows are written on every accepted report and only ever
        // read for the last hour — without this the table grows forever.
        \Modules\SupportDashboard\Task\PurgeRateLimitsHandler::TASK_KEY => \Modules\SupportDashboard\Task\PurgeRateLimitsHandler::REFERENCE,
        // Retention (§8.50): past support_retention_months with no report,
        // the whole record goes — id, URL, payload and credential hash.
        \Modules\SupportDashboard\Task\PurgeInstallationsHandler::TASK_KEY => \Modules\SupportDashboard\Task\PurgeInstallationsHandler::REFERENCE,
        // Monthly history (§8.51): closes every calendar month that ended.
        \Modules\SupportDashboard\Task\FinalizeMonthlyAggregateHandler::TASK_KEY => \Modules\SupportDashboard\Task\FinalizeMonthlyAggregateHandler::REFERENCE,
    ] as $supportTaskKey => $supportTaskReference) {
        $schedulerService->rearm('support_dashboard', $supportTaskKey, $supportTaskReference, new DateTimeImmutable());
    }
}

if (in_array('test_tools', $moduleManager->getEnabledModuleIds(), true)) {
    // The mail sandbox (ARCHITECTURE.md §8.63). Its transport was already
    // decided far above, next to MailService — this half only wires the
    // pages that show what was captured.
    $testToolsSandboxService = new \Modules\TestTools\Service\MailSandboxService(
        new \Modules\TestTools\Repository\CapturedEmailRepository($pdo, $encryptionService),
        $settingService,
        $encryptedFileStorageService,
        $journalService
    );

    $frontController->registerController(
        \Modules\TestTools\Controller\TestToolsController::class,
        new \Modules\TestTools\Controller\TestToolsController($twig)
    );

    $frontController->registerController(
        \Modules\TestTools\Controller\MailSandboxController::class,
        new \Modules\TestTools\Controller\MailSandboxController($twig, $testToolsSandboxService)
    );

    // The retention task's FIRST occurrence has to be seeded here.
    // Declaring a handler in module.json only teaches SchedulerRunner how
    // to run the task; it never queues one, and a self-rescheduling task
    // that is never queued reschedules itself never — exactly the mistake
    // ARCHITECTURE.md §8.49 records for support_dashboard, where two of
    // three tasks had consequently never run once on any receiver.
    // Tests\Modules\TestTools\ModuleSchedulingTest fails if this list
    // ever drifts from module.json's `scheduled_tasks`.
    foreach ([
        // Retention (§8.63): past mail_capture_retention messages, the
        // oldest go — rows and encrypted files together.
        \Modules\TestTools\Task\PurgeCapturedEmailsHandler::TASK_KEY => \Modules\TestTools\Task\PurgeCapturedEmailsHandler::REFERENCE,
    ] as $testToolsTaskKey => $testToolsTaskReference) {
        $schedulerService->rearm('test_tools', $testToolsTaskKey, $testToolsTaskReference, new DateTimeImmutable());
    }
}

if (in_array('camps', $moduleManager->getEnabledModuleIds(), true)) {
    $campsPlaceRepo = new \Modules\Camps\Repository\PlaceRepository($pdo);
    $campsCampRepo = new \Modules\Camps\Repository\CampRepository($pdo, $encryptionService);
    $campsContactRepo = new \Modules\Camps\Repository\ContactRepository($pdo, $encryptionService);
    $campsLinkRepo = new \Modules\Camps\Repository\LinkRepository($pdo);
    $campsDocumentRepo = new \Modules\Camps\Repository\DocumentRepository($pdo);
    $campsReviewRepo = new \Modules\Camps\Repository\ReviewRepository($pdo);
    $campsProposalRepo = new \Modules\Camps\Repository\FieldProposalRepository($pdo, $encryptionService);

    // Which stays a member's sections went on, for the admin member
    // page's « parcours » (ARCHITECTURE.md §7.4). Nothing records a
    // camp's participants one by one, so this crosses core's
    // member_section_periods with this module's camp_camp_sections —
    // see the service's own docblock for what that infers and what it
    // does not claim.
    $memberCampStayProvider = new \Modules\Camps\Service\MemberCampStayService(
        $campsCampRepo, $campsPlaceRepo, $sectionMembershipRepository, $sectionService, $scoutYearService
    );

    $campsSectionDescriber = new \Modules\Camps\Service\SectionDescriber($sectionService);
    $campsPlaceService = new \Modules\Camps\Service\PlaceService($campsPlaceRepo, $auditService);
    $campsCampService = new \Modules\Camps\Service\CampService($campsCampRepo, $auditService, $campsPlaceRepo);
    $campsContactService = new \Modules\Camps\Service\ContactService(
        $campsContactRepo, $auditService, $journalService
    );
    $campsDocumentService = new \Modules\Camps\Service\DocumentService(
        $campsDocumentRepo, $attachedFileRemover, $uploadHandler, $auditService
    );

    // Two OPTIONAL gallery capabilities, both nullable and both degrading
    // silently (ARCHITECTURE.md §7.4): link previews, and photos hosted
    // as a delegated album. A module whose subject is camp sites must not
    // become unusable because the gallery is switched off — without it a
    // link is a bare URL and the photos section is absent, and nothing
    // else changes.
    $campsLinkService = new \Modules\Camps\Service\LinkService(
        $campsLinkRepo,
        $auditService,
        $galleryLinkPreviewFetcher ?? null,
        $uploadHandler
    );
    $campsAlbumService = new \Modules\Camps\Service\CampAlbumService(
        $auditService,
        $galleryDelegatedAlbumManager ?? null
    );
    $campsReviewService = new \Modules\Camps\Service\ReviewService($campsReviewRepo, $auditService, $campsPlaceRepo);
    $campsSummaryService = new \Modules\Camps\Service\PlaceSummaryService(
        $campsPlaceRepo, $campsCampRepo, $campsReviewRepo, $editableContentService,
        $campsSectionDescriber, $llmConnectorForRgpd ?? null
    );
    $campsArchiveService = new \Modules\Camps\Service\PlaceArchiveService(
        $campsPlaceRepo, $campsCampRepo, $auditService
    );
    $campsMergeService = new \Modules\Camps\Service\MergeService(
        $campsPlaceRepo, $campsCampRepo, $campsContactRepo, $campsLinkRepo, $campsDocumentRepo,
        $campsReviewRepo, $editableContentService, $auditService, $campsAlbumService,
        // The PDO a merge's transaction runs on, and the mail that has to
        // follow a merged stay to its new reference.
        $pdo, $inboundMailForOthers
    );

    // Duplicate detection: the AI half is an optional dependency on
    // llm_connector and degrades to the textual comparison alone
    // (ARCHITECTURE.md §7.4). The model only ever SUGGESTS — a human
    // accepts or refuses, and nothing here can merge on its own.
    $campsDuplicateDetector = new \Modules\Camps\Service\DuplicatePlaceDetector(
        $campsPlaceRepo,
        $llmConnectorForRgpd ?? null
    );

    // The three DAILY tasks re-arm themselves to a fixed hour, so each
    // needs seeding exactly once — on the first page load after the module
    // is enabled. Guarded on find() rather than scheduled blindly, or every
    // request would queue another copy.
    foreach ([
        [\Modules\Camps\Task\ReviewReminderHandler::TASK_KEY, \Modules\Camps\Task\ReviewReminderHandler::REFERENCE, 'tomorrow 06:00'],
        [\Modules\Camps\Task\PurgeUnsortedMailHandler::TASK_KEY, \Modules\Camps\Task\PurgeUnsortedMailHandler::REFERENCE, 'tomorrow 04:00'],
        [\Modules\Camps\Task\RefreshPlaceSummariesHandler::TASK_KEY, \Modules\Camps\Task\RefreshPlaceSummariesHandler::REFERENCE, 'tomorrow 05:00'],
    ] as [$campsTaskKey, $campsTaskReference, $campsTaskWhen]) {
        $schedulerService->rearm('camps', $campsTaskKey, $campsTaskReference, $campsTaskWhen);
    }

    // Geocoding is the one that is NOT periodic, and seeding it like the
    // three above is what made it spin: GeocodePlacesHandler geocodes one
    // place and re-arms itself only while more are pending, so as soon as
    // the queue empties there is no pending occurrence left — and an
    // unconditional rearm() here queued another one, a minute later, for
    // ever. On the real site that was 277 runs in ten hours, each finding
    // nothing to do in two milliseconds, and a third of the event journal.
    //
    // So the condition is the work itself. countPendingGeocoding() replaces
    // the find() that rearm() would have done anyway, and on the ordinary
    // page load — nothing to geocode — this is where the chain stops
    // instead of restarting.
    if ($campsPlaceRepo->countPendingGeocoding() > 0) {
        $schedulerService->rearm(
            'camps',
            \Modules\Camps\Task\GeocodePlacesHandler::TASK_KEY,
            \Modules\Camps\Task\GeocodePlacesHandler::REFERENCE,
            '+1 minute'
        );
    }

    // The summary refresher is auto-resolved from the manifest like any
    // other task; it reaches llm_connector through
    // TaskContext::getOptional() at run time (the capability is
    // registered in public/scheduler-bootstrap.php, identically for both
    // entry points).

    // BOTH file gates, because they guard different routes and a module
    // registering only one leaves its files reachable through the other:
    // FileOwnershipChecker gates /files/{id} (documents, link preview
    // images), DelegatedAlbumAccessChecker gates /gallery/media/{id}
    // (the photos). They must agree, and here they do — every chief of
    // the unit sees every stay.
    $fileOwnershipCheckers[] = new \Modules\Camps\Service\CampFileOwnershipChecker();

    // Read back at the very end of this file, when the response exists.
    $campsMapTileOrigin = \Modules\Camps\Service\MapTiles::ORIGIN;

    // Inbound mail. The mail-reading services below serve the WEB
    // controllers (« Créer un camp depuis ce message », field
    // completion); the module's MessageConsumer itself is built inside
    // the sync handler's lazy factory in public/scheduler-bootstrap.php,
    // where its last-in-registration-order rule is enforced once.
    $campsMessageReader = new \Modules\Camps\Mail\MessageReader();
    $campsFieldCompletion = new \Modules\Camps\Mail\MailFieldCompletionService(
        $campsCampRepo, $campsProposalRepo, $auditService, $campsMessageReader
    );
    // `camps_auto_create_from_mail`: the SAME reading behind the automatic
    // stay and behind « Créer un camp depuis ce message », so the two can
    // never disagree about what a message says. The connector is optional
    // (ARCHITECTURE.md §7.5) and decides one thing only: with it, a NEW
    // place may be named from the message body; without it, a message can
    // still join a place already known, and nothing else is ever created.
    $campsStayFromMail = new \Modules\Camps\Mail\StayFromMailService(
        $campsCampRepo, $campsCampService, $campsPlaceService,
        $campsDuplicateDetector, $campsMessageReader, $settingService,
        $inboundMailForOthers ?? null, $llmConnectorForRgpd ?? null
    );
    $frontController->registerController(
        \Modules\Camps\Controller\CampsMailController::class,
        new \Modules\Camps\Controller\CampsMailController(
            $twig, $campsCampRepo, $campsPlaceRepo, $settingService, $inboundMailForOthers ?? null,
            $campsProposalRepo, $campsFieldCompletion
        )
    );
    $galleryDelegatedAlbumAccessCheckers[] = new \Modules\Camps\Service\CampAlbumAccessChecker();

    // Who may read a camp's or a place's change history (Core\Audit,
    // ARCHITECTURE.md §8.66). Both routes carrying the timeline are
    // role_min chief and every chief sees every camp of their own unit —
    // there is no per-place visibility in this module — so the checker
    // adds the one thing the role cannot answer: whether the entity
    // exists at all. Without these two lines the timeline would simply
    // not load, which is the intended direction of that failure.
    $auditAccessResolver->register(
        \Modules\Camps\Service\PlaceService::ENTITY_TYPE,
        static fn(int $id): bool => $campsPlaceRepo->findById($id) !== null
    );
    $auditAccessResolver->register(
        \Modules\Camps\Service\CampService::ENTITY_TYPE,
        static fn(int $id): bool => $campsCampRepo->findById($id) !== null
    );

    $frontController->registerController(
        \Modules\Camps\Controller\CampsChiefController::class,
        new \Modules\Camps\Controller\CampsChiefController(
            $twig, $campsPlaceRepo, $campsCampRepo, $campsPlaceService, $campsCampService,
            $campsSectionDescriber, $sectionService, $editableContentService, $auditService, $settingService,
            $campsContactRepo, $campsLinkRepo, $campsDocumentRepo, $campsAlbumService,
            $campsReviewRepo, $campsReviewService, $campsDuplicateDetector, $campsArchiveService,
            $inboundMailForOthers ?? null, $campsProposalRepo, $campsSummaryService,
            // Only to suggest this year's staff in « Réservation faite par »
            // — the field stays free text when the resolver is absent.
            $scoutYearResolver,
            // And to pre-fill the form from an unsorted message.
            $campsStayFromMail
        )
    );
    $frontController->registerController(
        \Modules\Camps\Controller\CampsAttachmentController::class,
        new \Modules\Camps\Controller\CampsAttachmentController(
            $twig, $campsCampRepo, $campsPlaceRepo, $campsContactRepo, $campsLinkRepo, $campsDocumentRepo,
            $campsContactService, $campsLinkService, $campsDocumentService, $campsAlbumService,
            $campsReviewService, $campsReviewRepo
        )
    );
    $frontController->registerController(
        \Modules\Camps\Controller\CampsMergeController::class,
        new \Modules\Camps\Controller\CampsMergeController(
            $twig, $campsPlaceRepo, $campsCampRepo, $campsMergeService, $campsArchiveService
        )
    );
    $frontController->registerController(
        \Modules\Camps\Controller\CampsConfigController::class,
        new \Modules\Camps\Controller\CampsConfigController($twig, $settingService)
    );
}

if (in_array('retro', $moduleManager->getEnabledModuleIds(), true)) {
    $retroBoardRepo = new \Modules\Retro\Repository\BoardRepository($pdo, $encryptionService);
    $retroCommentRepo = new \Modules\Retro\Repository\CommentRepository($pdo);
    $retroVoteRepo = new \Modules\Retro\Repository\VoteRepository($pdo);
    $retroRateLimitRepo = new \Modules\Retro\Repository\RateLimitRepository($pdo);

    $retroRateLimitService = new \Modules\Retro\Service\RateLimitService($retroRateLimitRepo, $encryptionService);
    $retroVoteService = new \Modules\Retro\Service\VoteService($retroVoteRepo, $retroCommentRepo, $encryptionService);
    // Optional dependency on the llm_connector module (ARCHITECTURE.md
    // §7.5), same reused instance as RGPD content generation above — the
    // moderation check and AI-shorten button simply degrade to unavailable.
    $retroModerationService = new \Modules\Retro\Service\ModerationService($llmConnectorForRgpd);
    $retroSummaryService = new \Modules\Retro\Service\SummaryService($llmConnectorForRgpd);
    $retroCommentService = new \Modules\Retro\Service\CommentService($retroCommentRepo, $retroModerationService, $retroRateLimitService);
    $retroBoardService = new \Modules\Retro\Service\BoardService(
        $retroBoardRepo, $retroCommentRepo, $memberService, $sectionService, $schedulerService, $journalService,
        $mailService, $twig, (string) ($settingService->get('site_name') ?: 'Unité scoute'), (string) ($settingService->get('base_url') ?: ''),
        $shortUrlService,
        in_array('calendar', $moduleManager->getEnabledModuleIds(), true) ? $calendarService : null,
        $retroSummaryService
    );

    $frontController->registerController(
        \Modules\Retro\Controller\RetroChiefController::class,
        new \Modules\Retro\Controller\RetroChiefController(
            $twig, $retroBoardRepo, $retroBoardService, $settingService, $scoutYearResolver, $moduleManager
        )
    );
    $frontController->registerController(
        \Modules\Retro\Controller\RetroBoardController::class,
        new \Modules\Retro\Controller\RetroBoardController(
            $twig, $retroBoardRepo, $retroCommentRepo, $retroCommentService, $retroVoteService, $retroBoardService,
            $retroRateLimitService, $retroModerationService, $cookieConsentService, $settingService, $scoutYearService
        )
    );
    $frontController->registerController(
        \Modules\Retro\Controller\RetroConfigController::class,
        new \Modules\Retro\Controller\RetroConfigController(
            $twig, $settingService, $journalService, $memberService, $scoutYearService, $retroModerationService
        )
    );

    // Bootstrap the recurring rate-limit purge — Task\PurgeRateLimitHandler
    // re-schedules itself daily at the end of every run (same pattern as
    // Core\Maintenance\Task\AutoBackupHandler), but the very first
    // occurrence needs an initial nudge. auto_close_board needs no such
    // bootstrap — it's scheduled per-board by Service\BoardService::
    // create()/update().
    $schedulerService->rearm('retro', 'purge_rate_limits', 'daily', new DateTimeImmutable());
}

// Re-registers calendar's event-facing services/controllers with the
// optional retro event-link lookup, now that $retroBoardService exists
// (retro's own block above needs $calendarService already built, so it
// necessarily runs after calendar's block in file order — this is the
// only way for the dependency to also flow in the opposite direction).
// Same "placed after both blocks so their repositories are in scope"
// precedent as MemberController's re-registration below. $calendarService/
// $calendarEventRepo/etc. are still in scope here — PHP has no block
// scoping, only function scoping, so calendar's own top-level `if` body
// variables remain readable for the rest of this script.
if (in_array('calendar', $moduleManager->getEnabledModuleIds(), true)) {
    $retroEventLinkLookup = in_array('retro', $moduleManager->getEnabledModuleIds(), true) ? $retroBoardService : null;

    $calendarService = new \Modules\Calendar\Service\CalendarService(
        $calendarRepo, $calendarEventRepo, $sectionService, $calendarUnitFeedTokenRepo, $retroEventLinkLookup
    );
    // CalendarService implements Modules\Calendar\Api\CalendarEventLookupInterface
    // — reused as-is (member page §3's "next upcoming event", no interface
    // change needed) rather than adding a second lookup surface.
    $calendarEventLookup = $calendarService;
    $calendarRetroAutoCreateService = new \Modules\Calendar\Service\CalendarRetroAutoCreateService(
        $schedulerService, $retroEventLinkLookup
    );
    $calendarEventService = new \Modules\Calendar\Service\CalendarEventService(
        $calendarEventRepo, $calendarService, $calendarNotificationService, $calendarRetroAutoCreateService
    );
    $calendarPersonalFeedService = new \Modules\Calendar\Service\PersonalFeedService(
        $calendarPersonalTokenRepo, $calendarService, $calendarEventRepo,
        $roleResolver, $memberService, $userAccountRepo, $sectionService, $retroEventLinkLookup
    );
    $calendarPickerService = new \Modules\Calendar\Service\CalendarPickerService(
        $calendarService, $calendarPersonalFeedService
    );

    $frontController->registerController(
        \Modules\Calendar\Controller\CalendarPublicController::class,
        new \Modules\Calendar\Controller\CalendarPublicController(
            $twig, $calendarService, $calendarPickerService, $monthGridBuilder, $calendarPersonalFeedService,
            $calendarIcsBuilder, $scoutYearResolver, $journalService
        )
    );
    $frontController->registerController(
        \Modules\Calendar\Controller\CalendarChiefController::class,
        new \Modules\Calendar\Controller\CalendarChiefController(
            $twig, $calendarService, $calendarPickerService, $monthGridBuilder, $calendarEventService,
            $sectionService, $memberService, $scoutYearResolver, $journalService, $settingService, $moduleManager,
            $sectionStaffAuthorizationService
        )
    );

    // Groups' optional "ce message parle de la réunion de samedi" link.
    // $calendarEventLookup only exists this far down the file, so groups'
    // own block wired its two event-aware controllers with no event
    // service and left the closure below behind to redo it once the
    // lookup is real. Calling it a second time replaces both
    // registrations; with calendar disabled this never runs and the
    // event-less pair stays in place, which is exactly the "works with
    // the other module switched off" contract of ARCHITECTURE.md §7.5.
    //
    // isset() rather than the module check the rest of this file uses:
    // the closure is the honest witness that groups' block actually ran,
    // and it is what static analysis can follow.
    if (isset($groupsRegisterEventAwareControllers)) {
        $groupsRegisterEventAwareControllers(
            new \Modules\Groups\Service\PostEventService($calendarEventLookup)
        );
    }
}

if (in_array('registration', $moduleManager->getEnabledModuleIds(), true)) {
    $registrationBaseUrl = (string) ($settingService->get('base_url') ?: '');
    $registrationSiteName = (string) ($settingService->get('site_name') ?: 'Unité scoute');

    $registrationRequestRepo = new \Modules\Registration\Repository\RegistrationRequestRepository($pdo, $encryptionService);
    $registrationYearCodeRepo = new \Modules\Registration\Repository\RegistrationYearCodeRepository($pdo);
    $registrationAgeBracketRepo = new \Modules\Registration\Repository\AgeBracketRepository($pdo);
    $registrationSlotCapacityRepo = new \Modules\Registration\Repository\SlotCapacityRepository($pdo);
    $registrationSecondaryEmailRepo = new \Modules\Registration\Repository\RegistrationSecondaryEmailRepository($pdo, $encryptionService);

    $registrationSlotService = new \Modules\Registration\Service\SlotService(
        $pdo, $encryptionService, $settingService, $registrationAgeBracketRepo, $registrationSlotCapacityRepo, $registrationRequestRepo
    );
    $registrationService = new \Modules\Registration\Service\RegistrationService(
        $registrationRequestRepo, $registrationYearCodeRepo, $scoutYearResolver, $scoutYearService, $settingService,
        $mailService, $editableContentService, $journalService, $registrationBaseUrl, $registrationSiteName
    );
    $registrationSecondaryEmailService = new \Modules\Registration\Service\SecondaryEmailService(
        $registrationSecondaryEmailRepo, $mailService, $twig, $journalService, $registrationBaseUrl, $registrationSiteName
    );
    $registrationTrackingService = new \Modules\Registration\Service\TrackingService(
        $registrationRequestRepo, $registrationSecondaryEmailRepo, $encryptionService
    );
    $registrationMenuHookService = new \Modules\Registration\Service\RegistrationMenuHookService($registrationTrackingService, $settingService);

    // Which registration request a member came from, for the admin member
    // page's origin line (ARCHITECTURE.md §7.4). A pointer only — the
    // request keeps its own page, and nothing of its content is copied.
    $memberRegistrationOriginProvider = new \Modules\Registration\Service\MemberRegistrationOriginService($registrationRequestRepo);

    // Iteration 5's staff-side services — status transitions, acceptance/
    // refusal emails, and the one migration path shared by automatic
    // reconciliation and manual linking. The Api\
    // HouseholdRegistrationCountProvider implementation is NOT built here:
    // Core\Member\FeeEstimationService is core and is assembled in the
    // common trunk above, whatever this module's state.
    $registrationStatusService = new \Modules\Registration\Service\RequestStatusService($registrationRequestRepo, $journalService);
    $registrationEmailService = new \Modules\Registration\Service\RequestEmailService(
        $registrationRequestRepo, $mailService, $editableContentService, $journalService, $registrationBaseUrl, $registrationSiteName
    );
    $registrationMigrationService = new \Modules\Registration\Service\MigrationService(
        $pdo, $registrationRequestRepo, $registrationSecondaryEmailRepo, $memberEmailRepository, $journalService
    );
    $registrationReconciliation = new \Modules\Registration\Service\ReconciliationService(
        $pdo, $registrationRequestRepo, $encryptionService, $registrationMigrationService, $journalService
    );
    $frontController->registerController(
        \Modules\Registration\Controller\PublicRegistrationController::class,
        new \Modules\Registration\Controller\PublicRegistrationController(
            $twig, $registrationService, $registrationSlotService, $sectionService, $registrationAgeBracketRepo,
            $scoutYearResolver, $memberService, $settingService, $humanCheckService
        )
    );
    $frontController->registerController(
        \Modules\Registration\Controller\TrackingController::class,
        new \Modules\Registration\Controller\TrackingController(
            $twig, $registrationTrackingService, $registrationSecondaryEmailService, $registrationRequestRepo,
            $registrationStatusService
        )
    );
    $frontController->registerController(
        \Modules\Registration\Controller\RegistrationConfigController::class,
        new \Modules\Registration\Controller\RegistrationConfigController(
            $twig, $registrationAgeBracketRepo, $registrationSlotCapacityRepo, $registrationYearCodeRepo,
            $scoutYearResolver, $scoutYearService, $registrationRequestRepo, $registrationSlotService,
            $sectionService, $editableContentService, $registrationStatusService, $journalService,
            $settingService, new \Modules\Registration\Service\RequestExportService()
        )
    );
    $frontController->registerController(
        \Modules\Registration\Controller\RegistrationRequestController::class,
        new \Modules\Registration\Controller\RegistrationRequestController(
            $twig, $registrationRequestRepo, $registrationAgeBracketRepo, $sectionService, $feeCategoryRepo,
            $feeEstimationService, $registrationStatusService, $registrationEmailService, $registrationMigrationService,
            $memberRepo, $memberYearRepo, $scoutYearResolver, $scoutYearService, $registrationSlotService,
            $memberService
        )
    );

    // Iteration 6 — Départs (reusing the one core section-staff
    // authorization service built up top, never a second instance;
    // DepartureService is also core but constructed once, up top, since
    // Core\Http\Controller\MemberController's own departure endpoint needs
    // it whether or not this module is even enabled) and Passage (own
    // PassageService + SectionTransferRepository storage).
    $frontController->registerController(
        \Modules\Registration\Controller\DeparturesController::class,
        new \Modules\Registration\Controller\DeparturesController(
            $twig, $sectionStaffAuthorizationService, $sectionService, $departureService, $scoutYearResolver
        )
    );

    $registrationSectionTransferRepo = new \Modules\Registration\Repository\SectionTransferRepository($pdo);
    $registrationPassageService = new \Modules\Registration\Service\PassageService(
        $pdo, $encryptionService, $sectionService, $registrationSectionTransferRepo, $registrationRequestRepo, $registrationAgeBracketRepo
    );
    $frontController->registerController(
        \Modules\Registration\Controller\PassageController::class,
        new \Modules\Registration\Controller\PassageController(
            $twig, $registrationPassageService, $registrationRequestRepo, $registrationSectionTransferRepo, $sectionService,
            $registrationAgeBracketRepo, $registrationSlotService, $scoutYearResolver, $scoutYearService
        )
    );

    // Iteration 7 — the year-transition veto (Api\
    // ScoutYearTransitionVetoProvider, ARCHITECTURE.md §7.5/§8.38): Core\
    // ScoutYear\ScoutYearAdminService was built earlier with a null veto,
    // before this module's services existed — rebuilt here with the real
    // one. ScoutYearController is no longer re-registered here: two
    // different modules now feed that page (this one and calendar), so it
    // is registered once, after every module block, with whichever
    // providers exist by then.
    $registrationScoutYearVeto = new \Modules\Registration\Service\ScoutYearTransitionVetoService($registrationRequestRepo);
    $scoutYearAdminService = new ScoutYearAdminService($settingService, $registrationScoutYearVeto);

    // Api\ScoutYearPreparationProvider (ARCHITECTURE.md §7.5) — the second
    // half of what the "Année scoute" workflow asks this module: how much
    // of the Passage page is still undecided. Its absence is also what
    // makes that workflow drop its three preparation steps, which are this
    // module's own pages.
    $registrationScoutYearPreparation = new \Modules\Registration\Service\ScoutYearPreparationService(
        $registrationPassageService, $scoutYearResolver, $scoutYearService
    );

    // Iteration 7 — "Prévisions" (own ForecastService, reusing
    // PassageService::getAnimeMemberYears()/getBranchChanges()/
    // getNewRegistrations() rather than recomputing any of them).
    $registrationForecastService = new \Modules\Registration\Service\ForecastService(
        $pdo, $encryptionService, $sectionService, $registrationPassageService
    );
    $frontController->registerController(
        \Modules\Registration\Controller\ForecastController::class,
        new \Modules\Registration\Controller\ForecastController(
            $twig, $registrationForecastService, $scoutYearResolver, $scoutYearService, $registrationSlotService
        )
    );

    // Re-registers ImportController with the real reconciliation trigger —
    // the earlier registration (before this module's services existed)
    // used a forward-reference `?? null` since $registrationReconciliation
    // is only actually built here (same "re-register with the real
    // provider" pattern as MemberController's own re-registration below).
    $frontController->registerController(
        ImportController::class,
        new ImportController(
            $twig, $importService, $scoutYearResolver, $importJournalRepo, $functionRepo,
            $importRetentionService, $rosterSnapshotRepository, $fileRepository, $userAccountRepo,
            $importReportPresenter, $storagePath, $registrationReconciliation
        )
    );

    // Iteration 6's mailing list — Api\ExternalMailingListProvider,
    // consumed optionally by mass_mail (ARCHITECTURE.md §7.5). mass_mail's
    // own MailingListService/MassMailController/ConfigController were
    // already registered earlier (before this module's services existed,
    // same ordering constraint as ImportController above) — re-registered
    // here with the real provider only when mass_mail is also enabled.
    $registrationExternalMailingListService = new \Modules\Registration\Service\ExternalMailingListService(
        $pdo, $encryptionService, $scoutYearResolver, $scoutYearService, $registrationRequestRepo
    );
    if (in_array('mass_mail', $moduleManager->getEnabledModuleIds(), true)) {
        // $massMailService itself holds its own internal reference to the
        // OLD $massMailListService built above (before this provider
        // existed) — rebuilding just the list service wouldn't be enough,
        // since PHP doesn't retroactively update an already-injected
        // dependency. Both are rebuilt together here.
        $massMailListService = new \Modules\MassMail\Service\MailingListService(
            $massMailListRepo, $massMailResolutionRepo, $sectionService, $massMailFunctionRepo, $registrationExternalMailingListService
        );
        // Fresh instances rather than reusing the $massMailAudienceRepo/…
        // variables from the mass_mail block above — they're identical
        // stateless constructions, and reusing them here would only widen
        // the "might not be defined" pattern this cross-module
        // re-registration already carries (phpstan-baseline.neon).
        $massMailService = new \Modules\MassMail\Service\MassMailService(
            $massMailEmailRepo, $massMailRecipientRepo, $massMailAttachmentRepo, $fileRepository,
            $massMailListService, $memberService, $memberEmailService, $sectionService, $mailService, $schedulerService, $journalService,
            new \Core\Security\HtmlSanitizer(), $scoutYearService, $importJournalRepo, $storagePath,
            new \Modules\MassMail\Repository\AudienceRepository($pdo, $encryptionService),
            new \Modules\MassMail\Repository\MemberResolutionRepository($pdo, $encryptionService),
            new \Modules\MassMail\Repository\SuppressedAddressRepository($pdo),
            new \Modules\MassMail\Service\MergeRenderer()
        );
        $frontController->registerController(
            \Modules\MassMail\Controller\MassMailController::class,
            new \Modules\MassMail\Controller\MassMailController(
                $twig, $massMailService, $massMailListService, $massMailAccessService, $memberService, $sectionService,
                $scoutYearService, $importJournalRepo, $settingService, $uploadHandler, $fileRepository,
                new \Modules\MassMail\Service\AudienceImportService(
                    new \Modules\MassMail\Repository\AudienceRepository($pdo, $encryptionService),
                    new \Modules\MassMail\Repository\MemberResolutionRepository($pdo, $encryptionService),
                    $journalService
                )
            )
        );
        $frontController->registerController(
            \Modules\MassMail\Controller\ConfigController::class,
            new \Modules\MassMail\Controller\ConfigController($twig, $massMailListService, $settingService)
        );
    }

    // Bootstrap the recurring open/close pollers — Task\
    // OpenRegistrationHandler/CloseRegistrationHandler re-schedule
    // themselves hourly at the end of every run (same pattern as
    // Modules\Retro\Task\PurgeRateLimitHandler), but the very first
    // occurrence needs an initial nudge.
    $schedulerService->rearm('registration', 'open_registration', 'poll', new DateTimeImmutable());
    $schedulerService->rearm('registration', 'close_registration', 'poll', new DateTimeImmutable());
    // Same bootstrap for the daily retention purge (Task\
    // PurgeRegistrationRequestsHandler) — module-scoped handlers need no
    // manual registerHandler() call in either entry point (auto-resolved
    // via ModuleManager::getTaskHandler()), only this one-time nudge.
    $schedulerService->rearm('registration', 'purge_registration_requests', 'daily', new DateTimeImmutable());
    // Same again for the Passage auto-assignment (Task\
    // AutoAssignPassageHandler) — it used to run inside PassageController::
    // index(), i.e. a write on every GET of the page.
    $schedulerService->rearm('registration', 'auto_assign_passage', 'hourly', new DateTimeImmutable());

    // Menu hook (Core\Module\MenuEntryProvider, ARCHITECTURE.md §7.4) — one
    // entry per pending registration request linked to the visitor's email.
    // $menuBuilder->build() was already called above (before this module's
    // services existed); addPage() only mutates MenuBuilder's own internal
    // list, so calling build() again here safely picks up these entries too,
    // and the Twig global is re-set to the refreshed array. The
    // highlight/active-page scan above ran before these URLs existed, so
    // DynamicMenuRegistrar::resolveActive() re-applies it over just the new
    // entries, carrying the earlier scan's best match forward.
    $registrationMenuEntries = $dynamicMenuRegistrar->register(
        $menuBuilder,
        [$registrationMenuHookService],
        AuthSession::isAuthenticated() ? AuthSession::getEmail() : null
    );
    if ($registrationMenuEntries !== []) {
        $menus = $menuBuilder->build();
        $twig->addGlobal('menus', $menus);

        $registrationMenuActive = $dynamicMenuRegistrar->resolveActive(
            $registrationMenuEntries,
            $currentPath,
            $activeMenuId,
            $activePageUrl,
            $bestMatchLength
        );
        $activeMenuId = $registrationMenuActive['menuId'];
        $activePageUrl = $registrationMenuActive['pageUrl'];
        $bestMatchLength = $registrationMenuActive['matchLength'];
        $twig->addGlobal('active_menu_id', $activeMenuId);
        $twig->addGlobal('active_page_url', $activePageUrl);
    }
}

// ── Locations (modules/rental) ─────────────────────────────────────────
// The repositories are built here rather than shared with the Desk-import
// listener block far above: that block runs before $moduleManager's own
// module list is settled into anything a static analyser can follow, so
// reusing its locals would only mean a forward reference nothing can prove
// is defined. Both are stateless wrappers around the same PDO handle.
if (in_array('rental', $moduleManager->getEnabledModuleIds(), true)) {
    $rentalCurrentYearId = (int) $scoutYearService->getCurrentYear()['id'];

    $rentalAssetRepository = new \Modules\Rental\Repository\RentalAssetRepository($pdo, $encryptionService);
    $rentalManagerRepository = new \Modules\Rental\Repository\RentalAssetManagerRepository($pdo);

    $rentalAuthorizationService = new \Modules\Rental\Service\RentalAuthorizationService(
        $memberService,
        $rentalAssetRepository,
        $rentalManagerRepository
    );
    $rentalSlugGenerator = new \Modules\Rental\Service\RentalSlugGenerator($rentalAssetRepository);
    // The closed list of asset types comes from configuration, so a unit
    // that lets out something nobody anticipated adds it once rather than
    // retyping it per asset. The service, not the form, is what enforces it.
    $rentalAssetService = new \Modules\Rental\Service\RentalAssetService(
        $rentalAssetRepository,
        $rentalSlugGenerator,
        $journalService,
        \Modules\Rental\Service\RentalAssetService::parseTypeList(
            (string) ($settingService->get('asset_type_suggestions', 'rental') ?: '')
        )
    );
    $rentalManagerService = new \Modules\Rental\Service\RentalManagerService(
        $rentalManagerRepository,
        $memberService,
        $journalService,
        // Reads `rental_manager_minimum_age`: who may be designated a
        // manager at all. A grant carries the renters' identities, the
        // money and the contracts, so the picker does not offer children.
        $settingService
    );
    // The pricing engine is pure and stateless, so one instance serves every
    // caller — the configuration simulator, the public page and the contract
    // are then provably the same code path, which is the only thing that
    // makes the simulator a real guard-rail against a wrong tariff.
    $rentalPricingService = new \Modules\Rental\Service\RentalPricingService(
        new \Modules\Rental\Repository\RentalPricingRepository($pdo),
        new \Modules\Rental\Pricing\RentalPricingEngine(),
        $journalService
    );

    // Occupancy sources (Modules\Rental\Availability\OccupancyProvider).
    // Bookings and manual blocks, merged by RentalAvailabilityService: every
    // calendar, estimate and range validation accounts for both without a
    // line of AvailabilityCalculator changing. To the public they are
    // indistinguishable, because an Occupancy carries nothing to tell them
    // apart by.
    $rentalBookingRepository = new \Modules\Rental\Repository\RentalBookingRepository($pdo, $encryptionService);
    $rentalChangeRequestRepository = new \Modules\Rental\Repository\RentalChangeRequestRepository($pdo, $encryptionService);
    // The booking's own change history (§6.15) now goes through Core\Audit
    // (§8.66), like Camps' and every other per-entity timeline: one storage
    // rule (every value encrypted), one partial, one JSON pagination route.
    // Modules\Rental\Audit\BookingAudit keeps this module's vocabulary —
    // its field keys, their French labels, and the member-to-account
    // mapping Core\Audit cannot make on its own.
    $rentalBookingAudit = new \Modules\Rental\Audit\BookingAudit(
        $auditService,
        new \Modules\Rental\Audit\ActorAccountResolver(
            $memberService, $userAccountRepo, $scoutYearService
        )
    );
    $rentalBookingService = new \Modules\Rental\Service\RentalBookingService(
        $rentalBookingRepository,
        $journalService,
        // The expiry sweep refuses a booking's pending change requests
        // along with it, exactly as a manager moving it to a final status
        // does — otherwise the renter's tracking page keeps offering
        // « Accepter » on a proposal for a booking that no longer exists.
        $rentalChangeRequestRepository,
        $rentalBookingAudit
    );
    $rentalBlockRepository = new \Modules\Rental\Repository\RentalBlockRepository($pdo);
    $rentalOccupancyProviders = [$rentalBookingService, $rentalBlockRepository];
    $rentalAvailabilityService = new \Modules\Rental\Service\RentalAvailabilityService(
        new \Modules\Rental\Availability\AvailabilityCalculator(),
        new \Modules\Rental\Repository\RentalConstraintsRepository($pdo),
        $rentalOccupancyProviders,
        // Booking rules are now written from the asset's own managed space
        // rather than from an admin-only page, so who changed them is worth
        // recording.
        $journalService
    );

    // Without this the timeline's later pages simply do not load — an
    // unregistered entity type is denied, which is the intended direction
    // of that failure (§8.66). Reading a booking's history needs the same
    // right as reading the booking, so the checker delegates to it.
    $auditAccessResolver->register(
        \Modules\Rental\Audit\BookingAudit::ENTITY_TYPE,
        static function (int $id) use ($rentalBookingRepository, $rentalAuthorizationService, $scoutYearService): bool {
            $booking = $rentalBookingRepository->findById($id);

            return $booking !== null && $rentalAuthorizationService->canManageAssetId(
                \Core\Security\AuthSession::getEmail(),
                (int) $scoutYearService->getCurrentYear()['id'],
                $booking->assetId
            );
        }
    );
    $rentalCommentRepository = new \Modules\Rental\Repository\RentalBookingCommentRepository($pdo, $encryptionService);
    // Payments (§6.19, §6.20). Every Finance dependency is nullable and
    // stays null when the module is disabled — the service then degrades to
    // "no receivable, no QR, nothing raised" and the rest of `rental` is
    // unaffected. A unit that settles its rentals by hand is a perfectly
    // normal unit (ARCHITECTURE.md §7.5).
    $rentalPaymentRepository = new \Modules\Rental\Repository\RentalPaymentRepository($pdo, $encryptionService);
    $rentalPaymentService = new \Modules\Rental\Service\RentalPaymentService(
        $rentalPaymentRepository,
        $rentalBookingAudit,
        $journalService,
        $financeExpectedReceivableForOthers,
        $financeStructuredCommunicationForOthers,
        $financeSepaQrCodeForOthers,
        $financeAccountForOthers
    );

    // Built before the controllers rather than in the inbound-mail block
    // further down, because the configuration page needs it — and it needs
    // nothing but the setting service and the null-seeded API handle, so it
    // is safe to build whether or not `inbound_mail` is enabled.
    $rentalMailboxSelection = new \Modules\Rental\Mail\MailboxSelection(
        $settingService,
        $inboundMailForOthers
    );

    $frontController->registerController(
        \Modules\Rental\Controller\RentalConfigController::class,
        new \Modules\Rental\Controller\RentalConfigController(
            $twig, $rentalAssetRepository, $rentalAssetService, $rentalManagerService,
            $scoutYearService, $settingService, $rentalPaymentService,
            // Which of the unit's already-configured mailboxes this module
            // listens to (§7.4). Never a host, an account or a password —
            // this only stores ids.
            $rentalMailboxSelection,
            // Read-only here: flags the public assets nobody has priced yet,
            // so a chief learns it from this page rather than from a visitor.
            $rentalPricingService
        )
    );
    $frontController->registerController(
        \Modules\Rental\Controller\RentalPricingController::class,
        new \Modules\Rental\Controller\RentalPricingController(
            $twig, $rentalPricingService, $rentalAvailabilityService,
            // `role_min: identified` on every one of this controller's
            // routes: the authorization service, not the route guard, is
            // what keeps one asset's tariff out of another manager's reach.
            $rentalAuthorizationService, $rentalAssetRepository, $scoutYearService,
            $rentalPaymentService
        )
    );
    $frontController->registerController(
        \Modules\Rental\Controller\RentalPublicController::class,
        new \Modules\Rental\Controller\RentalPublicController(
            $twig, $rentalAssetRepository, $rentalAuthorizationService, $scoutYearService,
            $rentalAvailabilityService, $rentalPricingService, new \Core\View\MonthGrid\DayStateGridBuilder()
        )
    );
    // Documents: contracts, invoices and whatever a manager attaches
    // (§6.24, §6.25, §6.27). The PDF path reuses dompdf through
    // Core\Pdf\DocumentPdfService — no new PDF dependency — and every file
    // lands under storage/ and is served only through FileAccessGuard.
    $rentalDocumentRepository = new \Modules\Rental\Repository\RentalDocumentRepository($pdo);
    $rentalDocumentService = new \Modules\Rental\Service\RentalDocumentService(
        $rentalDocumentRepository,
        $rentalBookingRepository,
        $rentalBookingAudit,
        $editableContentService,
        $fileRepository,
        $attachedFileRemover,
        new \Core\Pdf\DocumentPdfService(),
        new \Core\Security\HtmlSanitizer(),
        $settingService,
        $journalService,
        $storagePath
    );
    $rentalBookingMailService = new \Modules\Rental\Service\RentalBookingMailService(
        $mailService, $twig, $settingService, $journalService
    );

    // The asset paperwork register (§6.33). A reminder list, never a
    // compliance check: nothing here knows a regulation.
    $rentalComplianceService = new \Modules\Rental\Service\RentalComplianceService(
        new \Modules\Rental\Repository\RentalComplianceRepository($pdo),
        $settingService,
        $journalService,
        $fileRepository
    );

    // Its documents follow the same rule as a booking's: readable only by
    // somebody who may manage the asset (ARCHITECTURE.md §8.3).
    $fileOwnershipCheckers[] = new \Modules\Rental\File\RentalComplianceOwnershipChecker(
        $rentalAuthorizationService,
        (int) $scoutYearService->getCurrentYear()['id'],
        \Core\Security\AuthSession::getEmail()
    );

    // A rental document is readable only by somebody who may manage the
    // asset its booking belongs to — ARCHITECTURE.md §8.3's owner_type
    // registry, appended here so it reaches FileAccessGuard, which is built
    // after every module block. A renter is never allowed: their contract
    // reaches them by email and only by email (§6.24, §6.26).
    $fileOwnershipCheckers[] = new \Modules\Rental\File\RentalDocumentOwnershipChecker(
        $rentalBookingRepository,
        $rentalAuthorizationService,
        $rentalCurrentYearId,
        AuthSession::isAuthenticated() ? AuthSession::getEmail() : null
    );

    // The stay itself (§6.21–§6.23): meters, inventory, incidents and the
    // versioned final settlement. Note what is NOT here: the module
    // declares no `offline` section, so none of these pages is ever
    // whitelisted for offline caching — they are write pages, and the
    // offline layer caches reads (§6.23).
    // Calendar publication (§6.30, §6.31). The other half of the circular
    // dependency: the registry was created empty in the calendar block
    // above, and the provider is appended to it here. Both directions
    // degrade on their own — with `calendar` off the registry is null and
    // no provider is built; with `rental` off the registry simply has one
    // fewer entry.
    // Inbound mail (§7.6). This module's MessageConsumer is built inside
    // the sync handler's lazy factory in public/scheduler-bootstrap.php —
    // nothing on the web path reads the consumer registry. With
    // `inbound_mail` disabled the API handle is null and the
    // Communications tab is simply not offered.
    $rentalCommunicationService = null;

    if ($inboundMailForOthers !== null) {
        $rentalCommunicationService = new \Modules\Rental\Service\RentalCommunicationService(
            $rentalBookingRepository,
            $rentalDocumentRepository,
            $rentalAuthorizationService,
            $journalService,
            $inboundMailForOthers
        );
    }

    if ($calendarVirtualEventRegistry !== null) {
        $calendarVirtualEventRegistry->register(new \Modules\Rental\Calendar\RentalVirtualEventProvider(
            $rentalAssetRepository,
            $rentalBookingRepository,
            $rentalBlockRepository,
            $rentalAuthorizationService,
            (string) ($settingService->get('base_url') ?: '')
        ));
    }

    $rentalStayRepository = new \Modules\Rental\Repository\RentalStayRepository($pdo, $encryptionService);
    $rentalStayService = new \Modules\Rental\Service\RentalStayService(
        $rentalStayRepository,
        $rentalBookingAudit,
        $rentalPricingService,
        new \Modules\Rental\Stay\SettlementCalculator(),
        $journalService,
        $rentalPaymentService
    );

    $rentalOperationsService = new \Modules\Rental\Service\RentalOperationsService(
        $rentalBookingRepository,
        $rentalBookingAudit,
        $rentalCommentRepository,
        $rentalChangeRequestRepository,
        $rentalAvailabilityService,
        $rentalPricingService,
        new \Modules\Rental\Pricing\QuoteEditor(),
        $journalService,
        $rentalPaymentService,
        $rentalStayService
    );
    $rentalBlockService = new \Modules\Rental\Service\RentalBlockService(
        $rentalBlockRepository,
        $journalService
    );

    $frontController->registerController(
        \Modules\Rental\Controller\RentalManagementController::class,
        new \Modules\Rental\Controller\RentalManagementController(
            $twig, $rentalAuthorizationService, $scoutYearService, $rentalAssetRepository,
            $rentalBookingRepository, $auditService, $rentalCommentRepository,
            $rentalChangeRequestRepository, $rentalOperationsService, $rentalBlockService,
            $rentalAvailabilityService, $rentalPricingService, $memberService,
            new \Core\View\MonthGrid\DayStateGridBuilder(), $rentalPaymentService,
            $rentalDocumentService, $rentalBookingMailService, $uploadHandler, $rentalStayService,
            // `rental` consuming `calendar` — the other direction of the
            // same circularity, and a nullable dependency like every other
            // cross-module one.
            $calendarServiceForOthers,
            // And `rental` consuming `inbound_mail` (§7.7): null without
            // that module, in which case the booking page loses a tab and
            // nothing else.
            $rentalCommunicationService,
            $rentalComplianceService,
            // The three overview figures (§6.34), read from the live
            // bookings AND the aggregates a purge left behind.
            new \Modules\Rental\Service\RentalStatisticsService(
                $rentalBookingRepository,
                new \Modules\Rental\Repository\RentalAggregateRepository($pdo)
            ),
            // Only « Régénérer le lien de suivi » reaches it.
            $rentalBookingService
        )
    );
    $frontController->registerController(
        \Modules\Rental\Controller\RentalRequestController::class,
        new \Modules\Rental\Controller\RentalRequestController(
            $twig, $rentalAssetRepository, $rentalBookingService, $rentalAvailabilityService,
            $rentalPricingService,
            $rentalBookingMailService,
            $rentalManagerService, $memberService, $scoutYearService, $editableContentService,
            $humanCheckService, $settingService, $rentalOperationsService, $rentalChangeRequestRepository,
            // The renter's own ICS feed (§6.32): only the generator is
            // borrowed from `calendar`, never a calendar row. Without the
            // module the link simply is not offered.
            $calendarIcsBuilderForOthers,
            $calendarIcsBuilderForOthers !== null
                ? new \Modules\Rental\Calendar\RenterFeedBuilder((string) ($settingService->get('base_url') ?: ''))
                : null
        )
    );

    // Bootstrap the hourly hold-expiry poller (Task\ExpireRentalHoldsHandler,
    // which re-schedules itself at the end of every run — Core\Scheduler has
    // no recurring-task concept). Module-scoped handlers are auto-resolved
    // via ModuleManager::getTaskHandler() in both entry points, so this
    // one-time nudge is all the wiring there is; it is idempotent, so it
    // costs one indexed lookup per request and never queues a duplicate.
    // Availability does not depend on it: a hold is lapsed the moment its
    // deadline passes, which the calculator reads directly.
    \Modules\Rental\Task\ExpireRentalHoldsHandler::bootstrap($schedulerService);

    // The daily reminder pass (§6.29) and the retention purge (§6.35) are
    // auto-resolved from the manifest like any other task: each builds its
    // full service itself from the TaskContext, reaching Finance and
    // inbound_mail through TaskContext::getOptional() — one construction,
    // identical under the poor man's cron and a real crontab (the drift
    // where a crontab-run reminder pass said nothing about money is gone).
    // Their first occurrences are seeded by the shared scheduler bootstrap.

    // Menu hook (Core\Module\MenuEntryProvider) — the "Locations" index and
    // one entry per pinned public asset in "Notre unité", plus "Mes
    // locations" in "Espace membres" for an actual manager. Public entries,
    // so the hook runs for an anonymous visitor too and the email is passed
    // as null rather than the block being skipped. Same rebuild-and-
    // re-derive dance as the registration block above; see
    // Core\View\DynamicMenuRegistrar for why it cannot happen earlier.
    $rentalMenuHookService = new \Modules\Rental\Service\RentalMenuHookService(
        $rentalAssetRepository,
        $rentalAuthorizationService,
        $rentalCurrentYearId
    );
    $rentalMenuEntries = $dynamicMenuRegistrar->register(
        $menuBuilder,
        [$rentalMenuHookService],
        AuthSession::isAuthenticated() ? AuthSession::getEmail() : null
    );
    if ($rentalMenuEntries !== []) {
        $menus = $menuBuilder->build();
        $twig->addGlobal('menus', $menus);

        $rentalMenuActive = $dynamicMenuRegistrar->resolveActive(
            $rentalMenuEntries,
            $currentPath,
            $activeMenuId,
            $activePageUrl,
            $bestMatchLength
        );
        $activeMenuId = $rentalMenuActive['menuId'];
        $activePageUrl = $rentalMenuActive['pageUrl'];
        $bestMatchLength = $rentalMenuActive['matchLength'];
        $twig->addGlobal('active_menu_id', $activeMenuId);
        $twig->addGlobal('active_page_url', $activePageUrl);
    }
}

// Leadership ("Encadrement") — four read-only admin pages built entirely
// from core tables, plus the member page's own training-path card through
// Core\Module\FormationPathProvider (ARCHITECTURE.md §7.4/§8.65). The
// module stores nothing but its formation-level vocabulary mapping, so
// there is no cache to warm here and nothing to invalidate after an
// import.
if (in_array('leadership', $moduleManager->getEnabledModuleIds(), true)) {
    $leadershipRepository = new \Modules\Leadership\Repository\LeadershipRepository($connection, $encryptionService);
    $leadershipMappingRepository = new \Modules\Leadership\Repository\FormationLevelMappingRepository($connection);
    $leadershipResolver = new \Modules\Leadership\Service\FormationLevelResolver();
    $leadershipObligationsService = new \Modules\Leadership\Service\ObligationsService(
        new \Modules\Leadership\Service\CandidateDetector()
    );

    $frontController->registerController(
        \Modules\Leadership\Controller\LeadershipController::class,
        new \Modules\Leadership\Controller\LeadershipController(
            $twig,
            $leadershipRepository,
            $leadershipMappingRepository,
            $leadershipResolver,
            new \Modules\Leadership\Service\TrainingService(
                $leadershipRepository,
                $sectionService,
                $memberYearService,
                new \Modules\Leadership\Service\SupervisionCalculator()
            ),
            $leadershipObligationsService,
            new \Modules\Leadership\Service\StewardService($leadershipRepository, $leadershipObligationsService),
            $scoutYearResolver,
            $editableContentService
        )
    );

    $attentionProviders[] = new \Modules\Leadership\Service\LeadershipAttentionProvider(
        $leadershipRepository,
        new \Modules\Leadership\Service\TrainingService(
            $leadershipRepository,
            $sectionService,
            $memberYearService,
            new \Modules\Leadership\Service\SupervisionCalculator()
        ),
        $leadershipResolver,
        new \Modules\Leadership\Service\StewardService($leadershipRepository, $leadershipObligationsService)
    );

    $frontController->registerController(
        \Modules\Leadership\Controller\FormationMappingController::class,
        new \Modules\Leadership\Controller\FormationMappingController(
            $twig,
            $leadershipMappingRepository,
            $journalService
        )
    );

    $formationPathProvider = new \Modules\Leadership\Service\MemberFormationPathService(
        $leadershipRepository,
        $leadershipMappingRepository,
        $leadershipResolver
    );
}

if (in_array('fees', $moduleManager->getEnabledModuleIds(), true)) {
    $feesImportRepo = new \Modules\Fees\Repository\FeesImportRepository($pdo);
    $feesIgnoredHouseholdRepo = new \Modules\Fees\Repository\IgnoredHouseholdRepository($pdo, $encryptionService);
    $feesTariffService = new \Modules\Fees\Service\HouseholdTariffService(
        new \Modules\Fees\Repository\HouseholdTariffRepository($pdo),
        $feeCategoryRepo
    );

    $frontController->registerController(
        \Modules\Fees\Controller\FeesController::class,
        new \Modules\Fees\Controller\FeesController(
            $twig,
            $rosterSnapshotRepository,
            $feesImportRepo,
            $scoutYearResolver
        )
    );
    $frontController->registerController(
        \Modules\Fees\Controller\FeeAccuracyController::class,
        new \Modules\Fees\Controller\FeeAccuracyController(
            $twig,
            new \Modules\Fees\Service\FeeAccuracyService(
                $householdService,
                new \Modules\Fees\Repository\HouseholdDetailRepository($pdo, $encryptionService),
                $feesTariffService,
                $feesIgnoredHouseholdRepo,
                $feeCategoryRepo
            ),
            $feesTariffService,
            $feesIgnoredHouseholdRepo,
            $householdService,
            $feesImportRepo,
            $feeCategoryRepo,
            $scoutYearResolver,
            $journalService
        )
    );

    $attentionProviders[] = new \Modules\Fees\Service\FeesAttentionProvider(
        new \Modules\Fees\Service\FeeAccuracyService(
            $householdService,
            new \Modules\Fees\Repository\HouseholdDetailRepository($pdo, $encryptionService),
            $feesTariffService,
            $feesIgnoredHouseholdRepo,
            $feeCategoryRepo
        )
    );

    $feesInvoiceRepo = new \Modules\Fees\Repository\InvoiceRepository($pdo);
    $feesSnapshotRepo = $rosterSnapshotRepository;
    $feesVerification = new \Modules\Fees\Service\InvoiceVerificationService(
        $feesInvoiceRepo,
        $feesSnapshotRepo,
        $feesTariffService,
        $sectionService,
        new \Modules\Fees\Repository\HouseholdDetailRepository($pdo, $encryptionService)
    );
    $frontController->registerController(
        \Modules\Fees\Controller\InvoiceController::class,
        new \Modules\Fees\Controller\InvoiceController(
            $twig,
            new \Modules\Fees\Service\InvoiceImportService(
                new \Modules\Fees\Invoice\InvoiceReader(
                    new \Core\File\PdfTextExtractor(),
                    new \Modules\Fees\Invoice\InvoiceParser()
                ),
                $feesInvoiceRepo,
                new \Modules\Fees\Repository\InvoiceMemberMatchRepository($pdo, $encryptionService),
                $feesSnapshotRepo,
                $sectionService,
                $journalService
            ),
            new \Modules\Fees\Service\InvoiceSeasonService($feesInvoiceRepo),
            $feesVerification,
            $feesInvoiceRepo,
            $feesSnapshotRepo,
            $feesImportRepo,
            $scoutYearResolver,
            $linkedMemberIds,
            $journalService,
            // Optional (ARCHITECTURE.md §7.5): null whenever finance is off,
            // and the "conserver le PDF" control simply is not rendered.
            $expenseReceiptProvider
        )
    );
    $frontController->registerController(
        \Modules\Fees\Controller\InvoiceReportController::class,
        new \Modules\Fees\Controller\InvoiceReportController(
            $twig, $feesInvoiceRepo, $feesVerification, $scoutYearResolver, $journalService
        )
    );
}

// Re-registers MemberController (and its MemberPageService) with
// whichever optional providers are available — mass_mail's "Communications
// récentes", gallery's "Galeries photos", trombinoscope's section-
// responsable lookup (via $sectionResponsableProvider, ARCHITECTURE.md
// §7.4), calendar's next-upcoming-event lookup (via $calendarEventLookup),
// leadership's own training path (via $formationPathProvider), and
// finance's "ce qu'il reste à payer" block (via $memberPaymentProvider);
// each
// stays null when its module is disabled and the corresponding page block
// just doesn't render. Placed after every one of those modules' blocks
// above so their repositories/services are in scope.
if (
    in_array('mass_mail', $moduleManager->getEnabledModuleIds(), true)
    || in_array('gallery', $moduleManager->getEnabledModuleIds(), true)
    || in_array('calendar', $moduleManager->getEnabledModuleIds(), true)
    || in_array('trombinoscope', $moduleManager->getEnabledModuleIds(), true)
    || in_array('leadership', $moduleManager->getEnabledModuleIds(), true)
    || in_array('finance', $moduleManager->getEnabledModuleIds(), true)
) {
    $massMailQueryForMember = in_array('mass_mail', $moduleManager->getEnabledModuleIds(), true)
        ? new \Modules\MassMail\Service\MassMailQueryService($massMailRecipientRepo)
        : null;
    $galleryAlbumProviderForMember = in_array('gallery', $moduleManager->getEnabledModuleIds(), true)
        ? new \Modules\Gallery\Service\GalleryMemberQueryService(
            $galleryAlbumRepo, $galleryMediaRepo, $galleryMediaService, $sectionService, $scoutYearService
        )
        : null;

    $memberPageService = new \Core\Member\MemberPageService(
        $sectionService, $memberService, $badgeRepository, $memberBadgeRepository, $ageBranchRepo, $memberDocumentService, $memberEmailService,
        $sectionDocumentService, $sectionResponsableProvider, $massMailQueryForMember, $galleryAlbumProviderForMember, $calendarEventLookup,
        $formationPathProvider,
        $memberPaymentProvider
    );

    $frontController->registerController(
        MemberController::class,
        new MemberController($twig, $memberService, $memberYearService, $journalService, $memberPageService, $departureService)
    );
}

// The admin member page, registered here rather than with the other core
// controllers above for the same reason MemberController is: two of its
// blocks come from optional modules — finance's open and closed payments,
// registration's origin link, and the three « parcours » hooks
// (leadership's training path, camps' stays, groups' memberships) — and
// none of them is in scope until those modules' blocks have run. Each
// stays null when its module is disabled, and the corresponding block is
// then not built at all.
$frontController->registerController(MemberSearchController::class, new MemberSearchController(
    $twig, $memberSearchService, $memberService, $scoutYearResolver, $memberYearService, $departureService,
    $memberExportRowBuilder, $memberExportService, $journalService,
    new \Core\Member\AdminMemberPageService(
        $memberBadgeRepository, $memberPhotoService, $sectionMembershipRepository,
        $sectionService, $scoutYearService, $memberEmailRepository,
        $memberPaymentProvider, $memberRegistrationOriginProvider,
        $formationPathProvider, $memberCampStayProvider, $memberDiscussionGroupProvider
    ),
    $memberYearRepo,
    new \Core\Member\MemberNoteService(
        new \Core\Member\MemberNoteRepository($pdo, $encryptionService, $userAccountRepo),
        $journalService
    )
));

// File access (/files/{id}) — built here, deliberately last, because
// FileAccessGuard's ownership-checker registry must be complete before it
// is constructed: it is immutable (no setter, no addOwnershipChecker())
// and a module contributes its own checker by appending to
// $fileOwnershipCheckers from inside its own getEnabledModuleIds() block,
// every one of which runs above this point. FileController is the only
// consumer of the guard, so nothing earlier needs it. $linkedMemberIds was
// snapshotted where $linkedMembers is first resolved, well before the menu
// re-resolves it.
// Built here, after every module block, for the same reason the file
// access guard below is: the registry has to be complete first.
$frontController->registerController(
    \Core\Http\Controller\AttentionController::class,
    new \Core\Http\Controller\AttentionController(
        $twig,
        new \Core\Attention\AttentionService($attentionProviders),
        $scoutYearResolver
    )
);

$frontController->registerController(
    \Core\Http\Controller\DuplicateMemberController::class,
    new \Core\Http\Controller\DuplicateMemberController(
        $twig,
        $duplicateMemberRepository,
        $memberMergeService,
        new \Core\Import\ImportReportRepository($pdo, $encryptionService),
        $scoutYearResolver
    )
);

$fileAccessGuard = new FileAccessGuard(
    $fileRepository,
    Role::fromString($currentRole),
    $linkedMemberIds,
    $fileOwnershipCheckers
);
$fileController = new FileController($twig, $fileAccessGuard, $storagePath, $encryptedFileStorageService, $imageVariantService);
$fileController->setJournalService($journalService);
$frontController->registerController(FileController::class, $fileController);

// Gallery media serving (/gallery/media/{id}/{size}) — GalleryController
// built here, deliberately last, for the same reason as FileController
// just above: Service\DelegatedAlbumAccessRegistry must be complete
// before serveMedia() can safely consult it, and every module block that
// might append to $galleryDelegatedAlbumAccessCheckers runs above this
// point. Guarded by isset(): gallery might be disabled, in which case none
// of its variables exist and there is nothing to register.
if (isset(
    $galleryAlbumService,
    $galleryMediaService,
    $galleryMediaRepo,
    $galleryStorageBackendFactory,
    $galleryStorageLocationService,
    $galleryStorageLocationRepo,
    $galleryFfmpegAvailability,
    $galleryS3ErrorExplainerService
)) {
    $galleryDelegatedAlbumAccessRegistry = new \Modules\Gallery\Service\DelegatedAlbumAccessRegistry(
        $galleryDelegatedAlbumAccessCheckers
    );
    $galleryDelegatedAlbumDescriberRegistry = new \Modules\Gallery\Service\DelegatedAlbumDescriberRegistry(
        $galleryDelegatedAlbumDescribers
    );
    // Registered here rather than with gallery's other controllers, for the
    // same reason: its storage page lists delegated albums, and the
    // describers that name them are contributed by module blocks above.
    $frontController->registerController(
        \Modules\Gallery\Controller\GalleryConfigController::class,
        new \Modules\Gallery\Controller\GalleryConfigController(
            $twig, $settingService, $galleryFfmpegAvailability, $journalService,
            $galleryS3ErrorExplainerService, $galleryStorageLocationService, $galleryStorageLocationRepo,
            $galleryAlbumService, $galleryDelegatedAlbumDescriberRegistry
        )
    );
    $frontController->registerController(
        \Modules\Gallery\Controller\GalleryController::class,
        new \Modules\Gallery\Controller\GalleryController(
            $twig, $galleryAlbumService, $galleryMediaService, $galleryMediaRepo, $memberService,
            $sectionService, $scoutYearService, $galleryStorageBackendFactory, $galleryStorageLocationService,
            $galleryDelegatedAlbumAccessRegistry, $linkedMemberIds
        )
    );
}

// Scout year transition workflow — registered here, after every module
// block, rather than next to the other core controllers: the "Année
// scoute" page is fed by two optional modules at once (registration, for
// the veto and the Passage/Départs steps, and calendar, for the
// éphémérides step), and both are wired above. Registering it earlier and
// re-registering it inside each module block would have made the page's
// contents depend on which module happened to be enabled last.
//
// Every provider below is null when its module is off (ARCHITECTURE.md
// §7.5); Core\ScoutYear\ScoutYearTransitionService then simply drops the
// steps that module owns.
$scoutYearTransitionService = new \Core\ScoutYear\ScoutYearTransitionService(
    $scoutYearResolver,
    new \Core\ScoutYear\ScoutYearTransitionStepRepository($pdo),
    $sectionPhotoRepository,
    $userAccountRepo,
    $settingService,
    $moduleManager->getEnabledModuleIds(),
    $registrationScoutYearPreparation ?? null,
    $calendarScoutYearEventCount ?? null
);
$frontController->registerController(
    ScoutYearController::class,
    new ScoutYearController(
        $twig, $scoutYearResolver, $scoutYearAdminService, $scoutYearService,
        $scoutYearTransitionService, $journalService
    )
);

// RGPD configuration controller
$frontController->registerController(RgpdConfigController::class, new RgpdConfigController($twig, $editableContentService, $rgpdContentService, $settingService, $moduleManager, $journalService));

// Bypass RBAC for /setup routes ONLY while the site has no secrets yet —
// i.e. the first-run installer, where there is no database, no account and
// therefore nobody who could hold a role. Once initialized, every /setup
// route is reachable by its own role_min (superadmin) like any other, so a
// bypass here would not enable anything legitimate: it would only strip
// authentication off the installer, whose GET leaks db/smtp/admin settings
// and whose POST /setup/save rewrites database credentials and the admin
// email. The previous `allow_setup` config escape hatch did exactly that on
// a live site — an anonymous visitor could read /setup for a CSRF token and
// then re-point the install at their own database — so it is deliberately
// gone rather than merely defaulted to false.
if (!$secretManager->isInitialized()) {
    $frontController->setRbacBypassPrefix('/setup');
}

\Core\Debug\RequestTimeline::mark('services_ready');
/** @var \Core\Http\Response $response */
$response = \Core\Http\ErrorHandler::guard(static fn() => $frontController->handle($request));
\Core\Debug\RequestTimeline::mark('controller_dispatch_done');
$response->setCspNonce($cspNonce);

// Gallery photos served straight from S3-compatible storage need their
// origin explicitly allowed in img-src — computed for every configured S3
// location (there can be several at once now, module.json gallery multi-
// location support), never hardcoded to one (ARCHITECTURE.md §7.5).
if (isset($galleryStorageLocationRepo)) {
    foreach ($galleryStorageLocationRepo->findAll() as $galleryLocationForCsp) {
        if (!$galleryLocationForCsp->isS3()) {
            continue;
        }
        $s3OriginForCsp = \Modules\Gallery\Service\Storage\S3StorageBackend::servingOrigin(
            (string) ($galleryLocationForCsp->s3Endpoint ?? ''),
            (string) ($galleryLocationForCsp->s3Bucket ?? ''),
            $galleryLocationForCsp->s3PublicUrl
        );
        if ($s3OriginForCsp !== null) {
            $response->addImgSrcOrigin($s3OriginForCsp);
        }
    }
}

// The camps map draws OpenStreetMap tiles, which are <img> from another
// origin — the CSP's img-src has to name it or every tile is blocked and
// the map is a grey box. Read from a variable the module's own wiring
// block set, exactly like the gallery's S3 origin just above, rather than
// re-testing getEnabledModuleIds() here: this is the response-building
// tail, and a module-enabled test at this point reads as a per-module
// wiring block that arrives long after FileAccessGuard was built
// (Tests\Core\File\FileOwnershipCheckerWiringTest).
if (isset($campsMapTileOrigin)) {
    $response->addImgSrcOrigin($campsMapTileOrigin);
}

\Core\Debug\RequestTimeline::mark('response_send_begin');
$response->send();
\Core\Debug\RequestTimeline::mark('response_send_done');

// session_start() holds an exclusive lock on the session file for the
// whole script lifetime unless released early — without this, any other
// request carrying the same session cookie (another tab, a background
// fetch, the next click) would queue up and block for as long as the
// scheduler/cleanup work below takes, making a single slow task look like
// every page load is stuck.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
\Core\Debug\RequestTimeline::mark('session_write_close_done');

// Poor man's cron — run scheduler max once per minute, after response is sent
$lastRun = (int) $settingService->get('scheduler_last_run');
$now = time();
if (($now - $lastRun) > 60) {
    \Core\Debug\RequestTimeline::mark('poor_man_cron_triggered', ['seconds_since_last_run' => $now - $lastRun]);
    try {
        $settingRepo->updateValue(null, 'scheduler_last_run', (string) $now);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        \Core\Debug\RequestTimeline::mark('scheduler_process_overdue_begin');
        $schedulerRunner->processOverdue();
        \Core\Debug\RequestTimeline::mark('scheduler_process_overdue_done');
        $retentionDays = (int) ($settingService->get('journal_retention_days') ?: '730');
        \Core\Debug\RequestTimeline::mark('journal_cleanup_begin');
        $journalService->cleanup($retentionDays);
        \Core\Debug\RequestTimeline::mark('journal_cleanup_done');
        // Same rhythm as the journal cleanup, same reason: bots probing
        // /login and PDF previews both leave artifacts nothing else ever
        // deletes (see LoginThrottler::purgeStale() / PdfThumbnailCache).
        $loginThrottler->purgeStale();
        \Core\File\PdfThumbnailCache::purgeStale($storagePath);
        \Core\Debug\RequestTimeline::mark('stale_artifact_purge_done');
    } catch (\Throwable $e) {
        // Silently ignore scheduler errors in poor man's cron
    }
}

// Debug timeline flush (?debug=1) — gated on an already-authenticated
// admin session rather than a shared secret: the operator triggering this
// is browsing the live site as themselves, and the alternative (a secret
// URL param anyone could replay) would make this a standing, unauthenticated
// way to force extra journal writes on every request. Written last, after
// the poor-man's-cron tail above, so a slow scheduled task shows up in the
// same timeline as the request that happened to trigger it.
if (\Core\Debug\RequestTimeline::isActive() && \Core\Security\AuthSession::isAuthenticated()
    && in_array(\Core\Security\AuthSession::getRole(), ['admin', 'superadmin'], true)
) {
    $journalService->log(
        'core',
        'debug_request_timeline',
        'info',
        'Chronologie détaillée de requête (?debug=1) : ' . $request->getMethod() . ' ' . $request->getPath(),
        [
            'method' => $request->getMethod(),
            'path' => $request->getPath(),
            'timeline' => \Core\Debug\RequestTimeline::getEntries(),
        ],
        \Core\Security\AuthSession::getUserAccountId()
    );
}
