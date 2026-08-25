<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Import\ImportReportRepository;
use Core\Member\Duplicate\DuplicateMemberRepository;
use Core\Member\Duplicate\MemberMergeService;
use Core\Member\Duplicate\MergeException;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Twig\Environment;

/**
 * The pairs of member records that look like the same person, and the two
 * decisions a chef d'unité can take about each.
 *
 * Both are decisions, and both are recorded: merging, and saying they are
 * two different people. The second matters as much as the first — without
 * it every import would re-propose the same pair, and a list that keeps
 * re-asking a question already answered stops being read.
 */
class DuplicateMemberController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private DuplicateMemberRepository $duplicates,
        private MemberMergeService $mergeService,
        private ImportReportRepository $identities,
        private ScoutYearResolver $scoutYearResolver
    ) {
    }

    /**
     * GET /admin/doublons
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $currentYear = $this->scoutYearResolver->getCurrentPublicYear();
        $years = $this->scoutYearResolver->listYears();

        $rows = [];
        foreach ($this->duplicates->findPending() as $candidate) {
            $rows[] = [
                'candidate' => $candidate,
                // A name is a fact about a member in a year, and the two
                // halves of a pair belong to different years by
                // definition — so each is looked up across every year the
                // site knows, newest first.
                'kept' => $this->identityOf($candidate['kept_member_id'], $years),
                'duplicate' => $this->identityOf($candidate['duplicate_member_id'], $years),
                'preview' => $this->mergeService->preview($candidate['kept_member_id'], $candidate['duplicate_member_id']),
            ];
        }

        return $this->render('admin/duplicates.html.twig', [
            'rows' => $rows,
            'scout_year' => $currentYear,
        ]);
    }

    /**
     * POST /admin/doublons/{id}/fusionner
     *
     * @param array<string, string> $params
     */
    public function merge(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/admin/doublons')) !== null) {
            return $guard;
        }

        $candidate = $this->duplicates->findById((int) ($params['id'] ?? 0));
        if ($candidate === null || $candidate['status'] !== 'pending') {
            FlashMessage::set('error', "Cette proposition n'existe plus.");
            return $this->redirect('/admin/doublons');
        }

        try {
            $preview = $this->mergeService->merge(
                $candidate['kept_member_id'],
                $candidate['duplicate_member_id'],
                AuthSession::getUserAccountId(),
                $candidate['id']
            );
        } catch (MergeException $e) {
            FlashMessage::set('error', $e->getMessage());
            return $this->redirect('/admin/doublons');
        }

        FlashMessage::set(
            'success',
            'Fiches fusionnées : ' . $preview->total() . ' élément(s) rattaché(s) à la fiche conservée.'
        );

        return $this->redirect('/admin/doublons');
    }

    /**
     * POST /admin/doublons/{id}/distinctes
     *
     * @param array<string, string> $params
     */
    public function markDistinct(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/admin/doublons')) !== null) {
            return $guard;
        }

        $candidate = $this->duplicates->findById((int) ($params['id'] ?? 0));
        if ($candidate === null || $candidate['status'] !== 'pending') {
            FlashMessage::set('error', "Cette proposition n'existe plus.");
            return $this->redirect('/admin/doublons');
        }

        $this->mergeService->markDistinct($candidate['id'], AuthSession::getUserAccountId());
        FlashMessage::set('success', 'Ces deux fiches sont bien deux personnes distinctes. Elles ne seront plus proposées.');

        return $this->redirect('/admin/doublons');
    }

    /**
     * The readable identity of one member, taken from whichever scout
     * year still holds a row for them — newest first, because that is the
     * spelling the reader will recognise.
     *
     * @param array<int, array{id: int, label: string, start_date: string, end_date: string}> $years
     * @return array{totem: ?string, first_name: string, last_name: string, year: string}|null
     */
    private function identityOf(int $memberId, array $years): ?array
    {
        $sorted = $years;
        usort($sorted, static fn(array $a, array $b): int => strcmp($b['start_date'], $a['start_date']));

        foreach ($sorted as $year) {
            $identities = $this->identities->findMemberIdentities([$memberId], (int) $year['id']);
            if (isset($identities[$memberId])) {
                return $identities[$memberId] + ['year' => (string) $year['label']];
            }
        }

        return null;
    }
}
