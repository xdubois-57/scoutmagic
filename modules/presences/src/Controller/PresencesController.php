<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Presences\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\SpreadsheetResponse;
use Core\Journal\JournalService;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\Role;
use Core\View\SectionPickerHelper;
use Modules\Presences\Service\PresenceAnimeService;
use Modules\Presences\Service\PresenceAuthorizationService;
use Modules\Presences\Service\PresenceExportService;
use Modules\Presences\Service\PresenceRegisterService;
use Modules\Presences\Service\PresenceSheetService;
use Modules\Presences\Service\RegisterAnime;
use Modules\Presences\Service\RegisterEvent;
use Modules\Presences\Value\PresenceStatus;
use Twig\Environment;

/**
 * The module's pages. The router has already refused anyone below
 * `chief`; what this controller adds on every action is the narrower
 * question the RBAC hierarchy cannot ask — « of WHICH section »
 * (ARCHITECTURE.md §2's per-resource re-check, Service\
 * PresenceAuthorizationService).
 */
class PresencesController extends AbstractController
{
    /**
     * The two sizes « Les moins présents » offers. Five is the list one
     * acts on in an evening; ten is the one a Staff d'Unité reads before
     * a conseil d'unité.
     */
    private const LEAST_PRESENT_SIZES = [5, 10];

    public function __construct(
        protected Environment $twig,
        private PresenceAuthorizationService $authorization,
        private PresenceSheetService $sheetService,
        private PresenceRegisterService $registerService,
        private PresenceAnimeService $animeService,
        private PresenceExportService $exportService,
        private MemberService $memberService,
        private ScoutYearResolver $scoutYearResolver,
        private JournalService $journalService
    ) {
    }

    /**
     * GET /chefs/presences — the section's register, and the module's one
     * menu entry: everything else is reached from here.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $role = Role::fromString(AuthSession::getRole());
        $year = $this->scoutYearResolver->getEffectiveYear(ScoutYearSession::getPreviewId(), $role);
        $email = AuthSession::getEmail() ?? '';

        $sections = $this->authorization->staffedSections($email, $role->value, $year->id);
        foreach ($sections as &$section) {
            $section['color'] = SectionService::colorForSection($section);
        }
        unset($section);

        if ($sections === []) {
            return $this->render('@presences/index.html.twig', [
                'sections' => [],
                'scout_year_label' => $year->label,
            ]);
        }

        $requested = $request->getQuery('section');
        $selectedId = SectionPickerHelper::resolveDefault(
            $requested !== null && $requested !== '' ? (int) $requested : null,
            $this->memberService->getLinkedMembers($email, $year->id),
            $sections
        );
        // resolveDefault() only ever returns an id from the list it was
        // given, so a `?section=` naming somebody else's section falls
        // back to one of this account's rather than being refused — the
        // picker is a convenience, and the boundary has already been
        // applied by building the list from staffedSections().
        $selectedId ??= (int) $sections[0]['id'];
        $register = $this->registerService->buildRegister($selectedId, $year->id);

        $requestedTop = (int) ($request->getQuery('top') ?? 0);

        return $this->render('@presences/index.html.twig', [
            'sections' => $sections,
            'selected_section_id' => $selectedId,
            'register' => $register,
            'chart_events' => $register->chartEvents(),
            'top' => in_array($requestedTop, self::LEAST_PRESENT_SIZES, true)
                ? $requestedTop
                : self::LEAST_PRESENT_SIZES[0],
            'least_present_sizes' => self::LEAST_PRESENT_SIZES,
            'scout_year_label' => $year->label,
        ]);
    }

    /**
     * GET /chefs/presences/recherche — the register's one search field,
     * as JSON.
     *
     * One field for evenings AND animés: somebody types what they have
     * rather than choosing a mode first, and the results are grouped by
     * nature rather than mixed. The section is re-checked here exactly as
     * it is on a page — a JSON route is a route.
     *
     * @param array<string, string> $params
     */
    public function search(Request $request, array $params): Response
    {
        $role = Role::fromString(AuthSession::getRole());
        $year = $this->scoutYearResolver->getEffectiveYear(ScoutYearSession::getPreviewId(), $role);
        $email = AuthSession::getEmail() ?? '';

        $sectionId = (int) ($request->getQuery('section') ?? 0);
        if (!$this->authorization->maySeeSection($email, $role->value, $year->id, $sectionId)) {
            return $this->json(['success' => false, 'error' => self::REFUSED], 403);
        }

        $results = $this->registerService->search(
            $sectionId,
            $year->id,
            (string) ($request->getQuery('q') ?? '')
        );

        $events = array_map(
            static fn (RegisterEvent $event): array => [
                'url' => '/chefs/presences/feuille/' . $event->eventId,
                'title' => $event->title,
                'date' => $event->startDate,
                'pointed' => $event->pointed,
                'rate' => $event->rate,
            ],
            $results['events']
        );
        $animes = array_map(
            static fn (RegisterAnime $anime): array => [
                'url' => '/chefs/presences/anime/' . $anime->memberId,
                'name' => $anime->lastName . ', ' . $anime->firstName,
                'rate' => $anime->rate,
                'tone' => $anime->tone(),
            ],
            $results['animes']
        );

        return $this->json([
            'success' => true,
            'events' => $events,
            'animes' => $animes,
        ]);
    }

    /**
     * GET /chefs/presences/anime/{memberId} — one animé's year, and the
     * one screen that corrects it date by date.
     *
     * The page is not read-only: each date carries the same four states
     * and the same comment as an evening's sheet, and writes them to that
     * evening's OWN endpoint (`record()` below). There is therefore no
     * second write path to keep in step with the first — and no second
     * authorization check either, which is the point: `record()` re-derives
     * the event's section and the animé's membership of it on every call,
     * whichever screen the request came from.
     *
     * @param array<string, string> $params
     */
    public function anime(Request $request, array $params): Response
    {
        $role = Role::fromString(AuthSession::getRole());
        $year = $this->scoutYearResolver->getEffectiveYear(ScoutYearSession::getPreviewId(), $role);
        $email = AuthSession::getEmail() ?? '';

        $profile = $this->animeService->buildProfile(
            (int) ($params['memberId'] ?? 0),
            $email,
            $role->value,
            $year->id
        );
        // Same refusal as a sheet of another section: the site's 404, so
        // « not one of my animés » and « nobody » are indistinguishable.
        if ($profile === null) {
            return $this->notFound();
        }

        return $this->render('@presences/anime.html.twig', [
            'profile' => $profile,
            'history_limit' => PresenceAnimeService::HISTORY_LIMIT,
            'statuses' => PresenceStatus::ordered(),
            'scout_year_label' => $year->label,
        ]);
    }

    /**
     * GET /chefs/presences/export — the section's whole year as .xlsx.
     *
     * **Journaled with counters only.** The file carries names and the
     * comments written about minors, so what is worth recording is that
     * an export happened and how big it was — an entry naming an animé
     * would put in the journal exactly what the journal must not hold
     * (AGENTS.md § Security checklist).
     *
     * @param array<string, string> $params
     */
    public function export(Request $request, array $params): Response
    {
        $role = Role::fromString(AuthSession::getRole());
        $year = $this->scoutYearResolver->getEffectiveYear(ScoutYearSession::getPreviewId(), $role);
        $email = AuthSession::getEmail() ?? '';

        $sectionId = (int) ($request->getQuery('section') ?? 0);
        if (!$this->authorization->maySeeSection($email, $role->value, $year->id, $sectionId)) {
            return $this->notFound();
        }

        [$spreadsheet, $fileName, $counters] = $this->exportService->build($sectionId, $year->id, $year->label);

        $this->journalService->log(
            'presences',
            'presences_export',
            'info',
            'Export des présences d\'une section',
            [
                'section_id' => $sectionId,
                'scout_year_id' => $year->id,
                'animes' => $counters['animes'],
                'events' => $counters['events'],
                'rows' => $counters['rows'],
                'comments' => $counters['comments'],
            ],
            AuthSession::getUserAccountId()
        );

        return SpreadsheetResponse::download($spreadsheet, $fileName);
    }

    /**
     * GET /chefs/presences/feuille/{eventId} — one evening's sheet.
     *
     * @param array<string, string> $params
     */
    public function sheet(Request $request, array $params): Response
    {
        $role = Role::fromString(AuthSession::getRole());
        $year = $this->scoutYearResolver->getEffectiveYear(ScoutYearSession::getPreviewId(), $role);
        $email = AuthSession::getEmail() ?? '';

        $sheet = $this->sheetService->buildSheet((int) ($params['eventId'] ?? 0), $email, $role->value, $year->id);
        // The site's own 404, not a 403: an event this account may not
        // point and an event that does not exist answer identically, so
        // the page cannot be used to find out which sections hold which
        // evenings.
        if ($sheet === null) {
            return $this->notFound();
        }

        return $this->render('@presences/sheet.html.twig', [
            'sheet' => $sheet,
            'counts' => $sheet->counts(),
            'statuses' => PresenceStatus::ordered(),
            'scout_year_label' => $year->label,
            'csrf_token' => CsrfGuard::generateToken(),
        ]);
    }

    /**
     * POST /chefs/presences/feuille/{eventId}/enregistrer — one tap, one
     * save, JSON. The write path of BOTH screens: an evening's sheet posts
     * every row here, and an animé's page posts each of its dates to that
     * date's own event id.
     *
     * The body carries `status` XOR `comment`, never both: two animateurs
     * pointing the same list at once must not have one's tap erase the
     * other's comment (Service\PresenceSheetService::recordStatus()).
     *
     * Every refusal — no such event, another section's event, an animé
     * who is not in it — answers with the SAME sentence and the same
     * status, so the endpoint cannot be used to find out which is which.
     *
     * @param array<string, string> $params
     */
    public function record(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }
        if (($guard = $this->guardCsrfJson($request, (string) ($data['_csrf_token'] ?? ''))) !== null) {
            return $guard;
        }

        $eventId = (int) ($params['eventId'] ?? 0);
        $memberId = (int) ($data['member_id'] ?? 0);
        if ($eventId <= 0 || $memberId <= 0) {
            return $this->json(['success' => false, 'error' => self::REFUSED], 403);
        }

        $role = Role::fromString(AuthSession::getRole());
        $year = $this->scoutYearResolver->getEffectiveYear(ScoutYearSession::getPreviewId(), $role);
        $email = AuthSession::getEmail() ?? '';
        $userId = AuthSession::getUserAccountId();

        if (array_key_exists('status', $data)) {
            $status = PresenceStatus::tryParse(is_string($data['status']) ? $data['status'] : null);
            if ($status === null) {
                return $this->json(['success' => false, 'error' => 'État inconnu.'], 400);
            }

            $recorded = $this->sheetService->recordStatus(
                $eventId,
                $memberId,
                $status,
                $email,
                $role->value,
                $year->id,
                $userId
            );

            return $recorded
                ? $this->json(['success' => true, 'status' => $status->value])
                : $this->json(['success' => false, 'error' => self::REFUSED], 403);
        }

        if (array_key_exists('comment', $data)) {
            $recorded = $this->sheetService->recordComment(
                $eventId,
                $memberId,
                is_string($data['comment']) ? $data['comment'] : null,
                $email,
                $role->value,
                $year->id,
                $userId
            );

            return $recorded
                ? $this->json(['success' => true])
                : $this->json(['success' => false, 'error' => self::REFUSED], 403);
        }

        return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
    }

    /**
     * The one sentence every refusal of a write answers with. It names
     * nothing — not the event, not the animé, not which of the two was
     * the problem — because a message that told them apart would be an
     * oracle for which ids exist.
     */
    private const REFUSED = "Cette feuille de présence n'est pas la vôtre.";
}
