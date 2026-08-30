<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Core\Security\SuperAdminException;
use Core\Security\SuperAdminService;
use Core\Security\UserAccountRepository;
use Twig\Environment;

/**
 * Configuration > Comptes superadmin — the accounts that hold
 * `is_super_admin`, and nothing else.
 *
 * No Desk member is managed from here: a Desk member's access comes from
 * their function in the roster (Core\Security\RoleResolver::resolve()),
 * which this page does not touch. What it manages is the one access that
 * exists outside the roster entirely — the site operators.
 *
 * The refusals live in SuperAdminService, on the server, and the page's
 * JavaScript only greys a button out. A POST that arrives anyway is
 * refused by the service and reported here as a flash message.
 */
class SuperAdminAccountsController extends AbstractController
{
    private const PAGE_PATH = '/config/superadmins';

    public function __construct(
        protected Environment $twig,
        private UserAccountRepository $userAccountRepo,
        private SuperAdminService $superAdminService
    ) {
    }

    /**
     * GET /config/superadmins
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        return $this->render('config/super_admins.html.twig', [
            'accounts' => $this->userAccountRepo->findSuperAdmins(),
            'current_account_id' => AuthSession::getUserAccountId(),
        ]);
    }

    /**
     * POST /config/superadmins/add — grant the right to an address,
     * creating the account when it does not exist yet.
     *
     * @param array<string, string> $params
     */
    public function add(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::PAGE_PATH)) !== null) {
            return $guard;
        }

        $email = (string) $request->getBody('email', '');

        try {
            $result = $this->superAdminService->grant($email, AuthSession::getUserAccountId());
        } catch (SuperAdminException $e) {
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect(self::PAGE_PATH);
        }

        if ($result['already']) {
            FlashMessage::set('warning', 'Ce compte est déjà superadmin.');
        } elseif ($result['created']) {
            FlashMessage::set(
                'success',
                'Le compte a été créé et le droit superadmin lui a été accordé. '
                . 'La personne se connecte avec un lien magique depuis la page de connexion.'
            );
        } else {
            FlashMessage::set('success', 'Le droit superadmin a été accordé à ce compte.');
        }

        return $this->redirect(self::PAGE_PATH);
    }

    /**
     * POST /config/superadmins/revoke — withdraw the right. The flag
     * only: the account keeps its password, its passkeys and everything
     * that references it.
     *
     * @param array<string, string> $params
     */
    public function revoke(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::PAGE_PATH)) !== null) {
            return $guard;
        }

        $accountId = (int) $request->getBody('user_account_id', '0');

        try {
            $this->superAdminService->revoke($accountId, AuthSession::getUserAccountId());
        } catch (SuperAdminException $e) {
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect(self::PAGE_PATH);
        }

        FlashMessage::set('success', 'Le droit superadmin a été retiré.');

        return $this->redirect(self::PAGE_PATH);
    }
}
