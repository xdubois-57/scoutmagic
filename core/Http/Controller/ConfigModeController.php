<?php

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\View\ConfigurationMode;

class ConfigModeController extends AbstractController
{
    /**
     * POST /config-mode/activate — activate configuration mode.
     *
     * @param array<string, string> $params
     */
    public function activate(Request $request, array $params): Response
    {
        $csrf = (string) $request->getBody('_csrf_token', '');
        if (!CsrfGuard::validateToken($csrf)) {
            return (new Response('', 403))->setBody('Forbidden: invalid CSRF token.');
        }

        $role = AuthSession::getRole();
        if (ConfigurationMode::activate($role)) {
            FlashMessage::set('success', 'Mode configuration activé.');
        } else {
            FlashMessage::set('error', 'Vous n\'avez pas les permissions nécessaires.');
        }

        $referer = $request->getReferer() ?? '/';

        return $this->redirect($referer);
    }

    /**
     * POST /config-mode/deactivate — deactivate configuration mode.
     *
     * @param array<string, string> $params
     */
    public function deactivate(Request $request, array $params): Response
    {
        $csrf = (string) $request->getBody('_csrf_token', '');
        if (!CsrfGuard::validateToken($csrf)) {
            return (new Response('', 403))->setBody('Forbidden: invalid CSRF token.');
        }

        ConfigurationMode::deactivate();
        FlashMessage::set('success', 'Mode configuration désactivé.');

        $referer = $request->getReferer() ?? '/';

        return $this->redirect($referer);
    }
}
