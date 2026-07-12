<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Core\Config\AppConfig;
use Core\Database\Connection;
use Core\Http\Controller\HomeController;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Router;
use Core\Security\EncryptionService;
use Core\Security\SecretManager;
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

if (!$secretManager->isInitialized()) {
    // Site not initialized: render informational page (setup page comes in iteration 3)
    $html = $twig->render('errors/not_initialized.html.twig', [
        'site_name' => $config->get('site_name', 'Unité scoute'),
    ]);
    (new Response($html))->send();
    exit;
}

// Load secrets and create services
$secrets = $secretManager->readSecrets();

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

// Create router and register routes
$router = new Router();
$router->addRoute('GET', '/', HomeController::class, 'index', 'public');

// Handle the request
$request = Request::fromGlobals();
$frontController = new FrontController($router, $twig, $config);
$response = $frontController->handle($request);
$response->send();
