<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Notification;

/**
 * Whether the « Activer les notifications ? » invitation may be put in a
 * page at all (ARCHITECTURE.md §8.110).
 *
 * **The server decides whether the markup ships; the browser decides
 * whether it opens.** Everything this class can see is account-shaped —
 * is somebody signed in, did they already refuse, is Web Push configured
 * at all — and none of it answers the question the feature is actually
 * about: *is this page being read inside the installed application?*
 * `display-mode: standalone` is a media query and a per-device fact, so
 * only `public/assets/js/push-invitation.js` can answer it. Splitting the
 * decision that way keeps the dialog out of the source of every page it
 * could never open on, without pretending the server knows something it
 * cannot know.
 *
 * There is deliberately no unit-wide switch for this, unlike
 * « Le saviez-vous ? » (§8.95). A tip comes back at every interval
 * forever, which is what a switch exists to stop; this dialog is offered
 * once per account and never again after either answer, and the switch it
 * would need already exists one level down — an installation with no VAPID
 * key pair has no push notifications to invite anybody to.
 */
class PushInvitationService
{
    /**
     * Pages the invitation never ships on, as path prefixes — the whole
     * path, or the whole path plus a `/` and more.
     *
     * `/account` is the one that is not about the request being unsuitable:
     * the « Notifications push » switch the dialog talks about is ON that
     * page, and a modal backdrop over it would cover the control it is
     * sending the reader to. The others are §8.95's reasons unchanged —
     * `/api/` answers JSON to a script with no dialog to draw, `/cookies`
     * is a page where consent is given or withdrawn and carries nothing on
     * top of it, `/login` is somebody not signed in yet, and the offline
     * page is somebody with no network, where subscribing cannot succeed.
     *
     * @var string[]
     */
    private const NEVER_ON = ['/account', '/api', '/cookies', '/login', '/offline'];

    public function __construct(
        private readonly PushInvitationRepository $invitations,
        private readonly string $vapidPublicKey,
    ) {
    }

    /**
     * Whether this request's page carries the invitation's markup.
     *
     * Cheapest first, and deliberately: this runs on every request of the
     * site, and every test but the last answers "no" without touching the
     * database at all.
     *
     * @param bool $cookieBannerAnswered whether this visitor has already
     *        accepted or refused cookies — NOT whether they accepted. The
     *        banner sits at z-index 1035 and a Bootstrap modal's backdrop
     *        at 1050, so a dialog shown over it would swallow every click
     *        aimed at the decision the site is asking for, and the reader's
     *        only way out is a dismissal that answers « Plus tard » for
     *        good. §8.95 already makes this call for the tips dialog; this
     *        one has more to lose by getting it wrong, since its refusal is
     *        permanent.
     */
    public function offerForRequest(
        ?int $accountId,
        string $method,
        string $path,
        bool $cookieBannerAnswered
    ): bool {
        if ($accountId === null || !$cookieBannerAnswered || $this->vapidPublicKey === '') {
            return false;
        }
        if (strtoupper($method) !== 'GET' || !$this->isOfferablePath($path)) {
            return false;
        }

        return $this->invitations->dismissedAt($accountId) === null;
    }

    /**
     * Prefix, then boundary: `/account` and `/account/passkeys` are the
     * account, `/accounts-something` would not be.
     */
    private function isOfferablePath(string $path): bool
    {
        foreach (self::NEVER_ON as $excluded) {
            if ($path === $excluded || str_starts_with($path, $excluded . '/')) {
                return false;
            }
        }

        return true;
    }
}
