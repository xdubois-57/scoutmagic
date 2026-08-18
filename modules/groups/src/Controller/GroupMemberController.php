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
use Core\Member\MemberProfile;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Repository\GroupMember;
use Modules\Groups\Repository\GroupMemberRepository;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\GroupSectionRepository;
use Modules\Groups\Service\GroupAccessService;
use Modules\Groups\Service\GroupService;
use Modules\Groups\Service\GroupSessionContext;
use Modules\Groups\Service\GroupSessionContextFactory;
use Twig\Environment;

/**
 * A group's members page and the four membership actions.
 *
 * Two different denials, deliberately: a caller who is not a member of the
 * group gets 404 (the group must not be confirmed to exist), while a
 * member who is not a moderator gets 403 (they already know it exists).
 * Every action re-checks both server-side — hiding a button is never the
 * check.
 */
class GroupMemberController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private GroupRepository $groupRepository,
        private GroupMemberRepository $memberRepository,
        private GroupSectionRepository $sectionRepository,
        private GroupAccessService $accessService,
        private GroupService $groupService,
        private GroupSessionContextFactory $contextFactory,
        private MemberService $memberService,
        private SectionService $sectionService
    ) {
    }

    /**
     * GET /groups/{id}/members
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $context = $this->context();
        $group = $this->readableGroup($params, $context);
        if ($group === null) {
            return new Response('Not Found', 404);
        }

        $scoutYearId = $group->scoutYearId ?? $context->effectiveScoutYearId;
        $canModerate = $this->accessService->canModerate($group, $context);

        $explicit = [];
        foreach ($this->memberRepository->findByGroup($group->id) as $row) {
            $explicit[] = [
                'member_id' => $row->memberId,
                'profile' => $this->memberService->findProfileByMemberAndYear($row->memberId, $scoutYearId),
                'is_moderator' => $row->isModerator,
                'is_self' => in_array($row->memberId, $context->linkedMemberIds, true),
            ];
        }

        return $this->render('@groups/members.html.twig', [
            'group' => $group,
            'derived_sections' => $this->sectionNames($this->sectionRepository->findSectionIds($group->id)),
            'explicit_members' => $explicit,
            'can_moderate' => $canModerate,
            'invite_candidates' => $canModerate ? $this->inviteCandidates($group, $scoutYearId) : [],
            'sections' => $canModerate ? $this->sectionService->getAllWithBranches() : [],
        ]);
    }

    /**
     * POST /groups/{id}/invite-member
     *
     * @param array<string, string> $params
     */
    public function inviteMember(Request $request, array $params): Response
    {
        return $this->moderatorAction($params, function (DiscussionGroup $group, GroupSessionContext $context) use ($request) {
            $memberId = (int) $request->getBody('member_id', 0);
            if ($memberId > 0) {
                $this->groupService->inviteMember($group, $memberId, $context->linkedMemberIds[0] ?? 0);
            }

            return $this->redirect('/groups/' . $group->id . '/members');
        });
    }

    /**
     * POST /groups/{id}/invite-section
     *
     * @param array<string, string> $params
     */
    public function inviteSection(Request $request, array $params): Response
    {
        return $this->moderatorAction($params, function (DiscussionGroup $group) use ($request) {
            $sectionId = (int) $request->getBody('section_id', 0);
            if ($sectionId > 0) {
                $this->groupService->inviteSection($group, $sectionId);
            }

            return $this->redirect('/groups/' . $group->id . '/members');
        });
    }

    /**
     * POST /groups/{id}/moderator
     *
     * @param array<string, string> $params
     */
    public function setModerator(Request $request, array $params): Response
    {
        return $this->moderatorAction($params, function (DiscussionGroup $group, GroupSessionContext $context) use ($request) {
            $memberId = (int) $request->getBody('member_id', 0);
            if ($memberId > 0) {
                $this->groupService->setModerator(
                    $group,
                    $memberId,
                    (string) $request->getBody('is_moderator', '0') === '1',
                    $context->linkedMemberIds[0] ?? 0
                );
            }

            return $this->redirect('/groups/' . $group->id . '/members');
        });
    }

    /**
     * POST /groups/{id}/remove-member
     *
     * @param array<string, string> $params
     */
    public function removeMember(Request $request, array $params): Response
    {
        return $this->moderatorAction($params, function (DiscussionGroup $group) use ($request) {
            $memberId = (int) $request->getBody('member_id', 0);
            if ($memberId > 0) {
                $this->groupService->removeMember($group, $memberId);
            }

            return $this->redirect('/groups/' . $group->id . '/members');
        });
    }

    /**
     * The shared shape of every write here: CSRF, then membership (404),
     * then moderation (403), then the action itself.
     *
     * @param array<string, string> $params
     * @param callable(DiscussionGroup, GroupSessionContext): Response $action
     */
    private function moderatorAction(array $params, callable $action): Response
    {
        if (!CsrfGuard::validateRequest()) {
            return new Response('Jeton CSRF invalide.', 403);
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
     * Who a moderator may invite: every member of the unit for the group's
     * year, grouped by section. Only ever built for a moderator, on a page
     * they opened deliberately — hence the per-section queries rather than
     * a bespoke "all members" query no other page needs.
     *
     * @return array<int, array{section: string, members: MemberProfile[]}>
     */
    private function inviteCandidates(DiscussionGroup $group, int $scoutYearId): array
    {
        $alreadyIn = array_map(fn(GroupMember $m) => $m->memberId, $this->memberRepository->findByGroup($group->id));

        $groups = [];
        foreach ($this->sectionService->getAllWithBranches() as $section) {
            $sectionId = (int) $section['id'];
            $profiles = array_merge(
                $this->sectionService->getSectionStaff($sectionId, $scoutYearId),
                $this->sectionService->getSectionAnimes($sectionId, $scoutYearId)
            );

            $candidates = array_values(array_filter(
                $profiles,
                fn(MemberProfile $p) => !in_array($p->memberId, $alreadyIn, true)
            ));

            if ($candidates !== []) {
                $sectionLabel = (string) ($section['name'] ?? '');
                $groups[] = [
                    'section' => $sectionLabel !== '' ? $sectionLabel : (string) $section['desk_code'],
                    'members' => $candidates,
                ];
            }
        }

        return $groups;
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
