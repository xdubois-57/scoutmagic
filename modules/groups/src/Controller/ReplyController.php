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
use Modules\Gallery\Api\GalleryException;
use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\Post;
use Modules\Groups\Repository\PostRepository;
use Modules\Groups\Repository\Reply;
use Modules\Groups\Repository\ReplyRepository;
use Modules\Groups\Service\GroupAccessService;
use Modules\Groups\Service\GroupSessionContext;
use Modules\Groups\Service\GroupNotificationService;
use Modules\Groups\Service\GroupSessionContextFactory;
use Modules\Groups\Service\GroupsException;
use Modules\Groups\Service\MentionService;
use Modules\Groups\Service\PostMediaService;
use Modules\Groups\Service\ReplyPresenter;
use Modules\Groups\Service\ReplyService;
use Modules\Groups\Service\ReportService;
use Modules\Groups\Support\RejectedDraft;
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
        private GroupSessionContextFactory $contextFactory,
        private ReportService $reportService,
        private ?GroupNotificationService $notificationService = null,
        private ?MentionService $mentionService = null
    ) {
    }

    /**
     * GET /groups/{id}/posts/{postId}/replies?before=… — the page of a
     * post's replies immediately OLDER than the cursor, rendered as the
     * same cards the feed itself uses.
     *
     * Backwards, because that is the direction a conversation is read
     * back in: the thread opens on what was just said, and this fetches
     * what came before it (Repository\ReplyRepository::findLastForPosts()).
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

        $canModerate = $this->accessService->canModerate($group, $context);

        $before = (int) $request->getQuery('before', '0');
        if ($before <= 0) {
            return new Response('Bad Request', 400);
        }

        // One extra row, to know whether another page exists without a
        // second COUNT — same trick as the feed's own pagination. And the
        // same rule as the feed on auto-hidden replies: excluded in the
        // SQL for everyone but a moderator, so this page can never be the
        // way a member reaches one.
        $replies = $this->replyRepository->findPageBefore(
            $post->id,
            ReplyService::PAGE_SIZE + 1,
            $before,
            $canModerate
        );
        $hasMore = count($replies) > ReplyService::PAGE_SIZE;
        // The extra row is the OLDEST one here, so it comes off the FRONT
        // — dropping it from the end would silently discard a reply the
        // reader was owed and leave a hole in the middle of the thread.
        $replies = $hasMore ? array_slice($replies, 1) : $replies;

        $rows = $this->replyPresenter->decorate(
            $replies,
            $context,
            $canModerate,
            $group->scoutYearId ?? $context->effectiveScoutYearId,
            $this->postMediaService->albumMediaById($group)
        );

        return $this->render('@groups/partials/reply_page.html.twig', [
            'group' => $group,
            'post' => $post,
            'replies' => $rows,
            'replies_prev_before_id' => $hasMore && $rows !== [] ? $rows[0]['reply']->id : null,
        ]);
    }

    /**
     * POST /groups/{id}/posts/{postId}/replies
     *
     * @param array<string, string> $params
     */
    public function create(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/groups/' . (int) ($params['id'] ?? 0))) !== null) {
            return $guard;
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
        // merely hidden in the UI. The group's posting policy is the one
        // thing that does NOT refuse it: commenting stays open to every
        // member of a moderators-only group (canParticipate() versus
        // canPost(), Service\GroupAccessService).
        $permission = $this->accessService->canParticipate($group, $context);
        if (!$permission->allowed) {
            return $this->forbidden($permission->message, $request);
        }

        $body = (string) $request->getBody('body', '');
        $files = $request->getFiles('image');

        // At most one image per reply, enforced server-side: a request
        // carrying more is rejected whole rather than silently keeping the
        // first (module spec, same posture as a post's media ceiling).
        if (count($files) > 1) {
            return $this->failure($request, $group->id, 'Vous ne pouvez joindre qu\'une seule image par réponse.');
        }

        if (!$this->replyService->isReplyable($body, $files !== [])) {
            // Silent for a plain form POST, exactly as before; AJAX still
            // gets a distinguishable non-2xx with no flash message to
            // match — see Controller\PostController::create()'s own
            // identical empty-body path.
            if ($this->wantsJson($request)) {
                return $this->json(['error' => 'empty'], 400);
            }

            return $this->redirect('/groups/' . $group->id);
        }

        $authorMemberId = $this->accessService->authorMemberIdFor($group, $context);
        if ($authorMemberId === null || $context->userAccountId === null) {
            return $this->forbidden(GroupAccessService::NO_AUTHOR_MEMBER_MESSAGE, $request);
        }

        $mediaId = null;
        if ($files !== []) {
            try {
                $mediaId = $this->postMediaService->addOne($group, $files[0], $context->userAccountId);
            } catch (GalleryException $e) {
                // Nothing has been written yet, so there is nothing to roll
                // back — the reply simply is not created.
                return $this->failure($request, $group->id, $e->getMessage());
            }
        }

        try {
            $replyId = $this->replyService->create($group, $post->id, $context->userAccountId, $authorMemberId, $body,
                $mediaId);
        } catch (GroupsException $e) {
            // The image was uploaded before the reply row existed, so a
            // refusal here has to take it back out again — otherwise a
            // rejected reply would leave its picture in the group album.
            if ($mediaId !== null) {
                $this->postMediaService->deleteOne($group, $mediaId);
            }

            return $this->refuse($request, $e, $group->id, $body, $post->id);
        }

        // The post's author is told someone answered them — never
        // themselves, which Service\GroupNotificationService refuses on
        // its own rather than relying on this call site remembering.
        $reply = $this->replyRepository->findById($replyId);
        if ($reply !== null) {
            $this->notificationService?->replyReceived($group, $post, $reply, $context->effectiveScoutYearId);
            // Where a mention earns its keep most: a reply otherwise
            // reaches the post's author and nobody else, so a question
            // aimed at a third person three replies down a thread used to
            // reach no one at all.
            $this->notifyMentions($group, $post, $reply, $context);
        }

        // groups.js appends this fragment straight under the post instead
        // of reloading the page (module spec: "no reload to add a
        // reply") — same shape as a single reply already gets from
        // Service\ReplyPresenter for a whole page, just for the one just
        // created.
        if ($this->wantsJson($request) && $reply !== null) {
            return $this->json(['html' => $this->renderReplyCard($group, $reply, $context)]);
        }

        return $this->redirect('/groups/' . $group->id);
    }

    /**
     * One reply, rendered exactly as a whole page of them would be —
     * groups.js appends it under the post (a new reply) or swaps the
     * existing card for it (an edit) instead of reloading.
     *
     * Goes through Service\ReplyPresenter rather than building the row
     * here, so a single reply can never disagree with the same reply
     * rendered as part of a page about, say, whether its edit window is
     * still open.
     */
    private function renderReplyCard(DiscussionGroup $group, Reply $reply, GroupSessionContext $context): string
    {
        $rows = $this->replyPresenter->decorate(
            [$reply],
            $context,
            $this->accessService->canModerate($group, $context),
            $group->scoutYearId ?? $context->effectiveScoutYearId,
            $this->postMediaService->albumMediaById($group)
        );

        return $this->twig->render('@groups/partials/reply_card.html.twig', [
            'row' => $rows[0],
            'group' => $group,
        ]);
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
        return $this->replyAction($request, $params, function (
            DiscussionGroup $group,
            Reply $reply,
            GroupSessionContext $context
        ) use ($request) {
            if (!$this->replyService->canEdit($reply, $context)) {
                return $this->forbidden('Cette réponse ne peut plus être modifiée.', $request);
            }

            if (!$this->accessService->canParticipate($group, $context)->allowed) {
                return $this->forbidden('Ce groupe n\'accepte plus de modification.', $request);
            }

            $body = (string) $request->getBody('body', '');
            // An edit may not empty a reply that has no image to stand on
            // its own — the same "text alone or image alone" rule as
            // creation.
            if ($this->replyService->isReplyable($body, $reply->galleryMediaId !== null)) {
                // Read before the write: whoever this reply already named
                // has been told once, and an edit must not tell them
                // again — only whoever the edit newly names.
                $alreadyMentioned = $this->mentionService?->resolve(
                    $group,
                    $reply->body,
                    $context->effectiveScoutYearId
                ) ?? [];

                try {
                    $this->replyService->edit($reply, $body);
                } catch (GroupsException $e) {
                    return $this->refuse($request, $e, $group->id, $body, $reply->postId);
                }

                $post = $this->postRepository->findById($reply->postId);
                $edited = $this->replyRepository->findById($reply->id);
                if ($post !== null && $edited !== null) {
                    $this->notifyMentions($group, $post, $edited, $context, $alreadyMentioned);
                }
            }

            // Re-read so the fragment carries the edited body and the
            // "modifié" marker, rather than the stale instance the action
            // was resolved from.
            $updated = $this->replyRepository->findById($reply->id);
            if ($this->wantsJson($request) && $updated !== null) {
                return $this->json(['html' => $this->renderReplyCard($group, $updated, $context)]);
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
        return $this->replyAction($request, $params, function (
            DiscussionGroup $group,
            Reply $reply,
            GroupSessionContext $context
        ) use ($request) {
            $canModerate = $this->accessService->canModerate($group, $context);
            if (!$this->replyService->canDelete($reply, $context, $canModerate)) {
                return $this->forbidden('Vous ne pouvez pas supprimer cette réponse.', $request);
            }

            // Same rule as a post's: recorded only when a moderator
            // removes someone else's reply, and with ids only.
            if ($canModerate && $reply->authorUserAccountId !== $context->userAccountId) {
                $this->reportService->journalModeratorDeletion(
                    'reply',
                    $group->id,
                    $reply->id,
                    $context->userAccountId
                );
            }

            // Deletes the reply's image (another module's table, out of
            // CASCADE reach) before the row itself; the reply's own
            // reactions go with it by CASCADE.
            $this->replyService->delete($reply, $group);

            // groups.js removes the card from the DOM instead of
            // reloading — same shape as a post's own deletion.
            if ($this->wantsJson($request)) {
                return $this->json(['deleted' => true]);
            }

            return $this->redirect('/groups/' . $group->id);
        });
    }

    /**
     * A refused write, told to its author only — the exact counterpart of
     * Controller\PostController::refuse(), AJAX branch included: the
     * exception's own shape (message/type/suggestion) as JSON for
     * groups.js to show inline, RejectedDraft's session round-trip for a
     * plain form POST only. Nothing here is ever written to the database
     * or journaled either way.
     */
    private function refuse(Request $request, GroupsException $e, int $groupId, string $body, int $postId): Response
    {
        if ($this->wantsJson($request)) {
            return $this->json([
                'error' => $e->getMessage(),
                'type' => $e->type,
                'suggestion' => $e->suggestion,
            ], 422);
        }

        FlashMessage::set('error', $e->getMessage());
        if ($e->type === GroupsException::TYPE_OFFENSIVE) {
            RejectedDraft::set($body, $e->suggestion, $postId);
        }

        return $this->redirect('/groups/' . $groupId);
    }

    /**
     * A write refused with a plain, human message — the exact counterpart
     * of Controller\PostController::failure().
     */
    private function failure(Request $request, int $groupId, string $message, int $status = 400): Response
    {
        if ($this->wantsJson($request)) {
            return $this->json(['error' => $message], $status);
        }

        FlashMessage::set('error', $message);

        return $this->redirect('/groups/' . $groupId);
    }

    /**
     * groups.js sends this header on the reply form's fetch() — same
     * signal as Controller\ReactionController's own wantsJson().
     */
    private function wantsJson(Request $request): bool
    {
        return $request->getServer('HTTP_X_REQUESTED_WITH') === 'XMLHttpRequest';
    }

    /**
     * The shared shape of every reply write: CSRF, membership (404), the
     * reply actually belonging to a post of that group (404), then the
     * action's own rule.
     *
     * @param array<string, string> $params
     * @param callable(DiscussionGroup, Reply, GroupSessionContext): Response $action
     */
    private function replyAction(Request $request, array $params, callable $action): Response
    {
        if (($guard = $this->guardCsrf($request, '/groups/' . (int) ($params['id'] ?? 0))) !== null) {
            return $guard;
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
    /**
     * Notifies whoever this reply's STORED body names after an "@" — the
     * reply's own text, resolved server-side (Service\MentionService),
     * never a list of ids the request claimed.
     *
     * The deep link points at the POST, because that is where the reply
     * lives and what a member needs to open to answer it.
     *
     * @param int[] $alreadyNotified member ids to leave alone, so an edit
     *        only tells the people the edit newly named
     */
    private function notifyMentions(
        DiscussionGroup $group,
        Post $post,
        Reply $reply,
        GroupSessionContext $context,
        array $alreadyNotified = []
    ): void {
        if ($this->mentionService === null || $this->notificationService === null || $context->userAccountId === null) {
            return;
        }

        $this->notificationService->mentioned(
            $group,
            $post->id,
            array_values(array_diff(
                $this->mentionService->resolve($group, $reply->body, $context->effectiveScoutYearId),
                $alreadyNotified
            )),
            $reply->body,
            $reply->isHidden() || $post->isHidden(),
            $context->userAccountId,
            $context->effectiveScoutYearId
        );
    }

    /**
     * The one place this module turns "not a member" into a response, so
     * the 404-not-403 rule is applied identically everywhere.
     *
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
