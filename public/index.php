<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

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
use Core\Config\ScoutYearService;
use Core\Http\Controller\AccountController;
use Core\Http\Controller\AuthController;
use Core\Http\Controller\ConfigGeneralController;
use Core\Http\Controller\FunctionsController;
use Core\Http\Controller\CookieController;
use Core\Http\Controller\ConfigModeController;
use Core\Http\Controller\EditableContentController;
use Core\Http\Controller\FileController;
use Core\Http\Controller\ImportController;
use Core\Http\Controller\JournalController;
use Core\Http\Controller\MemberController;
use Core\Http\Controller\PageController;
use Core\Http\Controller\PlaceholderController;
use Core\Http\Controller\ScheduledActionsController;
use Core\Http\Controller\SettingsController;
use Core\Http\Controller\SetupController;
use Core\Http\Controller\StaffsController;
use Core\Http\Controller\UploadController;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Module\ModuleManager;
use Core\Module\ModuleRegistryRepository;
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
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Security\RoleResolver;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Router;
use Core\Mail\DkimManager;
use Core\Mail\MailServiceFactory;
use Core\Security\AuthService;
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
use Twig\TwigFunction;
use Core\View\ConfigurationMode;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
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

$isInitialized = $secretManager->isInitialized();
$isSetupRoute = str_starts_with($request->getPath(), '/setup');

// Start session for setup routes or when initialized
if ($isInitialized || $isSetupRoute) {
    SessionManager::start();
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
    $setupController = new SetupController($twig, $secretManager, $dkimManager, $schemaPath);

    if ($request->getMethod() === 'GET' && $request->getPath() === '/setup') {
        $response = $setupController->index($request, []);
    } elseif ($request->getMethod() === 'POST' && $request->getPath() === '/setup/test-db') {
        $response = $setupController->testDatabase($request, []);
    } elseif ($request->getMethod() === 'POST' && $request->getPath() === '/setup/save') {
        $response = $setupController->save($request, []);
    } elseif ($request->getMethod() === 'GET' && $request->getPath() === '/setup/dns') {
        $response = $setupController->checkDns($request, []);
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

// Auto-migrate: apply any pending schema changes from core.sql
$migrationRunner = new MigrationRunner(
    $connection,
    new SchemaIntrospector($connection->getPdo()),
    new SchemaComparator(),
    new SqlParser()
);
$migrationRunner->migrate([$schemaPath]);

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
$settingService->register('scheduler_last_run', '0', 'number', 'Dernier passage du planificateur',
    'Horodatage Unix du dernier passage du planificateur de tâches. Géré automatiquement.',
    null, null, null, false, 200);

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

// Create MailService
$mailService = MailServiceFactory::create($secrets, $dkimManager);

// Create AuthService
$authService = new AuthService(
    $connection,
    $encryptionService,
    $mailService,
    $twig,
    (string) $settingService->get('base_url'),
    (string) $settingService->get('site_name')
);

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
$importService = new DeskImportService(
    $pdo, $encryptionService, $csvParser, $mappingResolver,
    $memberRepo, $memberYearRepo, $importJournalRepo, $userAccountRepo
);
$roleResolver = new RoleResolver($memberYearRepo, $encryptionService, $pdo);
$memberService = new MemberService($memberYearRepo, $encryptionService, $connection);
$sectionService = new SectionService($connection, $encryptionService);

// Create file services
$storagePath = dirname(__DIR__) . '/storage';
$fileRepository = new FileRepository($pdo);
$fileAccessGuard = new FileAccessGuard($fileRepository, Role::fromString(AuthSession::getRole()));
$uploadHandler = new UploadHandler($fileRepository, $storagePath);

// Role labels in French
$roleLabelMap = [
    'public' => 'Public',
    'identified' => 'Animé',
    'intendant' => 'Intendant',
    'chief' => 'Chef',
    'admin' => 'Admin',
];

// Set Twig globals for auth state (after session is started)
$currentRole = AuthSession::getRole();
$twig->addGlobal('is_authenticated', AuthSession::isAuthenticated());
$twig->addGlobal('current_user_email', AuthSession::getEmail());
$twig->addGlobal('current_user_role', $currentRole);

// Update user display name based on linked members
$displayName = AuthSession::getEmail() ?? '';
$memberCount = 0;
if (AuthSession::isAuthenticated()) {
    $linkedMembers = $memberService->getLinkedMembers(
        AuthSession::getEmail(),
        $scoutYearService->getCurrentYear()['id']
    );
    if (count($linkedMembers) > 0) {
        $primaryMember = MemberService::getHighestRoleMember($linkedMembers);
        $displayName = $primaryMember !== null ? $primaryMember->getDisplayName() : $displayName;
        $memberCount = count($linkedMembers);
    }
}
$twig->addGlobal('current_user_display_name', $displayName);
$twig->addGlobal('current_user_member_count', $memberCount);
$twig->addGlobal('current_user_role_label', $roleLabelMap[$currentRole] ?? 'Public');
$twig->addGlobal('current_path', $request->getPath());
$twig->addGlobal('config_mode', ConfigurationMode::isActive());
$twig->addGlobal('_editable_content_service', $editableContentService);
$twig->addGlobal('cookie_consent_given', $cookieConsentService->hasConsented());

// Build menu
$menuBuilder = new MenuBuilder(Role::fromString($currentRole));

// Register core pages in menus
$menuBuilder->addPage(MenuBuilder::MENU_NOTRE_UNITE, 'Accueil', '/', 'public', 10);
$menuBuilder->addPage(MenuBuilder::MENU_NOTRE_UNITE, 'Contact', '/contact', 'public', 20);
$menuBuilder->addPage(MenuBuilder::MENU_NOTRE_UNITE, 'Sections', '/sections', 'public', 30);
$menuBuilder->addPage(MenuBuilder::MENU_NOTRE_UNITE, 'Protection des données', '/rgpd', 'public', 40);
$menuBuilder->addPage(MenuBuilder::MENU_ESPACE_CHEFS, 'Staffs', '/chefs/staffs', 'intendant', 10);
$menuBuilder->addPage(MenuBuilder::MENU_ESPACE_ADMIN, 'Import Desk', '/admin/import', 'chief', 10);
$menuBuilder->addPage(MenuBuilder::MENU_ESPACE_ADMIN, 'Journal', '/admin/journal', 'chief', 20);
$menuBuilder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Configuration générale', '/config/general', 'admin', 10);
$menuBuilder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Configuration du site', '/setup', 'admin', 15);
$menuBuilder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Fonctions', '/config/functions', 'admin', 20);
$menuBuilder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Paramètres', '/config/settings', 'admin', 30);
$menuBuilder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Actions planifiées', '/config/scheduled', 'admin', 40);

// Create router early so ModuleManager can register routes
$router = new Router();

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
    $router
);

// Set up SchedulerRunner with ModuleManager
$schedulerRunner->setModuleManager($moduleManager);

// Add dynamic member entries to Espace des animés
if (AuthSession::isAuthenticated()) {
    $linkedMembers = $memberService->getLinkedMembers(
        AuthSession::getEmail(),
        $scoutYearService->getCurrentYear()['id']
    );

    foreach ($linkedMembers as $index => $member) {
        $menuBuilder->addPage(
            MenuBuilder::MENU_ESPACE_ANIMES,
            $member->getDisplayName(),
            '/members/' . $member->memberYearId,
            'identified',
            10 + $index,  // order: members first
            true,          // isDynamic = true
            $member->getMainSectionName()  // subtitle
        );
    }

    // Separator between dynamic member entries and static module pages
    if (count($linkedMembers) > 0) {
        $menuBuilder->addSeparator(MenuBuilder::MENU_ESPACE_ANIMES, 50);
    } else {
        // Empty state message when no members are linked
        $currentYear = $scoutYearService->getCurrentYear();
        $menuBuilder->addPage(
            MenuBuilder::MENU_ESPACE_ANIMES,
            'Aucun membre associé à votre compte pour l\'année ' . $currentYear['label'],
            '#',
            'identified',
            10,
            false,
            null
        );
    }
}

// Register core routes
// Public pages
$router->addRoute('GET', '/', PageController::class, 'home', 'public');
$router->addRoute('GET', '/contact', PageController::class, 'contact', 'public');
$router->addRoute('GET', '/sections', PageController::class, 'sections', 'public');
$router->addRoute('GET', '/rgpd', PageController::class, 'rgpd', 'public');

// Auth routes
$router->addRoute('GET', '/login', AuthController::class, 'login', 'public');
$router->addRoute('POST', '/login/magic-link', AuthController::class, 'requestMagicLink', 'public');
$router->addRoute('POST', '/login/password', AuthController::class, 'loginWithPassword', 'public');
$router->addRoute('GET', '/login/passkey/options', AuthController::class, 'passkeyOptions', 'public');
$router->addRoute('POST', '/login/passkey/verify', AuthController::class, 'passkeyVerify', 'public');
$router->addRoute('GET', '/auth/verify', AuthController::class, 'verifyMagicLink', 'public');
$router->addRoute('GET', '/auth/poll/{id}', AuthController::class, 'pollMagicLink', 'public');
$router->addRoute('POST', '/logout', AuthController::class, 'logout', 'identified');

// Account routes
$router->addRoute('GET', '/account', AccountController::class, 'index', 'identified');
$router->addRoute('POST', '/account/profile', AccountController::class, 'updateProfile', 'identified');
$router->addRoute('POST', '/account/password', AccountController::class, 'updatePassword', 'identified');
$router->addRoute('GET', '/account/passkey/register-options', AccountController::class, 'passkeyRegisterOptions', 'identified');
$router->addRoute('POST', '/account/passkey/register', AccountController::class, 'passkeyRegister', 'identified');
$router->addRoute('POST', '/account/passkey/delete', AccountController::class, 'passkeyDelete', 'identified');

// Member pages
$router->addRoute('GET', '/members/{id}', MemberController::class, 'show', 'identified');

// Configuration mode
$router->addRoute('POST', '/config-mode/activate', ConfigModeController::class, 'activate', 'admin');
$router->addRoute('POST', '/config-mode/deactivate', ConfigModeController::class, 'deactivate', 'admin');

// Editable content API
$router->addRoute('POST', '/api/editable-content', EditableContentController::class, 'update', 'admin');

// Cookie consent
$router->addRoute('GET', '/cookies', CookieController::class, 'preferences', 'public');
$router->addRoute('POST', '/cookies/save', CookieController::class, 'save', 'public');
$router->addRoute('POST', '/cookies/accept-all', CookieController::class, 'acceptAll', 'public');
$router->addRoute('POST', '/cookies/reject-all', CookieController::class, 'rejectAll', 'public');

// File serving
$router->addRoute('GET', '/files/{id}', FileController::class, 'serve', 'public');

// File upload
$router->addRoute('GET', '/upload', UploadController::class, 'index', 'admin');
$router->addRoute('POST', '/upload', UploadController::class, 'store', 'admin');

// Setup routes (admin, but bypassed when not initialized)
$router->addRoute('GET', '/setup', SetupController::class, 'index', 'admin');
$router->addRoute('POST', '/setup/test-db', SetupController::class, 'testDatabase', 'admin');
$router->addRoute('POST', '/setup/save', SetupController::class, 'save', 'admin');
$router->addRoute('GET', '/setup/dns', SetupController::class, 'checkDns', 'admin');
$router->addRoute('POST', '/setup/test-email', SetupController::class, 'testEmail', 'admin');

// Import
$router->addRoute('GET', '/admin/import', ImportController::class, 'index', 'chief');
$router->addRoute('POST', '/admin/import', ImportController::class, 'import', 'chief');

// Journal
$router->addRoute('GET', '/admin/journal', JournalController::class, 'index', 'chief');

// Settings
$router->addRoute('GET', '/config/settings', SettingsController::class, 'index', 'admin');
$router->addRoute('POST', '/config/settings/update', SettingsController::class, 'update', 'admin');

// Scheduled actions
$router->addRoute('GET', '/config/scheduled', ScheduledActionsController::class, 'index', 'admin');

// Configuration générale
$router->addRoute('GET', '/config/general', ConfigGeneralController::class, 'index', 'admin');
$router->addRoute('POST', '/config/general/module-toggle', ConfigGeneralController::class, 'toggleModule', 'admin');

// Staffs
$router->addRoute('GET', '/chefs/staffs', StaffsController::class, 'index', 'intendant');
$router->addRoute('POST', '/chefs/staffs/update-section', StaffsController::class, 'updateSection', 'chief');

// Functions configuration
$router->addRoute('GET', '/config/functions', FunctionsController::class, 'index', 'admin');
$router->addRoute('POST', '/config/functions/update', FunctionsController::class, 'update', 'admin');

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

// Determine active menu from current path
$activeMenuId = '';
foreach ($menus as $menu) {
    foreach ($menu['pages'] as $page) {
        if (!$page['isSeparator'] && ($page['url'] ?? '') === $request->getPath()) {
            $activeMenuId = $menu['id'];
            break 2;
        }
    }
}
$twig->addGlobal('active_menu_id', $activeMenuId);

// Handle the request
$frontController = new FrontController($router, $twig, $config);

// Register controllers with dependencies
$frontController->registerController(PageController::class, new PageController($twig, $editableContentService, $sectionRepository, $cookieConsentService));
$frontController->registerController(CookieController::class, new CookieController($twig, $cookieConsentService));
$setupController = new SetupController($twig, $secretManager, $dkimManager, $schemaPath);
$setupController->setSettingService($settingService);
$setupController->setJournalService($journalService);
$frontController->registerController(SetupController::class, $setupController);
// Build auth dependencies
$authService->setJournalService($journalService);
$loginThrottler = new LoginThrottler($connection);
$passwordAuthMethod = new PasswordAuthMethod($userAccountRepo, $encryptionService, $loginThrottler);
$passwordAuthMethod->setJournalService($journalService);
$webAuthnCredentialRepo = new WebAuthnCredentialRepository($pdo);
$webAuthnBaseUrl = (string) ($settingService->get('base_url') ?: 'https://localhost');
$webAuthnService = new WebAuthnService(
    $webAuthnCredentialRepo,
    $userAccountRepo,
    parse_url($webAuthnBaseUrl, PHP_URL_HOST) ?: 'localhost',
    (string) ($settingService->get('site_name') ?: 'Unité scoute'),
    $webAuthnBaseUrl
);

$authController = new AuthController($twig, $authService, $roleResolver, $scoutYearService);
$authController->setPasswordAuth($passwordAuthMethod);
$authController->setWebAuthnService($webAuthnService);
$frontController->registerController(AuthController::class, $authController);
$frontController->registerController(AccountController::class, new AccountController($twig, $userAccountRepo, $webAuthnCredentialRepo, $webAuthnService));
$frontController->registerController(ImportController::class, new ImportController($twig, $importService, $scoutYearService, $importJournalRepo, $functionRepo, $storagePath));
$frontController->registerController(MemberController::class, new MemberController($twig, $memberService));
$frontController->registerController(StaffsController::class, new StaffsController($twig, $sectionService, $memberService, $scoutYearService, $journalService));
$frontController->registerController(ConfigModeController::class, new ConfigModeController($twig));
$editableContentController = new EditableContentController($twig, $editableContentService);
$editableContentController->setJournalService($journalService);
$frontController->registerController(EditableContentController::class, $editableContentController);
$fileController = new FileController($twig, $fileAccessGuard, $storagePath);
$fileController->setJournalService($journalService);
$frontController->registerController(FileController::class, $fileController);
$frontController->registerController(UploadController::class, new UploadController($twig, $uploadHandler, $editableContentService));
$frontController->registerController(JournalController::class, new JournalController($twig, $journalRepo));
$frontController->registerController(SettingsController::class, new SettingsController($twig, $settingService, $journalService));
$frontController->registerController(ScheduledActionsController::class, new ScheduledActionsController($twig, $schedulerRepo));
$frontController->registerController(ConfigGeneralController::class, new ConfigGeneralController($twig, $moduleManager));
$frontController->registerController(FunctionsController::class, new FunctionsController($twig, $functionRepo, $journalService));
$frontController->registerController(PlaceholderController::class, new PlaceholderController($twig));

// Bypass RBAC for /setup routes when site is not initialized or explicitly allowed
$allowSetup = (bool) $config->get('allow_setup', false);
if (!$secretManager->isInitialized() || $allowSetup) {
    $frontController->setRbacBypassPrefix('/setup');
}

$response = $frontController->handle($request);
$response->setCspNonce($cspNonce);
$response->send();

// Poor man's cron — run scheduler max once per minute, after response is sent
$lastRun = (int) $settingService->get('scheduler_last_run');
$now = time();
if (($now - $lastRun) > 60) {
    try {
        $settingRepo->updateValue(null, 'scheduler_last_run', (string) $now);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        $schedulerRunner->processOverdue();
        $retentionDays = (int) ($settingService->get('journal_retention_days') ?: '730');
        $journalService->cleanup($retentionDays);
    } catch (\Throwable $e) {
        // Silently ignore scheduler errors in poor man's cron
    }
}
