<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Contact\CardDav\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Twig\Environment;

/**
 * « Cet hébergeur laisse-t-il passer la synchronisation ? » — the probe
 * behind the button on « Synchroniser mes contacts » (`ARCHITECTURE.md`
 * §8.118).
 *
 * **It exists because of where this project is deployed.** Shared
 * hosting is the target, and a fair number of Apache configurations
 * refuse `PROPFIND` and `REPORT` outright, or route them into the host's
 * own `mod_dav` before PHP is ever reached. The symptom on the other end
 * is a phone that says « impossible de se connecter » with no further
 * detail, and a chef d'unité who has no way of telling a wrong password
 * from a web server that ate the request. This answers exactly that
 * question, from the browser of somebody already signed in.
 *
 * It replies the same trivial JSON to `GET`, `PROPFIND` and `REPORT`,
 * and what matters is not the body but the fact that it arrived: a reply
 * means PHP was reached by that method, and a 403 or 405 the browser
 * reports instead means the web server answered first.
 *
 * `role_min: admin`, session-authenticated like the page that offers it
 * — it is not part of the CardDAV routes' `SECURITY.md` §4 exception,
 * and it carries no device credential. It reads nothing, writes nothing
 * and names nobody, so there is no data for it to leak.
 */
class CardDavProbeController extends AbstractController
{
    public function __construct(protected Environment $twig)
    {
        parent::__construct($twig);
    }

    /** @param array<string, string> $params */
    public function probe(Request $request, array $params = []): Response
    {
        $body = json_encode([
            'ok' => true,
            'method' => $request->getMethod(),
        ], JSON_THROW_ON_ERROR);

        return (new Response($body))
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->setHeader('Cache-Control', 'no-store');
    }
}
