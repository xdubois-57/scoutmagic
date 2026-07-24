<?php

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\Request;
use Core\Http\Response;
use Core\Url\ShortUrlService;
use Twig\Environment;

class ShortUrlController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private ShortUrlService $shortUrlService
    ) {
    }

    /**
     * GET /s/{code} — 302 redirect to the stored target URL, 404 if unknown.
     *
     * @param array<string, string> $params
     */
    public function resolve(Request $request, array $params): Response
    {
        $code = (string) ($params['code'] ?? '');
        $targetUrl = $code !== '' ? $this->shortUrlService->resolve($code) : null;

        if ($targetUrl === null) {
            return new Response('Not Found', 404);
        }

        return $this->redirect($targetUrl);
    }
}
