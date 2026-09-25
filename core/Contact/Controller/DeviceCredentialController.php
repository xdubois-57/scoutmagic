<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Contact\Controller;

use Core\Contact\CardDav\AddressBookService;
use Core\Contact\Device\DeviceCredentialService;
use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Twig\Environment;

/**
 * « Synchroniser mes contacts », reached from Mon compte —
 * `role_min: admin`, because the address book those credentials open is a
 * staff address book and nobody below that floor may read it anyway
 * ({@see \Core\Contact\Device\DeviceAuthenticator}).
 *
 * **A credential is revoked only by the account that owns it**, here. The
 * route's floor says who may see this page at all; it says nothing about
 * whose credentials they may touch, so ownership is re-checked on the
 * row (`SECURITY.md` §3 — « role_min is a floor, never the whole
 * answer »). A credential belonging to somebody else answers the same
 * 404 as one that does not exist, so nobody can enumerate them.
 */
class DeviceCredentialController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private DeviceCredentialService $service
    ) {
    }

    /**
     * GET /account/devices
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $userAccountId = AuthSession::getUserAccountId();
        if ($userAccountId === null) {
            return $this->redirect('/login');
        }

        return $this->render('account/devices.html.twig', [
            'credentials' => $this->service->listForAccount($userAccountId),
            'sync_enabled' => $this->service->isSyncEnabled(),
            'can_create' => $this->service->canCreateFor($userAccountId),
            'max_per_account' => DeviceCredentialService::MAX_PER_ACCOUNT,
            'max_label_length' => DeviceCredentialService::MAX_LABEL_LENGTH,
            'account_email' => AuthSession::getEmail(),
            // The address a client is given, spelled out rather than
            // left for the reader to assemble: autodiscovery from the
            // bare domain is what most clients do, and the collection
            // path is what the ones that cannot discover need typed in.
            //
            // Taken from the request rather than from a setting, so it
            // is right on an installation whose `base_url` was never
            // filled in — the reader is looking at this page through the
            // very address their phone needs. It is echoed back only to
            // the browser that sent it, and Twig escapes it like any
            // other value.
            'site_url' => $this->siteUrl($request),
            'carddav_collection_path' => AddressBookService::COLLECTION_PATH,
            'breadcrumb_current' => 'Synchroniser mes contacts',
        ]);
    }

    /**
     * POST /api/account/devices (AJAX, JSON) — creates a credential and
     * returns its secret **in this response and nowhere else**.
     *
     * JSON rather than a form post and a redirect, for the reason
     * `MaintenanceController::generateWebhookSecret()` is JSON too: the
     * secret has to reach the screen exactly once without being parked
     * anywhere on the way. A flash message would put it in the session,
     * which is a file on the server that outlives the request.
     *
     * @param array<string, string> $params
     */
    public function create(Request $request, array $params): Response
    {
        $userAccountId = AuthSession::getUserAccountId();
        if ($userAccountId === null) {
            return $this->json(['success' => false, 'error' => 'Session expirée.'], 401);
        }

        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data) || !CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        if (!$this->service->canCreateFor($userAccountId)) {
            return $this->json([
                'success' => false,
                'error' => 'Vous avez déjà ' . DeviceCredentialService::MAX_PER_ACCOUNT
                    . ' appareils enregistrés. Révoquez-en un avant d\'en ajouter un autre.',
            ], 409);
        }

        $created = $this->service->create($userAccountId, (string) ($data['label'] ?? ''), $userAccountId);

        return $this->json([
            'success' => true,
            'secret' => $created->secret,
            'credential' => [
                'id' => $created->credential->id,
                'label' => $created->credential->label,
            ],
        ]);
    }

    /**
     * POST /account/devices/{id}/revoke
     *
     * @param array<string, string> $params
     */
    public function revoke(Request $request, array $params): Response
    {
        $userAccountId = AuthSession::getUserAccountId();
        if ($userAccountId === null) {
            return $this->redirect('/login');
        }

        if (($guard = $this->guardCsrf($request, '/account/devices')) !== null) {
            return $guard;
        }

        $credential = $this->service->findOwned((int) ($params['id'] ?? 0), $userAccountId);
        if ($credential === null) {
            return $this->notFound();
        }

        $this->service->revoke($credential, $userAccountId);
        FlashMessage::set(
            'success',
            'Appareil révoqué. Il ne peut plus se synchroniser — la copie déjà descendue reste sur l\'appareil.'
        );

        return $this->redirect('/account/devices');
    }

    /**
     * `https://unite.example.org` — the origin this page was reached
     * through, with no trailing slash, or an empty string when the host
     * is unknown (a request that carried no Host header, which is only
     * ever a test or a probe).
     */
    private function siteUrl(Request $request): string
    {
        $host = $request->getServer('HTTP_HOST');
        if (!is_string($host) || $host === '') {
            return '';
        }

        return ($request->isHttps() ? 'https://' : 'http://') . $host;
    }
}
