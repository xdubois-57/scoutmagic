<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\PostRepository;
use Modules\Groups\Repository\ReplyRepository;
use Modules\Groups\Service\GroupAccessService;
use Modules\Groups\Service\GroupSessionContext;
use Modules\Groups\Service\GroupNotificationService;
use Modules\Groups\Service\GroupSessionContextFactory;
use Modules\Groups\Service\ReactionService;
use Modules\Groups\Service\ReactionSummary;
use Modules\Groups\Service\ReactorListService;
use Modules\Groups\Support\ReactionOutcome;
use Twig\Environment;

/**
 * Reacting on a post and on a reply.
 *
 * The 404-not-403 rule matters more here than anywhere else in this
 * module: without it, POSTing a reaction at an arbitrary post id would
 * answer differently for "this id exists in a group you cannot see" than
 * for "this id does not exist", turning the endpoint into an oracle for
 * enumerating post ids across every group on the site. So the group is
 * resolved and read-checked first, and the item is then required to belong
 * to THAT group — a post from another group is indistinguishable from a
 * nonexistent one.
 *
 * Writing additionally requires canPost(): a closed group, a past scout
 * year or an incomplete profile refuses a reaction exactly as it refuses a
 * post.
 */
class ReactionController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private GroupRepository $groupRepository,
        private PostRepository $postRepository,
        private ReplyRepository $replyRepository,
        private GroupAccessService $accessService,
        private ReactionService $reactionService,
        private GroupSessionContextFactory $contextFactory,
        private ?GroupNotificationService $notificationService = null,
        private ?ReactorListService $reactorListService = null
    ) {
    }

    /**
     * POST /groups/{id}/posts/{postId}/react
     *
     * @param array<string, string> $params
     */
    public function react(Request $request, array $params): Response
    {
        return $this->reactAction($request, $params, false, function (
            DiscussionGroup $group,
            int $memberId,
            string $key,
            GroupSessionContext $context
        ) use ($params) {
            $post = $this->postRepository->findById((int) ($params['postId'] ?? 0));
            if ($post === null || $post->groupId !== $group->id) {
                return null;
            }
            // Auto-hidden content is invisible to non-moderators on every
            // read path (the feed's SQL, and GroupController::post()'s deep
            // link) — so it must not be reactable either. Otherwise a member
            // could react to a post they cannot see, notifying its author
            // and bumping last_activity_at, which postpones the retention
            // purge of the very content moderation hid.
            if ($post->isHidden() && !$this->accessService->canModerate($group, $context)) {
                return null;
            }

            $outcome = $this->reactionService->toggleOnPost($group, $post->id, $memberId, $key);
            if ($outcome === ReactionOutcome::ADDED && $context->userAccountId !== null) {
                // Only on ADDED: taking a reaction back off must not tell
                // the author somebody reacted, and toggling one on and off
                // repeatedly must not be a way to buzz their phone.
                $this->notificationService?->reactionOnPost(
                    $group,
                    $post,
                    $context->userAccountId,
                    $context->effectiveScoutYearId
                );
            }

            return [
                $outcome,
                $outcome === ReactionOutcome::INVALID
                    ? null
                    : $this->reactionService->summaryForPost($post->id, $context->linkedMemberIds),
                '/groups/' . $group->id . '/posts/' . $post->id . '/react',
                '/groups/' . $group->id . '/posts/' . $post->id . '/reactions',
            ];
        });
    }

    /**
     * POST /groups/{id}/replies/{replyId}/react
     *
     * @param array<string, string> $params
     */
    public function reactToReply(Request $request, array $params): Response
    {
        return $this->reactAction($request, $params, true, function (
            DiscussionGroup $group,
            int $memberId,
            string $key,
            GroupSessionContext $context
        ) use ($params) {
            $reply = $this->replyRepository->findById((int) ($params['replyId'] ?? 0));
            if ($reply === null) {
                return null;
            }

            // Same two-step ownership check as a post's, one level deeper:
            // the reply's own post must belong to the authorised group.
            $post = $this->postRepository->findById($reply->postId);
            if ($post === null || $post->groupId !== $group->id) {
                return null;
            }
            // Same rule as react(): neither a hidden reply nor a reply whose
            // parent post is hidden is reactable by a non-moderator.
            if (($reply->isHidden() || $post->isHidden()) && !$this->accessService->canModerate($group, $context)) {
                return null;
            }

            $outcome = $this->reactionService->toggleOnReply($group, $reply->id, $post->id, $memberId, $key);
            if ($outcome === ReactionOutcome::ADDED && $context->userAccountId !== null) {
                $this->notificationService?->reactionOnReply(
                    $group,
                    $post,
                    $reply,
                    $context->userAccountId,
                    $context->effectiveScoutYearId
                );
            }

            return [
                $outcome,
                $outcome === ReactionOutcome::INVALID
                    ? null
                    : $this->reactionService->summaryForReply($reply->id, $context->linkedMemberIds),
                '/groups/' . $group->id . '/replies/' . $reply->id . '/react',
                '/groups/' . $group->id . '/replies/' . $reply->id . '/reactions',
            ];
        });
    }

    /**
     * GET /groups/{id}/posts/{postId}/reactions — who reacted, and with
     * what (the dialog behind a reaction tally's own click, groups.js).
     *
     * @param array<string, string> $params
     */
    public function postReactors(Request $request, array $params): Response
    {
        return $this->reactorsAction($params, function (
            DiscussionGroup $group,
            GroupSessionContext $context
        ) use ($params) {
            $post = $this->postRepository->findById((int) ($params['postId'] ?? 0));
            if ($post === null || $post->groupId !== $group->id) {
                return null;
            }
            if ($post->isHidden() && !$this->accessService->canModerate($group, $context)) {
                return null;
            }

            return $this->reactorListService?->forPost(
                $post->id,
                $group->scoutYearId ?? $context->effectiveScoutYearId
            ) ?? [];
        });
    }

    /**
     * GET /groups/{id}/replies/{replyId}/reactions
     *
     * @param array<string, string> $params
     */
    public function replyReactors(Request $request, array $params): Response
    {
        return $this->reactorsAction($params, function (
            DiscussionGroup $group,
            GroupSessionContext $context
        ) use ($params) {
            $reply = $this->replyRepository->findById((int) ($params['replyId'] ?? 0));
            if ($reply === null) {
                return null;
            }

            $post = $this->postRepository->findById($reply->postId);
            if ($post === null || $post->groupId !== $group->id) {
                return null;
            }
            if ($reply->isHidden() && !$this->accessService->canModerate($group, $context)) {
                return null;
            }

            return $this->reactorListService?->forReply($reply->id,
                $group->scoutYearId ?? $context->effectiveScoutYearId) ?? [];
        });
    }

    /**
     * The shared shape of both `reactors` endpoints: read access only —
     * unlike reacting itself, viewing who already reacted needs no
     * canPost() (module spec: viewing stays as available as the item
     * itself already is). $locate returns null when the item does not
     * belong to the authorised group, or is hidden from this viewer
     * (→ 404 either way, same oracle reasoning as reactAction()'s own
     * docblock).
     *
     * @param array<string, string> $params
     * @param callable(DiscussionGroup, GroupSessionContext): ?array<
     *     int,
     *     array{key: string, emoji: string, names: string[]}
     * > $locate
     */
    private function reactorsAction(array $params, callable $locate): Response
    {
        $context = $this->context();
        $group = $this->groupRepository->findById((int) ($params['id'] ?? 0));
        if ($group === null || !$this->accessService->canRead($group, $context)) {
            return new Response('Not Found', 404);
        }

        $groups = $locate($group, $context);
        if ($groups === null) {
            return new Response('Not Found', 404);
        }

        return $this->json([
            'html' => $this->twig->render('@groups/partials/reactors_list.html.twig', ['groups' => $groups]),
        ]);
    }

    /**
     * The shared shape of both reaction endpoints. $toggle returns null
     * when the item does not belong to the authorised group (→ 404), or a
     * 4-tuple [ReactionOutcome, ?ReactionSummary, string $action, string
     * $reactorsUrl] once the toggle ran — the summary is null for INVALID
     * (→ 400), since nothing was written for it to describe.
     *
     * $reactorsUrl travels with $action rather than being derived from it
     * because the fragment rendered below REPLACES the one the page was
     * served with, tally and all: leaving it out rendered
     * data-reactors-url="" (Twig has strict_variables off), and the
     * "qui a réagi ?" dialog behind that tally then fetched the empty
     * string — that is, the current page — and hung on its spinner
     * waiting for JSON that was never coming. It is only ever reachable
     * after a reaction, which is exactly the path this method renders.
     *
     * A plain form POST still lands here with no special header and gets
     * the same redirect it always has (the no-JS fallback the templates'
     * docblocks promise); groups.js sends `X-Requested-With:
     * XMLHttpRequest` and gets the freshly rendered reactions fragment
     * back instead, so it can swap it in without a page reload.
     *
     * @param array<string, string> $params
     * @param callable(DiscussionGroup, int, string, GroupSessionContext): (null|array{
     *     0: ReactionOutcome,
     *     1: ?ReactionSummary,
     *     2: string,
     *     3: string
     * }) $toggle
     */
    private function reactAction(Request $request, array $params, bool $compact, callable $toggle): Response
    {
        if (($guard = $this->guardCsrf($request, '/groups/' . (int) ($params['id'] ?? 0))) !== null) {
            return $guard;
        }

        $context = $this->context();
        $group = $this->groupRepository->findById((int) ($params['id'] ?? 0));
        if ($group === null || !$this->accessService->canRead($group, $context)) {
            return new Response('Not Found', 404);
        }

        // canParticipate(), not canPost(): a group where only moderators
        // publish is still a group everybody reacts in.
        $permission = $this->accessService->canParticipate($group, $context);
        if (!$permission->allowed) {
            return new Response($permission->message, 403);
        }

        // Which member the reaction is recorded under: the same resolution
        // a post or a reply uses, so an account linked to several members
        // of the group reacts as exactly one of them and the UNIQUE
        // (item, member) index means one reaction, not one per link.
        $allowed = $this->accessService->memberIdsAllowedToPostAs($group, $context);
        $memberId = $allowed[0] ?? 0;
        if ($memberId === 0) {
            return new Response('Aucun membre de ce groupe n\'est associé à votre compte.', 403);
        }

        // Never trusted: Service\ReactionService validates it against
        // Support\Reactions' constant map before writing anything, and
        // returns false for anything else.
        $key = (string) $request->getBody('reaction', '');
        $result = $toggle($group, $memberId, $key, $context);

        if ($result === null) {
            return new Response('Not Found', 404);
        }

        [$outcome, $summary, $action, $reactorsUrl] = $result;
        if ($outcome === ReactionOutcome::INVALID) {
            return new Response('Réaction inconnue.', 400);
        }

        if (!$this->wantsJson($request)) {
            return $this->redirect('/groups/' . $group->id);
        }

        return $this->json([
            'outcome' => $outcome === ReactionOutcome::ADDED ? 'added' : 'removed',
            'html' => $this->twig->render('@groups/partials/reactions.html.twig', [
                'summary' => $summary,
                'action' => $action,
                'reactors_url' => $reactorsUrl,
                'compact' => $compact,
            ]),
        ]);
    }

    /**
     * groups.js sends this header on every reaction fetch() so the
     * endpoint knows to answer with JSON instead of a redirect — a plain
     * form POST (no JS, or JS that failed to load) never sets it and gets
     * the original full-page behaviour unchanged.
     */
    private function wantsJson(Request $request): bool
    {
        return $request->getServer('HTTP_X_REQUESTED_WITH') === 'XMLHttpRequest';
    }

    private function context(): GroupSessionContext
    {
        return $this->contextFactory->build(
            AuthSession::getEmail(),
            AuthSession::getRole(),
            AuthSession::getUserAccountId(),
            ScoutYearSession::getPreviewId()
        );
    }
}
