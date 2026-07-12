<?php

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\Request;
use Core\Http\Response;

class HomeController extends AbstractController
{
    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        return $this->render('home/index.html.twig', [
            'site_name' => 'Unité scoute',
        ]);
    }
}
