<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Config\FirstStepsService;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\SafeRedirect;
use Twig\Environment;

/**
 * The « Masquer » button of the « Premiers pas » band (issue #718 § A,
 * Core\Config\FirstStepsService). The band itself is rendered by
 * base.html.twig from a global set in public/index.php.
 */
class FirstStepsController extends AbstractController
{
    public function __construct(Environment $twig, private FirstStepsService $firstSteps)
    {
        parent::__construct($twig);
    }

    /**
     * POST /config/premiers-pas/masquer
     *
     * @param array<string, string> $params
     */
    public function dismiss(Request $request, array $params): Response
    {
        // The Referer is untrusted — reduce it to a same-site path so it can
        // never redirect off-site (audit M17).
        $back = SafeRedirect::internalPathFromUrl($request->getReferer() ?? '/');

        if (($guard = $this->guardCsrf($request, $back)) !== null) {
            return $guard;
        }

        $this->firstSteps->dismiss();
        FlashMessage::set('success', 'Bandeau « Premiers pas » masqué. Les trois étapes restent décrites dans '
            . 'l\'aide, au sujet « Premiers pas après l\'installation ».');

        return $this->redirect($back);
    }
}
