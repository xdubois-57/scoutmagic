<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */
declare(strict_types=1);

namespace Modules\MassMail\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Member\EmailAddressReactivationService;
use Core\Member\MemberEmailException;
use Core\Security\AuthSession;
use Twig\Environment;

/** The admin-only restoration of an address, at its holder's explicit request. */
class AddressReactivationController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private EmailAddressReactivationService $reactivation
    ) {
    }

    /** @param array<string, string> $params */
    public function reactivate(Request $request, array $params): Response
    {
        $back = '/admin/listes-de-diffusion';
        if (($guard = $this->guardCsrf($request, $back)) !== null) {
            return $guard;
        }
        try {
            $changed = $this->reactivation->reactivate(
                (string) $request->getBody('email', ''), AuthSession::getUserAccountId()
            );
            FlashMessage::set(
                $changed > 0 ? 'success' : 'warning',
                $changed > 0 ? 'Adresse réactivée pour les e-mails groupés.' : "Cette adresse n'était pas désinscrite."
            );
        } catch (MemberEmailException $error) {
            FlashMessage::set('error', $error->getMessage());
        }
        return $this->redirect($back);
    }
}
