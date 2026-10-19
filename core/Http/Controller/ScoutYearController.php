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
use Core\ScoutYear\ActivationBlocker;
use Core\ScoutYear\PublicYearActivationBlockedException;
use Core\ScoutYear\ScoutYearAdminService;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\ScoutYear\ScoutYearTransitionService;
use Core\ScoutYear\StaffYearActivationBlockedException;
use Core\ScoutYear\Step\StepContext;
use Core\ScoutYear\Step\StepMarkResult;
use Core\Security\AuthSession;
use Core\Security\Role;
use Twig\Environment;

/**
 * Scout year navigation and transition (Espace chefs d'U).
 *
 * Lets a chief preview any year (session-only), activate a staff year for
 * chiefs/intendants, transition the whole site to a new public year, and
 * mark the human steps of the workflow.
 *
 * The workflow itself — which steps exist, in which phase, in which order,
 * and what marks each one done — lives in Core\ScoutYear\
 * ScoutYearTransitionService, which is also the specification for
 * tests/e2e/specs/scout-year-transition.spec.js. This controller only
 * routes to it. The hard blocks live in ScoutYearAdminService and the
 * step checks, never here: every POST below is refused by them however it
 * was sent (a forged or replayed request included), and this controller
 * only turns the refusal into a message and a journal entry.
 *
 * Every POST has two answers, decided by what the caller sent rather than
 * by a second route (CHANTIER-annee-scoute.md §2.8): a form gets a flash and a
 * redirect — the no-JavaScript fallback — and the page's own script, which
 * posts JSON (public/assets/js/admin-scout-year.js), gets `{success,
 * message|error, html: {state, banner}}`: the page state re-rendered by
 * the same Twig partial index() uses, for the same person and the same
 * target year, never from anything the browser holds.
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
        return $this->render('admin/scout_year.html.twig', $this->stateContext());
    }

    /**
     * POST /admin/scout-year/preview — preview a year (session-only).
     *
     * @param array<string, string> $params
     */
    public function preview(Request $request, array $params): Response
    {
        $json = $this->jsonBody($request);
        if (($guard = $this->guard($request, $json)) !== null) {
            return $guard;
        }

        $yearId = (int) $this->field($request, $json, 'scout_year_id');
        if ($yearId <= 0 || $this->scoutYearService->findById($yearId) === null) {
            return $this->refused($json, 'Année scoute invalide.');
        }

        ScoutYearSession::setPreview($yearId);

        return $this->succeeded($json, 'Prévisualisation activée pour cette session.');
    }

    /**
     * POST /admin/scout-year/clear-preview — return to the current year.
     *
     * Also the target of the site-wide preview band's close button, so the
     * form fallback goes back where it was posted from.
     *
     * @param array<string, string> $params
     */
    public function clearPreview(Request $request, array $params): Response
    {
        $json = $this->jsonBody($request);
        if (($guard = $this->guard($request, $json)) !== null) {
            return $guard;
        }

        ScoutYearSession::clear();

        return $this->succeeded($json, 'Retour à l\'année courante.', $request->getReferer() ?? '/admin/scout-year');
    }

    /**
     * POST /admin/scout-year/activate-staff — set the staff year.
     *
     * @param array<string, string> $params
     */
    public function activateStaff(Request $request, array $params): Response
    {
        $json = $this->jsonBody($request);
        if (($guard = $this->guard($request, $json)) !== null) {
            return $guard;
        }

        $yearId = (int) $this->field($request, $json, 'scout_year_id');
        $year = $yearId > 0 ? $this->scoutYearService->findById($yearId) : null;
        if ($year === null) {
            return $this->refused($json, 'Année scoute invalide.');
        }

        try {
            $this->adminService->activateStaffYear($yearId);
        } catch (StaffYearActivationBlockedException $e) {
            $this->journalService->log(
                'core',
                'scout_year_staff_activation_blocked',
                'security',
                "Activation de {$year['label']} pour le staff refusée : aucun import Desk réussi",
                ['year_id' => $yearId, 'blockers' => $e->getBlockerCodes()],
                AuthSession::getUserAccountId()
            );

            return $this->refused($json, $this->refusalMessage(
                "Impossible d'activer {$year['label']} pour les staffs.",
                $e->getBlockers(),
                (string) $year['label']
            ));
        }

        $this->journalService->log(
            'core',
            'scout_year_staff_activated',
            'security',
            "Année scoute du staff activée : {$year['label']}",
            ['year_id' => $yearId],
            AuthSession::getUserAccountId()
        );

        return $this->succeeded($json, "Année {$year['label']} activée pour le staff.");
    }

    /**
     * POST /admin/scout-year/deactivate-staff — clear the staff year.
     *
     * @param array<string, string> $params
     */
    public function deactivateStaff(Request $request, array $params): Response
    {
        $json = $this->jsonBody($request);
        if (($guard = $this->guard($request, $json)) !== null) {
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

        return $this->succeeded($json, 'Année du staff désactivée.');
    }

    /**
     * POST /admin/scout-year/activate-public — transition the whole site.
     *
     * @param array<string, string> $params
     */
    public function activatePublic(Request $request, array $params): Response
    {
        $json = $this->jsonBody($request);
        if (($guard = $this->guard($request, $json)) !== null) {
            return $guard;
        }

        $yearId = (int) $this->field($request, $json, 'scout_year_id');
        $year = $yearId > 0 ? $this->scoutYearService->findById($yearId) : null;
        if ($year === null) {
            return $this->refused($json, 'Année scoute invalide.');
        }

        $oldYearId = $this->resolver->getPublicYearId();

        try {
            $this->adminService->activatePublicYear($yearId);
        } catch (PublicYearActivationBlockedException $e) {
            $this->journalService->log(
                'core',
                'scout_year_public_activation_blocked',
                'security',
                "Bascule vers {$year['label']} refusée : " . implode(', ', $e->getBlockerCodes()),
                [
                    'year_id' => $yearId,
                    'blockers' => $e->getBlockerCodes(),
                    'blocking_request_count' => $e->getBlockingRequestCount(),
                ],
                AuthSession::getUserAccountId()
            );

            return $this->refused($json, $this->refusalMessage(
                "Impossible de basculer sur {$year['label']}.",
                $e->getBlockers(),
                (string) $year['label']
            ));
        }

        $oldYear = $oldYearId !== null ? $this->scoutYearService->findById($oldYearId) : null;
        $this->journalService->log(
            'core',
            ScoutYearTransitionService::JOURNAL_PUBLIC_ACTIVATED,
            'security',
            "Année scoute publique activée : {$year['label']}",
            [
                'old_year_id' => $oldYearId,
                'new_year_id' => $yearId,
                // Read back by the « Dernière bascule » line, so that line
                // still names the years once one of them is gone.
                'old_year_label' => $oldYear['label'] ?? null,
                'new_year_label' => $year['label'],
            ],
            AuthSession::getUserAccountId()
        );

        return $this->succeeded($json, "Année {$year['label']} activée pour tout le monde.");
    }

    /**
     * POST /admin/scout-year/step — mark or unmark one human step.
     *
     * `done` is explicit, never « toggle » — 1 or 0 from the form, true or
     * false (a JSON boolean, nothing else) from the page's script — so a
     * replay or a second tab can only ever restate a state. Only human
     * steps are settable (ScoutYearTransitionService::markStep()): an
     * observed step's key, an unknown one or a disabled module's is
     * refused rather than quietly ignored, so a replayed or hand-crafted
     * request can never make the page claim the public year was activated
     * when it was not; and a human step whose check refuses the mark right
     * now (step 1 during the re-enrolment campaign) is refused with its
     * reason.
     *
     * @param array<string, string> $params
     */
    public function toggleStep(Request $request, array $params): Response
    {
        $json = $this->jsonBody($request);
        if (($guard = $this->guard($request, $json)) !== null) {
            return $guard;
        }

        $stepKey = $this->field($request, $json, 'step_key');
        if ($json === null) {
            $done = $request->getBody('done', '0') === '1';
        } elseif (is_bool($json['done'] ?? null)) {
            $done = $json['done'];
        } else {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        // The same target the page itself computes: the year following the
        // public one. Never the previewed or staff year — a mark belongs to
        // the transition being prepared, not to whatever year the person
        // marking it happens to be looking at.
        $context = $this->context();
        $targetId = $context->targetId();

        $result = $this->transitionService->markStep($context, $stepKey, $done, AuthSession::getUserAccountId());
        if ($result->outcome === StepMarkResult::NOT_MARKABLE) {
            return $this->refused($json, 'Cette étape ne peut pas être marquée à la main.');
        }
        if ($result->outcome === StepMarkResult::REFUSED) {
            return $this->refused($json, (string) $result->reason);
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

        return $this->succeeded($json, $done ? 'Étape marquée comme faite.' : 'Étape remise à faire.');
    }

    /**
     * Everything admin/scout_year/_state.html.twig renders, recomputed from
     * server state for the person asking: the page and the JSON answer of
     * every action build it here, and only here, so the fragment the script
     * swaps in is exactly what a reload would show (CHANTIER-annee-scoute.md §2.8).
     *
     * @return array<string, mixed>
     */
    private function stateContext(): array
    {
        $context = $this->context();
        $publicYear = $context->publicYear;

        return [
            'public_year' => $publicYear,
            'staff_year' => $context->staffYearId !== null ? $this->scoutYearService->findById($context->staffYearId) : null,
            'target_year' => $context->targetYear,
            'preview_year' => $context->sessionPreviewId !== null
                ? $this->scoutYearService->findById($context->sessionPreviewId)
                : null,
            'workflow' => $this->transitionService->buildWorkflow($context),
            // Non-blocking signal only (§8.26) — the transition itself is not
            // gated by any date, this just flags that nobody has run it yet
            // even though the calendar has moved past this year.
            'public_year_ended' => $publicYear['end_date'] < $this->now()->format('Y-m-d'),
        ];
    }

    /**
     * The JSON body when this request is the page's own script — which
     * posts JSON (ScoutMagicApi.postJson()) — and null for a plain form
     * POST, the no-JavaScript fallback of the same route. An unreadable
     * body is an empty one: it then carries no CSRF token and stops there.
     *
     * @return array<string, mixed>|null
     */
    private function jsonBody(Request $request): ?array
    {
        $contentType = strtolower((string) $request->getServer('CONTENT_TYPE', ''));
        if (!str_contains($contentType, 'application/json')) {
            return null;
        }

        $data = json_decode($request->getRawBody(), true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed>|null $json
     */
    private function guard(Request $request, ?array $json): ?Response
    {
        if ($json === null) {
            return $this->guardCsrf($request, '/admin/scout-year');
        }

        $token = $json['_csrf_token'] ?? null;

        return $this->guardCsrfJson($request, is_string($token) ? $token : null);
    }

    /**
     * One input field, from whichever of the two bodies this request has.
     *
     * @param array<string, mixed>|null $json
     */
    private function field(Request $request, ?array $json, string $key): string
    {
        $value = $json === null ? $request->getBody($key, '') : ($json[$key] ?? '');

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * The action went through: a flash and a redirect for the form, or the
     * recomputed page state for the script.
     *
     * @param array<string, mixed>|null $json
     */
    private function succeeded(?array $json, string $message, string $redirectTo = '/admin/scout-year'): Response
    {
        if ($json === null) {
            FlashMessage::set('success', $message);

            return $this->redirect($redirectTo);
        }

        return $this->json(['success' => true, 'message' => $message, 'html' => $this->fragments()]);
    }

    /**
     * The server refused the action. The script gets the recomputed state
     * along with the reason: a refusal often means the page was stale (a
     * second tab, someone else acting), and the page then shows what is
     * true rather than what it believed.
     *
     * @param array<string, mixed>|null $json
     */
    private function refused(?array $json, string $message): Response
    {
        if ($json === null) {
            FlashMessage::set('error', $message);

            return $this->redirect('/admin/scout-year');
        }

        return $this->json(['success' => false, 'error' => $message, 'html' => $this->fragments()], 422);
    }

    /**
     * The two server-rendered pieces the script swaps in: the page state,
     * and the site-wide band naming the year this session is served — a
     * preview started or left here changes it.
     *
     * @return array{state: string, banner: string}
     */
    private function fragments(): array
    {
        $effective = $this->resolver->getEffectiveYear(
            ScoutYearSession::getPreviewId(),
            Role::fromString(AuthSession::getRole())
        );

        return [
            'state' => $this->renderToString('admin/scout_year/_state.html.twig', $this->stateContext()),
            'banner' => $this->renderToString('partials/year_override_banner.html.twig', [
                'override_type' => $effective->overrideType,
                'year_label' => $effective->label,
            ]),
        ];
    }

    /**
     * The workflow context of this request: the target is the year
     * following the public one, the preview is this session's own, the
     * staff year is the setting — all server state, never a value the
     * browser sent.
     */
    private function context(): StepContext
    {
        $publicYear = $this->resolver->getCurrentPublicYear();
        $targetId = $this->scoutYearService->ensureYear(ScoutYearService::nextLabel($publicYear['label']));
        $targetYear = $this->scoutYearService->findById($targetId)
            ?? throw new \LogicException("Scout year {$targetId} vanished right after ensureYear().");

        return $this->transitionService->contextFor(
            $targetYear,
            $publicYear,
            ScoutYearSession::getPreviewId(),
            $this->resolver->getStaffYearId(),
            $this->now()
        );
    }

    /**
     * @param list<ActivationBlocker> $blockers
     */
    private function refusalMessage(string $lead, array $blockers, string $yearLabel): string
    {
        return $lead . ' ' . implode(' ', array_map(
            static fn(ActivationBlocker $b): string => $b->message($yearLabel),
            $blockers
        ));
    }

    /**
     * Current moment. Overridable in tests to exercise the switch window.
     */
    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
