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
use Core\Journal\JournalService;
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
        private JournalService $journalService
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
        if (($guard = $this->guardCsrf($request, SafeRedirect::internalPathFromUrl($request->getReferer() ?? '/admin/members'))) !== null) {
            return $guard;
        }

        $role = AuthSession::getRole();
        if (!Role::fromString($role)->hasAccess(Role::ADMIN)) {
            FlashMessage::set('error', 'Vous n\'avez pas les permissions nécessaires.');

            return $this->redirectBack($request);
        }

        $memberYearId = (int) ($params['id'] ?? 0);

        // The member must exist in the year currently in effect — the same
        // check MemberSearchController::index() makes before rendering the
        // detail card this button lives on. Without it the session could be
        // pointed at any member_years row by id, including one from a year
        // this session isn't even looking at.
        $effective = $this->resolver->getEffectiveYear(
            ScoutYearSession::getPreviewId(),
            Role::fromString($role)
        );
        $target = $memberYearId > 0 ? $this->searchService->findById($effective->id, $memberYearId) : null;
        if ($target === null) {
            return new Response($this->twig->render('errors/404.html.twig'), 404);
        }

        // The search page deliberately lists inactive members too (a member
        // not registered this year still has a card). MemberService only
        // ever injects an active member_year, so accepting one here would
        // set an override that silently resolves to nothing — refuse it
        // where the admin can actually see why.
        if (!$target->isActive) {
            FlashMessage::set('error', 'Ce membre n\'est pas inscrit cette année scoute : ajout impossible.');

            return $this->redirectBack($request);
        }

        TemporaryMemberSession::set($memberYearId);
        ConfigurationMode::activate($role);

        $this->journalService->log(
            'core',
            'temporary_member_added',
            'security',
            'Ajout temporaire d\'un membre à la liste d\'animés d\'une session',
            ['member_year_id' => $memberYearId],
            AuthSession::getUserAccountId()
        );

        FlashMessage::set('success', 'Membre ajouté temporairement à votre liste.');

        return $this->redirectBack($request);
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
        if (($guard = $this->guardCsrf($request, SafeRedirect::internalPathFromUrl($request->getReferer() ?? '/admin/members'))) !== null) {
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
