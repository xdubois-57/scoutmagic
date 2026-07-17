<?php

declare(strict_types=1);

namespace Core\Member\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Member\MemberNotFoundException;
use Core\Member\MemberService;
use Core\Member\MemberYearService;
use Core\Member\Service\MemberSearchService;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthSession;
use Core\Security\Role;
use Twig\Environment;

/**
 * GET /admin/members — search and view members of the effective scout year.
 */
class MemberSearchController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private MemberSearchService $searchService,
        private MemberService $memberService,
        private ScoutYearResolver $resolver,
        private MemberYearService $memberYearService
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $role = Role::fromString(AuthSession::getRole());
        $effective = $this->resolver->getEffectiveYear(ScoutYearSession::getPreviewId(), $role);
        $yearId = $effective->id;

        $query = trim((string) $request->getQuery('q', ''));
        $results = $query !== '' ? $this->searchService->search($yearId, $query) : [];

        $detail = null;
        $effectiveAge = null;
        $memberId = (int) $request->getQuery('member', 0);
        if ($memberId > 0) {
            // The member must belong to the effective scout year.
            if ($this->searchService->findById($yearId, $memberId) === null) {
                return $this->notFound();
            }
            try {
                $detail = $this->memberService->getMemberProfile($memberId);
            } catch (MemberNotFoundException) {
                return $this->notFound();
            }

            $effectiveAge = $this->memberYearService->getEffectiveAge(
                MemberYearService::extractBirthYear($detail->birthDate),
                $detail->scoutYearOffset,
                MemberYearService::referenceYearFromScoutYearLabel($detail->scoutYearLabel)
            );
        }

        return $this->render('admin/members/search.html.twig', [
            'query' => $query,
            'results' => $results,
            'detail' => $detail,
            'effective_age' => $effectiveAge,
            'selected_member_id' => $memberId,
            'year_label' => $effective->label,
        ]);
    }

    private function notFound(): Response
    {
        return new Response($this->twig->render('errors/404.html.twig'), 404);
    }
}
