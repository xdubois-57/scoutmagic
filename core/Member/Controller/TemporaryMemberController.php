<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\SafeRedirect;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalService;
use Core\Member\Service\MemberSearchResult;
use Core\Member\Service\MemberSearchService;
use Core\Member\TemporaryMemberSession;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\Role;
use Core\View\ConfigurationMode;
use Twig\Environment;

/**
 * Adds one member to the current session's own list of animés, for the
 * lifetime of that session only (ARCHITECTURE.md §8.42).
 *
 * The route's role_min gets an admin session in the door; the role check
 * below is the actual enforcement point, same route/service split as
 * Core\View\ConfigurationMode::activate() and everything else in this
 * codebase.
 */
class TemporaryMemberController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private MemberSearchService $searchService,
        private ScoutYearResolver $resolver,
        private JournalService $journalService,
        /**
         * Only ever used to turn an annual row into the person behind it,
         * and that person back into their row for the year this session is
         * looking at — see resolveInEffectiveYear().
         */
        private MemberYearRepository $memberYears
    ) {
    }

    /**
     * POST /admin/members/{id}/temporary-access — add the member to this
     * session's list and turn configuration mode on with it.
     *
     * @param array<string, string> $params
     */
    public function add(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request,
            SafeRedirect::internalPathFromUrl($request->getReferer() ?? '/admin/members'))) !== null) {
            return $guard;
        }

        $role = AuthSession::getRole();
        if (!Role::fromString($role)->hasAccess(Role::ADMIN)) {
            FlashMessage::set('error', 'Vous n\'avez pas les permissions nécessaires.');

            return $this->redirectBack($request);
        }

        $requestedId = (int) ($params['id'] ?? 0);
        $requested = $requestedId > 0 ? $this->memberYears->findById($requestedId) : null;
        if ($requested === null) {
            return new Response($this->twig->render('errors/404.html.twig'), 404);
        }

        // The override must always name a row of the year currently in
        // effect: without that the session could be pointed at any
        // member_years row by id, including one from a year it isn't even
        // looking at.
        //
        // But the id that ARRIVES here legitimately belongs to another
        // year. MemberSearchController::show() normalises onto the member's
        // MOST RECENT annual row — deliberately, because a link may carry a
        // past year's id and the sheet shows the latest either way — so the
        // button on that page carries next year's id for anyone who already
        // has a row there. That is every staff member from the moment a
        // staff year is prepared (ScoutYearAdminService::activateStaffYear()),
        // and it made the button answer 404 for exactly those people while
        // working for everyone else.
        //
        // So the person is resolved first and the year second, rather than
        // the id being taken as it stands.
        $effective = $this->resolver->getEffectiveYear(
            ScoutYearSession::getPreviewId(),
            Role::fromString($role)
        );
        $target = $this->resolveInEffectiveYear((int) $requested['member_id'], $effective->id);

        // The search page deliberately lists inactive members too (a member
        // not registered this year still has a card), and a member can have
        // no row at all for this year. MemberService only ever injects an
        // active member_year, so accepting either would set an override that
        // silently resolves to nothing — refuse it where the admin can
        // actually see why, rather than as a 404 that names no reason.
        if ($target === null || !$target->isActive) {
            FlashMessage::set('error', 'Ce membre n\'est pas inscrit cette année scoute : ajout impossible.');

            return $this->redirectBack($request);
        }

        TemporaryMemberSession::set($target->memberYearId);
        ConfigurationMode::activate($role);

        $this->journalService->log(
            'core',
            'temporary_member_added',
            'security',
            'Ajout temporaire d\'un membre à la liste d\'animés d\'une session',
            // The row actually set, never the one asked for: the two differ
            // whenever the sheet showed a later year, and a journal entry
            // naming the id that was refused would document nothing.
            ['member_year_id' => $target->memberYearId],
            AuthSession::getUserAccountId()
        );

        FlashMessage::set('success', 'Membre ajouté temporairement à votre liste.');

        return $this->redirectBack($request);
    }

    /**
     * This member's row for the year this session is looking at, or null
     * when they have none.
     *
     * Goes through MemberSearchService rather than reading the row it just
     * found, so the existence and `isActive` answers stay the ones the
     * search page itself gives — two sources for "is this member usable
     * this year" would drift, and the drift would show up as a button that
     * works on one screen and not the other.
     */
    private function resolveInEffectiveYear(int $memberId, int $scoutYearId): ?MemberSearchResult
    {
        $inYear = $this->memberYears->findByMemberAndYear($memberId, $scoutYearId);
        if ($inYear === null) {
            return null;
        }

        return $this->searchService->findById($scoutYearId, $inYear['id']);
    }

    /**
     * POST /admin/members/temporary-access/remove — drop it again.
     *
     * Deliberately NOT role-gated beyond the route's own role_min: removing
     * the override is always safe, and a session that has since lost the
     * admin role must still be able to clear it rather than carry a flag it
     * can no longer see a banner for.
     *
     * @param array<string, string> $params
     */
    public function remove(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request,
            SafeRedirect::internalPathFromUrl($request->getReferer() ?? '/admin/members'))) !== null) {
            return $guard;
        }

        $memberYearId = TemporaryMemberSession::get();
        TemporaryMemberSession::clear();

        if ($memberYearId !== null) {
            $this->journalService->log(
                'core',
                'temporary_member_removed',
                'security',
                'Retrait du membre temporaire de la liste d\'animés d\'une session',
                ['member_year_id' => $memberYearId],
                AuthSession::getUserAccountId()
            );
        }

        FlashMessage::set('success', 'Membre temporaire retiré de votre liste.');

        return $this->redirectBack($request);
    }

    /**
     * The Referer is untrusted — reduce it to a same-site path so it can
     * never redirect off-site (audit M17, same treatment as
     * Core\Http\Controller\ConfigModeController).
     */
    private function redirectBack(Request $request): Response
    {
        return $this->redirect(SafeRedirect::internalPathFromUrl($request->getReferer() ?? '/admin/members'));
    }
}
