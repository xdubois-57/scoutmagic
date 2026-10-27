<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Member\MemberNotFoundException;
use Core\Member\MemberService;
use Core\Security\AuthSession;
use Core\Security\Role;
use Modules\MassMail\Api\MassMailQueryInterface;
use Modules\MassMail\Service\TargetEmailLink;
use Twig\Environment;

/**
 * The member page's "view as sent" detail page for one received email
 * (member page §4, ARCHITECTURE.md §8.22) — lives in this module rather
 * than core\Http\Controller\MemberController because the content itself
 * (subject/body) is entirely mass_mail's own data; core\Member\
 * MemberPageService only ever links to it (via the target id it
 * already returns from getRecentEmailsForMember()) and never renders it
 * directly. Only registered when mass_mail is enabled — the "Communications
 * récentes" list that links here is likewise hidden when it's disabled.
 */
class MemberEmailController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private MemberService $memberService,
        private MassMailQueryInterface $massMailQuery
    ) {
    }

    /**
     * GET /members/{id}/emails/{target_id} — `{id}` is a `member_years` id,
     * never a `members` one; `{target_id}` is the entry (one target of a
     * communication, issue #999). role_min: identified, additionally scoped
     * server-side (role_min alone is not the real boundary, same principle
     * as MemberController::show()) to chief/admin or the member themselves
     * via MemberService::canAccess(). On top of that,
     * findEmailDetailForMember() re-verifies server-side that the target
     * really belongs to this member, so a target id from one member's page
     * can never be reused to read another member's mail.
     *
     * « Cette communication concerne aussi … » names the other members the
     * same classic-list communication was meant for **only when this reader
     * may already open their page** — canAccess() again, for each — with a
     * link to their own entry. Nobody else is ever named.
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $memberYearId = (int) $params['id'];
        $targetId = (int) $params['target_id'];
        $userEmail = AuthSession::getEmail() ?? '';
        $userRole = AuthSession::getRole();

        if (!$this->memberService->canAccess($userEmail, $memberYearId, $userRole)) {
            return $this->forbidden(
                "Ce courrier n'est consultable que par la personne concernée et par les animateurs.",
                $request
            );
        }

        try {
            $profile = $this->memberService->getMemberProfile($memberYearId);
        } catch (MemberNotFoundException $e) {
            return new Response('Member not found', 404);
        }

        $detail = $this->massMailQuery->findEmailDetailForMember($profile->memberId, $targetId);
        if ($detail === null) {
            return new Response('Not found', 404);
        }

        $alsoConcerns = $this->alsoConcerns($detail['other_targets'], $userEmail, $userRole);

        return $this->render('members/email_detail.html.twig', [
            'member' => $profile,
            'email' => $detail,
            'also_concerns' => array_slice($alsoConcerns, 0, self::ALSO_CONCERNS_SHOWN),
            'also_concerns_more' => max(0, count($alsoConcerns) - self::ALSO_CONCERNS_SHOWN),
            // Real ancestor page: the member's own page this email was
            // listed on — a breadcrumb_trail, not a `parents` menu label
            // (design.md §7.3).
            'breadcrumb_trail' => [
                ['label' => $profile->getDisplayName(), 'url' => '/members/' . $profile->memberYearId],
            ],
        ]);
    }

    /**
     * How many of the other members are named before « et N autres » — a
     * chief opening one entry of « Membres actifs » may see every other
     * member, and four hundred names are not a sentence.
     */
    private const ALSO_CONCERNS_SHOWN = 10;

    /**
     * The other targets this reader may see, named, each with a link to its
     * own entry when it has a member_years row to go through — and named
     * without one otherwise (the link would have nowhere to land).
     *
     * A member without that row has no page to check canAccess() against,
     * so only a role that opens every page (chief and above) names them: the
     * same answer canAccess() gives such a role, and no name for anybody
     * else.
     *
     * @param list<array{target_id: int, member_id: int, scout_year_id: int|null}> $others
     * @return list<array{name: string, url: string|null}>
     */
    private function alsoConcerns(array $others, string $userEmail, string $userRole): array
    {
        $byYear = [];
        foreach ($others as $other) {
            if ($other['scout_year_id'] !== null) {
                $byYear[$other['scout_year_id']][] = $other['member_id'];
            }
        }
        $memberYearIds = [];
        $displayNames = [];
        foreach ($byYear as $scoutYearId => $memberIds) {
            foreach ($this->memberService->findMemberYearIdsByMembersAndYear($memberIds, $scoutYearId) as $id => $my) {
                $memberYearIds[$scoutYearId . ':' . $id] = $my;
            }
            foreach ($this->memberService->findDisplayNamesByMemberIds($memberIds, $scoutYearId) as $id => $name) {
                $displayNames[$scoutYearId . ':' . $id] = $name;
            }
        }
        $opensEveryPage = Role::fromString($userRole)->hasAccess(Role::CHIEF);

        $named = [];
        $withoutName = [];
        foreach ($others as $other) {
            $key = $other['scout_year_id'] . ':' . $other['member_id'];
            $otherMemberYearId = $memberYearIds[$key] ?? null;

            $visible = $otherMemberYearId !== null
                ? $this->memberService->canAccess($userEmail, $otherMemberYearId, $userRole)
                : $opensEveryPage;
            if (!$visible) {
                continue;
            }

            $name = $displayNames[$key] ?? null;
            if ($name === null) {
                $withoutName[] = $other['member_id'];
            }
            $named[] = [
                'member_id' => $other['member_id'],
                'name' => $name,
                'url' => $otherMemberYearId !== null
                    ? TargetEmailLink::url($otherMemberYearId, $other['target_id'])
                    : null,
            ];
        }

        // A member with no row for the year — or none active — is named by
        // their most recent one.
        $fallback = $withoutName !== [] ? $this->memberService->findNamesForMembers($withoutName) : [];

        $result = [];
        foreach ($named as $entry) {
            $result[] = [
                'name' => $entry['name'] ?? ($fallback[$entry['member_id']] ?? 'Membre'),
                'url' => $entry['url'],
            ];
        }
        usort($result, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $result;
    }
}
