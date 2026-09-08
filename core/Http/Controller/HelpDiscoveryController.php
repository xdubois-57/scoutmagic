<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Help\Discovery\DiscoveryService;
use Core\Help\Discovery\SeenTopicRepository;
use Core\Help\HelpTopic;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Core\Security\Role;
use Twig\Environment;

/**
 * What the « Le saviez-vous ? » dialog writes back (ARCHITECTURE.md
 * §8.95), plus the « Revoir les astuces » button on /account.
 *
 * **One call, at the close.** The dialog walks its cards in the browser
 * and posts once, with everything the reader actually went past — a
 * request per card would be four requests to say what one says, and every
 * one of them a chance to half-record a passage.
 *
 * **The ids are revalidated against the account's own eligible set, never
 * written as received.** A client must not be able to mark seen what it
 * was never handed: the same reflex as
 * Core\Http\Controller\MaintenanceController::installUpdate(), which
 * re-checks server-side what the browser claims. The cost of getting this
 * wrong is small — somebody loses a tip they never read — which is
 * exactly why it would never be noticed.
 *
 * **No journaling, anywhere in here.** §8.64 locks "no journaling of help
 * consultation" and discovery is not an exception: `help_topics_seen` is
 * a preference, not a reading trace, and nothing counts who read what.
 */
class HelpDiscoveryController extends AbstractController
{
    /**
     * The four things the dialog can say on its way out.
     *
     * `never` is « Ne plus me proposer d'astuces », and it is expressed
     * as "mark the whole eligible remainder seen" rather than as a mute
     * flag — which is what gives it exactly the intended meaning: the
     * refusal covers today's scope only, so a promotion or a newly
     * enabled module brings the dialog back with the topics it made
     * eligible, with one mechanism instead of two.
     */
    private const ACTIONS = ['close', 'snooze', 'never', 'more'];

    public function __construct(
        Environment $twig,
        private readonly DiscoveryService $discovery,
        private readonly SeenTopicRepository $seenTopics,
    ) {
        parent::__construct($twig);
    }

    /**
     * POST /api/aide/decouverte — the dialog closing.
     *
     * @param array<string, string> $params
     */
    public function record(Request $request, array $params): Response
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
            // run — this is the belt to its braces, and the one case
            // where there is no account to record anything against.
            return $this->json(['success' => false, 'error' => self::SESSION_EXPIRED_MESSAGE], 403);
        }

        $action = isset($body['action']) && is_string($body['action']) ? $body['action'] : '';
        if (!in_array($action, self::ACTIONS, true)) {
            return $this->json([
                'success' => false,
                'error' => "Cette action n'existe pas. Rechargez la page et réessayez.",
            ], 400);
        }

        $eligible = $this->discovery->eligibleTopics(Role::fromString(AuthSession::getRole()), $accountId);
        $eligibleIds = array_map(static fn (HelpTopic $t): string => $t->id, $eligible);

        $toMark = $action === 'never'
            ? $eligibleIds
            : array_values(array_intersect($eligibleIds, $this->claimedIds($body)));

        // `never` has consumed everything there was, and `more` is asking
        // for the next batch right now: neither wants a delay on top.
        $until = match ($action) {
            'close' => $this->discovery->nextOrdinaryOpening(),
            'snooze' => $this->discovery->nextOpeningAfterSnooze(),
            default => null,
        };

        $this->seenTopics->markSeen($accountId, $toMark);
        $this->seenTopics->snooze($accountId, $until);

        return $this->json(['success' => true]);
    }

    /**
     * POST /account/discovery/reset — « Revoir les astuces ».
     *
     * Forgets everything this account was shown and releases any delay,
     * so the dialog starts again from the top of the corpus. Scoped to
     * the session's own account: there is no id in the request to get
     * wrong.
     *
     * @param array<string, string> $params
     */
    public function reset(Request $request, array $params): Response
    {
        $accountId = AuthSession::getUserAccountId();
        if ($accountId === null) {
            return $this->redirect('/login');
        }

        if (($guard = $this->guardCsrf($request, '/account')) !== null) {
            return $guard;
        }

        $this->seenTopics->clear($accountId);
        $this->seenTopics->snooze($accountId, null);
        FlashMessage::set('success', 'Les astuces vous seront proposées à nouveau.');

        return $this->redirect('/account');
    }

    /**
     * The ids the browser says it went past — strings only, and only ones
     * shaped like a topic id. Anything else is dropped here rather than
     * being handed to the intersection, which would silently accept it if
     * a topic ever happened to be called that.
     *
     * @param array<mixed> $body
     * @return string[]
     */
    private function claimedIds(array $body): array
    {
        if (!isset($body['ids']) || !is_array($body['ids'])) {
            return [];
        }

        $ids = [];
        foreach ($body['ids'] as $id) {
            if (is_string($id) && preg_match('/^[a-z0-9][a-z0-9-]*$/', $id) === 1) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
