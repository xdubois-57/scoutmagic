<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Core\Config\AppConfig;
use Core\Database\Connection;
use Core\Http\Controller\AuthController;
use Core\Http\Controller\HomeController;
use Core\Http\Controller\SetupController;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Router;
use Core\Database\MigrationRunner;
use Core\Database\SchemaComparator;
use Core\Database\SchemaIntrospector;
use Core\Database\SqlParser;
use Core\Mail\DkimManager;
use Core\Mail\MailServiceFactory;
use Core\Security\AuthService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Core\Security\SecretManager;
use Core\Security\SessionManager;
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

// Set Twig globals for auth state (after session is started)
$twig->addGlobal('is_authenticated', AuthSession::isAuthenticated());
$twig->addGlobal('current_user_email', AuthSession::getEmail());
$twig->addGlobal('current_user_role', AuthSession::getRole());

// Create router and register routes
$router = new Router();
$router->addRoute('GET', '/', HomeController::class, 'index', 'public');
$router->addRoute('GET', '/login', AuthController::class, 'login', 'public');
$router->addRoute('POST', '/login/magic-link', AuthController::class, 'requestMagicLink', 'public');
$router->addRoute('GET', '/auth/verify', AuthController::class, 'verifyMagicLink', 'public');
$router->addRoute('GET', '/auth/poll/{id}', AuthController::class, 'pollMagicLink', 'public');
$router->addRoute('POST', '/logout', AuthController::class, 'logout', 'identified');
$router->addRoute('GET', '/setup', SetupController::class, 'index', 'admin');
$router->addRoute('POST', '/setup/test-db', SetupController::class, 'testDatabase', 'admin');
$router->addRoute('POST', '/setup/save', SetupController::class, 'save', 'admin');
$router->addRoute('GET', '/setup/dns', SetupController::class, 'checkDns', 'admin');

// Handle the request
$frontController = new FrontController($router, $twig, $config);
$frontController->registerController(SetupController::class, new SetupController($twig, $secretManager, $dkimManager, $schemaPath));
$frontController->registerController(AuthController::class, new AuthController($twig, $authService));
$response = $frontController->handle($request);
$response->send();
