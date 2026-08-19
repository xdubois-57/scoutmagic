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
use Modules\Groups\Service\AuthorOptionsService;
use Modules\Groups\Service\GroupAccessService;
use Modules\Groups\Service\GroupFeedService;
use Modules\Groups\Service\GroupSessionContext;
use Modules\Groups\Service\GroupSessionContextFactory;
use Modules\Groups\Service\GroupsException;
use Modules\Groups\Service\PostLinkService;
use Modules\Groups\Service\PostMediaService;
use Modules\Groups\Service\PostService;
use Modules\Groups\Service\ReplyService;
use Modules\Groups\Service\ReportService;
use Modules\Groups\Support\RejectedDraft;
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
        private PostMediaService $postMediaService,
        private PostLinkService $postLinkService,
        private ReplyService $replyService,
        private AuthorOptionsService $authorOptionsService,
        private ReportService $reportService
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
            // The post cards this renders carry a reply composer, exactly
            // like the ones on the group page — so this page has to supply
            // the same two values that decide whether it appears and who it
            // may sign as. Without them a "Charger plus" page would quietly
            // render posts you cannot reply to.
            'post_permission' => $this->accessService->canPost($group, $context),
            'author_options' => $this->authorOptionsService->forGroup($group, $context),
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
        $link = trim((string) $request->getBody('link', ''));

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

        // Same "reject the whole post rather than silently drop it"
        // posture as the media ceiling above — a link the member actually
        // typed is either attached or the post is refused, never posted
        // with the link quietly missing.
        if ($link !== '' && !PostLinkService::isValidUrl($link)) {
            FlashMessage::set('error', 'Le lien saisi n\'est pas une adresse web valide.');
            return $this->redirect('/groups/' . $group->id);
        }

        if (!$this->postService->isPostable($body, count($files), $link !== '')) {
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

        try {
            $postId = $this->postService->create($group, $context->userAccountId, $authorMemberId, $body);
        } catch (GroupsException $e) {
            return $this->refuse($e, $group->id, $body, null);
        }

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

        if ($link !== '') {
            // Never throws — a throttled member, an unreachable page, or
            // one with no Open Graph tags all still attach a (plain)
            // link, so there is nothing here to roll the post back for.
            $this->postLinkService->attach($group, $postId, $link, $authorMemberId, $context->userAccountId);
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
                try {
                    $this->postService->edit($post, $body);
                } catch (GroupsException $e) {
                    return $this->refuse($e, $group->id, $body, null);
                }
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
            $canModerate = $this->accessService->canModerate($group, $context);
            if (!$this->postService->canDelete($post, $context, $canModerate)) {
                return new Response('Vous ne pouvez pas supprimer ce message.', 403);
            }

            // Journaled before the row goes, and only when it is a
            // moderator acting on someone else's message — an author
            // deleting their own is an ordinary edit, not a moderation
            // decision. Ids only, never the message's text.
            if ($canModerate && $post->authorUserAccountId !== $context->userAccountId) {
                $this->reportService->journalModeratorDeletion('post', $group->id, $post->id, $context->userAccountId);
            }

            // Everything living outside this post row's own CASCADE reach
            // goes first, while the rows pointing at it still exist: its
            // media, its link's cached image, and its replies' images.
            // The reply rows themselves — and every reaction on the post
            // and on those replies — are removed by the CASCADE
            // (schema.sql), which is why nothing here touches them.
            $this->postMediaService->deleteAllForPost($group, $post->id);
            $this->postLinkService->deleteForPost($post->id);
            $this->replyService->deleteAllMediaForPost($group, $post->id);
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
     * A refused write, told to its author and to nobody else: the reason
     * in a flash, and their own text plus the suggested rewording handed
     * back through the session so the composer is not emptied.
     *
     * Nothing here is written to the database or journaled — the refused
     * text exists only in this member's own session, for one render
     * (Support\RejectedDraft).
     */
    private function refuse(GroupsException $e, int $groupId, string $body, ?int $postId): Response
    {
        FlashMessage::set('error', $e->getMessage());
        if ($e->type === GroupsException::TYPE_OFFENSIVE) {
            RejectedDraft::set($body, $e->suggestion, $postId);
        }

        return $this->redirect('/groups/' . $groupId);
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
