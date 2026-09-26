<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Modules\Social\Card\CardService;
use Twig\Environment;

/**
 * GET /partage/carte/{token} — the one public route of the module, and the
 * only way Meta's servers can fetch an image to publish (see
 * {@see CardService} and SECURITY.md).
 *
 * Every refusal is the same bare 404, whatever the reason — unknown,
 * malformed, expired: an answer that differed would tell a guesser which
 * tokens once existed.
 */
final class CardController extends AbstractController
{
    public function __construct(Environment $twig, private readonly CardService $cards)
    {
        parent::__construct($twig);
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $path = $this->cards->open((string) ($params['token'] ?? ''), new \DateTimeImmutable());
        if ($path === null) {
            return (new Response('Not Found', 404))
                ->setHeader('Content-Type', 'text/plain; charset=utf-8')
                ->setHeader('Cache-Control', 'no-store');
        }

        return (new Response())
            ->setHeader('Content-Type', 'image/jpeg')
            ->setHeader('Content-Length', (string) filesize($path))
            // The address dies within the hour: nothing may keep it longer.
            ->setHeader('Cache-Control', 'no-store')
            ->setHeader('X-Robots-Tag', 'noindex, noimageindex')
            ->setBodyFile($path);
    }
}
