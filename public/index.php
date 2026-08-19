<?php

declare(strict_types=1);

$composerAutoloader = require_once __DIR__ . '/../vendor/autoload.php';

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
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Core\View\RgpdContentService;
use Core\View\MenuBuilder;
use Core\View\SectionRepository;
use Core\View\TwigFactory;

// Load configuration
$config = new AppConfig(__DIR__ . '/../config/app.php');

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

$encryptionService = new EncryptionService(
    $secrets['encryption_key'] ?? '',
    $secrets['blind_index_key'] ?? ''
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
    header('Content-Type: text/html; charset=utf-8');
    echo <<<'HTML'
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mise à jour en cours…</title>
<style>
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
<script>
(function () {
  var bar = document.getElementById('bar');
  var hint = document.getElementById('hint');

  function step() {
    fetch('/api/system/migration-step', { method: 'POST' })
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
HTML;
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
// generic Configuration > Paramètres page's grouped rendering
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
$schedulerService = new SchedulerService($schedulerRepo);
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
$mailService = MailServiceFactory::create($secrets, $dkimManager);

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
    $webPush = new WebPush(['VAPID' => [
        'subject' => $vapidSubject,
        'publicKey' => (string) ($secrets['vapid_public_key'] ?? ''),
        'privateKey' => (string) ($secrets['vapid_private_key'] ?? ''),
    ]]);
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
$shortUrlRepository = new ShortUrlRepository($pdo);
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
$importService = new DeskImportService(
    $pdo, $encryptionService, $csvParser, $mappingResolver,
    $memberRepo, $memberYearRepo, $importJournalRepo, $userAccountRepo, $unitStaffSectionService,
    $sectionMembershipService
);
// Multi-email support per member (Core\Member\MemberEmailService) — built
// before $roleResolver so login-by-email resolution can also match a
// currently-valid secondary address (see RoleResolver's own docblock).
$memberEmailRepository = new \Core\Member\MemberEmailRepository($pdo, $encryptionService);
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

$memberService = new MemberService($memberYearRepo, $encryptionService, $connection);
$memberYearService = new MemberYearService();
$memberSearchService = new MemberSearchService(new MemberSearchRepository($connection, $encryptionService));
// "Won't be back next scout year" marking (ARCHITECTURE.md §8) — a plain
// fact about a member_year, not inscriptions-specific, so it lives here at
// core level even though the registration module's own "Départs" page
// (below, once that module's block is reached) was its first consumer;
// Core\Http\Controller\MemberController's "/members/{id}/departure" AJAX
// endpoint (admin member-search page) is the other, always-available one.
$departureService = new \Core\Member\DepartureService(new \Core\Member\DepartureRepository($pdo, $encryptionService), $journalService);
// Badges — transversal roles assignable to chiefs (Core\Badge). Global
// concept configured once (Configuration générale), assignment scoped per
// member_year (Staffs page), displayed on the trombinoscope.
$badgeRepository = new BadgeRepository($pdo);
$memberBadgeRepository = new MemberBadgeRepository($pdo);

$sectionService = new SectionService($connection, $encryptionService, $memberBadgeRepository);
$badgeService = new BadgeService($badgeRepository, $memberBadgeRepository, $sectionService);

// Member page (Espace animés) "Documents privés" storage — see
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

// Create file services
$storagePath = dirname(__DIR__) . '/storage';
$fileRepository = new FileRepository($pdo);
$uploadHandler = new UploadHandler($fileRepository, $storagePath);
$encryptedFileStorageService = new \Core\File\EncryptedFileStorageService($fileRepository, $encryptionService, $storagePath);

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

// Same "one per year, fall back to the most recent earlier one" component
// as above, keyed by section instead of member — the Staffs page's group
// photo of a section's chiefs. See Core\Photo\SectionPhotoService.
$sectionPhotoService = new SectionPhotoService(new SectionPhotoRepository($pdo));
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
$fileOwnershipCheckers = [$sectionDocumentOwnershipChecker];

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

// Snapshot taken here rather than at the guard's construction site:
// $linkedMembers is re-resolved further down for the Espace animés menu,
// and the guard's owner-scoping must not depend on which of the two
// resolutions happens to be the current one by the time it is built.
$linkedMemberIds = array_map(fn($m) => $m->memberId, $linkedMembers);

$twig->addGlobal('current_user_display_name', $displayName);
$twig->addGlobal('current_user_member_count', $memberCount);
// Server-rendered on page load — public/assets/js/notification-badge.js
// refreshes it live afterward (60s poll + immediate on an incoming push).
$unreadNotificationsCount = AuthSession::isAuthenticated()
    ? $notificationRepo->countUnread((int) AuthSession::getUserAccountId())
    : 0;
$twig->addGlobal('unread_notifications_count', $unreadNotificationsCount);
// Feeds partials/notification_dropdown.html.twig's "last one" preview —
// only fetched when there's actually something pending, same "cheap
// enough, nothing to gain from unconditional" precedent as elsewhere in
// this bootstrap (see e.g. $linkedMembers above).
$twig->addGlobal(
    'latest_notification',
    $unreadNotificationsCount > 0 ? $notificationRepo->findLatestUnread((int) AuthSession::getUserAccountId()) : null
);
$twig->addGlobal('current_user_role_label', $roleLabelMap[$currentRole] ?? 'Public');
$twig->addGlobal('current_path', $request->getPath());
$twig->addGlobal('config_mode', ConfigurationMode::isActive());
$twig->addGlobal('effective_scout_year', $effectiveScoutYear->label);
$twig->addGlobal('effective_scout_year_id', $effectiveScoutYear->id);
$twig->addGlobal('is_year_overridden', $effectiveScoutYear->isOverridden());
$twig->addGlobal('year_override_type', $effectiveScoutYear->overrideType);
$twig->addGlobal('_editable_content_service', $editableContentService);
$twig->addGlobal('_member_photo_service', $memberPhotoService);
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

// Register core pages in menus
$menuBuilder->addPage(MenuBuilder::MENU_NOTRE_UNITE, 'Accueil', '/', 'public', 10);
$menuBuilder->addPage(MenuBuilder::MENU_NOTRE_UNITE, 'Contact', '/contact', 'public', 20);
$menuBuilder->addPage(MenuBuilder::MENU_NOTRE_UNITE, 'Sections', '/sections', 'public', 30);
$menuBuilder->addPage(MenuBuilder::MENU_NOTRE_UNITE, 'Protection des données', '/rgpd', 'public', 40);
$menuBuilder->addPage(MenuBuilder::MENU_ESPACE_CHEFS, 'Staffs', '/chefs/staffs', 'intendant', 10);
// Configuration générale — shrunk to just the configuration-mode toggle,
// moved here from the Configuration menu and widened from superadmin to
// admin (see /config-mode/activate|deactivate's own role_min and
// Core\View\ConfigurationMode, widened the same way) so every chief
// d'unité, not only a superadmin, can edit site content. First in this
// menu (order 10) — the most-used entry for a chief d'unité.
$menuBuilder->addPage(MenuBuilder::MENU_ESPACE_ADMIN, 'Configuration générale', '/config/general', 'admin', 10);
$menuBuilder->addPage(MenuBuilder::MENU_ESPACE_ADMIN, 'Import Desk', '/admin/import', 'admin', 20);
$menuBuilder->addPage(MenuBuilder::MENU_ESPACE_ADMIN, 'Membres', '/admin/members', 'admin', 30);
$menuBuilder->addPage(MenuBuilder::MENU_ESPACE_ADMIN, 'Année scoute', '/admin/scout-year', 'admin', 40);
$menuBuilder->addPage(MenuBuilder::MENU_ESPACE_ADMIN, 'Journal', '/admin/journal', 'admin', 50);
// Configuration avancée first (order 5, ahead of Modules/Badges below) —
// the most-used entry for a superadmin; the rest of this menu keeps its
// existing relative order.
$menuBuilder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Configuration avancée', '/setup', 'superadmin', 5);
$menuBuilder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Modules', '/config/modules', 'superadmin', 10);
$menuBuilder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Badges', '/config/badges', 'superadmin', 12);
$menuBuilder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Desk', '/config/functions', 'superadmin', 20);
$menuBuilder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Paramètres', '/config/settings', 'superadmin', 30);
$menuBuilder->addPage(MenuBuilder::MENU_CONFIGURATION, 'RGPD', '/config/rgpd', 'superadmin', 35);
$menuBuilder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Actions planifiées', '/config/scheduled', 'superadmin', 40);
$menuBuilder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Maintenance', '/config/maintenance', 'admin', 45);
$menuBuilder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Notifications', '/config/notifications', 'superadmin', 46);
// order 10, not a leftover "after the separator" number — GROUP_CORE
// (addPage()'s default) already sorts this after the dynamic member
// entries/empty-state placeholder above regardless of the numeric order,
// and it's currently the only core static page in this menu.
$menuBuilder->addPage(MenuBuilder::MENU_ESPACE_ANIMES, 'Notifications', '/notifications', 'identified', 10);

// Create router early so ModuleManager can register routes
$router = new Router();

// Offline whitelist (Core\Offline\OfflineWhitelist) — single shared
// instance so module-declared pages (registered by ModuleManager below,
// as modules load) are visible both to the Twig global built later and to
// FrontController's ETag logic (§8.25).
$offlineWhitelist = new OfflineWhitelist();

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
    $offlineWhitelist
);

// Set up SchedulerRunner with ModuleManager and the context task handlers run
// with — without this, processOverdue() throws the moment it reaches a real
// (module-registered) task, since TaskContext has no fallback construction.
$schedulerRunner->setModuleManager($moduleManager);
$schedulerRunner->setTaskContext(new TaskContext(
    $connection,
    $encryptionService,
    $mailService,
    $journalService,
    $settingService,
    $userAccountRepo,
    $storagePath,
    $notificationService
));

// Core (not module) scheduled task handlers — registered directly since
// module.json's scheduled_tasks mechanism only applies to module handlers.
$schedulerRunner->registerHandler('core', 'create_backup', new \Core\Maintenance\Task\CreateBackupHandler());
$schedulerRunner->registerHandler('core', 'install_update', new \Core\Maintenance\Task\InstallUpdateHandler());
$schedulerRunner->registerHandler('core', 'reset_settings', new \Core\Maintenance\Task\ResetSettingsHandler());
$schedulerRunner->registerHandler('core', 'full_reset', new \Core\Maintenance\Task\FullResetHandler());
$schedulerRunner->registerHandler('core', 'restore_backup', new \Core\Maintenance\Task\RestoreBackupHandler());
$schedulerRunner->registerHandler('core', 'auto_backup', new \Core\Maintenance\Task\AutoBackupHandler());
$schedulerRunner->registerHandler('core', 'check_stable_update', new \Core\Maintenance\Task\CheckStableUpdateHandler());
$schedulerRunner->registerHandler('core', 'compress_section_document', new \Core\Member\Task\CompressSectionDocumentHandler());
$schedulerRunner->registerHandler('core', 'send_notifications', new \Core\Notification\Task\SendNotificationsHandler());
$schedulerRunner->registerHandler('core', 'purge_notifications', new \Core\Notification\Task\PurgeNotificationsHandler());
$schedulerRunner->registerHandler('core', 'purge_human_check_rate_limits', new \Core\Security\HumanCheck\Task\PurgeHumanCheckRateLimitsHandler());

// Bootstrap the recurring automatic backup — Task\AutoBackupHandler
// re-schedules itself at the end of every run (same pattern as
// Modules\LlmConnector\Task\RefreshModelsHandler's weekly refresh, since
// Core\Scheduler has no first-class recurring-task concept), but the very
// first occurrence needs an initial nudge.
if ($schedulerService->find('core', 'auto_backup', 'auto') === null) {
    $schedulerService->schedule('core', 'auto_backup', new DateTimeImmutable(), [], 'auto');
}

// Same bootstrap for the notification retention purge (Core\Notification\
// Task\PurgeNotificationsHandler).
if ($schedulerService->find('core', 'purge_notifications', \Core\Notification\Task\PurgeNotificationsHandler::REFERENCE) === null) {
    $schedulerService->schedule('core', 'purge_notifications', new DateTimeImmutable(), [], \Core\Notification\Task\PurgeNotificationsHandler::REFERENCE);
}

// Same bootstrap for the daily stable-channel update check
// (Core\Maintenance\Task\CheckStableUpdateHandler) — the very first
// occurrence runs immediately, then it self-reschedules for 01:00 +
// jitter every day after that.
if ($schedulerService->find('core', 'check_stable_update', 'daily') === null) {
    $schedulerService->schedule('core', 'check_stable_update', new DateTimeImmutable(), [], 'daily');
}

// Same bootstrap for the human-check rate-limit purge (Core\Security\
// HumanCheck\Task\PurgeHumanCheckRateLimitsHandler).
if ($schedulerService->find('core', 'purge_human_check_rate_limits', \Core\Security\HumanCheck\Task\PurgeHumanCheckRateLimitsHandler::REFERENCE) === null) {
    $schedulerService->schedule('core', 'purge_human_check_rate_limits', new DateTimeImmutable(), [], \Core\Security\HumanCheck\Task\PurgeHumanCheckRateLimitsHandler::REFERENCE);
}

// Add dynamic member entries to Espace animés — group: GROUP_DYNAMIC keeps
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
            MenuBuilder::GROUP_DYNAMIC
        );
    }

    // Empty state message when no members are linked — conceptually the
    // same "dynamic member list" slot (hence GROUP_DYNAMIC), but isDynamic
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
            MenuBuilder::GROUP_DYNAMIC
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
$router->addRoute('GET', '/login', AuthController::class, 'login', 'public');
$router->addRoute('POST', '/login/magic-link', AuthController::class, 'requestMagicLink', 'public');
$router->addRoute('POST', '/login/password', AuthController::class, 'loginWithPassword', 'public');
$router->addRoute('GET', '/login/passkey/options', AuthController::class, 'passkeyOptions', 'public');
$router->addRoute('POST', '/login/passkey/verify', AuthController::class, 'passkeyVerify', 'public');
$router->addRoute('GET', '/auth/verify', AuthController::class, 'verifyMagicLink', 'public');
$router->addRoute('GET', '/auth/poll/{id}', AuthController::class, 'pollMagicLink', 'public');
$router->addRoute('POST', '/logout', AuthController::class, 'logout', 'identified');

// Password reset ("Mot de passe oublié")
$router->addRoute('POST', '/password-reset/request', PasswordResetController::class, 'request', 'public');
$router->addRoute('GET', '/password-reset/{id}', PasswordResetController::class, 'show', 'public');
$router->addRoute('POST', '/password-reset/{id}', PasswordResetController::class, 'submit', 'public');

// Account routes
$router->addRoute('GET', '/account', AccountController::class, 'index', 'identified', ['label' => 'Mon compte', 'parents' => []]);
$router->addRoute('POST', '/account/profile', AccountController::class, 'updateProfile', 'identified');
$router->addRoute('POST', '/account/password', AccountController::class, 'updatePassword', 'identified');
$router->addRoute('GET', '/account/passkey/register-options', AccountController::class, 'passkeyRegisterOptions', 'identified');
$router->addRoute('POST', '/account/passkey/register', AccountController::class, 'passkeyRegister', 'identified');
$router->addRoute('POST', '/account/passkey/delete', AccountController::class, 'passkeyDelete', 'identified');
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
// as /auth/verify and /password-reset/{id}.
$router->addRoute('GET', '/members/emails/confirm/{id}', \Core\Http\Controller\MemberEmailAddressController::class, 'confirm', 'public');
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

// Editable content API
$router->addRoute('POST', '/api/editable-content', EditableContentController::class, 'update', 'superadmin');
$router->addRoute('POST', '/api/rich-text-content', EditableContentController::class, 'updateField', 'superadmin');

// Cookie consent
$router->addRoute('GET', '/cookies', CookieController::class, 'preferences', 'public');
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
$router->addRoute('GET', '/upload', UploadController::class, 'index', 'identified');
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
$router->addRoute('GET', '/setup', SetupController::class, 'index', 'superadmin', ['label' => 'Configuration avancée', 'parents' => [MenuBuilder::labelFor(MenuBuilder::MENU_CONFIGURATION)]]);
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

// Journal
$router->addRoute('GET', '/admin/journal', JournalController::class, 'index', 'admin', ['label' => 'Journal', 'parents' => [MenuBuilder::labelFor(MenuBuilder::MENU_ESPACE_ADMIN)]]);

// Scout year navigation and transition
$router->addRoute('GET', '/admin/members', MemberSearchController::class, 'index', 'admin', ['label' => 'Membres', 'parents' => [MenuBuilder::labelFor(MenuBuilder::MENU_ESPACE_ADMIN)]]);
$router->addRoute('GET', '/admin/scout-year', ScoutYearController::class, 'index', 'admin', ['label' => 'Année scoute', 'parents' => [MenuBuilder::labelFor(MenuBuilder::MENU_ESPACE_ADMIN)]]);
$router->addRoute('POST', '/admin/scout-year/preview', ScoutYearController::class, 'preview', 'admin');
$router->addRoute('POST', '/admin/scout-year/clear-preview', ScoutYearController::class, 'clearPreview', 'admin');
$router->addRoute('POST', '/admin/scout-year/activate-staff', ScoutYearController::class, 'activateStaff', 'admin');
$router->addRoute('POST', '/admin/scout-year/deactivate-staff', ScoutYearController::class, 'deactivateStaff', 'admin');
$router->addRoute('POST', '/admin/scout-year/activate-public', ScoutYearController::class, 'activatePublic', 'admin');

// Settings
$router->addRoute('GET', '/config/settings', SettingsController::class, 'index', 'superadmin', ['label' => 'Paramètres', 'parents' => [MenuBuilder::labelFor(MenuBuilder::MENU_CONFIGURATION)]]);
$router->addRoute('POST', '/config/settings/update', SettingsController::class, 'update', 'superadmin');
$router->addRoute('POST', '/config/settings/logo-delete', SettingsController::class, 'deleteLogo', 'superadmin');
$router->addRoute('POST', '/config/settings/logo-notify-ios', SettingsController::class, 'notifyIosLogoUpdate', 'superadmin');

// Scheduled actions
$router->addRoute('GET', '/config/scheduled', ScheduledActionsController::class, 'index', 'superadmin', ['label' => 'Actions planifiées', 'parents' => [MenuBuilder::labelFor(MenuBuilder::MENU_CONFIGURATION)]]);
$router->addRoute('GET', '/config/maintenance', MaintenanceController::class, 'index', 'admin', ['label' => 'Maintenance', 'parents' => [MenuBuilder::labelFor(MenuBuilder::MENU_CONFIGURATION)]]);
$router->addRoute('POST', '/config/maintenance/backup/database', MaintenanceController::class, 'createDatabaseBackup', 'admin');
$router->addRoute('POST', '/config/maintenance/backup/full', MaintenanceController::class, 'createFullBackup', 'admin');
$router->addRoute('POST', '/config/maintenance/backup/auto-frequency', MaintenanceController::class, 'updateAutoBackupFrequency', 'admin');
$router->addRoute('GET', '/api/maintenance/backup-status/{id}', MaintenanceController::class, 'backupStatus', 'admin');
$router->addRoute('POST', '/config/maintenance/update/install', MaintenanceController::class, 'installUpdate', 'admin');
$router->addRoute('POST', '/config/maintenance/update/check-now', MaintenanceController::class, 'checkForUpdatesNow', 'admin');
$router->addRoute('GET', '/api/maintenance/update-status/{id}', MaintenanceController::class, 'updateStatus', 'admin');
$router->addRoute('POST', '/config/maintenance/reset/settings', MaintenanceController::class, 'resetSettings', 'admin');
$router->addRoute('POST', '/config/maintenance/reset/full', MaintenanceController::class, 'fullReset', 'admin');
$router->addRoute('POST', '/config/maintenance/reset/restore', MaintenanceController::class, 'restoreBackup', 'admin');
$router->addRoute('GET', '/api/maintenance/reset-status/{id}', MaintenanceController::class, 'resetStatus', 'admin');
$router->addRoute('POST', '/config/maintenance/auto-update/save', MaintenanceController::class, 'saveAutoUpdatePreferences', 'admin');
$router->addRoute('POST', '/api/maintenance/webhook-secret', MaintenanceController::class, 'generateWebhookSecret', 'admin');
// The only public, CSRF-free route in the codebase — GitHub is a machine
// caller with no session; the HMAC-SHA256 signature (Core\Maintenance\
// GitHubWebhookService::verifySignature()) is what authenticates it
// instead. See Core\Http\Controller\WebhookController's own docblock.
$router->addRoute('POST', '/api/webhook/github', \Core\Http\Controller\WebhookController::class, 'github', 'public');

// Configuration générale — shrunk to just the configuration-mode toggle
// (module registry and badges split out below); moved to "Espace chefs d'U"
// in the menu (see addPage() above) and widened to admin, same as the
// /config-mode/* routes it links to — URL kept unchanged, nothing forces it
// to change.
$router->addRoute('GET', '/config/general', ConfigGeneralController::class, 'index', 'admin', ['label' => 'Configuration générale', 'parents' => [MenuBuilder::labelFor(MenuBuilder::MENU_ESPACE_ADMIN)]]);

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
        $pageUrl = $page['url'] ?? '';
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

// RGPD content service (may use LLM if module is active)
$llmConnectorForRgpd = null;
$llmProviderRepoForRgpd = null;
$llmModelRepoForRgpd = null;
if (in_array('llm_connector', $moduleManager->getEnabledModuleIds(), true)) {
    $llmProviderRepoForRgpd = new \Modules\LlmConnector\Repository\ProviderRepository($pdo, $encryptionService);
    $llmModelRepoForRgpd = new \Modules\LlmConnector\Repository\ProviderModelRepository($pdo);
    $llmConnectorForRgpd = new \Modules\LlmConnector\Service\LlmConnectorService($llmProviderRepoForRgpd, $llmModelRepoForRgpd, $journalService);
}
$rgpdContentService = new RgpdContentService($moduleManager, $settingService, $llmConnectorForRgpd, $llmProviderRepoForRgpd, $llmModelRepoForRgpd);

// Handle the request
$maintenanceGate = new \Core\Maintenance\MaintenanceGate($updateHistoryRepository);
$frontController = new FrontController($router, $twig, $config, $offlineWhitelist, $maintenanceGate);

// Optional dependency on the trombinoscope module (ARCHITECTURE.md §7.4)
// for the Sections page's "responsable" name — set below only when
// 'trombinoscope' is enabled; every PageController re-registration after
// that block reuses this variable, exactly like $bannerService/
// $newsArticleService further down.
$sectionResponsableProvider = null;

// Optional dependency on the calendar module (ARCHITECTURE.md §7.5) for
// the member page's "next upcoming event" (§3) — set below only when
// 'calendar' is enabled, same pattern as $sectionResponsableProvider
// above.
$calendarEventLookup = null;

// Baseline MemberPageService (core deps only) — re-registered further
// down, once mass_mail/gallery/trombinoscope/calendar availability is
// known, exactly like MemberController itself.
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
$loginThrottler = new LoginThrottler($connection);
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
$frontController->registerController(AccountController::class, new AccountController($twig, $userAccountRepo, $webAuthnCredentialRepo, $webAuthnService));
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
$frontController->registerController(PasswordResetController::class, new PasswordResetController($twig, $passwordResetService));
$frontController->registerController(ShortUrlController::class, new ShortUrlController($twig, $shortUrlService));
$frontController->registerController(ImportController::class, new ImportController($twig, $importService, $scoutYearResolver, $importJournalRepo, $functionRepo, $storagePath, $registrationReconciliation ?? null));
$frontController->registerController(MemberController::class, new MemberController($twig, $memberService, $memberYearService, $journalService, $memberPageService, $departureService));
$frontController->registerController(
    \Core\Http\Controller\MemberEmailAddressController::class,
    new \Core\Http\Controller\MemberEmailAddressController($twig, $memberEmailService, $memberService)
);
$frontController->registerController(StaffsController::class, new StaffsController($twig, $sectionService, $memberService, $scoutYearResolver, $journalService, $badgeService, $unitStaffSectionService, $sectionDocumentService, $settingService));
$frontController->registerController(\Core\Http\Controller\SectionDocumentController::class, new \Core\Http\Controller\SectionDocumentController($twig, $sectionDocumentService));
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
    $unitStaffSectionService, $scoutYearService, $editableContentService, $ageBranchRepo, null
);
$offlineController = new OfflineController($twig, $offlineManifestService);
$frontController->registerController(OfflineController::class, $offlineController);
$uploadController = new UploadController($twig, $uploadHandler, $editableContentService, $memberPhotoService, $sectionPhotoService, $sectionPhotoProcessor, $landscapeImageProcessor, $memberService, $ageBranchRepo, $unitLogoService, $imageVariantService);
$uploadController->setJournalService($journalService);
$frontController->registerController(UploadController::class, $uploadController);
$frontController->registerController(\Core\Http\Controller\PwaController::class, new \Core\Http\Controller\PwaController($twig, $settingService, $unitLogoService));
$frontController->registerController(JournalController::class, new JournalController($twig, $journalRepo, $userAccountRepo));
$frontController->registerController(ScoutYearController::class, new ScoutYearController($twig, $scoutYearResolver, $scoutYearAdminService, $scoutYearService, $journalService));
$frontController->registerController(MemberSearchController::class, new MemberSearchController($twig, $memberSearchService, $memberService, $scoutYearResolver, $memberYearService, $departureService));
$frontController->registerController(SettingsController::class, new SettingsController($twig, $settingService, $journalService, $unitLogoService, $notificationService, $userAccountRepo));
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
            $unitStaffSectionService, $scoutYearService, $editableContentService, $ageBranchRepo, $trombinoscopeService
        ))
    );
}

if (in_array('calendar', $moduleManager->getEnabledModuleIds(), true)) {
    $calendarRepo = new \Modules\Calendar\Repository\CalendarRepository($pdo);
    $calendarEventRepo = new \Modules\Calendar\Repository\CalendarEventRepository($pdo);
    $calendarPersonalTokenRepo = new \Modules\Calendar\Repository\CalendarPersonalTokenRepository($pdo);
    $calendarUnitFeedTokenRepo = new \Modules\Calendar\Repository\CalendarUnitFeedTokenRepository($pdo);

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
            $sectionService, $memberService, $scoutYearResolver, $journalService, $settingService, $moduleManager
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
    $financeService = new \Modules\Finance\Service\FinanceService(
        $financeAccountRepo, $financeCategoryRepo, $financeFiscalYearRepo, $sectionService, $financeTransactionRepo, $financeBalanceService,
        $settingService, $financeCategoryRuleRepo, $financeAccountTransferCategoryService
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
    $financeCalendarRepoForAi = in_array('calendar', $moduleManager->getEnabledModuleIds(), true) ? $calendarRepo : null;
    $financeCalendarEventRepoForAi = in_array('calendar', $moduleManager->getEnabledModuleIds(), true) ? $calendarEventRepo : null;
    $financeAiCategorizationService = new \Modules\Finance\Service\AiCategorizationService(
        $llmConnectorForRgpd, $financeCategoryRepo, $financeAiSuggestionRepo, $journalService,
        $financeAccountRepo, $financeTransactionAttachmentRepo, $financeAttachmentRepo,
        $financeCalendarRepoForAi, $financeCalendarEventRepoForAi
    );
    $financeBulkCategorizationService = new \Modules\Finance\Service\BulkCategorizationService(
        $financeTransactionRepo, $financeRuleEngine, $financeAiCategorizationService, $settingService, $schedulerService
    );

    $financeImportService = new \Modules\Finance\Service\ImportService(
        $pdo, $encryptionService, $financeParserFactory, $financeTransactionRepo, $financeCheckpointRepo,
        $financeStatementImportRepo, $financeFiscalYearRepo, $financeRuleEngine, $financeBalanceService, $financeReceiptMatchingService,
        $financeBulkCategorizationService
    );
    $financeEncryptedFileStorage = new \Core\File\EncryptedFileStorageService($fileRepository, $encryptionService, $storagePath);
    $financeReceiptService = new \Modules\Finance\Service\ReceiptService(
        $financeAttachmentRepo, $financeAccountRepo, $financeTransactionAttachmentRepo, $financeEncryptedFileStorage,
        $financeTransactionRepo
    );
    // Optional dependency on the llm_connector module (ARCHITECTURE.md
    // §7.5) — reuses the same LlmConnectorInterface instance already
    // built for RGPD content generation above; extraction is skipped
    // gracefully whenever it's null/unavailable.
    $financeReceiptExtractionService = new \Modules\Finance\Service\ReceiptExtractionService($schedulerService, $llmConnectorForRgpd);
    $financeFirstReceiptResolver = new \Modules\Finance\Service\FirstReceiptResolver($financeTransactionAttachmentRepo, $financeAttachmentRepo);

    $frontController->registerController(
        \Modules\Finance\Controller\DashboardController::class,
        new \Modules\Finance\Controller\DashboardController(
            $twig, $financeService, $financeBalanceService, $financeTransactionRepo, $financeReceiptService,
            $financeCategoryRepo, $financeAttachmentRepo, $financeTransactionAttachmentRepo, $financeStatementImportRepo,
            $financeFirstReceiptResolver
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
    $financeExpectedReceivableRepo = new \Modules\Finance\Repository\ExpectedReceivableRepository($pdo, $encryptionService);
    $financeStructuredCommunicationForOthers = new \Modules\Finance\Service\StructuredCommunicationService($financeExpectedReceivableRepo);
    $financeExpectedReceivableForOthers = new \Modules\Finance\Service\ExpectedReceivableService($financeExpectedReceivableRepo, $financeTransactionRepo);
    $financeSepaQrCodeForOthers = new \Modules\Finance\Service\SepaQrCodeService();
    $financeAccountForOthers = new \Modules\Finance\Service\FinanceAccountService($financeAccountRepo);

    $financeReceivablesOverviewService = new \Modules\Finance\Service\ReceivablesOverviewService($financeExpectedReceivableRepo, $financeExpectedReceivableForOthers);
    $frontController->registerController(
        \Modules\Finance\Controller\ReceivablesController::class,
        new \Modules\Finance\Controller\ReceivablesController($twig, $financeReceivablesOverviewService)
    );
}

if (in_array('mass_mail', $moduleManager->getEnabledModuleIds(), true)) {
    $massMailListRepo = new \Modules\MassMail\Repository\MailingListRepository($pdo);
    $massMailResolutionRepo = new \Modules\MassMail\Repository\MemberResolutionRepository($pdo, $encryptionService);
    $massMailEmailRepo = new \Modules\MassMail\Repository\EmailRepository($pdo);
    $massMailRecipientRepo = new \Modules\MassMail\Repository\RecipientRepository($pdo, $encryptionService);
    $massMailAttachmentRepo = new \Modules\MassMail\Repository\EmailAttachmentRepository($pdo);
    $massMailFunctionRepo = new \Core\Import\FunctionRepository($pdo);

    $massMailListService = new \Modules\MassMail\Service\MailingListService(
        $massMailListRepo, $massMailResolutionRepo, $sectionService, $massMailFunctionRepo
    );
    $massMailAccessService = new \Modules\MassMail\Service\MassMailAccessService($memberService, $sectionService);
    $massMailService = new \Modules\MassMail\Service\MassMailService(
        $massMailEmailRepo, $massMailRecipientRepo, $massMailAttachmentRepo, $fileRepository,
        $massMailListService, $memberService, $memberEmailService, $sectionService, $mailService, $schedulerService, $journalService,
        new \Core\Security\HtmlSanitizer(), $scoutYearService, $importJournalRepo, $storagePath
    );

    $frontController->registerController(
        \Modules\MassMail\Controller\MassMailController::class,
        new \Modules\MassMail\Controller\MassMailController(
            $twig, $massMailService, $massMailListService, $massMailAccessService, $memberService, $sectionService,
            $scoutYearService, $importJournalRepo, $settingService, $uploadHandler, $fileRepository
        )
    );
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
        new \Modules\MassMail\Controller\UnsubscribeController($twig, $massMailRecipientRepo, $memberEmailService)
    );

    // MemberController is re-registered once, with every optional
    // provider (mass_mail included), in the combined block further down
    // — see the comment there for why.
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
            $financeAccountForOthers, $humanCheckService
        )
    );
    $frontController->registerController(
        \Modules\News\Controller\FormController::class,
        new \Modules\News\Controller\FormController(
            $twig, $newsArticleService, $newsFormService, $newsResponseService, $scoutYearService, $journalService,
            $financeExpectedReceivableForOthers, $humanCheckService
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
            $sectionResponsableProvider
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
    $rgpdContentService->setGalleryStorageLocationRepository($galleryStorageLocationRepo);

    $galleryAccessService = new \Modules\Gallery\Service\GalleryAccessService($memberService, $sectionService, $scoutYearService);
    $galleryOgScraperService = new \Modules\Gallery\Service\OgScraperService();
    $galleryStorageBackendFactory = new \Modules\Gallery\Service\Storage\StorageBackendFactory($galleryStorageLocationRepo, $storagePath);
    $galleryFfmpegAvailability = new \Modules\Gallery\Service\FfmpegAvailability();
    // Optional dependency on the llm_connector module (ARCHITECTURE.md
    // §7.5), same reused instance as RGPD content generation above — the
    // "Expliquer avec l'IA" button is simply hidden when it's unavailable.
    $galleryS3ErrorExplainerService = new \Modules\Gallery\Service\S3ErrorExplainerService($llmConnectorForRgpd);
    $galleryStorageLocationService = new \Modules\Gallery\Service\StorageLocationService(
        $galleryStorageLocationRepo, $galleryAlbumRepo, $galleryStorageBackendFactory, $settingService,
        $galleryS3SecretRepo, $storagePath
    );

    $galleryAlbumService = new \Modules\Gallery\Service\AlbumService(
        $galleryAlbumRepo, $galleryMediaRepo, $galleryAccessService, $galleryOgScraperService,
        $galleryStorageBackendFactory, $galleryStorageLocationRepo, $galleryStorageLocationService,
        $scoutYearService, $settingService, $schedulerService, $uploadHandler,
        $notificationService, $userAccountRepo
    );
    $galleryMediaService = new \Modules\Gallery\Service\MediaService(
        $galleryMediaRepo, $galleryAlbumRepo, $uploadHandler, $schedulerService, $settingService,
        $galleryAccessService, $galleryStorageBackendFactory, $galleryStorageLocationService, $galleryFfmpegAvailability
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
            $sectionService, $settingService, $galleryStorageLocationRepo, $galleryStorageLocationService
        )
    );
    $frontController->registerController(
        \Modules\Gallery\Controller\GalleryConfigController::class,
        new \Modules\Gallery\Controller\GalleryConfigController(
            $twig, $settingService, $galleryFfmpegAvailability, $journalService,
            $galleryS3ErrorExplainerService, $galleryStorageLocationService, $galleryStorageLocationRepo,
            $galleryAlbumService
        )
    );
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
    $groupsListService = new \Modules\Groups\Service\GroupListService(
        $groupsGroupRepo, $groupsSectionRepo, $groupsMemberRepo, $sectionMembershipRepository
    );
    $groupsContextFactory = new \Modules\Groups\Service\GroupSessionContextFactory(
        $memberService, $userAccountRepo, $scoutYearResolver
    );

    $groupsPostRepo = new \Modules\Groups\Repository\PostRepository($pdo);
    $groupsActivityService = new \Modules\Groups\Service\GroupActivityService($groupsGroupRepo, $groupsPostRepo);
    $groupsPostService = new \Modules\Groups\Service\PostService($groupsPostRepo, $groupsActivityService);

    // gallery is a hard dependency (module.json's requires) — this block
    // only ever runs when it is enabled, so $galleryDelegatedAlbumManager
    // (built unconditionally inside gallery's own block above) is always
    // available here; no nullable optional-dependency handling. The
    // assert() below is a static-analysis narrowing hint only (PHPStan
    // cannot see across the two independent `if` blocks) — same
    // precedent as the \assert() calls already in DelegatedAlbumService
    // and GalleryController.
    \assert(isset($galleryDelegatedAlbumManager));
    $groupsPostMediaRepo = new \Modules\Groups\Repository\PostMediaRepository($pdo);
    $groupsPostMediaService = new \Modules\Groups\Service\PostMediaService(
        $galleryDelegatedAlbumManager, $groupsPostMediaRepo, $groupsGroupRepo
    );

    $groupsFeedService = new \Modules\Groups\Service\GroupFeedService(
        $groupsPostRepo,
        new \Modules\Groups\Service\PostAuthorResolver($memberService, $userAccountRepo),
        $groupsPostService,
        $groupsPostMediaService
    );

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

    $frontController->registerController(
        \Modules\Groups\Controller\GroupController::class,
        new \Modules\Groups\Controller\GroupController(
            $twig, $groupsGroupRepo, $groupsListService, $groupsAccessService, $groupsService,
            $groupsContextFactory, $sectionService, $groupsFeedService, $memberService, $groupsPostMediaService
        )
    );
    $frontController->registerController(
        \Modules\Groups\Controller\PostController::class,
        new \Modules\Groups\Controller\PostController(
            $twig, $groupsGroupRepo, $groupsPostRepo, $groupsAccessService, $groupsFeedService,
            $groupsPostService, $groupsContextFactory, $groupsPostMediaService
        )
    );
    $frontController->registerController(
        \Modules\Groups\Controller\GroupMemberController::class,
        new \Modules\Groups\Controller\GroupMemberController(
            $twig, $groupsGroupRepo, $groupsMemberRepo, $groupsSectionRepo, $groupsAccessService,
            $groupsService, $groupsContextFactory, $memberService, $sectionService
        )
    );
}

if (in_array('retro', $moduleManager->getEnabledModuleIds(), true)) {
    $retroBoardRepo = new \Modules\Retro\Repository\BoardRepository($pdo);
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
    if ($schedulerService->find('retro', 'purge_rate_limits', 'daily') === null) {
        $schedulerService->schedule('retro', 'purge_rate_limits', new DateTimeImmutable(), [], 'daily');
    }
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
            $sectionService, $memberService, $scoutYearResolver, $journalService, $settingService, $moduleManager
        )
    );
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

    // Iteration 5's staff-side services — status transitions, acceptance/
    // refusal emails, the one migration path shared by automatic
    // reconciliation and manual linking, and the household count Api\
    // HouseholdRegistrationCountProvider implementation wired nullable into
    // Core\Member\FeeEstimationService (ARCHITECTURE.md §7.5).
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
    $registrationHouseholdCountService = new \Modules\Registration\Service\HouseholdRegistrationCountService($registrationRequestRepo);
    $feeEstimationRepository = new \Core\Member\FeeEstimationRepository($pdo);
    $feeEstimationService = new \Core\Member\FeeEstimationService($feeEstimationRepository, $encryptionService, $registrationHouseholdCountService);

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
            $settingService
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

    // Iteration 6 — Départs (Core\Member\SectionStaffAuthorizationService is
    // core, first actually consumed by this page; DepartureService is also
    // core but constructed once, up top, since Core\Http\Controller\
    // MemberController's own departure endpoint needs it whether or not
    // this module is even enabled) and Passage (own PassageService +
    // SectionTransferRepository storage).
    $registrationSectionStaffAuth = new \Core\Member\SectionStaffAuthorizationService($connection, $encryptionService, $sectionService);
    $frontController->registerController(
        \Modules\Registration\Controller\DeparturesController::class,
        new \Modules\Registration\Controller\DeparturesController(
            $twig, $registrationSectionStaffAuth, $sectionService, $departureService, $scoutYearResolver
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
    // ScoutYear\ScoutYearAdminService/ScoutYearController were already
    // registered earlier (before this module's services existed, same
    // ordering constraint as ImportController/mass_mail above) with a
    // null veto — rebuilt and re-registered here with the real one.
    $registrationScoutYearVeto = new \Modules\Registration\Service\ScoutYearTransitionVetoService($registrationRequestRepo);
    $scoutYearAdminService = new ScoutYearAdminService($settingService, $registrationScoutYearVeto);
    $frontController->registerController(
        ScoutYearController::class,
        new ScoutYearController($twig, $scoutYearResolver, $scoutYearAdminService, $scoutYearService, $journalService)
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
            $twig, $importService, $scoutYearResolver, $importJournalRepo, $functionRepo, $storagePath, $registrationReconciliation
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
        $massMailService = new \Modules\MassMail\Service\MassMailService(
            $massMailEmailRepo, $massMailRecipientRepo, $massMailAttachmentRepo, $fileRepository,
            $massMailListService, $memberService, $memberEmailService, $sectionService, $mailService, $schedulerService, $journalService,
            new \Core\Security\HtmlSanitizer(), $scoutYearService, $importJournalRepo, $storagePath
        );
        $frontController->registerController(
            \Modules\MassMail\Controller\MassMailController::class,
            new \Modules\MassMail\Controller\MassMailController(
                $twig, $massMailService, $massMailListService, $massMailAccessService, $memberService, $sectionService,
                $scoutYearService, $importJournalRepo, $settingService, $uploadHandler, $fileRepository
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
    if ($schedulerService->find('registration', 'open_registration', 'poll') === null) {
        $schedulerService->schedule('registration', 'open_registration', new DateTimeImmutable(), [], 'poll');
    }
    if ($schedulerService->find('registration', 'close_registration', 'poll') === null) {
        $schedulerService->schedule('registration', 'close_registration', new DateTimeImmutable(), [], 'poll');
    }
    // Same bootstrap for the daily retention purge (Task\
    // PurgeRegistrationRequestsHandler) — module-scoped handlers need no
    // manual registerHandler() call in either entry point (auto-resolved
    // via ModuleManager::getTaskHandler()), only this one-time nudge.
    if ($schedulerService->find('registration', 'purge_registration_requests', 'daily') === null) {
        $schedulerService->schedule('registration', 'purge_registration_requests', new DateTimeImmutable(), [], 'daily');
    }
    // Same again for the Passage auto-assignment (Task\
    // AutoAssignPassageHandler) — it used to run inside PassageController::
    // index(), i.e. a write on every GET of the page.
    if ($schedulerService->find('registration', 'auto_assign_passage', 'hourly') === null) {
        $schedulerService->schedule('registration', 'auto_assign_passage', new DateTimeImmutable(), [], 'hourly');
    }

    // Espace animés menu hook (Core\Module\EspaceAnimesEntryProvider,
    // ARCHITECTURE.md §7.4) — one entry per pending registration request
    // linked to the visitor's email. $menuBuilder->build() was already
    // called above (before this module's services existed); addPage() only
    // mutates MenuBuilder's own internal list, so calling build() again
    // here safely picks up these entries too, and the Twig global is
    // re-set to the refreshed array. The highlight/active-page scan above
    // ran before these URLs existed, so it's re-applied here for exact
    // matches only (same "isDynamic entries: exact match only" rule as the
    // original scan — see its own comment).
    if (AuthSession::isAuthenticated()) {
        $registrationMenuEmail = AuthSession::getEmail();
        if ($registrationMenuEmail !== null) {
            $registrationMenuEntries = $registrationMenuHookService->getEntriesForEmail($registrationMenuEmail);
            foreach ($registrationMenuEntries as $registrationMenuIndex => $registrationMenuEntry) {
                $menuBuilder->addPage(
                    MenuBuilder::MENU_ESPACE_ANIMES,
                    $registrationMenuEntry['label'],
                    $registrationMenuEntry['url'],
                    'identified',
                    1000 + $registrationMenuIndex,
                    true,
                    $registrationMenuEntry['subtitle'],
                    MenuBuilder::GROUP_DYNAMIC
                );
            }
            if ($registrationMenuEntries !== []) {
                $menus = $menuBuilder->build();
                $twig->addGlobal('menus', $menus);

                foreach ($registrationMenuEntries as $registrationMenuEntry) {
                    if ($registrationMenuEntry['url'] === $currentPath && strlen($registrationMenuEntry['url']) > $bestMatchLength) {
                        $activeMenuId = MenuBuilder::MENU_ESPACE_ANIMES;
                        $activePageUrl = $registrationMenuEntry['url'];
                        $bestMatchLength = strlen($registrationMenuEntry['url']);
                    }
                }
                $twig->addGlobal('active_menu_id', $activeMenuId);
                $twig->addGlobal('active_page_url', $activePageUrl);
            }
        }
    }
}

// Re-registers MemberController (and its MemberPageService) with
// whichever optional providers are available — mass_mail's "Communications
// récentes", gallery's "Galeries photos", trombinoscope's section-
// responsable lookup (via $sectionResponsableProvider, ARCHITECTURE.md
// §7.4), and calendar's next-upcoming-event lookup (via
// $calendarEventLookup); each stays null when its module is disabled and
// the corresponding page block just doesn't render. Placed after every
// one of those modules' blocks above so their repositories/services are
// in scope.
if (
    in_array('mass_mail', $moduleManager->getEnabledModuleIds(), true)
    || in_array('gallery', $moduleManager->getEnabledModuleIds(), true)
    || in_array('calendar', $moduleManager->getEnabledModuleIds(), true)
    || in_array('trombinoscope', $moduleManager->getEnabledModuleIds(), true)
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
        $sectionDocumentService, $sectionResponsableProvider, $massMailQueryForMember, $galleryAlbumProviderForMember, $calendarEventLookup
    );

    $frontController->registerController(
        MemberController::class,
        new MemberController($twig, $memberService, $memberYearService, $journalService, $memberPageService, $departureService)
    );
}

// File access (/files/{id}) — built here, deliberately last, because
// FileAccessGuard's ownership-checker registry must be complete before it
// is constructed: it is immutable (no setter, no addOwnershipChecker())
// and a module contributes its own checker by appending to
// $fileOwnershipCheckers from inside its own getEnabledModuleIds() block,
// every one of which runs above this point. FileController is the only
// consumer of the guard, so nothing earlier needs it. $linkedMemberIds was
// snapshotted where $linkedMembers is first resolved, well before the menu
// re-resolves it.
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
if (isset($galleryAlbumService, $galleryMediaService, $galleryMediaRepo, $galleryStorageBackendFactory, $galleryStorageLocationService)) {
    $galleryDelegatedAlbumAccessRegistry = new \Modules\Gallery\Service\DelegatedAlbumAccessRegistry(
        $galleryDelegatedAlbumAccessCheckers
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

// RGPD configuration controller
$frontController->registerController(RgpdConfigController::class, new RgpdConfigController($twig, $editableContentService, $rgpdContentService, $settingService, $moduleManager, $journalService));

// Bypass RBAC for /setup routes when site is not initialized or explicitly allowed
$allowSetup = (bool) $config->get('allow_setup', false);
if (!$secretManager->isInitialized() || $allowSetup) {
    $frontController->setRbacBypassPrefix('/setup');
}

\Core\Debug\RequestTimeline::mark('services_ready');
$response = $frontController->handle($request);
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
