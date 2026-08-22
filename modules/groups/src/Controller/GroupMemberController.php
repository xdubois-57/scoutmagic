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
use Core\Member\MemberProfile;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\UserAccountRepository;
use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Repository\GroupMember;
use Modules\Groups\Repository\GroupMemberRepository;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\GroupSectionRepository;
use Modules\Groups\Service\GroupAccessService;
use Modules\Groups\Service\GroupMembershipService;
use Modules\Groups\Service\GroupNotificationService;
use Modules\Groups\Service\GroupRecipientResolver;
use Modules\Groups\Service\GroupService;
use Modules\Groups\Service\MemberIdentityService;
use Modules\Groups\Service\LeaveOutcome;
use Modules\Groups\Service\GroupSessionContext;
use Modules\Groups\Service\GroupSessionContextFactory;
use Modules\Groups\Support\GroupLabel;
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
        private SectionService $sectionService,
        private ?GroupMembershipService $membershipService = null,
        private ?MemberIdentityService $identityService = null,
        // Both optional and trailing, like every other collaborator here:
        // without them the page still lists members, it just cannot offer
        // the moderator grant, which names a login and therefore needs to
        // resolve one (Service\GroupAccessService::canModerate()).
        private ?GroupRecipientResolver $recipientResolver = null,
        private ?UserAccountRepository $userAccountRepository = null,
        private ?GroupNotificationService $notificationService = null
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
        $rows = $this->memberRepository->findByGroup($group->id);
        // Named the way this module names anybody, in one batched
        // resolution for the whole list rather than a profile lookup per
        // row (Service\MemberIdentityService).
        $identities = $this->identityService?->forMembers(
            array_map(fn(\Modules\Groups\Repository\GroupMember $m) => $m->memberId, $rows),
            $scoutYearId
        ) ?? [];

        foreach ($rows as $row) {
            $explicit[] = [
                'member_id' => $row->memberId,
                'identity' => $identities[$row->memberId] ?? null,
                'is_moderator' => $row->isModerator && $row->moderatorUserAccountId !== null,
                // Which logins could be sitting behind this membership,
                // and which one — if any — currently holds the moderator
                // flag. Only ever built for a moderator: it is the one
                // control on this page whose subject is an address rather
                // than a person's name (see logins()).
                'logins' => $canModerate ? $this->logins($row->memberId, $row->moderatorUserAccountId) : [],
                'moderator_account_id' => $row->moderatorUserAccountId,
                // A flag granted before it named a login. It moderates
                // nothing until somebody re-grants it, and saying so is
                // better than a row that reads "Modérateur" and behaves
                // like it is not (Service\ModeratorBindingService binds
                // the unambiguous ones by itself).
                'moderation_unbound' => $row->isModerator && $row->moderatorUserAccountId === null,
                'is_self' => in_array($row->memberId, $context->linkedMemberIds, true),
            ];
        }

        return $this->render('@groups/members.html.twig', [
            'group' => $group,
            // Named the same way every other page of this module names it
            // — with the scout year when the group is tied to the current
            // one (Support\GroupLabel).
            'group_label' => GroupLabel::withYear(
                $group,
                $context->effectiveScoutYearId,
                $context->effectiveScoutYearLabel
            ),
            'derived_sections' => $this->sectionNames($this->sectionRepository->findSectionIds($group->id)),
            'explicit_members' => $explicit,
            'can_moderate' => $canModerate,
            'invite_candidates' => $canModerate ? $this->inviteCandidates($group, $scoutYearId) : [],
            'sections' => $canModerate ? $this->sectionService->getAllWithBranches() : [],
            // Only an explicit membership can be left — see the template.
            'can_leave' => $this->explicitMemberId($group, $context) !== null,
            // Same trail as GroupController::show(), one level deeper —
            // see that controller's own comment for why a direct link is
            // safe here.
            'breadcrumb_trail' => [
                ['label' => 'Groupes', 'url' => '/groups'],
                ['label' => $group->name, 'url' => '/groups/' . $group->id],
            ],
        ]);
    }

    /**
     * GET /groups/{id}/member-search?q=… — moderator only. The top 10
     * matches by first name, last name or totem, restricted to exactly
     * the same pool `invite_candidates` already offers (candidatePool())
     * — never an open search across the whole unit, so a moderator
     * typing into the box can only ever find someone the plain dropdown
     * already offered them.
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
        if (!$this->accessService->canModerate($group, $context)) {
            return new Response('Seul un modérateur du groupe peut effectuer cette action.', 403);
        }

        $query = trim((string) $request->getQuery('q', ''));
        if ($query === '') {
            return $this->json([]);
        }

        $scoutYearId = $group->scoutYearId ?? $context->effectiveScoutYearId;
        $matches = array_values(array_filter(
            $this->candidatePool($group, $scoutYearId),
            fn(MemberProfile $p) => $this->matchesQuery($p, $query)
        ));

        $shown = array_slice($matches, 0, 10);
        $accountLabels = $this->accountLabels($shown, $scoutYearId);

        return $this->json(array_map(fn(MemberProfile $p) => [
            'id' => $p->memberId,
            'label' => $this->searchLabel($p, $accountLabels[$p->memberId] ?? ''),
        ], $shown));
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

                // Never on its own: an invitation notifies only when the
                // moderator ticked the box (see members.html.twig).
                if ($request->getBody('notify') !== null) {
                    $this->notificationService?->memberInvited($group, $memberId, $context->userAccountId);
                }
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
            $grant = (string) $request->getBody('is_moderator', '0') === '1';
            $accountId = (int) $request->getBody('user_account_id', 0);

            // A grant names one login, and that login is re-checked here
            // against the member it is being granted for: the form is a
            // request, not the authority. An account that cannot log in
            // as this member is refused rather than stored, so a
            // hand-made POST cannot hand the flag to an unrelated
            // address.
            if ($grant && !in_array($accountId, $this->recipientResolver?->accountIdsForMember($memberId) ?? [], true)) {
                FlashMessage::set('error', 'Cette adresse ne peut pas se connecter en tant que ce membre.');

                return $this->redirect('/groups/' . $group->id . '/members');
            }

            if ($memberId > 0) {
                $done = $this->groupService->setModerator(
                    $group,
                    $memberId,
                    $grant,
                    $context->linkedMemberIds[0] ?? 0,
                    $grant ? $accountId : null
                );

                // The same rule as leaving (LeaveOutcome::LAST_MODERATOR),
                // told the same way this page tells its other refusal: a
                // group must keep a moderator of its own, and revoking the
                // last one is refused rather than performed.
                if (!$done) {
                    FlashMessage::set('error', 'Ce compte est le dernier modérateur de ce groupe : désignez '
                        . 'd\'abord un autre modérateur avant de lui retirer la modération.');
                }
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
            if ($memberId > 0 && !$this->groupService->removeMember($group, $memberId)) {
                // Same protection as a voluntary departure: the last
                // moderator cannot be removed either, or the group would
                // be left with nobody of its own in charge.
                FlashMessage::set('error', 'Ce membre est le dernier modérateur de ce groupe : désignez '
                    . 'd\'abord un autre modérateur avant de le retirer.');
            }

            return $this->redirect('/groups/' . $group->id . '/members');
        });
    }

    /**
     * POST /groups/{id}/leave — any member of the group, for themselves.
     *
     * Deliberately NOT a moderatorAction(): this is the one write here a
     * member performs on their own membership, so it needs read access
     * and nothing more. It is also the only one that can be refused for a
     * reason other than authorisation — a derived membership has nothing
     * to leave, and the group's last moderator may not walk away — which
     * is why Service\GroupMembershipService answers with a LeaveOutcome
     * rather than a bool.
     *
     * @param array<string, string> $params
     */
    public function leave(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateRequest()) {
            return new Response('Jeton CSRF invalide.', 403);
        }

        $context = $this->context();
        $group = $this->groupRepository->findById((int) ($params['id'] ?? 0));
        if ($group === null || !$this->accessService->canRead($group, $context) || $this->membershipService === null) {
            return new Response('Not Found', 404);
        }

        // Which of the caller's own members is leaving: the explicit row
        // is what a member actually holds, so the first linked member
        // that has one. Falling back to the first allowed member makes
        // the derived case reach the service and be refused there with
        // its own French explanation, rather than silently 403-ing.
        $memberId = $this->leavingMemberId($group, $context);
        if ($memberId === null) {
            return new Response('Aucun membre de ce groupe n\'est associé à votre compte.', 403);
        }

        $outcome = $this->membershipService->leave($group, $memberId, $context->userAccountId);
        FlashMessage::set($outcome === LeaveOutcome::LEFT ? 'success' : 'error', $outcome->message());

        // A member who just left can no longer read the group, so sending
        // them back to it would be a 404 — the list is where they belong.
        return $this->redirect($outcome === LeaveOutcome::LEFT ? '/groups' : '/groups/' . $group->id . '/members');
    }

    private function leavingMemberId(DiscussionGroup $group, GroupSessionContext $context): ?int
    {
        return $this->explicitMemberId($group, $context)
            ?? $this->accessService->memberIdsAllowedToPostAs($group, $context)[0]
            ?? ($context->linkedMemberIds[0] ?? null);
    }

    /**
     * The caller's own explicit membership row in this group, if any —
     * what "can leave" means, and what leave() removes.
     */
    private function explicitMemberId(DiscussionGroup $group, GroupSessionContext $context): ?int
    {
        foreach ($context->linkedMemberIds as $memberId) {
            if ($this->memberRepository->find($group->id, $memberId) !== null) {
                return $memberId;
            }
        }

        return null;
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
     * @return array<int, array{section: string, members: array<int, array{id: int, label: string}>}>
     */
    private function inviteCandidates(DiscussionGroup $group, int $scoutYearId): array
    {
        $alreadyIn = $this->alreadyInMemberIds($group);

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
                $accountLabels = $this->accountLabels($candidates, $scoutYearId);
                $sectionLabel = (string) ($section['name'] ?? '');
                $groups[] = [
                    'section' => $sectionLabel !== '' ? $sectionLabel : (string) $section['desk_code'],
                    // Labelled here rather than in the template so the
                    // plain dropdown and the search box that replaces it
                    // can never word the same person differently.
                    'members' => array_map(fn(MemberProfile $p) => [
                        'id' => $p->memberId,
                        'label' => $this->searchLabel($p, $accountLabels[$p->memberId] ?? ''),
                    ], $candidates),
                ];
            }
        }

        return $groups;
    }

    /**
     * The same pool as inviteCandidates(), flattened rather than grouped
     * by section — what search() filters against. A member reachable
     * through more than one function (e.g. staff of one section, animé of
     * another) is kept once, not once per source.
     *
     * @return array<int, MemberProfile>
     */
    private function candidatePool(DiscussionGroup $group, int $scoutYearId): array
    {
        $alreadyIn = $this->alreadyInMemberIds($group);

        $byMemberId = [];
        foreach ($this->sectionService->getAllWithBranches() as $section) {
            $sectionId = (int) $section['id'];
            foreach (array_merge(
                $this->sectionService->getSectionStaff($sectionId, $scoutYearId),
                $this->sectionService->getSectionAnimes($sectionId, $scoutYearId)
            ) as $profile) {
                if (!in_array($profile->memberId, $alreadyIn, true)) {
                    $byMemberId[$profile->memberId] = $profile;
                }
            }
        }

        return array_values($byMemberId);
    }

    /**
     * @return int[]
     */
    private function alreadyInMemberIds(DiscussionGroup $group): array
    {
        return array_map(fn(GroupMember $m) => $m->memberId, $this->memberRepository->findByGroup($group->id));
    }

    /**
     * Matches search() the same simple, accent-sensitive case-insensitive
     * substring way Modules\Finance\Controller\DashboardController's own
     * attachment search already does — first name, last name or totem,
     * any one of the three is enough.
     */
    private function matchesQuery(MemberProfile $profile, string $query): bool
    {
        foreach ([$profile->firstName, $profile->lastName, $profile->totem ?? ''] as $field) {
            if ($field !== '' && mb_stripos($field, $query) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * The account behind each of these candidates, named account-first,
     * in one lookup for the whole list rather than one per row.
     *
     * @param MemberProfile[] $profiles
     * @return array<int, string> member id => label, '' when no named account
     */
    private function accountLabels(array $profiles, int $scoutYearId): array
    {
        return $this->identityService?->accountLabelForMembers(
            array_map(fn(MemberProfile $p) => $p->memberId, $profiles),
            $scoutYearId
        ) ?? [];
    }

    /**
     * "Marie Dupont (Akéla)" — the human first, the membership in
     * parentheses, the shape Service\MemberIdentityService gives every
     * other name in this module.
     *
     * Resolved through that service when an account stands behind the
     * membership, and from the membership itself when none does — see
     * the two branches below.
     */
    private function searchLabel(MemberProfile $profile, string $accountLabel = ''): string
    {
        // Account first, like everywhere else in this module. Narrowed to
        // this one membership rather than all of them: the moderator is
        // picking a membership to invite, and "Marie Dupont (Akéla,
        // Baloo)" on two separate rows would not say which is which.
        if ($accountLabel !== '') {
            return $accountLabel;
        }

        // No account behind them — which is the normal case here, since a
        // candidate is by definition somebody not in the group yet and
        // most animés never have one. Their own full name and totem is
        // the whole answer, and a richer one than a bare totem: this is a
        // search box, and a surname is what a moderator types into it.

        $fullName = trim($profile->firstName . ' ' . $profile->lastName);
        $totem = $profile->totem !== null && $profile->totem !== '' ? $profile->totem : null;

        if ($fullName === '') {
            return $totem ?? ('Membre #' . $profile->memberId);
        }

        return $totem !== null ? $fullName . ' (' . $totem . ')' : $fullName;
    }

    /**
     * Every login that can reach $memberId, each with the address it
     * signs in with and whether it currently holds this group's moderator
     * flag.
     *
     * **This is the one place in this module that shows an email
     * address**, and it is deliberate: the control it feeds grants
     * moderation to ONE login, a member can be reachable by several (a
     * parent's own address plus a confirmed secondary one), and two
     * addresses of the same human carry the same name — so the name alone
     * cannot say which one is being empowered. It is rendered only for a
     * moderator of the group, only on this page, and only inside that
     * control (SECURITY.md § Groups).
     *
     * @return array<int, array{id: int, label: string, email: string, is_moderator: bool}>
     */
    private function logins(int $memberId, ?int $moderatorAccountId): array
    {
        if ($this->recipientResolver === null || $this->userAccountRepository === null) {
            return [];
        }

        $logins = [];
        foreach ($this->recipientResolver->accountIdsForMember($memberId) as $accountId) {
            $account = $this->userAccountRepository->findById($accountId);
            if ($account === null) {
                continue;
            }

            $name = trim(($account->firstName ?? '') . ' ' . ($account->lastName ?? ''));
            $logins[] = [
                'id' => $account->id,
                'label' => $name !== '' ? $name : $account->email,
                'email' => $account->email,
                'is_moderator' => $moderatorAccountId === $account->id,
            ];
        }

        return $logins;
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
