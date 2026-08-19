<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Modules\Gallery\Service\GalleryException;
use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\Post;
use Modules\Groups\Repository\PostRepository;
use Modules\Groups\Repository\Reply;
use Modules\Groups\Repository\ReplyRepository;
use Modules\Groups\Service\GroupAccessService;
use Modules\Groups\Service\GroupSessionContext;
use Modules\Groups\Service\GroupSessionContextFactory;
use Modules\Groups\Service\PostMediaService;
use Modules\Groups\Service\ReplyPresenter;
use Modules\Groups\Service\ReplyService;
use Twig\Environment;

/**
 * Replying to a post, editing and deleting a reply, and the "Charger plus"
 * page of a post's replies.
 *
 * Same 404-not-403 rule as every other route in this module: a caller who
 * is not a member of the group gets 404 on all of them, write actions
 * included, because a 403 would confirm the group exists. The same applies
 * one level down — a reply id belonging to another group's post is a 404
 * here, never someone else's reply.
 */
class ReplyController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private GroupRepository $groupRepository,
        private PostRepository $postRepository,
        private ReplyRepository $replyRepository,
        private GroupAccessService $accessService,
        private ReplyService $replyService,
        private ReplyPresenter $replyPresenter,
        private PostMediaService $postMediaService,
        private GroupSessionContextFactory $contextFactory
    ) {
    }

    /**
     * GET /groups/{id}/posts/{postId}/replies?after=… — one more page of a
     * post's replies, oldest first, rendered as the same cards the feed
     * itself uses.
     *
     * @param array<string, string> $params
     */
    public function page(Request $request, array $params): Response
    {
        $context = $this->context();
        $group = $this->readableGroup($params, $context);
        if ($group === null) {
            return new Response('Not Found', 404);
        }

        $post = $this->postOf($group, $params);
        if ($post === null) {
            return new Response('Not Found', 404);
        }

        $after = (int) $request->getQuery('after', '0');
        // One extra row, to know whether another page exists without a
        // second COUNT — same trick as the feed's own pagination.
        $replies = $this->replyRepository->findPage($post->id, ReplyService::PAGE_SIZE + 1, $after > 0 ? $after : null);
        $hasMore = count($replies) > ReplyService::PAGE_SIZE;
        $replies = array_slice($replies, 0, ReplyService::PAGE_SIZE);

        $rows = $this->replyPresenter->decorate(
            $replies,
            $context,
            $this->accessService->canModerate($group, $context),
            $group->scoutYearId ?? $context->effectiveScoutYearId,
            $this->postMediaService->albumMediaById($group)
        );

        return $this->render('@groups/partials/reply_page.html.twig', [
            'group' => $group,
            'post' => $post,
            'replies' => $rows,
            'replies_next_after_id' => $hasMore && $rows !== [] ? $rows[count($rows) - 1]['reply']->id : null,
        ]);
    }

    /**
     * POST /groups/{id}/posts/{postId}/replies
     *
     * @param array<string, string> $params
     */
    public function create(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateRequest()) {
            return new Response('Jeton CSRF invalide.', 403);
        }

        $context = $this->context();
        $group = $this->readableGroup($params, $context);
        if ($group === null) {
            return new Response('Not Found', 404);
        }

        $post = $this->postOf($group, $params);
        if ($post === null) {
            return new Response('Not Found', 404);
        }

        // A closed group, a past-year one and an incomplete profile refuse
        // a reply exactly as they refuse a post — re-checked here, never
        // merely hidden in the UI.
        $permission = $this->accessService->canPost($group, $context);
        if (!$permission->allowed) {
            return new Response($permission->message, 403);
        }

        $body = (string) $request->getBody('body', '');
        $files = $request->getFiles('image');

        // At most one image per reply, enforced server-side: a request
        // carrying more is rejected whole rather than silently keeping the
        // first (module spec, same posture as a post's media ceiling).
        if (count($files) > 1) {
            FlashMessage::set('error', 'Vous ne pouvez joindre qu\'une seule image par réponse.');
            return $this->redirect('/groups/' . $group->id);
        }

        if (!$this->replyService->isReplyable($body, $files !== [])) {
            return $this->redirect('/groups/' . $group->id);
        }

        $authorMemberId = $this->authorMemberId($group, $context, $request);
        if ($authorMemberId === null || $context->userAccountId === null) {
            return new Response('Aucun membre de ce groupe n\'est associé à votre compte.', 403);
        }

        $mediaId = null;
        if ($files !== []) {
            try {
                $mediaId = $this->postMediaService->addOne($group, $files[0], $context->userAccountId);
            } catch (GalleryException $e) {
                // Nothing has been written yet, so there is nothing to roll
                // back — the reply simply is not created.
                FlashMessage::set('error', $e->getMessage());
                return $this->redirect('/groups/' . $group->id);
            }
        }

        $this->replyService->create($group, $post->id, $context->userAccountId, $authorMemberId, $body, $mediaId);

        return $this->redirect('/groups/' . $group->id);
    }

    /**
     * POST /groups/{id}/replies/{replyId}/edit — author only, and only
     * inside the 15-minute window, recomputed here from the stored
     * created_at.
     *
     * @param array<string, string> $params
     */
    public function edit(Request $request, array $params): Response
    {
        return $this->replyAction($params, function (DiscussionGroup $group, Reply $reply, GroupSessionContext $context) use ($request) {
            if (!$this->replyService->canEdit($reply, $context)) {
                return new Response('Cette réponse ne peut plus être modifiée.', 403);
            }

            if (!$this->accessService->canPost($group, $context)->allowed) {
                return new Response('Ce groupe n\'accepte plus de modification.', 403);
            }

            $body = (string) $request->getBody('body', '');
            // An edit may not empty a reply that has no image to stand on
            // its own — the same "text alone or image alone" rule as
            // creation.
            if ($this->replyService->isReplyable($body, $reply->galleryMediaId !== null)) {
                $this->replyService->edit($reply, $body);
            }

            return $this->redirect('/groups/' . $group->id);
        });
    }

    /**
     * POST /groups/{id}/replies/{replyId}/delete — author or moderator.
     *
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        return $this->replyAction($params, function (DiscussionGroup $group, Reply $reply, GroupSessionContext $context) {
            if (!$this->replyService->canDelete($reply, $context, $this->accessService->canModerate($group, $context))) {
                return new Response('Vous ne pouvez pas supprimer cette réponse.', 403);
            }

            // Deletes the reply's image (another module's table, out of
            // CASCADE reach) before the row itself; the reply's own
            // reactions go with it by CASCADE.
            $this->replyService->delete($reply, $group);

            return $this->redirect('/groups/' . $group->id);
        });
    }

    /**
     * The shared shape of every reply write: CSRF, membership (404), the
     * reply actually belonging to a post of that group (404), then the
     * action's own rule.
     *
     * @param array<string, string> $params
     * @param callable(DiscussionGroup, Reply, GroupSessionContext): Response $action
     */
    private function replyAction(array $params, callable $action): Response
    {
        if (!CsrfGuard::validateRequest()) {
            return new Response('Jeton CSRF invalide.', 403);
        }

        $context = $this->context();
        $group = $this->readableGroup($params, $context);
        if ($group === null) {
            return new Response('Not Found', 404);
        }

        $reply = $this->replyRepository->findById((int) ($params['replyId'] ?? 0));
        if ($reply === null) {
            return new Response('Not Found', 404);
        }

        // The reply must hang off a post of the group named in the URL —
        // the group is what was authorised, so a reply id from anywhere
        // else is a 404, never someone else's reply.
        $post = $this->postRepository->findById($reply->postId);
        if ($post === null || $post->groupId !== $group->id) {
            return new Response('Not Found', 404);
        }

        return $action($group, $reply, $context);
    }

    /**
     * Which member the reply is signed as: only ever one this account is
     * actually a member of this group through, exactly like a post's own
     * author resolution.
     */
    private function authorMemberId(DiscussionGroup $group, GroupSessionContext $context, Request $request): ?int
    {
        $allowed = $this->accessService->memberIdsAllowedToPostAs($group, $context);
        $requested = (int) $request->getBody('author_member_id', 0);
        $memberId = in_array($requested, $allowed, true) ? $requested : ($allowed[0] ?? 0);

        return $memberId === 0 ? null : $memberId;
    }

    /**
     * @param array<string, string> $params
     */
    private function postOf(DiscussionGroup $group, array $params): ?Post
    {
        $post = $this->postRepository->findById((int) ($params['postId'] ?? 0));

        return $post !== null && $post->groupId === $group->id ? $post : null;
    }

    /**
     * @param array<string, string> $params
     */
    private function readableGroup(array $params, GroupSessionContext $context): ?DiscussionGroup
    {
        $group = $this->groupRepository->findById((int) ($params['id'] ?? 0));
        if ($group === null || !$this->accessService->canRead($group, $context)) {
            return null;
        }

        return $group;
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
