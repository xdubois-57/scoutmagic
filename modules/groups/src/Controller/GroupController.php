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
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\Role;
use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Service\GroupAccessService;
use Modules\Groups\Service\GroupListItem;
use Modules\Groups\Service\GroupFeedService;
use Modules\Groups\Service\GroupListService;
use Modules\Groups\Service\GroupService;
use Modules\Groups\Service\GroupSessionContext;
use Modules\Groups\Service\GroupSessionContextFactory;
use Modules\Groups\Service\PostService;
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
        private MemberService $memberService
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
            'author_options' => $this->authorOptions($group, $context),
            'max_body_length' => PostService::MAX_BODY_LENGTH,
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
     * The members of this group the caller may sign a post as, with their
     * display names resolved in one query. Never every linked member: an
     * account linked to three children must not be offered the one who is
     * not a member here (GroupAccessService::memberIdsAllowedToPostAs()).
     *
     * @return array<int, array{id: int, name: string}>
     */
    private function authorOptions(DiscussionGroup $group, GroupSessionContext $context): array
    {
        $memberIds = $this->accessService->memberIdsAllowedToPostAs($group, $context);
        if (count($memberIds) < 2) {
            return [];
        }

        $names = $this->memberService->findDisplayNamesByMemberIds(
            $memberIds,
            $group->scoutYearId ?? $context->effectiveScoutYearId
        );

        $options = [];
        foreach ($memberIds as $memberId) {
            $options[] = ['id' => $memberId, 'name' => $names[$memberId] ?? ('Membre #' . $memberId)];
        }

        return $options;
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
