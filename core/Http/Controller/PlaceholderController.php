<?php

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\Request;
use Core\Http\Response;

class PlaceholderController extends AbstractController
{
    /** @var array<string, string> */
    private const PAGE_TITLES = [
        '/admin/journal' => 'Journal',
        '/config/functions' => 'Fonctions',
        '/config/settings' => 'Paramètres',
        '/config/scheduled' => 'Actions planifiées',
        '/chefs/staffs' => 'Staffs',
    ];

    /**
     * Render a placeholder page for routes not yet implemented.
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $title = self::PAGE_TITLES[$request->getPath()] ?? 'Page en construction';

        return $this->render('placeholder.html.twig', ['page_title' => $title]);
    }
}
