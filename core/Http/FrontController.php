<?php

declare(strict_types=1);

namespace Core\Http;

use Core\Config\AppConfig;
use Core\Http\Controller\AbstractController;
use Twig\Environment;

class FrontController
{
    /** @var array<string, AbstractController> */
    private array $controllerInstances = [];

    public function __construct(
        private Router $router,
        private Environment $twig,
        private AppConfig $config
    ) {
    }

    /**
     * Register a pre-built controller instance for a specific class.
     */
    public function registerController(string $className, AbstractController $instance): void
    {
        $this->controllerInstances[$className] = $instance;
    }

    public function handle(Request $request): Response
    {
        $resolvedRoute = $this->router->resolve($request);

        if ($resolvedRoute === null) {
            return $this->renderNotFound();
        }

        // TODO: RBAC guard will be inserted here in iteration 5

        $controllerClass = $resolvedRoute->controllerClass;
        $action = $resolvedRoute->action;

        // Use pre-registered instance if available, otherwise create with Twig only
        if (isset($this->controllerInstances[$controllerClass])) {
            $controller = $this->controllerInstances[$controllerClass];
        } else {
            $controller = new $controllerClass($this->twig);
        }

        /** @var Response $response */
        $response = $controller->$action($request, $resolvedRoute->params);

        return $response;
    }

    private function renderNotFound(): Response
    {
        $html = $this->twig->render('errors/404.html.twig', [
            'site_name' => $this->config->get('site_name', 'Unité scoute'),
        ]);

        return (new Response($html))->setStatusCode(404);
    }
}
