<?php

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\Response;
use Twig\Environment;

abstract class AbstractController
{
    public function __construct(protected Environment $twig)
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function render(string $template, array $context = []): Response
    {
        $html = $this->twig->render($template, $context);
        return new Response($html);
    }

    protected function redirect(string $url, int $statusCode = 302): Response
    {
        return (new Response())->setStatusCode($statusCode)->setHeader('Location', $url);
    }

    protected function json(mixed $data, int $statusCode = 200): Response
    {
        return (new Response(json_encode($data), $statusCode))
            ->setHeader('Content-Type', 'application/json');
    }
}
