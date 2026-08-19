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
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\Role;
use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\PostRepository;
use Modules\Groups\Service\AuthorOptionsService;
use Modules\Groups\Service\GroupAccessService;
use Modules\Groups\Service\GroupListItem;
use Modules\Groups\Service\GroupFeedService;
use Modules\Groups\Service\GroupListService;
use Modules\Groups\Service\GroupService;
use Modules\Groups\Service\GroupSessionContext;
use Modules\Groups\Service\GroupSessionContextFactory;
use Modules\Groups\Service\PostMediaService;
use Modules\Groups\Service\PostService;
use Modules\Groups\Support\RejectedDraft;
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
        private AuthorOptionsService $authorOptionsService,
        private PostRepository $postRepository
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

        return $this->render('@groups/list.html.twig', [
            'items' => $this->decorate($this->listService->findCurrent($context)),
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
            'items' => $this->decorate($this->listService->findArchived($context)),
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

        return $this->render('@groups/show.html.twig', [
            'group' => $group,
            'badges' => $this->badges($group, $context),
            'can_moderate' => $canModerate,
            'post_permission' => $this->accessService->canPost($group, $context),
            'pinned' => $page->pinned,
            'posts' => $page->posts,
            'next_cursor' => $page->nextCursor,
            // Only shown when the account is linked to several members of
            // this group — with one, there is nothing to choose.
            'author_options' => $this->authorOptionsService->forGroup($group, $context),
            'max_body_length' => PostService::MAX_BODY_LENGTH,
            'max_media_per_post' => PostMediaService::MAX_MEDIA_PER_POST,
            'video_upload_allowed' => $this->postMediaService->videoUploadAllowed(),
            // A message the AI moderation just refused, handed back to
            // its author so the composer is not emptied. Read-and-clear:
            // it survives exactly this one render, and lives nowhere but
            // this member's own session (Support\RejectedDraft).
            'rejected_draft' => RejectedDraft::take(),
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
            // The media of auto-hidden posts and replies are filtered out
            // here too: hiding a message that no longer shows its photos
            // in the feed but still shows them one click away in the
            // gallery would hide nothing at all.
            'media' => $this->postMediaService->groupGalleryMedia(
                $group,
                $this->accessService->canModerate($group, $context)
            ),
        ]);
    }

    /**
     * POST /groups — chief only (RBAC gates the route); a section group
     * when a section is chosen, an invitation group otherwise.
     *
     * @param array<string, string> $params
     */
    public function create(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateRequest()) {
            return new Response('Jeton CSRF invalide.', 403);
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
        if ($sectionId > 0) {
            $groupId = $this->groupService->createSectionGroup($name, $sectionId, $context->effectiveScoutYearId, $creatorMemberId);
        } else {
            // "Sur invitation" — tied to the effective year only when the
            // chief asks for it (schema.sql documents the nullable column).
            $scoutYearId = $request->getBody('tie_to_year') !== null ? $context->effectiveScoutYearId : null;
            $groupId = $this->groupService->createInvitationGroup($name, $scoutYearId, $creatorMemberId);
        }

        return $this->redirect('/groups/' . $groupId);
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

    /**
     * @param GroupListItem[] $items
     * @return array<int, array<string, mixed>>
     */
    private function decorate(array $items): array
    {
        return array_map(fn(GroupListItem $item) => [
            'group' => $item->group,
            'is_moderator' => $item->isModerator,
            'is_archived' => $item->isArchived,
            'section_names' => $this->sectionNames($item->sectionIds),
        ], $items);
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
