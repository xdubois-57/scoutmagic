<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Member\DepartureService;
use Core\Member\MemberYearService;
use Core\Member\SectionService;
use Core\Member\SectionStaffAuthorizationService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Modules\Registration\Service\ReenrollmentDepartureService;
use Twig\Environment;

/**
 * "Départs" (module spec iteration 6, Espace des chefs, role_min: chief):
 * marks which animés of a section won't be back next scout year. Scoped
 * to the sections the acting account actually staffs — an animateur sees
 * and can only act on their own section(s); admin/superadmin see and can
 * act on all of them. Scoping is entirely delegated to Core\Member\
 * SectionStaffAuthorizationService (ARCHITECTURE.md §8.33) — never
 * recomputed here — and re-checked on every write, not just at display.
 *
 * Anchored on the PUBLIC year, always — see publicYear() below for why this
 * page must not follow ScoutYearResolver::getEffectiveYear() the way most
 * of the site does.
 */
class DeparturesController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private SectionStaffAuthorizationService $sectionStaffAuthorizationService,
        private SectionService $sectionService,
        private DepartureService $departureService,
        private ScoutYearResolver $scoutYearResolver,
        private ReenrollmentDepartureService $reenrollmentDepartureService
    ) {
    }

    /**
     * GET /departs
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $email = AuthSession::getEmail() ?? '';
        $effectiveYear = $this->publicYear();

        $staffedSections = $this->sectionStaffAuthorizationService->getStaffedSections($email, AuthSession::getRole(), $effectiveYear->id);

        if ($staffedSections === []) {
            return $this->render('@registration/departures.html.twig', [
                'sections' => [],
                'selected_section' => null,
                'rows' => [],
                'scout_year_label' => $effectiveYear->label,
                'reenrollment' => ['visible' => false, 'divergences' => 0, 'unanswered' => 0, 'target_year_label' => null],
                'csrf_token' => CsrfGuard::generateToken(),
            ]);
        }

        $requestedSectionId = (int) $request->getQuery('section_id', 0);
        $selectedSection = $this->resolveSelectedSection($staffedSections, $requestedSectionId);

        $referenceYear = MemberYearService::referenceYearFromScoutYearLabel($effectiveYear->label);
        $memberYearService = new MemberYearService();

        $rows = [];
        foreach ($this->sectionService->getSectionAnimes((int) $selectedSection['id'], $effectiveYear->id) as $profile) {
            $status = $this->departureService->getStatus($profile->memberYearId);
            $birthYear = MemberYearService::extractBirthYear($profile->birthDate);
            $effectiveAge = $memberYearService->getEffectiveAge($birthYear, $profile->scoutYearOffset, $referenceYear);

            $rows[] = [
                'profile' => $profile,
                'branch_year_label' => $effectiveAge->getBranchYearLabel(),
                'leaving' => $status?->leaving ?? false,
                'comment' => $status?->comment ?? '',
            ];
        }

        // What the family said about each of these children, and the two
        // numbers a chief needs before reading the list (roadmap IT-16).
        // The page renders the answers; it never writes one, and nothing
        // it offers can edit or erase one.
        $annotated = $this->reenrollmentDepartureService->annotate($rows, $effectiveYear->label);

        return $this->render('@registration/departures.html.twig', [
            'sections' => $staffedSections,
            'selected_section' => $selectedSection,
            'rows' => $annotated['rows'],
            'scout_year_label' => $effectiveYear->label,
            'reenrollment' => [
                'visible' => $annotated['visible'],
                'divergences' => $annotated['divergences'],
                'unanswered' => $annotated['unanswered'],
                'target_year_label' => $annotated['target_year_label'],
            ],
            'csrf_token' => CsrfGuard::generateToken(),
        ]);
    }

    /**
     * POST /departs/{member_year_id} — auto-save, JSON, one field at a
     * time (`leaving` XOR `comment` present in the body) so two
     * animateurs editing the same section around the same time never have
     * one field's save silently clobber the other's (module spec's own
     * concurrency requirement). The section this member_year actually
     * belongs to is re-derived and re-checked against the account's own
     * staffed sections on every call — never trusts the page the request
     * came from.
     *
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }
        if (($guard = $this->guardCsrfJson($request, (string) ($data['_csrf_token'] ?? ''))) !== null) {
            return $guard;
        }

        $memberYearId = (int) ($params['member_year_id'] ?? 0);
        if ($memberYearId <= 0) {
            return $this->json(['success' => false, 'error' => 'Membre introuvable.'], 404);
        }

        $email = AuthSession::getEmail() ?? '';
        $effectiveYear = $this->publicYear();

        if (!$this->accountStaffsMemberYear($email, AuthSession::getRole(), $effectiveYear->id, $memberYearId)) {
            return $this->json(['success' => false, 'error' => "Cette section n'est pas la vôtre."], 403);
        }

        $userId = AuthSession::getUserAccountId();

        if (array_key_exists('leaving', $data)) {
            if ($data['leaving']) {
                $this->departureService->markLeaving($memberYearId, null, $userId);
            } else {
                $this->departureService->unmarkLeaving($memberYearId, $userId);
            }
        }
        if (array_key_exists('comment', $data)) {
            $this->departureService->updateComment($memberYearId, (string) $data['comment']);
        }

        return $this->json(['success' => true]);
    }

    /**
     * Re-derives which section $memberYearId is an animé of, among the
     * account's OWN staffed sections, and confirms it's really there —
     * the server-side re-check the module spec requires on every write,
     * built entirely out of the same vetted lookups index() already uses
     * (never a parallel/hand-rolled cloisonnement check).
     *
     * Membership only, so it goes through getSectionAnimeMemberYearIds()
     * — the id set behind the getSectionAnimes() index() renders — rather
     * than hydrating every animé of every staffed section to compare one
     * integer: this runs on every per-field auto-save.
     */
    private function accountStaffsMemberYear(string $email, string $accountRole, int $scoutYearId, int $memberYearId): bool
    {
        foreach ($this->sectionStaffAuthorizationService->getStaffedSections($email, $accountRole, $scoutYearId) as $section) {
            $animeIds = $this->sectionService->getSectionAnimeMemberYearIds((int) $section['id'], $scoutYearId);
            if (in_array($memberYearId, $animeIds, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The PUBLIC scout year, always — never getEffectiveYear(). "Départs"
     * is the first of the three pages preparing next year (with Passage and
     * Prévisions, which already anchor here), and they must all agree on
     * which year they are talking about.
     *
     * This page used to follow the effective year, so a chief previewing
     * next year wrote departure marks onto THAT year's member_years rows —
     * where Service\PassageService::getBranchChanges(), which filters
     * `leaving = 0` on the public year, would never see them: the member
     * kept showing up as a branch-change candidate and the Prévisions
     * departure count ignored them too.
     *
     * @return \Core\ScoutYear\EffectiveScoutYear
     */
    private function publicYear(): \Core\ScoutYear\EffectiveScoutYear
    {
        $publicYear = $this->scoutYearResolver->getCurrentPublicYear();

        return new \Core\ScoutYear\EffectiveScoutYear((int) $publicYear['id'], (string) $publicYear['label'], null);
    }

    /**
     * @param array<int, array{id: int, desk_code: string, name: ?string, email: ?string, age_branch_id: int, branch_name: string, branch_sort_order: int, color: ?string}> $staffedSections
     * @return array{id: int, desk_code: string, name: ?string, email: ?string, age_branch_id: int, branch_name: string, branch_sort_order: int, color: ?string}
     */
    private function resolveSelectedSection(array $staffedSections, int $requestedSectionId): array
    {
        foreach ($staffedSections as $section) {
            if ($section['id'] === $requestedSectionId) {
                return $section;
            }
        }

        return $staffedSections[0];
    }
}
