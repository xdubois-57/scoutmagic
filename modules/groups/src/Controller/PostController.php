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
use Modules\Groups\Service\GroupNotificationService;
use Modules\Groups\Service\GroupSessionContext;
use Modules\Groups\Service\GroupSessionContextFactory;
use Modules\Groups\Service\GroupsException;
use Modules\Groups\Service\MemberIdentityService;
use Modules\Groups\Service\MentionService;
use Modules\Groups\Service\PostLinkService;
use Modules\Groups\Service\PollService;
use Modules\Groups\Service\PostEventService;
use Modules\Groups\Service\PostMediaService;
use Modules\Groups\Service\PostService;
use Modules\Groups\Service\ReplyService;
use Modules\Groups\Service\ReportService;
use Modules\Groups\Service\SeenByService;
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
        private ReportService $reportService,
        private ?GroupNotificationService $notificationService = null,
        private ?SeenByService $seenByService = null,
        private ?MentionService $mentionService = null,
        private ?PostEventService $eventService = null,
        private ?PollService $pollService = null,
        // Optional and trailing, like every other collaborator here: with
        // no identity service the member-scoped poll picker still works,
        // it just names its options by member id.
        private ?MemberIdentityService $identityService = null
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
            // The post cards this renders carry a reply composer and the
            // polls' own controls, exactly like the ones on the group
            // page — so this page has to supply the same values that
            // decide whether they appear. Without them a "Charger plus"
            // page would quietly render posts you cannot reply to.
            'post_permission' => $this->accessService->canPost($group, $context),
            // The weaker of the two, and the one the cards actually read
            // — see GroupController::show().
            'participate_permission' => $this->accessService->canParticipate($group, $context),
            'vote_members' => $this->voteMemberOptions($group, $context),
            // Which message the pin confirmation says will lose its pin
            // — resolved only for a moderator, who is the only reader
            // ever offered the control (Service\PostService::pinnedLabel()).
            'pinned_post_label' => $canModerate ? $this->postService->pinnedLabel($group->id) : '',
        ]);
    }

    /**
     * GET /groups/{id}/mention-search?q=… — the group's own members
     * matching what is being typed after an "@", for the composer's
     * autocomplete.
     *
     * Any member of the group may call it, unlike the invite box's
     * member-search (moderator only): the names it returns are the names
     * of people this caller already sees in the group, so it discloses
     * nothing the members page does not. It is still scoped to this
     * group's membership and never to the unit at large — an autocomplete
     * that searched everybody would turn a discussion group into a
     * directory.
     *
     * @param array<string, string> $params
     */
    public function mentionSearch(Request $request, array $params): Response
    {
        $context = $this->context();
        $group = $this->readableGroup($params, $context);
        if ($group === null || $this->mentionService === null) {
            return new Response('Not Found', 404);
        }

        return $this->json($this->mentionService->suggest(
            $group,
            (string) $request->getQuery('q', ''),
            $context->effectiveScoutYearId
        ));
    }

    /**
     * GET /groups/{id}/posts/{postId}/seen-by — the names behind the
     * "vu par N" line, as the same {html} shape the reactors dialog uses.
     *
     * Restricted to the post's OWN author, and that restriction is the
     * feature's whole design rather than a detail of it. A read mark says
     * where a member has been in a conversation; the one person with a
     * legitimate claim on that is the one asking whether their own
     * message reached anybody. A moderator gets no privileged view here
     * either — moderation is about what was said, not about who was
     * reading — so anyone else, moderator included, gets the module's
     * usual 404 rather than a 403 that would confirm the post exists.
     *
     * @param array<string, string> $params
     */
    public function seenBy(Request $request, array $params): Response
    {
        $context = $this->context();
        $group = $this->readableGroup($params, $context);
        if ($group === null || $this->seenByService === null) {
            return new Response('Not Found', 404);
        }

        $post = $this->postRepository->findById((int) ($params['postId'] ?? 0));
        if ($post === null || $post->groupId !== $group->id) {
            return new Response('Not Found', 404);
        }

        // The author is the LOGIN that wrote it (a post belongs to the
        // identified address, never to the membership it was signed
        // with), so "did my message reach anyone" is that login's
        // question and nobody else's — another address reaching the same
        // member did not write it.
        if ($context->userAccountId === null || $post->authorUserAccountId !== $context->userAccountId) {
            return new Response('Not Found', 404);
        }

        return $this->json([
            'html' => $this->twig->render('@groups/partials/seen_by_list.html.twig', [
                'names' => $this->seenByService->namesForPost(
                    $group,
                    $post,
                    $group->scoutYearId ?? $context->effectiveScoutYearId
                ),
            ]),
        ]);
    }

    /**
     * POST /groups/{id}/posts
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
            return $this->failure(
                $request,
                $group->id,
                'Vous ne pouvez joindre que ' . PostMediaService::MAX_MEDIA_PER_POST . ' médias au maximum par message.'
            );
        }

        // No manual "Lien" field any more (module spec): the first URL
        // typed in the body becomes the post's link, exactly as an
        // explicitly-entered one always has — and is removed from the
        // stored text once it is going to be represented as a preview
        // card instead (firstUrlIn() already only ever returns something
        // isValidUrl() accepts, so there is nothing left to reject here).
        $link = PostLinkService::firstUrlIn($body) ?? '';
        if ($link !== '') {
            $body = PostLinkService::stripUrl($body, $link);
        }

        // A poll's question is text of its own, so a post that carries
        // one is never "empty" even with no body, no media and no link.
        // Normalised before that check rather than after, so a half-filled
        // poll (a question with one option) does not keep an otherwise
        // empty post alive.
        $poll = $this->pollService?->normalise(
            (string) $request->getBody('poll_question', ''),
            // An <input name="poll_options[]"> row set, so already an
            // array — cast for the case where nothing at all was
            // submitted, or something that is not one was.
            (array) $request->getBody('poll_options', []),
            // Both read from the composer rather than left to their
            // defaults: the two controls exist in show.html.twig, and a
            // request that carries neither still lands on exactly those
            // defaults (one answer per login, a single choice) inside
            // normalise().
            (string) $request->getBody('poll_vote_scope', PollService::SCOPE_ACCOUNT),
            (string) $request->getBody('poll_allow_multiple', '') !== ''
        );

        if (!$this->postService->isPostable($body, count($files), $link !== '', $poll !== null)) {
            // Silent for a plain form POST, exactly as before — the
            // composer already disables its own submit button on an empty
            // draft, so reaching here at all means something bypassed the
            // UI. AJAX still gets a distinguishable non-2xx, with no flash
            // message to match.
            if ($this->wantsJson($request)) {
                return $this->json(['error' => 'empty'], 400);
            }

            return $this->redirect('/groups/' . $group->id);
        }

        // A post belongs to the LOGIN that wrote it, and it is signed
        // with that account's name — so there is nothing to choose and no
        // "publier en tant que" any more. The membership stored beside it
        // is still needed by the machinery keyed on one (the rate limit,
        // the read state, "vu par"), and is resolved here rather than
        // asked for: whichever of this account's members belongs to this
        // group.
        $allowed = $this->accessService->memberIdsAllowedToPostAs($group, $context);
        $authorMemberId = $allowed[0] ?? 0;
        if ($authorMemberId === 0 || $context->userAccountId === null) {
            return new Response('Aucun membre de ce groupe n\'est associé à votre compte.', 403);
        }

        try {
            $postId = $this->postService->create($group, $context->userAccountId, $authorMemberId, $body);
        } catch (GroupsException $e) {
            return $this->refuse($request, $e, $group->id, $body, null);
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
                return $this->failure($request, $group->id, $e->getMessage());
            }
        }

        if ($link !== '') {
            // Never throws — a throttled member, an unreachable page, or
            // one with no Open Graph tags all still attach a (plain)
            // link, so there is nothing here to roll the post back for.
            $this->postLinkService->attach($group, $postId, $link, $authorMemberId, $context->userAccountId);
        }

        if ($poll !== null) {
            // Not nullsafe: $poll is itself the result of a call on
            // $this->pollService, so reaching here proves it is there.
            $this->pollService->attachTo($postId, $poll);
        }

        // The submitted id is re-resolved against the calendar's own
        // visibility rules before anything is stored — never trusted, and
        // never joined to. An id naming an event this member may not see
        // resolves to null and the post simply carries no event.
        $eventId = $this->eventService?->resolveSubmitted(
            (int) $request->getBody('calendar_event_id', 0) ?: null,
            $context->role
        );
        if ($eventId !== null) {
            $this->postRepository->setCalendarEventId($postId, $eventId);
        }

        // Last, and only once the post is complete: notifying about a
        // post whose media upload then failed and rolled it back would
        // deep-link everyone to a 404. Never throws (see
        // Service\GroupNotificationService) — a post that published fine
        // is not undone because a notification could not go out.
        $created = $this->postRepository->findById($postId);
        if ($created !== null) {
            $this->notificationService?->postPublished($group, $created, $context->effectiveScoutYearId);
            $this->notifyMentions($group, $created->id, $created->body, $created->isHidden(), $context);
        }

        // groups.js inserts this fragment straight into the feed instead
        // of reloading the page — the composer's own submit is the whole
        // reason this endpoint gets an AJAX path at all (module spec: "no
        // reload to publish a post"). $created is only null on a
        // find-right-after-insert race so implausible no test exercises
        // it; falling back to the redirect is still correct either way.
        if ($this->wantsJson($request) && $created !== null) {
            $canModerate = $this->accessService->canModerate($group, $context);
            $row = $this->feedService->rowForNewPost($group, $created, $context, $canModerate);

            return $this->json([
                'html' => $this->twig->render('@groups/partials/post_card.html.twig', [
                    'row' => $row,
                    'group' => $group,
                    'post_permission' => $permission,
                    'participate_permission' => $this->accessService->canParticipate($group, $context),
                    'vote_members' => $this->voteMemberOptions($group, $context),
                    'pinned_post_label' => $canModerate ? $this->postService->pinnedLabel($group->id) : '',
                ]),
                // What groups.js scrolls to once the card is in the feed
                // — the anchor the card itself carries, so the two can
                // never name different things.
                'post_id' => $created->id,
            ]);
        }

        // The no-JavaScript path lands on the same message, by the same
        // anchor: a plain form post reloads the group, and without the
        // fragment the member is left at the top of a page whose new
        // message is somewhere below the composer.
        return $this->redirect('/groups/' . $group->id . ($created !== null ? '#post-' . $created->id : ''));
    }

    /**
     * POST /groups/{id}/link-preview — the live card groups.js shows
     * while composing (module spec: no manual "Lien" field; the first URL
     * typed anywhere in the draft is detected and previewed automatically
     * before the post is ever saved). Same write-permission boundary as
     * create() — this consumes the same per-member outbound-fetch
     * throttle a real post's own link would (Service\PostLinkService::
     * livePreview()'s own docblock), so a caller who could not post here
     * must not be able to spend that budget either.
     *
     * @param array<string, string> $params
     */
    public function linkPreview(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrfJson($request)) !== null) {
            return $guard;
        }

        $context = $this->context();
        $group = $this->readableGroup($params, $context);
        if ($group === null) {
            return new Response('Not Found', 404);
        }

        $permission = $this->accessService->canPost($group, $context);
        if (!$permission->allowed) {
            return new Response($permission->message, 403);
        }

        $allowed = $this->accessService->memberIdsAllowedToPostAs($group, $context);
        $memberId = $allowed[0] ?? 0;
        if ($memberId === 0) {
            return new Response('Aucun membre de ce groupe n\'est associé à votre compte.', 403);
        }

        $url = PostLinkService::firstUrlIn((string) $request->getBody('body', ''));
        if ($url === null) {
            return $this->json(['url' => null]);
        }

        return $this->json($this->postLinkService->livePreview($url, $memberId));
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
        return $this->postAction($request, $params, function (DiscussionGroup $group, Post $post, GroupSessionContext $context) use ($request) {
            if (!$this->postService->canEdit($post, $context)) {
                return new Response('Ce message ne peut plus être modifié.', 403);
            }

            // A closed group (or a past-year one) accepts no write at all,
            // edits included. canParticipate(), not canPost(): correcting
            // a message you already published is not starting a
            // conversation, so a group that later moved to
            // moderators-only must not strand its author's own typo.
            if (!$this->accessService->canParticipate($group, $context)->allowed) {
                return new Response('Ce groupe n\'accepte plus de modification.', 403);
            }

            $body = (string) $request->getBody('body', '');
            if ($this->postService->isPostable($body)) {
                // Read BEFORE the write: whoever the message already named
                // has already been told, and must not be told again
                // because a typo elsewhere in it was corrected.
                $alreadyMentioned = $this->mentionService?->resolve($group, $post->body, $context->effectiveScoutYearId) ?? [];

                try {
                    $this->postService->edit($post, $body);
                } catch (GroupsException $e) {
                    return $this->refuse($request, $e, $group->id, $body, null);
                }

                $edited = $this->postRepository->findById($post->id);
                if ($edited !== null) {
                    $this->notifyMentions($group, $edited->id, $edited->body, $edited->isHidden(), $context, $alreadyMentioned);
                }
            }

            // Only the body comes back, never the whole card: groups.js
            // swaps this one <p> in place, so the reply thread the member
            // had expanded underneath survives the edit. Re-read so the
            // fragment carries what was actually stored (the moderation
            // layer may have normalised it) rather than what was posted.
            $updated = $this->postRepository->findById($post->id);
            if ($this->wantsJson($request) && $updated !== null) {
                return $this->json([
                    'html' => $this->twig->render('@groups/partials/post_body.html.twig', ['post' => $updated]),
                ]);
            }

            return $this->redirect('/groups/' . $group->id);
        });
    }

    /**
     * POST /groups/{id}/posts/{postId}/vote — one member's answer to the
     * poll on that post.
     *
     * Gated on canParticipate(), not merely on canRead(): voting is
     * writing, and a closed group or a past scout year refuses it for
     * exactly the same reasons it refuses a reply. That is also why a
     * poll carries no "closed" flag of its own — schema.sql says so.
     *
     * Answering is deliberately NOT canPost(): a group where only
     * moderators publish still asks its members questions, and a poll
     * nobody may answer would be a poll for nobody.
     *
     * The option id is re-checked against the post's own poll inside
     * Service\PollService, so a hand-made request cannot vote for an
     * option belonging to another poll.
     *
     * @param array<string, string> $params
     */
    public function vote(Request $request, array $params): Response
    {
        return $this->postAction($request, $params, function (DiscussionGroup $group, Post $post, GroupSessionContext $context) use ($request): Response {
            $permission = $this->accessService->canParticipate($group, $context);
            if (!$permission->allowed) {
                return new Response($permission->message, 403);
            }

            // WHO the answer is recorded as belongs to the poll, not to
            // this request: an account-scoped poll records the login, a
            // member-scoped one records one of this account's members —
            // and Service\PollService re-checks that it is one of theirs
            // whatever the form said (voter_member_id below is a
            // request, never an authority).
            $allowed = $this->accessService->memberIdsAllowedToPostAs($group, $context);
            if ($this->pollService === null) {
                return new Response('Ce message ne porte pas de sondage.', 400);
            }

            $recorded = $this->pollService->vote(
                $post->id,
                (int) $request->getBody('option_id', 0),
                $context->userAccountId,
                (int) $request->getBody('voter_member_id', 0),
                $allowed
            );
            if (!$recorded) {
                return new Response('Ce choix n\'existe pas.', 400);
            }

            // groups.js swaps the poll fragment in place; a plain form
            // POST still gets the redirect it always would, so voting
            // works with no JavaScript at all.
            if ($this->wantsJson($request)) {
                $poll = $this->pollService->forPosts([$post->id], $context->userAccountId, $allowed)[$post->id] ?? null;

                return $this->json([
                    'html' => $this->twig->render('@groups/partials/poll.html.twig', [
                        'poll' => $poll,
                        'group' => $group,
                        'post' => $post,
                        'can_vote' => true,
                        'vote_members' => $this->voteMemberOptions($group, $context),
                    ]),
                ]);
            }

            return $this->redirect('/groups/' . $group->id . '#post-' . $post->id);
        });
    }

    /**
     * POST /groups/{id}/posts/{postId}/delete — author or moderator.
     *
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        return $this->postAction($request, $params, function (DiscussionGroup $group, Post $post, GroupSessionContext $context) use ($request) {
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

            // groups.js removes the post's <article> from the DOM instead
            // of reloading (module spec: "delete must be instant"); the
            // confirm() dialog still runs first, client-side, exactly as
            // it always has (data-confirm — this is just what the
            // confirmed submission does afterward).
            if ($this->wantsJson($request)) {
                return $this->json(['deleted' => true]);
            }

            return $this->redirect('/groups/' . $group->id);
        });
    }

    /**
     * POST /groups/{id}/posts/{postId}/pin — moderator only.
     *
     * Pinning is exclusive: whatever this group had pinned stops being
     * pinned here (Service\PostService::pin()). The moderator was told
     * which post that is, and chose how long this one stays up, before
     * the request was ever sent — but neither is a condition of it: a
     * submit with no duration at all (no JavaScript, a hand-made
     * request) pins for the default week rather than failing.
     *
     * @param array<string, string> $params
     */
    public function pin(Request $request, array $params): Response
    {
        $duration = (string) $request->getBody('duration', PostService::PIN_DURATION_DEFAULT);

        return $this->postAction($request, $params, function (DiscussionGroup $group, Post $post, GroupSessionContext $context) use ($duration) {
            if (!$this->accessService->canModerate($group, $context)) {
                return new Response('Seul un modérateur du groupe peut épingler un message.', 403);
            }

            $this->postService->pin($post, $duration);

            return $this->redirect('/groups/' . $group->id);
        });
    }

    /**
     * POST /groups/{id}/posts/{postId}/unpin — moderator only.
     *
     * @param array<string, string> $params
     */
    public function unpin(Request $request, array $params): Response
    {
        return $this->postAction($request, $params, function (DiscussionGroup $group, Post $post, GroupSessionContext $context) {
            if (!$this->accessService->canModerate($group, $context)) {
                return new Response('Seul un modérateur du groupe peut épingler un message.', 403);
            }

            $this->postService->unpin($post);

            return $this->redirect('/groups/' . $group->id);
        });
    }

    /**
     * A refused write, told to its author and to nobody else.
     *
     * AJAX gets the exception's own shape back as JSON — message, type,
     * and the AI's suggested rewording when there is one — so groups.js
     * can show it inline without a reload; RejectedDraft is a session
     * round-trip built for the reload path and has nothing to do here,
     * since the composer's own text is what the client already has.
     *
     * A plain form POST keeps the existing behaviour: the reason in a
     * flash, and the text plus suggestion handed back through the session
     * (Support\RejectedDraft) so the composer is not emptied. Nothing
     * here is ever written to the database or journaled either way — the
     * refused text exists only in this member's own session/response, for
     * one use.
     */
    private function refuse(Request $request, GroupsException $e, int $groupId, string $body, ?int $postId): Response
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
     * The shared shape of every post write: CSRF, membership (404), the
     * post actually belonging to that group (404), then the action's own
     * rule.
     *
     * @param array<string, string> $params
     * @param callable(DiscussionGroup, Post, GroupSessionContext): Response $action
     */
    private function postAction(Request $request, array $params, callable $action): Response
    {
        if (($guard = $this->guardCsrf($request, '/groups/' . (int) ($params['id'] ?? 0))) !== null) {
            return $guard;
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
     * Notifies whoever the STORED body names after an "@".
     *
     * Resolution happens here, from the text the database now holds,
     * rather than from anything the request claimed — Service\
     * MentionService's docblock says why. Never fatal, same as every
     * other notification in this module.
     *
     * @param int[] $alreadyNotified member ids to leave alone, so an edit
     *        only tells the people the edit newly named
     */
    private function notifyMentions(
        DiscussionGroup $group,
        int $postId,
        string $body,
        bool $suppressed,
        GroupSessionContext $context,
        array $alreadyNotified = []
    ): void {
        if ($this->mentionService === null || $this->notificationService === null || $context->userAccountId === null) {
            return;
        }

        $mentioned = array_values(array_diff(
            $this->mentionService->resolve($group, $body, $context->effectiveScoutYearId),
            $alreadyNotified
        ));

        $this->notificationService->mentioned(
            $group,
            $postId,
            $mentioned,
            $body,
            $suppressed,
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

    /**
     * The members this account may answer a member-scoped poll for, named
     * the way this module names anybody — the account first, narrowed to
     * the one membership each option stands for ("Marie Dupont (Akéla)").
     *
     * Empty when there is only one: there is nothing to pick between, and
     * a dialog asking a question with one answer is a click for nothing.
     *
     * @return array<int, array{id: int, name: string}>
     */
    private function voteMemberOptions(DiscussionGroup $group, GroupSessionContext $context): array
    {
        $memberIds = $this->accessService->memberIdsAllowedToPostAs($group, $context);
        if (count($memberIds) < 2) {
            return [];
        }

        $labels = $this->identityService?->accountLabelForMembers(
            $memberIds,
            $group->scoutYearId ?? $context->effectiveScoutYearId
        ) ?? [];

        return array_map(
            static fn(int $memberId): array => [
                'id' => $memberId,
                'name' => ($labels[$memberId] ?? '') !== '' ? $labels[$memberId] : ('Membre #' . $memberId),
            ],
            $memberIds
        );
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

    /**
     * groups.js sends this header on the composer's fetch() — the same
     * signal Controller\ReactionController's own wantsJson() answers —
     * so create() can tell the AJAX path from a plain form POST with no
     * JS, or JS that failed to load.
     */
    private function wantsJson(Request $request): bool
    {
        return $request->getServer('HTTP_X_REQUESTED_WITH') === 'XMLHttpRequest';
    }

    /**
     * A write refused with a plain, human message — a full media ceiling,
     * an unpostable empty body, a rejected upload. AJAX gets the message
     * back as JSON with a non-2xx status, so groups.js can show it without
     * a reload; a plain form POST gets the existing flash-and-redirect
     * behaviour unchanged.
     */
    private function failure(Request $request, int $groupId, string $message, int $status = 400): Response
    {
        if ($this->wantsJson($request)) {
            return $this->json(['error' => $message], $status);
        }

        FlashMessage::set('error', $message);

        return $this->redirect('/groups/' . $groupId);
    }
}
