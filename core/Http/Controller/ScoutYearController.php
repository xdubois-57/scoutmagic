<?php

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
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\Role;
use Twig\Environment;

/**
 * Scout year navigation and transition (Espace admin).
 *
 * Lets a chief preview any year (session-only), activate a staff year for
 * chiefs/intendants, and transition the whole site to a new public year.
 */
class ScoutYearController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private ScoutYearResolver $resolver,
        private ScoutYearAdminService $adminService,
        private ScoutYearService $scoutYearService,
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
            'can_activate_public' => $staffYear !== null,
        ]);
    }

    /**
     * POST /admin/scout-year/preview — preview a year (session-only).
     *
     * @param array<string, string> $params
     */
    public function preview(Request $request, array $params): Response
    {
        if (!$this->validCsrf($request)) {
            return $this->forbidden();
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
        if (!$this->validCsrf($request)) {
            return $this->forbidden();
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
        if (!$this->validCsrf($request)) {
            return $this->forbidden();
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
            "Staff scout year set to {$year['label']}",
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
        if (!$this->validCsrf($request)) {
            return $this->forbidden();
        }

        $this->adminService->deactivateStaffYear();
        $this->journalService->log(
            'core',
            'scout_year_staff_deactivated',
            'security',
            'Staff scout year cleared',
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
        if (!$this->validCsrf($request)) {
            return $this->forbidden();
        }

        $yearId = (int) $request->getBody('scout_year_id', '0');
        $year = $yearId > 0 ? $this->scoutYearService->findById($yearId) : null;
        if ($year === null) {
            FlashMessage::set('error', 'Année scoute invalide.');
            return $this->redirect('/admin/scout-year');
        }

        $oldYearId = $this->resolver->getPublicYearId();

        $this->adminService->activatePublicYear($yearId);
        $this->journalService->log(
            'core',
            'scout_year_public_activated',
            'security',
            "Public scout year set to {$year['label']}",
            ['old_year_id' => $oldYearId, 'new_year_id' => $yearId],
            AuthSession::getUserAccountId()
        );
        FlashMessage::set('success', "Année {$year['label']} activée pour tout le monde.");

        return $this->redirect('/admin/scout-year');
    }

    private function validCsrf(Request $request): bool
    {
        return CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''));
    }

    private function forbidden(): Response
    {
        return (new Response('', 403))->setBody('Forbidden: invalid CSRF token.');
    }
}
