<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Controller;

use Core\Config\SettingService;
use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\Role;
use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\PostRepository;
use Modules\Groups\Service\GroupAccessService;
use Modules\Groups\Service\GroupListItem;
use Modules\Groups\Service\GroupFeedService;
use Modules\Groups\Service\GroupListService;
use Modules\Groups\Service\GroupReadStateService;
use Modules\Groups\Service\GroupService;
use Modules\Groups\Service\GroupSessionContext;
use Modules\Groups\Service\GroupMembershipService;
use Modules\Groups\Service\GroupSessionContextFactory;
use Modules\Groups\Service\ReopenOutcome;
use Modules\Groups\Service\PostEventService;
use Modules\Groups\Service\PostMediaService;
use Modules\Groups\Service\PostService;
use Modules\Groups\Service\MemberIdentityService;
use Modules\Groups\Service\ReportService;
use Modules\Groups\Service\ModeratorBindingService;
use Modules\Groups\Service\SectionGroupSyncService;
use Modules\Groups\Support\GroupLabel;
use Modules\Groups\Support\RejectedDraft;
use Modules\Groups\Support\SearchTerm;
use Twig\Environment;

/**
 * The group list, the archives tab, one group's page, and group creation.
 *
 * Every route that names a group answers 404 — never 403 — when the caller
 * is not a member: a 403 would confirm that the group exists, and these
 * groups are invisible to non-members by design (there is no directory, no
 * public group, no self-join and no join request).
 */
class GroupController extends AbstractController
{
    /**
     * How long a not-yet-posted draft stays cached in the composer's own
     * browser (never the server — module spec: "local storage cache").
     * Floored at 1 so a stray 0/negative setting cannot make groups.js
     * discard a draft before the member even finishes typing it.
     */
    private const SETTING_DRAFT_TTL_MINUTES = 'groups_draft_ttl_minutes';
    private const DEFAULT_DRAFT_TTL_MINUTES = 60;

    /**
     * Matches discussion_groups.description's own VARCHAR(300): the cut
     * here is what keeps a pasted paragraph from being silently truncated
     * by the database instead. One line about what the group is for, not
     * a second place to hold a conversation.
     */
    public const MAX_DESCRIPTION_LENGTH = 300;

    public function __construct(
        protected Environment $twig,
        private GroupRepository $groupRepository,
        private GroupListService $listService,
        private GroupAccessService $accessService,
        private GroupService $groupService,
        private GroupSessionContextFactory $contextFactory,
        private SectionService $sectionService,
        private GroupFeedService $feedService,
        private PostMediaService $postMediaService,
        private PostRepository $postRepository,
        private ?SectionGroupSyncService $sectionGroupSyncService = null,
        private ?ModeratorBindingService $moderatorBindingService = null,
        private ?GroupMembershipService $membershipService = null,
        private ?SettingService $settingService = null,
        private ?GroupReadStateService $readStateService = null,
        private ?PostEventService $eventService = null,
        private ?MemberIdentityService $identityService = null,
        private ?ReportService $reportService = null,
        // Trailing and optional like every other collaborator here. Used
        // for one thing only: naming the message a pin would replace
        // (Service\PostService::pinnedLabel()), which a page renders
        // without perfectly well — the confirmation then simply says
        // that a message will be unpinned without quoting it.
        private ?PostService $postService = null
    ) {
    }

    /**
     * GET /groups
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $context = $this->context();

        // Self-healing, the same pattern as Core\Badge\BadgeService::
        // syncSectionReferentBadges(): the group list is where a missing
        // section group would be noticed, so this is where it gets
        // created, without waiting for tonight's task and without a core
        // hook into the Desk import (Service\SectionGroupSyncService
        // explains why there is none). Idempotent, so on every run after
        // the first it is one SELECT per section and no write at all.
        $this->sectionGroupSyncService?->sync($context->effectiveScoutYearId);

        // Same self-healing spot, same shape: a moderator row granted
        // before the flag named a login binds itself to the one account
        // behind that member, if there is exactly one
        // (Service\ModeratorBindingService). Two queries and no write
        // once every row is bound.
        $this->moderatorBindingService?->run();

        return $this->render('@groups/list.html.twig', [
            'items' => $this->decorate($this->listService->findCurrent($context), $context),
            'archived_count' => count($this->listService->findArchived($context)),
            'can_create' => $context->role->hasAccess(Role::CHIEF),
            'sections' => $this->sectionService->getAllWithBranches(),
            'is_archive_tab' => false,
        ]);
    }

    /**
     * GET /groups/archives — past-year groups the caller was a member of.
     *
     * @param array<string, string> $params
     */
    public function archives(Request $request, array $params): Response
    {
        $context = $this->context();

        return $this->render('@groups/list.html.twig', [
            'items' => $this->decorate($this->listService->findArchived($context), $context),
            'archived_count' => 0,
            'can_create' => false,
            'sections' => [],
            'is_archive_tab' => true,
        ]);
    }

    /**
     * GET /groups/{id}
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $context = $this->context();
        $group = $this->readableGroup($params, $context);
        if ($group === null) {
            return new Response('Not Found', 404);
        }

        $canModerate = $this->accessService->canModerate($group, $context);
        $page = $this->feedService->page($group, $context, $canModerate);

        // Opening the group is what clears its unread badge — recorded
        // after the feed is built, so a failure here can never cost the
        // render, and never before, so a page that 404s marks nothing.
        $this->readStateService?->markRead($group, $context);

        return $this->render('@groups/show.html.twig', [
            'group' => $group,
            // The name the page shows: the group's own, plus the scout
            // year when it is tied to the one in effect (Support\GroupLabel).
            'group_label' => $this->label($group, $context),
            'badges' => $this->badges($group, $context),
            'can_moderate' => $canModerate,
            // A past-year group stays a read-only archive (prompt 3), so
            // the button is not offered — and the header says why rather
            // than leaving a moderator wondering where it went.
            'can_reopen' => $canModerate
                && $group->isClosed()
                && ($group->scoutYearId === null || $group->scoutYearId === $context->effectiveScoutYearId),
            'post_permission' => $this->accessService->canPost($group, $context),
            // What the CARDS need, which is a weaker question than the
            // composer's: commenting, reacting and answering a poll stay
            // open to every member of a group where only moderators
            // publish (Service\GroupAccessService::canParticipate()).
            'participate_permission' => $this->accessService->canParticipate($group, $context),
            // Who this account may answer a member-scoped poll for —
            // empty when there is nothing to choose between (see
            // partials/poll.html.twig).
            'vote_members' => $this->voteMemberOptions($group, $context),
            // The moderator's own entry to what has been reported, with
            // its count. Resolved only for a moderator: nobody else is
            // shown the entry, and nobody else may open the page behind
            // it (Controller\ReportController::index()).
            'reported_count' => $canModerate ? count($this->reportService?->reportedInGroup($group->id)['post_ids'] ?? []) : 0,
            // The message the pin confirmation names as the one about to
            // lose its pin — a group keeps exactly one. Read from the
            // page just built rather than queried again, and empty for
            // anyone who is not a moderator (they are never offered the
            // control at all).
            'pinned_post_label' => $canModerate ? ($this->postService?->pinnedLabel($group->id) ?? '') : '',
            'pinned' => $page->pinned,
            'posts' => $page->posts,
            'next_cursor' => $page->nextCursor,
            // Only shown when the account is linked to several members of
            // this group — with one, there is nothing to choose.
            'max_body_length' => PostService::MAX_BODY_LENGTH,
            'max_media_per_post' => PostMediaService::MAX_MEDIA_PER_POST,
            'video_upload_allowed' => $this->postMediaService->videoUploadAllowed(),
            'draft_ttl_minutes' => $this->draftTtlMinutes(),
            // Empty whenever the calendar module is disabled, which is
            // also what hides the picker — the composer never mentions a
            // feature this install does not have.
            'event_options' => $this->eventService?->options($context) ?? [],
            // How many option boxes the poll section offers. Four is
            // enough for the questions a group actually asks and short
            // enough to stay a form rather than a wall; the two beyond
            // the minimum are labelled optional, and blank ones are
            // dropped server-side (Service\PollService::normalise()).
            // A message the AI moderation just refused, handed back to
            // its author so the composer is not emptied. Read-and-clear:
            // it survives exactly this one render, and lives nowhere but
            // this member's own session (Support\RejectedDraft).
            'rejected_draft' => RejectedDraft::take(),
            // Replaces the route's static "Groupe" label with this
            // group's own name, and adds a real "Groupes" link back to
            // the module's list page ahead of it — partials/
            // breadcrumb_bar.html.twig's own docblock explains why a
            // direct link is safe here and not for an ordinary parent.
            'breadcrumb_trail' => [['label' => 'Groupes', 'url' => '/groups']],
            'breadcrumb_current' => $this->label($group, $context),
        ]);
    }

    /**
     * GET /groups/{id}/search?q=… — the group's own messages matching a
     * term, rendered as the same cards the feed uses.
     *
     * Scoped to one group and to what this reader may see, in that order:
     * readableGroup() applies the module's usual 404-not-403 rule first,
     * and every query underneath is given that authorised group's id and
     * this reader's moderator flag — a search box is exactly the kind of
     * feature through which an auto-hidden message would otherwise leak
     * back to the member it was hidden from.
     *
     * Nothing here is journaled: what somebody searched for in a group is
     * as personal as what they wrote in it (SECURITY.md §11).
     *
     * @param array<string, string> $params
     */
    public function search(Request $request, array $params): Response
    {
        $context = $this->context();
        $group = $this->readableGroup($params, $context);
        if ($group === null) {
            return new Response('Not Found', 404);
        }

        $query = SearchTerm::normalise((string) $request->getQuery('q', ''));

        // An empty box means "show me everything again", which is the
        // group's own page — not this one with nothing on it. Clearing
        // the field and submitting used to land here with no term, and a
        // results page with no results reads exactly like a group that
        // lost its messages.
        if ($query === '') {
            return $this->redirect('/groups/' . $group->id);
        }

        $usable = SearchTerm::isUsable($query);
        $canModerate = $this->accessService->canModerate($group, $context);

        return $this->render('@groups/search.html.twig', [
            'group' => $group,
            'group_label' => $this->label($group, $context),
            'badges' => $this->badges($group, $context),
            'query' => $query,
            // Same as show(): a result card carries the same kebab menu,
            // so it needs the same answer to "what would épingler
            // replace?" (Service\PostService::pinnedLabel()).
            'pinned_post_label' => $canModerate ? ($this->postService?->pinnedLabel($group->id) ?? '') : '',
            // `participate_permission` is deliberately NOT passed, the
            // same way `post_permission` never was: a search result is a
            // place to find what was said, not a second place to answer
            // it (search.html.twig's own comment). The cards then default
            // to offering neither a comment box nor a vote.
            // Two states left: a term too short to run, and a term that
            // ran. "Nothing typed yet" no longer reaches this page at all
            // — an empty box redirects to the group above, which is what
            // "back to everything" actually means.
            'has_searched' => true,
            'too_short' => !$usable,
            'min_length' => SearchTerm::MIN_LENGTH,
            'result_limit' => GroupFeedService::RESULT_LIMIT,
            'results' => $usable
                ? $this->feedService->search($group, $context, $canModerate, SearchTerm::pattern($query))
                : [],
            // Same trail as gallery(): "Groupes", then this group's own
            // page, both real links.
            'breadcrumb_trail' => [
                ['label' => 'Groupes', 'url' => '/groups'],
                ['label' => $group->name, 'url' => '/groups/' . $group->id],
            ],
        ]);
    }

    /**
     * GET /groups/{id}/posts/{postId} — where a notification's deep link
     * lands.
     *
     * A real server route rather than a `#post-123` fragment, for one
     * reason: a fragment is resolved by the browser and never reaches the
     * server, so a link forwarded to somebody outside the group would
     * render them the group page and only then fail to scroll. Here
     * membership is re-checked before anything is rendered, and a
     * non-member gets the module's usual 404 — never a 403, which would
     * confirm the group exists (prompt 3's rule, unchanged).
     *
     * The post itself is only used to check it belongs to the authorised
     * group; the landing page is the group's own feed, anchored on the
     * post. Rendering one post alone would be a second, parallel rendering
     * path for exactly the thing show() already renders.
     *
     * @param array<string, string> $params
     */
    public function post(Request $request, array $params): Response
    {
        $context = $this->context();
        $group = $this->readableGroup($params, $context);
        if ($group === null) {
            return new Response('Not Found', 404);
        }

        $post = $this->postRepository->findById((int) ($params['postId'] ?? 0));
        // A post id from another group is a 404, not someone else's post —
        // the group in the URL is what was authorised.
        if ($post === null || $post->groupId !== $group->id) {
            return new Response('Not Found', 404);
        }

        // A hidden post is not reachable through its own deep link either,
        // for anyone but a moderator: the notification that linked here
        // predates the hiding, and following it must not become the way
        // around it (prompt 9 — the hidden state is enforced in the query,
        // and this is one more query).
        if ($post->isHidden() && !$this->accessService->canModerate($group, $context)) {
            return new Response('Not Found', 404);
        }

        return $this->redirect('/groups/' . $group->id . '#post-' . $post->id);
    }

    /**
     * GET /groups/{id}/gallery — "Galerie du groupe": every media of the
     * group's posts, newest first. Same 404-not-403 membership rule as
     * every other group page — readableGroup() resolves the album id
     * from the authorised $group itself, never from anything in the
     * request, so this can never be pointed at another group's media.
     *
     * @param array<string, string> $params
     */
    public function gallery(Request $request, array $params): Response
    {
        $context = $this->context();
        $group = $this->readableGroup($params, $context);
        if ($group === null) {
            return new Response('Not Found', 404);
        }

        return $this->render('@groups/gallery.html.twig', [
            'group' => $group,
            'group_label' => $this->label($group, $context),
            // The media of auto-hidden posts and replies are filtered out
            // here too: hiding a message that no longer shows its photos
            // in the feed but still shows them one click away in the
            // gallery would hide nothing at all.
            'media' => $this->postMediaService->groupGalleryMedia(
                $group,
                $this->accessService->canModerate($group, $context)
            ),
            // Same trail as show(), one level deeper: "Groupes" then this
            // group's own page, both real links — see show()'s own
            // comment for why that is safe here.
            'breadcrumb_trail' => [
                ['label' => 'Groupes', 'url' => '/groups'],
                ['label' => $group->name, 'url' => '/groups/' . $group->id],
            ],
        ]);
    }

    /**
     * GET /groups/{id}/media-status?ids=1,2,3 — polled by groups.js while
     * a just-posted photo or video still shows a spinner, so the real
     * thumbnail appears the moment the background resize (gallery's own
     * Task\ProcessPhotoHandler/ProcessVideoHandler) finishes, without a
     * page reload. Same 404-not-403 membership rule as every other group
     * route; an id from another group's album, or one that no longer
     * exists, is simply absent from the response — never a distinguishable
     * error a caller could use to probe another group's media ids.
     *
     * A JSON array, not an object keyed by media id: PHP silently
     * re-encodes an int-keyed array as a JSON array instead of an object
     * whenever the keys happen to be sequential from 0 (json_encode has no
     * way to tell "empty array" from "object with no numeric-looking
     * keys" apart either), which would make the shape depend on which
     * ids the caller happened to ask about. An array of {id, status,
     * html} objects has no such ambiguity.
     *
     * @param array<string, string> $params
     */
    public function mediaStatus(Request $request, array $params): Response
    {
        $context = $this->context();
        $group = $this->readableGroup($params, $context);
        if ($group === null) {
            return new Response('Not Found', 404);
        }

        $ids = array_values(array_filter(
            array_map('intval', explode(',', (string) $request->getQuery('ids', ''))),
            fn (int $id) => $id > 0
        ));

        $result = [];
        foreach ($this->postMediaService->mediaByIds($group, $ids) as $media) {
            $resolved = in_array($media->processingStatus, ['done', 'failed'], true);
            $result[] = [
                'id' => $media->id,
                'status' => $media->processingStatus,
                // Only rendered once resolved: while still pending/
                // processing, the client already shows the spinner
                // media_thumb.html.twig would render right back anyway.
                'html' => $resolved
                    ? $this->twig->render('@groups/partials/media_thumb.html.twig', ['media' => $media])
                    : null,
            ];
        }

        return $this->json($result);
    }

    /**
     * POST /groups — chief only (RBAC gates the route); a section group
     * when a section is chosen, an invitation group otherwise.
     *
     * @param array<string, string> $params
     */
    public function create(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/groups')) !== null) {
            return $guard;
        }

        $context = $this->context();
        $creatorMemberId = $context->linkedMemberIds[0] ?? null;
        if ($creatorMemberId === null) {
            return new Response('Aucun membre n\'est associé à votre compte pour cette année scoute.', 403);
        }

        $name = trim((string) $request->getBody('name', ''));
        if ($name === '') {
            return $this->redirect('/groups');
        }
        $name = mb_substr($name, 0, 150);

        $sectionId = (int) $request->getBody('section_id', 0);

        // The quota covers invitation groups only, and is checked here
        // rather than in the service because refusing needs a French
        // sentence naming the limit. A section group is created by the
        // scheduled task, never by a person — a chief creating one by
        // hand is filling a gap that task will fill anyway, so it is not
        // clutter one person chose to make.
        if ($sectionId === 0 && $this->membershipService !== null
            && !$this->membershipService->canCreateAnotherGroup($creatorMemberId)
        ) {
            FlashMessage::set('error', sprintf(
                'Vous avez déjà %d groupes ouverts, soit le maximum autorisé. Clôturez-en un avant d\'en créer un nouveau.',
                $this->membershipService->creationQuota()
            ));

            return $this->redirect('/groups');
        }

        if ($sectionId > 0) {
            $groupId = $this->groupService->createSectionGroup(
                $name,
                $sectionId,
                $context->effectiveScoutYearId,
                $creatorMemberId,
                $context->userAccountId
            );
        } else {
            // "Sur invitation" — tied to the effective year only when the
            // chief asks for it (schema.sql documents the nullable column).
            $scoutYearId = $request->getBody('tie_to_year') !== null ? $context->effectiveScoutYearId : null;
            $groupId = $this->groupService->createInvitationGroup(
                $name,
                $scoutYearId,
                $creatorMemberId,
                $context->userAccountId
            );
        }

        return $this->redirect('/groups/' . $groupId);
    }

    /**
     * POST /groups/{id}/edit — moderator only. Renames the group, sets
     * its optional one-liner description, and, for an invitation group,
     * links or unlinks it to the current scout year
     * — same "tie_to_year" checkbox and semantics as create() above. A
     * section group's own scout-year link is never editable (its year
     * comes from its section, schema.sql); the checkbox is not offered
     * for one, and Service\GroupService::edit() ignores it either way.
     *
     * @param array<string, string> $params
     */
    public function edit(Request $request, array $params): Response
    {
        return $this->moderatorAction($request, $params, function (DiscussionGroup $group, GroupSessionContext $context) use ($request): Response {
            $name = trim((string) $request->getBody('name', ''));
            if ($name === '') {
                FlashMessage::set('error', 'Le nom du groupe ne peut pas être vide.');

                return $this->redirect('/groups/' . $group->id);
            }
            $name = mb_substr($name, 0, 150);

            // An emptied field clears the description rather than leaving
            // the old one in place — the form always carries the current
            // value, so a blank box is a deliberate erasure.
            $description = trim((string) $request->getBody('description', ''));
            $description = $description === '' ? null : mb_substr($description, 0, self::MAX_DESCRIPTION_LENGTH);

            $scoutYearId = $request->getBody('tie_to_year') !== null ? $context->effectiveScoutYearId : null;

            // Who may publish here. A radio pair, so the submitted value
            // is always one of the two — and anything else is normalised
            // to the open default rather than refused
            // (Repository\GroupRepository::setPostingPolicy()).
            $postingPolicy = (string) $request->getBody('posting_policy', DiscussionGroup::POSTING_MEMBERS);

            $this->groupService->edit($group, $name, $scoutYearId, $description, $postingPolicy);

            FlashMessage::set('success', 'Les informations du groupe ont été mises à jour.');

            return $this->redirect('/groups/' . $group->id);
        });
    }

    /**
     * POST /groups/{id}/close — moderator only.
     *
     * The manual counterpart of Task\CloseInactiveGroupsHandler: a
     * project that is over does not need to wait months for the
     * inactivity window to notice. Closing is read-only, never hiding —
     * the group stays fully visible to its members.
     *
     * @param array<string, string> $params
     */
    public function close(Request $request, array $params): Response
    {
        return $this->moderatorAction($request, $params, function (DiscussionGroup $group, GroupSessionContext $context): Response {
            $this->membershipService?->close($group, $context->userAccountId);
            FlashMessage::set('success', 'Le groupe est clôturé : il reste consultable, mais n\'accepte plus de nouvelle publication.');

            return $this->redirect('/groups/' . $group->id);
        });
    }

    /**
     * POST /groups/{id}/reopen — moderator only.
     *
     * Without this, automatic closure would be one-way: a project group
     * dormant between two camps would close itself and nobody could wake
     * it up. Reopening resets last_activity_at, or the inactivity task
     * would close it again on its very next run.
     *
     * @param array<string, string> $params
     */
    public function reopen(Request $request, array $params): Response
    {
        return $this->moderatorAction($request, $params, function (DiscussionGroup $group, GroupSessionContext $context): Response {
            if ($this->membershipService === null) {
                return new Response('Not Found', 404);
            }

            $outcome = $this->membershipService->reopen($group, $context->effectiveScoutYearId, $context->userAccountId);
            FlashMessage::set($outcome === ReopenOutcome::REOPENED ? 'success' : 'error', $outcome->message());

            return $this->redirect('/groups/' . $group->id);
        });
    }

    /**
     * The shared shape of both group-state actions: CSRF, membership
     * (404 — never 403, which would confirm the group exists), then the
     * moderator check (403, because a member of the group already knows
     * it exists).
     *
     * @param array<string, string> $params
     * @param callable(DiscussionGroup, GroupSessionContext): Response $action
     */
    private function moderatorAction(Request $request, array $params, callable $action): Response
    {
        if (($guard = $this->guardCsrf($request, '/groups/' . (int) ($params['id'] ?? 0))) !== null) {
            return $guard;
        }

        $context = $this->context();
        $group = $this->readableGroup($params, $context);
        if ($group === null) {
            return new Response('Not Found', 404);
        }

        if (!$this->accessService->canModerate($group, $context)) {
            return new Response('Seul un modérateur du groupe peut effectuer cette action.', 403);
        }

        return $action($group, $context);
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
     * The configured cap, floored at 1 — same posture as
     * Service\GroupMembershipService::creationQuota() for the same
     * reason: a 0 or negative setting must not silently disable the
     * feature it was meant to tune.
     */
    private function draftTtlMinutes(): int
    {
        $raw = $this->settingService?->get(self::SETTING_DRAFT_TTL_MINUTES, 'groups', self::DEFAULT_DRAFT_TTL_MINUTES);
        $configured = is_numeric($raw) ? (int) $raw : self::DEFAULT_DRAFT_TTL_MINUTES;

        return max(1, $configured);
    }

    /**
     * The members this account may answer a member-scoped poll for, named
     * account-first and narrowed to one membership each ("Marie Dupont
     * (Akéla)"). Empty when there is only one — nothing to pick between.
     *
     * The same list Controller\PostController builds for the fragment it
     * re-renders after a vote; both go through
     * Service\GroupAccessService::memberIdsAllowedToPostAs(), so the
     * page and the fragment can never offer a different set.
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
     * @param GroupListItem[] $items
     * @return array<int, array<string, mixed>>
     */
    private function decorate(array $items, GroupSessionContext $context): array
    {
        return array_map(fn(GroupListItem $item) => [
            'group' => $item->group,
            // "Louveteaux (2025-2026)" for a group tied to the year in
            // effect, the bare name otherwise — one implementation,
            // Support\GroupLabel.
            'label' => GroupLabel::withYear(
                $item->group,
                $context->effectiveScoutYearId,
                $context->effectiveScoutYearLabel
            ),
            'is_moderator' => $item->isModerator,
            'is_archived' => $item->isArchived,
            'section_names' => $this->sectionNames($item->sectionIds),
            // Never on the archives tab: a past-year group is a read-only
            // archive, so "you have not caught up" is not a call to
            // action there, just noise on something already finished.
            'has_unread' => $item->hasUnread && !$item->isArchived,
        ], $items);
    }

    /**
     * The group's name as every page of this module writes it — see
     * Support\GroupLabel.
     */
    private function label(DiscussionGroup $group, GroupSessionContext $context): string
    {
        return GroupLabel::withYear($group, $context->effectiveScoutYearId, $context->effectiveScoutYearLabel);
    }

    /**
     * @return array<string, mixed>
     */
    private function badges(DiscussionGroup $group, GroupSessionContext $context): array
    {
        return [
            'is_moderator' => $this->accessService->canModerate($group, $context),
            'is_archived' => $group->scoutYearId !== null && $group->scoutYearId !== $context->effectiveScoutYearId,
            'section_names' => $this->sectionNames($group->sectionId !== null ? [$group->sectionId] : []),
            'is_invitation' => $group->sectionId === null,
        ];
    }

    /**
     * @param int[] $sectionIds
     * @return string[]
     */
    private function sectionNames(array $sectionIds): array
    {
        $names = [];
        foreach ($sectionIds as $sectionId) {
            $section = $this->sectionService->getSection($sectionId);
            if ($section !== null) {
                $label = (string) ($section['name'] ?? '');
                $names[] = $label !== '' ? $label : (string) $section['desk_code'];
            }
        }

        return array_values(array_filter($names, fn(string $n) => $n !== ''));
    }
}
