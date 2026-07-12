<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Core\Config\AppConfig;
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
use Core\Http\Controller\AuthController;
use Core\Http\Controller\CookieController;
use Core\Http\Controller\ConfigModeController;
use Core\Http\Controller\EditableContentController;
use Core\Http\Controller\FileController;
use Core\Http\Controller\ImportController;
use Core\Http\Controller\PageController;
use Core\Http\Controller\PlaceholderController;
use Core\Http\Controller\SetupController;
use Core\Http\Controller\UploadController;
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
use Core\Security\Role;
use Core\Security\SecretManager;
use Core\Security\SessionManager;
use Core\Security\UserAccountRepository;
use Core\View\ConfigurationMode;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Core\View\MenuBuilder;
use Core\View\SectionRepository;
use Core\View\TwigFactory;

// Load configuration
$config = new AppConfig(__DIR__ . '/../config/app.php');

// Create Twig environment
$twig = TwigFactory::create(
    __DIR__ . '/../core/View/templates',
    $config->isDebug()
);

// Add site_name as a global Twig variable
$twig->addGlobal('site_name', $config->get('site_name', 'Unité scoute'));

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

    $response->send();
    exit;
}

// Load secrets and create services
$secrets = $secretManager->readSecrets();

// Update site_name from secrets if available
if (!empty($secrets['site_name'])) {
    $twig->addGlobal('site_name', $secrets['site_name']);
}

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

// Create MailService
$mailService = MailServiceFactory::create($secrets, $dkimManager);

// Create AuthService
$authService = new AuthService(
    $connection,
    $encryptionService,
    $mailService,
    $twig,
    $secrets['base_url'] ?? '',
    $secrets['site_name'] ?? ''
);

// Create cookie consent service
$cookieConsentService = new CookieConsentService();

// Create editable content service
$pdo = $connection->getPdo();
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
$twig->addGlobal('current_user_display_name', AuthSession::getEmail() ?? '');
$twig->addGlobal('current_user_role_label', $roleLabelMap[$currentRole] ?? 'Public');
$twig->addGlobal('current_path', $request->getPath());
$twig->addGlobal('config_mode', ConfigurationMode::isActive());
$twig->addGlobal('_editable_content_service', $editableContentService);
$twig->addGlobal('contact_email', $secrets['mail_from_address'] ?? '');
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
$menuBuilder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Configuration générale', '/setup', 'admin', 10);
$menuBuilder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Fonctions', '/config/functions', 'admin', 20);
$menuBuilder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Paramètres', '/config/settings', 'admin', 30);
$menuBuilder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Actions planifiées', '/config/scheduled', 'admin', 40);

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

// Create router and register routes
$router = new Router();

// Public pages
$router->addRoute('GET', '/', PageController::class, 'home', 'public');
$router->addRoute('GET', '/contact', PageController::class, 'contact', 'public');
$router->addRoute('GET', '/sections', PageController::class, 'sections', 'public');
$router->addRoute('GET', '/rgpd', PageController::class, 'rgpd', 'public');

// Auth routes
$router->addRoute('GET', '/login', AuthController::class, 'login', 'public');
$router->addRoute('POST', '/login/magic-link', AuthController::class, 'requestMagicLink', 'public');
$router->addRoute('GET', '/auth/verify', AuthController::class, 'verifyMagicLink', 'public');
$router->addRoute('GET', '/auth/poll/{id}', AuthController::class, 'pollMagicLink', 'public');
$router->addRoute('POST', '/logout', AuthController::class, 'logout', 'identified');

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

// Placeholder routes for pages not yet built
// Import
$router->addRoute('GET', '/admin/import', ImportController::class, 'index', 'chief');
$router->addRoute('POST', '/admin/import', ImportController::class, 'import', 'chief');
$router->addRoute('GET', '/admin/journal', PlaceholderController::class, 'show', 'chief');
$router->addRoute('GET', '/config/functions', PlaceholderController::class, 'show', 'admin');
$router->addRoute('GET', '/config/settings', PlaceholderController::class, 'show', 'admin');
$router->addRoute('GET', '/config/scheduled', PlaceholderController::class, 'show', 'admin');
$router->addRoute('GET', '/chefs/staffs', PlaceholderController::class, 'show', 'intendant');

// Handle the request
$frontController = new FrontController($router, $twig, $config);

// Register controllers with dependencies
$frontController->registerController(PageController::class, new PageController($twig, $editableContentService, $sectionRepository, $cookieConsentService));
$frontController->registerController(CookieController::class, new CookieController($twig, $cookieConsentService));
$frontController->registerController(SetupController::class, new SetupController($twig, $secretManager, $dkimManager, $schemaPath));
$frontController->registerController(AuthController::class, new AuthController($twig, $authService, $roleResolver, $scoutYearService));
$frontController->registerController(ImportController::class, new ImportController($twig, $importService, $scoutYearService, $importJournalRepo, $functionRepo, $storagePath));
$frontController->registerController(ConfigModeController::class, new ConfigModeController($twig));
$frontController->registerController(EditableContentController::class, new EditableContentController($twig, $editableContentService));
$frontController->registerController(FileController::class, new FileController($twig, $fileAccessGuard, $storagePath));
$frontController->registerController(UploadController::class, new UploadController($twig, $uploadHandler, $editableContentService));
$frontController->registerController(PlaceholderController::class, new PlaceholderController($twig));

$response = $frontController->handle($request);
$response->send();
