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
use Modules\Groups\Service\GroupAccessService;
use Modules\Groups\Service\GroupFeedService;
use Modules\Groups\Service\GroupSessionContext;
use Modules\Groups\Service\GroupSessionContextFactory;
use Modules\Groups\Service\PostMediaService;
use Modules\Groups\Service\PostService;
use Twig\Environment;

/**
 * Posting, editing, deleting and pinning, plus the "Charger plus" page of
 * the feed.
 *
 * Every action here re-checks its own authorisation server-side through
 * GroupAccessService and PostService — the kebab menu only decides what is
 * offered, never what is allowed. A caller who is not a member of the
 * group gets 404 on all of them, including the write actions: a 403 would
 * confirm the group exists.
 */
class PostController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private GroupRepository $groupRepository,
        private PostRepository $postRepository,
        private GroupAccessService $accessService,
        private GroupFeedService $feedService,
        private PostService $postService,
        private GroupSessionContextFactory $contextFactory,
        private PostMediaService $postMediaService
    ) {
    }

    /**
     * GET /groups/{id}/feed?cursor=… — one more page of the stream,
     * rendered as the same cards the page itself uses.
     *
     * @param array<string, string> $params
     */
    public function feed(Request $request, array $params): Response
    {
        $context = $this->context();
        $group = $this->readableGroup($params, $context);
        if ($group === null) {
            return new Response('Not Found', 404);
        }

        $canModerate = $this->accessService->canModerate($group, $context);
        $page = $this->feedService->page($group, $context, $canModerate, (string) $request->getQuery('cursor', ''));

        return $this->render('@groups/partials/feed_page.html.twig', [
            'group' => $group,
            'posts' => $page->posts,
            'next_cursor' => $page->nextCursor,
        ]);
    }

    /**
     * POST /groups/{id}/posts
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

        // The closed group, the past year and the incomplete profile are
        // all refused here, not merely hidden in the UI.
        $permission = $this->accessService->canPost($group, $context);
        if (!$permission->allowed) {
            return new Response($permission->message, 403);
        }

        $body = (string) $request->getBody('body', '');
        $files = $request->getFiles('media');

        // The ceiling is checked before anything is written — a post over
        // it is rejected whole, never silently truncated to the first
        // four (module spec).
        if (count($files) > PostMediaService::MAX_MEDIA_PER_POST) {
            FlashMessage::set(
                'error',
                'Vous ne pouvez joindre que ' . PostMediaService::MAX_MEDIA_PER_POST . ' médias au maximum par message.'
            );
            return $this->redirect('/groups/' . $group->id);
        }

        if (!$this->postService->isPostable($body, count($files))) {
            return $this->redirect('/groups/' . $group->id);
        }

        // Which member the post is signed as: only ever one this account
        // is actually a member of this group through. A parent linked to
        // three children cannot post as the one who is not in the group.
        $allowed = $this->accessService->memberIdsAllowedToPostAs($group, $context);
        $requested = (int) $request->getBody('author_member_id', 0);
        $authorMemberId = in_array($requested, $allowed, true) ? $requested : ($allowed[0] ?? 0);
        if ($authorMemberId === 0 || $context->userAccountId === null) {
            return new Response('Aucun membre de ce groupe n\'est associé à votre compte.', 403);
        }

        $postId = $this->postService->create($group, $context->userAccountId, $authorMemberId, $body);

        if ($files !== []) {
            try {
                $this->postMediaService->addMedia($group, $postId, $files, $context->userAccountId);
            } catch (GalleryException $e) {
                // The whole post is rejected, never left half-saved: undo
                // whatever media did make it in before the failing file,
                // then the post itself.
                $this->postMediaService->deleteAllForPost($group, $postId);
                $this->postRepository->delete($postId);
                FlashMessage::set('error', $e->getMessage());
                return $this->redirect('/groups/' . $group->id);
            }
        }

        return $this->redirect('/groups/' . $group->id);
    }

    /**
     * POST /groups/{id}/posts/{postId}/edit — author only, and only
     * inside the 15-minute window, which is recomputed here from the
     * stored created_at.
     *
     * @param array<string, string> $params
     */
    public function edit(Request $request, array $params): Response
    {
        return $this->postAction($params, function (DiscussionGroup $group, Post $post, GroupSessionContext $context) use ($request) {
            if (!$this->postService->canEdit($post, $context)) {
                return new Response('Ce message ne peut plus être modifié.', 403);
            }

            // A closed group (or a past-year one) accepts no write at all,
            // edits included.
            if (!$this->accessService->canPost($group, $context)->allowed) {
                return new Response('Ce groupe n\'accepte plus de modification.', 403);
            }

            $body = (string) $request->getBody('body', '');
            if ($this->postService->isPostable($body)) {
                $this->postService->edit($post, $body);
            }

            return $this->redirect('/groups/' . $group->id);
        });
    }

    /**
     * POST /groups/{id}/posts/{postId}/delete — author or moderator.
     *
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        return $this->postAction($params, function (DiscussionGroup $group, Post $post, GroupSessionContext $context) {
            if (!$this->postService->canDelete($post, $context, $this->accessService->canModerate($group, $context))) {
                return new Response('Vous ne pouvez pas supprimer ce message.', 403);
            }

            // Media first: it must be resolved from the still-existing
            // discussion_group_post_media join rows, which the post's own
            // deletion below cascades away.
            $this->postMediaService->deleteAllForPost($group, $post->id);
            $this->postService->delete($post);

            return $this->redirect('/groups/' . $group->id);
        });
    }

    /**
     * POST /groups/{id}/posts/{postId}/pin — moderator only.
     *
     * @param array<string, string> $params
     */
    public function pin(Request $request, array $params): Response
    {
        return $this->setPinned($params, true);
    }

    /**
     * POST /groups/{id}/posts/{postId}/unpin — moderator only.
     *
     * @param array<string, string> $params
     */
    public function unpin(Request $request, array $params): Response
    {
        return $this->setPinned($params, false);
    }

    /**
     * @param array<string, string> $params
     */
    private function setPinned(array $params, bool $isPinned): Response
    {
        return $this->postAction($params, function (DiscussionGroup $group, Post $post, GroupSessionContext $context) use ($isPinned) {
            if (!$this->accessService->canModerate($group, $context)) {
                return new Response('Seul un modérateur du groupe peut épingler un message.', 403);
            }

            $this->postService->setPinned($post, $isPinned);

            return $this->redirect('/groups/' . $group->id);
        });
    }

    /**
     * The shared shape of every post write: CSRF, membership (404), the
     * post actually belonging to that group (404), then the action's own
     * rule.
     *
     * @param array<string, string> $params
     * @param callable(DiscussionGroup, Post, GroupSessionContext): Response $action
     */
    private function postAction(array $params, callable $action): Response
    {
        if (!CsrfGuard::validateRequest()) {
            return new Response('Jeton CSRF invalide.', 403);
        }

        $context = $this->context();
        $group = $this->readableGroup($params, $context);
        if ($group === null) {
            return new Response('Not Found', 404);
        }

        $post = $this->postRepository->findById((int) ($params['postId'] ?? 0));
        // A post id from another group is a 404, not someone else's post:
        // the group in the URL is the one that was authorised.
        if ($post === null || $post->groupId !== $group->id) {
            return new Response('Not Found', 404);
        }

        return $action($group, $post, $context);
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
