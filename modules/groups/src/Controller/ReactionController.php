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
        private ?GroupNotificationService $notificationService = null
    ) {
    }

    /**
     * POST /groups/{id}/posts/{postId}/react
     *
     * @param array<string, string> $params
     */
    public function react(Request $request, array $params): Response
    {
        return $this->reactAction($request, $params, function (DiscussionGroup $group, int $memberId, string $key, GroupSessionContext $context) use ($params) {
            $post = $this->postRepository->findById((int) ($params['postId'] ?? 0));
            if ($post === null || $post->groupId !== $group->id) {
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

            return $outcome;
        });
    }

    /**
     * POST /groups/{id}/replies/{replyId}/react
     *
     * @param array<string, string> $params
     */
    public function reactToReply(Request $request, array $params): Response
    {
        return $this->reactAction($request, $params, function (DiscussionGroup $group, int $memberId, string $key, GroupSessionContext $context) use ($params) {
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

            return $outcome;
        });
    }

    /**
     * The shared shape of both reaction endpoints. $toggle returns null
     * when the item does not belong to the authorised group (→ 404), or
     * the toggle's own outcome (INVALID → 400).
     *
     * @param array<string, string> $params
     * @param callable(DiscussionGroup, int, string, GroupSessionContext): ?ReactionOutcome $toggle
     */
    private function reactAction(Request $request, array $params, callable $toggle): Response
    {
        if (!CsrfGuard::validateRequest()) {
            return new Response('Jeton CSRF invalide.', 403);
        }

        $context = $this->context();
        $group = $this->groupRepository->findById((int) ($params['id'] ?? 0));
        if ($group === null || !$this->accessService->canRead($group, $context)) {
            return new Response('Not Found', 404);
        }

        $permission = $this->accessService->canPost($group, $context);
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
        if ($result === ReactionOutcome::INVALID) {
            return new Response('Réaction inconnue.', 400);
        }

        return $this->redirect('/groups/' . $group->id);
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
