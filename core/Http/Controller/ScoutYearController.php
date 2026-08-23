<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Config\ScoutYearService;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\ScoutYear\ScoutYearAdminService;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\ScoutYear\ScoutYearTransitionService;
use Core\ScoutYear\ScoutYearTransitionVetoedException;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\Role;
use Twig\Environment;

/**
 * Scout year navigation and transition (Espace chefs d'U).
 *
 * Lets a chief preview any year (session-only), activate a staff year for
 * chiefs/intendants, transition the whole site to a new public year, and
 * tick off the workflow steps the site cannot observe by itself.
 *
 * The workflow itself — which steps exist, in which phase, in which order,
 * and what marks each one done — lives in Core\ScoutYear\
 * ScoutYearTransitionService, which is also the specification for
 * tests/e2e/specs/scout-year-transition.spec.js. This controller only
 * routes to it.
 */
class ScoutYearController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private ScoutYearResolver $resolver,
        private ScoutYearAdminService $adminService,
        private ScoutYearService $scoutYearService,
        private ScoutYearTransitionService $transitionService,
        private JournalService $journalService
    ) {
    }

    /**
     * GET /admin/scout-year — management page.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $role = Role::fromString(AuthSession::getRole());
        $effective = $this->resolver->getEffectiveYear(ScoutYearSession::getPreviewId(), $role);

        $publicYear = $this->resolver->getCurrentPublicYear();

        $staffYearId = $this->resolver->getStaffYearId();
        $staffYear = $staffYearId !== null ? $this->scoutYearService->findById($staffYearId) : null;

        // The transition targets the year following the public year.
        $targetId = $this->scoutYearService->ensureYear(ScoutYearService::nextLabel($publicYear['label']));
        $targetYear = $this->scoutYearService->findById($targetId);

        // Registration module veto (§8.38) — 0 when the module is absent,
        // disabled, or has nothing open. The staff-year step shows this as a
        // non-blocking warning; the public-activation step hard-blocks on it
        // (enforced again, server-side, in activatePublic() below — this is
        // only for the page's own display).
        $blockingRequestCount = $this->adminService->countBlockingRegistrationRequests();

        $workflow = $this->transitionService->buildWorkflow(
            $targetYear,
            $publicYear,
            ScoutYearSession::getPreviewId(),
            $staffYearId,
            (int) $publicYear['id'],
            $this->now()
        );

        return $this->render('admin/scout_year.html.twig', [
            'public_year' => $publicYear,
            'staff_year' => $staffYear,
            'effective_year' => [
                'id' => $effective->id,
                'label' => $effective->label,
                'override_type' => $effective->overrideType,
            ],
            'years' => $this->resolver->listYears(),
            'member_count' => $this->resolver->countMembers($effective->id),
            'section_count' => $this->resolver->countSections($effective->id),
            'can_activate_public' => $staffYear !== null && $blockingRequestCount === 0,
            'target_year' => $targetYear,
            'phases' => $workflow['phases'],
            'current_step' => $workflow['current_step'],
            'step_count' => $workflow['step_count'],
            'desk_encoding_date' => $workflow['desk_encoding_date'],
            'blocking_request_count' => $blockingRequestCount,
            // Non-blocking signal only (§8.26) — the transition itself is no
            // longer gated by any date, this just flags that nobody has run
            // it yet even though the calendar has moved past this year.
            'public_year_ended' => $publicYear['end_date'] < $this->now()->format('Y-m-d'),
        ]);
    }

    /**
     * POST /admin/scout-year/preview — preview a year (session-only).
     *
     * @param array<string, string> $params
     */
    public function preview(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/admin/scout-year')) !== null) {
            return $guard;
        }

        $yearId = (int) $request->getBody('scout_year_id', '0');
        if ($yearId <= 0 || $this->scoutYearService->findById($yearId) === null) {
            FlashMessage::set('error', 'Année scoute invalide.');
            return $this->redirect('/admin/scout-year');
        }

        ScoutYearSession::setPreview($yearId);
        FlashMessage::set('success', 'Prévisualisation activée pour cette session.');

        return $this->redirect('/admin/scout-year');
    }

    /**
     * POST /admin/scout-year/clear-preview — return to the current year.
     *
     * @param array<string, string> $params
     */
    public function clearPreview(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/admin/scout-year')) !== null) {
            return $guard;
        }

        ScoutYearSession::clear();
        FlashMessage::set('success', 'Retour à l\'année courante.');

        return $this->redirect($request->getReferer() ?? '/admin/scout-year');
    }

    /**
     * POST /admin/scout-year/activate-staff — set the staff year.
     *
     * @param array<string, string> $params
     */
    public function activateStaff(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/admin/scout-year')) !== null) {
            return $guard;
        }

        $yearId = (int) $request->getBody('scout_year_id', '0');
        $year = $yearId > 0 ? $this->scoutYearService->findById($yearId) : null;
        if ($year === null) {
            FlashMessage::set('error', 'Année scoute invalide.');
            return $this->redirect('/admin/scout-year');
        }

        $this->adminService->activateStaffYear($yearId);
        $this->journalService->log(
            'core',
            'scout_year_staff_activated',
            'security',
            "Année scoute du staff activée : {$year['label']}",
            ['year_id' => $yearId],
            AuthSession::getUserAccountId()
        );
        FlashMessage::set('success', "Année {$year['label']} activée pour le staff.");

        return $this->redirect('/admin/scout-year');
    }

    /**
     * POST /admin/scout-year/deactivate-staff — clear the staff year.
     *
     * @param array<string, string> $params
     */
    public function deactivateStaff(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/admin/scout-year')) !== null) {
            return $guard;
        }

        $this->adminService->deactivateStaffYear();
        $this->journalService->log(
            'core',
            'scout_year_staff_deactivated',
            'security',
            'Année scoute du staff désactivée',
            [],
            AuthSession::getUserAccountId()
        );
        FlashMessage::set('success', 'Année du staff désactivée.');

        return $this->redirect('/admin/scout-year');
    }

    /**
     * POST /admin/scout-year/activate-public — transition the whole site.
     *
     * @param array<string, string> $params
     */
    public function activatePublic(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/admin/scout-year')) !== null) {
            return $guard;
        }

        $yearId = (int) $request->getBody('scout_year_id', '0');
        $year = $yearId > 0 ? $this->scoutYearService->findById($yearId) : null;
        if ($year === null) {
            FlashMessage::set('error', 'Année scoute invalide.');
            return $this->redirect('/admin/scout-year');
        }

        $oldYearId = $this->resolver->getPublicYearId();

        try {
            $this->adminService->activatePublicYear($yearId);
        } catch (ScoutYearTransitionVetoedException $e) {
            $count = $e->getBlockingRequestCount();
            $this->journalService->log(
                'core',
                'scout_year_public_activation_blocked',
                'security',
                "Bascule vers {$year['label']} refusée : {$count} demande(s) d'inscription non clôturée(s)",
                ['blocking_request_count' => $count],
                AuthSession::getUserAccountId()
            );
            FlashMessage::set('error', sprintf(
                "Impossible de basculer : %d demande(s) d'inscription ne sont pas encore clôturées, toutes années confondues. "
                . 'Traitez-les depuis la page de gestion des inscriptions avant de poursuivre — le traitement en masse '
                . "(« tout refuser », « tout retirer ») y est disponible.",
                $count
            ));
            return $this->redirect('/admin/scout-year');
        }

        $this->journalService->log(
            'core',
            'scout_year_public_activated',
            'security',
            "Année scoute publique activée : {$year['label']}",
            ['old_year_id' => $oldYearId, 'new_year_id' => $yearId],
            AuthSession::getUserAccountId()
        );
        FlashMessage::set('success', "Année {$year['label']} activée pour tout le monde.");

        return $this->redirect('/admin/scout-year');
    }

    /**
     * POST /admin/scout-year/step — tick or untick one manual step.
     *
     * Only the steps the site cannot observe on its own are settable here
     * (Core\ScoutYear\ScoutYearTransitionService::isManualStep()); an
     * auto-derived step's key is refused rather than quietly ignored, so a
     * replayed or hand-crafted request can never make the page claim the
     * public year was activated when it was not.
     *
     * @param array<string, string> $params
     */
    public function toggleStep(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/admin/scout-year')) !== null) {
            return $guard;
        }

        $stepKey = (string) $request->getBody('step_key', '');
        $done = $request->getBody('done', '0') === '1';

        // The same target the page itself computes: the year following the
        // public one. Never the previewed or staff year — a mark belongs to
        // the transition being prepared, not to whatever year the person
        // ticking it happens to be looking at.
        $publicYear = $this->resolver->getCurrentPublicYear();
        $targetId = $this->scoutYearService->ensureYear(ScoutYearService::nextLabel($publicYear['label']));

        if (!$this->transitionService->setManualStep($targetId, $stepKey, $done, AuthSession::getUserAccountId())) {
            FlashMessage::set('error', 'Cette étape ne peut pas être cochée manuellement.');
            return $this->redirect('/admin/scout-year');
        }

        $this->journalService->log(
            'core',
            $done ? 'scout_year_step_marked' : 'scout_year_step_unmarked',
            'info',
            $done
                ? "Étape « {$stepKey} » marquée comme faite pour l'année scoute {$targetId}"
                : "Étape « {$stepKey} » remise à faire pour l'année scoute {$targetId}",
            ['step_key' => $stepKey, 'scout_year_id' => $targetId],
            AuthSession::getUserAccountId()
        );

        FlashMessage::set('success', $done ? 'Étape marquée comme faite.' : 'Étape remise à faire.');

        return $this->redirect('/admin/scout-year');
    }

    /**
     * Current moment. Overridable in tests to exercise the switch window.
     */
    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
