<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Contact\Controller;

use Core\Contact\Device\DeviceCredentialService;
use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Twig\Environment;

/**
 * Configuration > « Synchronisation des contacts », `role_min:
 * superadmin`: the site-wide cut-out, and every device of every account.
 *
 * A feature that replicates personal data onto personal telephones has to
 * have both — a switch that stops all of it at once without hunting down
 * credentials one by one, and a view that shows the whole of what is
 * currently able to pull. A superadmin who can only see their own devices
 * cannot answer « qui synchronise ? », which is the question that matters
 * the day it is asked.
 */
class ContactSyncConfigController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private DeviceCredentialService $service
    ) {
    }

    /**
     * GET /config/synchronisation-contacts
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        // One collaborator, not two: the owner names come from the same
        // service as the credentials (`ARCHITECTURE.md` § Layered MVC),
        // which is also what keeps them to one query for the whole page.
        ['credentials' => $credentials, 'owners' => $owners] = $this->service->listAllWithOwners();

        return $this->render('config/contact_sync.html.twig', [
            'credentials' => $credentials,
            'owners' => $owners,
            'sync_enabled' => $this->service->isSyncEnabled(),
            'live_count' => count(array_filter($credentials, static fn($c): bool => !$c->isRevoked())),
        ]);
    }

    /**
     * POST /config/synchronisation-contacts/switch
     *
     * @param array<string, string> $params
     */
    public function toggle(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/config/synchronisation-contacts')) !== null) {
            return $guard;
        }

        $enabled = (string) $request->getBody('enabled', '0') === '1';
        $this->service->setSyncEnabled($enabled, AuthSession::getUserAccountId());

        FlashMessage::set(
            'success',
            $enabled
                ? 'Synchronisation réactivée. Les appareils non révoqués peuvent de nouveau se synchroniser.'
                : 'Synchronisation coupée pour tout le site. Aucun appareil ne peut plus se synchroniser ;'
                    . ' les copies déjà descendues restent sur les appareils.'
        );

        return $this->redirect('/config/synchronisation-contacts');
    }

    /**
     * POST /config/synchronisation-contacts/{id}/revoke — a superadmin
     * revoking somebody else's device, which is the whole point of this
     * page and the one place on the site where that is possible.
     *
     * @param array<string, string> $params
     */
    public function revoke(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/config/synchronisation-contacts')) !== null) {
            return $guard;
        }

        $credential = $this->service->findAny((int) ($params['id'] ?? 0));
        if ($credential === null) {
            return $this->notFound();
        }

        $this->service->revoke($credential, AuthSession::getUserAccountId(), bySuperAdmin: true);
        FlashMessage::set(
            'success',
            'Appareil révoqué. Il ne peut plus se synchroniser — la copie déjà descendue reste sur l\'appareil.'
        );

        return $this->redirect('/config/synchronisation-contacts');
    }
}
