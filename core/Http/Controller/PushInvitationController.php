<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Help\Discovery\DiscoveryService;
use Core\Http\Request;
use Core\Http\Response;
use Core\Notification\PushInvitationRepository;
use Core\Security\AuthSession;
use Twig\Environment;

/**
 * What the installed application's « Activer les notifications ? » dialog
 * writes back (ARCHITECTURE.md §8.110).
 *
 * **The subscription itself is not created here.** Accepting goes through
 * `POST /api/push-subscription` (Core\Http\Controller\
 * PushSubscriptionController) like every other subscription on this site,
 * because a device that subscribed from this dialog and a device that
 * subscribed from « Mon compte » must be the same row written the same
 * way. What this endpoint records is the *answer*, and the answer has two
 * consequences and no third: the refusal is remembered for good, and the
 * day's tips step aside either way.
 *
 * **Two actions, and the asymmetry is deliberate.** `later` writes
 * `push_invitation_dismissed_at`, after which the invitation is never
 * offered again on any device and « Mon compte » is the only way in —
 * which is what the dialog says. `enabled` writes nothing about the
 * account: a Web Push subscription belongs to ONE device, so a second
 * installed device still has to be asked, and the browser's own
 * `Notification.permission` is what keeps the dialog from coming back on
 * the device that accepted.
 *
 * **No journaling.** Creating the subscription is already journaled by
 * PushSubscriptionController, which is the act on the unit's data; the
 * answer to a dialog is a display preference, read nowhere but on the
 * account it belongs to — the same reading as §8.95's `help_topics_seen`.
 */
class PushInvitationController extends AbstractController
{
    /**
     * The two things the dialog can say on its way out. Dismissing it any
     * other way — the close cross, Escape, a click on the backdrop — is
     * `later` too, because it means the same thing and the browser sends
     * it as such.
     */
    private const ACTIONS = ['later', 'enabled'];

    public function __construct(
        Environment $twig,
        private readonly PushInvitationRepository $invitations,
        private readonly DiscoveryService $discovery,
    ) {
        parent::__construct($twig);
    }

    /**
     * POST /api/notifications/invitation — the dialog closing.
     *
     * @param array<string, string> $params
     */
    public function answer(Request $request, array $params): Response
    {
        $body = json_decode((string) $request->getRawBody(), true);
        $body = is_array($body) ? $body : [];

        // The payload is JSON (public/assets/js/api.js's postJson), so the
        // token is read out of it as well as out of the header — the same
        // shape as every other JSON endpoint here. SECURITY.md §4: the
        // GitHub webhook is the site's only tokenless POST.
        $token = isset($body['_csrf_token']) && is_string($body['_csrf_token']) ? $body['_csrf_token'] : null;
        if (($guard = $this->guardCsrfJson($request, $token)) !== null) {
            return $guard;
        }

        $accountId = AuthSession::getUserAccountId();
        if ($accountId === null) {
            // The route is role_min: identified, so the guard has already
            // run — this is the belt to its braces, and the one case where
            // there is no account to record anything against.
            return $this->json(['success' => false, 'error' => self::SESSION_EXPIRED_MESSAGE], 403);
        }

        $action = isset($body['action']) && is_string($body['action']) ? $body['action'] : '';
        if (!in_array($action, self::ACTIONS, true)) {
            return $this->json([
                'success' => false,
                'error' => "Cette action n'existe pas. Rechargez la page et réessayez.",
            ], 400);
        }

        if ($action === 'later') {
            $this->invitations->dismiss($accountId);
        }

        // « Si ce dialogue est affiché, on n'affiche pas les astuces ce
        // jour-là » — whichever way it was answered. While it is still on
        // screen the browser holds the tips back on its own
        // (public/assets/js/help-discovery.js); this is what carries that
        // across the rest of the day's page loads, once the dialog is
        // gone and nothing client-side remembers it was ever there.
        $this->discovery->holdBack($accountId);

        return $this->json(['success' => true]);
    }
}
