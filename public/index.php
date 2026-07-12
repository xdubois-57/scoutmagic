<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Core\Config\AppConfig;
use Core\Http\Controller\HomeController;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
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

// Create router and register routes
$router = new Router();
$router->addRoute('GET', '/', HomeController::class, 'index', 'public');

// Handle the request
$request = Request::fromGlobals();
$frontController = new FrontController($router, $twig, $config);
$response = $frontController->handle($request);
$response->send();
