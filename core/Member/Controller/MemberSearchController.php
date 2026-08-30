<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Controller;

use Core\Config\SettingService;
use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\FlashMessage;
use Core\Http\Response;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalService;
use Core\Member\DepartureService;
use Core\Member\Export\MemberExportRowBuilder;
use Core\Member\Export\MemberExportService;
use Core\Member\AdminMemberPageService;
use Core\Member\MemberNoteException;
use Core\Member\MemberDocumentMailer;
use Core\Member\MemberDocumentService;
use Core\Member\MemberNoteService;
use Core\Member\MemberNotFoundException;
use Core\Member\MemberService;
use Core\Member\MemberYearService;
use Core\Member\Service\MemberSearchService;
use Core\Member\TemporaryMemberSession;
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
        private MemberYearService $memberYearService,
        private DepartureService $departureService,
        private MemberExportRowBuilder $exportRowBuilder,
        private MemberExportService $exportService,
        private JournalService $journalService,
        private AdminMemberPageService $adminMemberPageService,
        private MemberYearRepository $memberYearRepository,
        private MemberNoteService $memberNoteService,
        private MemberDocumentService $memberDocumentService,
        private MemberDocumentMailer $memberDocumentMailer,
        private SettingService $settingService
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $role = Role::fromString(AuthSession::getRole());
        $effective = $this->resolver->getEffectiveYear(ScoutYearSession::getPreviewId(), $role);

        $query = trim((string) $request->getQuery('q', ''));
        $scope = MemberSearchService::normalizeScope($request->getQuery('scope'));
        // Widening to the past years is an explicit act, never a default
        // and never a keystroke: every extra year is a whole year of
        // AES decryption in PHP (Service\MemberSearchService's own
        // docblock). The page offers a button; this reads what it sent.
        $allYears = $request->getQuery('annees') === '1';

        $results = [];
        if ($query !== '') {
            $results = $allYears
                ? $this->searchService->searchAllYears($query, $scope, $effective->id)
                : $this->searchService->searchGrouped($effective->id, $query, $scope, $effective->id);
        }

        return $this->render('admin/members/search.html.twig', [
            'query' => $query,
            'results' => $results,
            'scope' => $scope,
            'scopes' => MemberSearchService::SCOPES,
            'all_years' => $allYears,
            'former_count' => count(array_filter($results, fn($r) => $r->isFormerMember)),
            'year_label' => $effective->label,
        ]);
    }

    /**
     * GET /admin/members/{id} — one member's own page, `role_min: admin`.
     *
     * The detail used to render below the search results, reached as
     * `/admin/members?q=…&member={id}`. It is dense — every Desk field,
     * the year's functions, the effective age, the scout-year offset, the
     * departure marker — and a route with an identifier is what this
     * codebase does everywhere else a person carries that much
     * (`/config/inscriptions/demandes/{id}`, `/members/{id}`). It also
     * makes the address shareable, which `?member=42` bolted onto a
     * search never was: that link dragged the `q=` along and replayed an
     * unrelated search for whoever opened it.
     *
     * **The page always shows the member's LAST KNOWN year**, whichever
     * one the search matched, and names it on screen. Someone looking up
     * a former member wants their most recent contact details, not the
     * ones from 2019 — and without the year being stated a chef d'unité
     * reads them as current and phones a number that stopped working
     * years ago.
     *
     * That is why the old "belongs to the effective scout year, or 404"
     * check is gone: it would 404 every former member, which is exactly
     * who the widened search exists to find. What replaces it is weaker
     * on purpose and still a real check — the `member_years` row must
     * exist, and the member must have a most recent year to show. The
     * boundary here is the route's own `role_min: admin`, unchanged.
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $role = Role::fromString(AuthSession::getRole());
        $effective = $this->resolver->getEffectiveYear(ScoutYearSession::getPreviewId(), $role);
        $memberYearId = (int) ($params['id'] ?? 0);
        if ($memberYearId <= 0) {
            return $this->notFound();
        }

        $requested = $this->memberYearRepository->findById($memberYearId);
        if ($requested === null) {
            return $this->notFound();
        }

        // Normalise onto the member's most recent annual row: a link may
        // legitimately carry a past year's id, and the page shows the
        // latest either way.
        $latest = $this->memberYearRepository->findMostRecentForMember((int) $requested['member_id']);
        $memberYearId = (int) ($latest['id'] ?? $memberYearId);

        try {
            $profile = $this->memberService->getMemberProfile($memberYearId);
        } catch (MemberNotFoundException) {
            return $this->notFound();
        }

        $effectiveAge = $this->memberYearService->getEffectiveAge(
            MemberYearService::extractBirthYear($profile->birthDate),
            $profile->scoutYearOffset,
            MemberYearService::referenceYearFromScoutYearLabel($profile->scoutYearLabel)
        );
        $departureStatus = $this->departureService->getStatus($profile->memberYearId);

        return $this->render('admin/members/show.html.twig', array_merge(
            $this->adminMemberPageService->buildPageData($profile, $effective->id),
            [
                'member' => $profile,
                'breadcrumb_current' => trim($profile->lastName . ' ' . $profile->firstName),
                'effective_age' => $effectiveAge,
                'departure_leaving' => $departureStatus?->leaving ?? false,
                'departure_comment' => $departureStatus?->comment ?? '',
                'is_temporary_member' => TemporaryMemberSession::get() === $profile->memberYearId,
                // Keyed on the PERSISTENT member id, not the annual row:
                // a note about a person outlives the scout year that saw
                // it written.
                'notes' => $this->memberNoteService->listForMember($profile->memberId),
                'note_max_length' => MemberNoteService::MAX_LENGTH,
                // The year the page is actually showing, which is the
                // member's own latest — not necessarily the effective one.
                'year_label' => $profile->scoutYearLabel,
                'is_past_year' => $profile->scoutYearLabel !== $effective->label,
            ]
        ));
    }

    /**
     * POST /admin/members/{id}/notes — add one dated staff note.
     *
     * `role_min: admin`, the page's own floor: only the Staff d'Unité and
     * the superadmin reach these, and the router's guard is the whole
     * guarantee — there is no per-section compartmenting to apply on top
     * (see Core\Member\MemberNoteService's docblock for what that costs).
     *
     * @param array<string, string> $params
     */
    public function addNote(Request $request, array $params): Response
    {
        [$profile, $error] = $this->loadMember($params);
        if ($error !== null) {
            return $error;
        }
        if (($guard = $this->guardCsrf($request, $this->memberPath($profile->memberYearId))) !== null) {
            return $guard;
        }

        try {
            $this->memberNoteService->add(
                $profile->memberId,
                (string) $request->getBody('body', ''),
                AuthSession::getUserAccountId()
            );
            FlashMessage::set('success', 'Note ajoutée.');
        } catch (MemberNoteException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirect($this->memberPath($profile->memberYearId));
    }

    /**
     * POST /admin/members/{id}/notes/{note_id} — correct an entry.
     *
     * Any reader may edit any entry, deliberately: everyone who can read
     * these is a chef d'unité. The author and the date are not touched —
     * they are what gives the history its meaning.
     *
     * @param array<string, string> $params
     */
    public function updateNote(Request $request, array $params): Response
    {
        [$profile, $error] = $this->loadMember($params);
        if ($error !== null) {
            return $error;
        }
        if (($guard = $this->guardCsrf($request, $this->memberPath($profile->memberYearId))) !== null) {
            return $guard;
        }

        try {
            $this->memberNoteService->update(
                $profile->memberId,
                (int) ($params['note_id'] ?? 0),
                (string) $request->getBody('body', ''),
                AuthSession::getUserAccountId()
            );
            FlashMessage::set('success', 'Note modifiée.');
        } catch (MemberNoteException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirect($this->memberPath($profile->memberYearId));
    }

    /**
     * POST /admin/members/{id}/notes/{note_id}/delete
     *
     * A note written by mistake on the wrong person has to be able to
     * disappear, or somebody works around it by appending "ignorer la
     * note ci-dessus".
     *
     * @param array<string, string> $params
     */
    public function deleteNote(Request $request, array $params): Response
    {
        [$profile, $error] = $this->loadMember($params);
        if ($error !== null) {
            return $error;
        }
        if (($guard = $this->guardCsrf($request, $this->memberPath($profile->memberYearId))) !== null) {
            return $guard;
        }

        try {
            $this->memberNoteService->delete(
                $profile->memberId,
                (int) ($params['note_id'] ?? 0),
                AuthSession::getUserAccountId()
            );
            FlashMessage::set('success', 'Note supprimée.');
        } catch (MemberNoteException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirect($this->memberPath($profile->memberYearId));
    }

    /**
     * POST /admin/members/{id}/documents/{document_id}/renvoyer — send one
     * private document to the family again.
     *
     * The one gesture the Staff d'Unité bypass on `files.owner_member_id`
     * exists for (ARCHITECTURE.md §8.3): a family says « nous n'avons rien
     * reçu », and without this the only answer is to deposit the whole
     * federation PDF again.
     *
     * Three things are decided here rather than by the form:
     *
     * - **The document must belong to THIS member.** A document id in a
     *   request body is a request, never an authority (SECURITY.md §3):
     *   without this check the route would mail any private document in the
     *   site to any address the staff can reach through a member sheet.
     * - **The address is the member's own, read from their record.** The
     *   form carries none, so there is nothing to tamper with and no way to
     *   turn this into a send-anywhere endpoint.
     * - **It is journaled at `security` level**, like the opening itself,
     *   with identifiers only — a copy of a nominative document leaving the
     *   site is worth the same trace as one being read.
     *
     * @param array<string, string> $params
     */
    public function resendDocument(Request $request, array $params): Response
    {
        [$profile, $error] = $this->loadMember($params);
        if ($error !== null) {
            return $error;
        }
        $path = $this->memberPath($profile->memberYearId);
        if (($guard = $this->guardCsrf($request, $path)) !== null) {
            return $guard;
        }

        $documentId = (int) ($params['document_id'] ?? 0);
        $document = $documentId > 0 ? $this->memberDocumentService->findDocument($documentId) : null;
        if ($document === null || $document->memberId !== $profile->memberId) {
            return $this->notFound();
        }

        $address = trim((string) $profile->email);
        if ($address === '') {
            FlashMessage::set(
                'error',
                'Le site ne connaît aucune adresse e-mail pour ce membre. Corrigez-la dans Desk, '
                . 'puis relancez l\'envoi.'
            );

            return $this->redirect($path);
        }

        try {
            $this->memberDocumentMailer->send(
                $document->title,
                $document->fileId,
                $address,
                (string) $this->settingService->get('site_name', null, 'Votre unité')
            );
        } catch (\Throwable $e) {
            FlashMessage::set(
                'error',
                'L\'envoi a échoué. Vérifiez l\'adresse du membre, puis réessayez.'
            );

            return $this->redirect($path);
        }

        $this->journalService->log(
            'core',
            'member_document_resent',
            'security',
            'Document privé d\'un membre renvoyé par e-mail',
            ['member_document_id' => $document->id, 'member_id' => $document->memberId, 'file_id' => $document->fileId],
            AuthSession::getUserAccountId()
        );

        FlashMessage::set('success', 'Document renvoyé par e-mail.');

        return $this->redirect($path);
    }

    private function memberPath(int $memberYearId): string
    {
        return '/admin/members/' . $memberYearId;
    }

    /**
     * The member a POST route acts on, resolved exactly as show() does
     * — including the normalisation onto their most recent annual row, so
     * a note added from a former member's page attaches to the person and
     * not to the year the URL happened to name.
     *
     * @param array<string, string> $params
     * @return array{0: \Core\Member\MemberProfile|null, 1: Response|null}
     */
    private function loadMember(array $params): array
    {
        $memberYearId = (int) ($params['id'] ?? 0);
        if ($memberYearId <= 0) {
            return [null, $this->notFound()];
        }

        $requested = $this->memberYearRepository->findById($memberYearId);
        if ($requested === null) {
            return [null, $this->notFound()];
        }

        $latest = $this->memberYearRepository->findMostRecentForMember((int) $requested['member_id']);

        try {
            return [$this->memberService->getMemberProfile((int) ($latest['id'] ?? $memberYearId)), null];
        } catch (MemberNotFoundException) {
            return [null, $this->notFound()];
        }
    }

    /**
     * GET /admin/members/export — the current search's results (?q=...) or
     * an explicit selection of them (?selected[]=...) as a canonical
     * member .xlsx (Core\Member\Export — same generic, mail-merge-reusable
     * format as every member export on the site, ARCHITECTURE.md §8.61).
     * Selected ids are re-validated server-side against the effective
     * scout year — a stale or forged id is silently dropped, never
     * exported.
     *
     * @param array<string, string> $params
     */
    public function export(Request $request, array $params): Response
    {
        $role = Role::fromString(AuthSession::getRole());
        $effective = $this->resolver->getEffectiveYear(ScoutYearSession::getPreviewId(), $role);

        $selectedRaw = $request->getQuery('selected');
        $selectedIds = is_array($selectedRaw) ? array_values(array_filter(array_map('intval', $selectedRaw), fn(int $id) => $id > 0)) : [];

        if ($selectedIds !== []) {
            $memberYearIds = array_values(array_filter(
                array_unique($selectedIds),
                fn(int $id) => $this->searchService->findById($effective->id, $id) !== null
            ));
            $scope = 'selection';
        } else {
            $query = trim((string) $request->getQuery('q', ''));
            // The same membership scope the screen is showing — exporting
            // the actives while looking at « Tous » would be a surprise.
            // Never the widened past-year search, though: the canonical
            // member export is one scout year's worth of columns, and a
            // former member has no row in this one.
            $memberYearIds = array_map(
                fn(\Core\Member\Service\MemberSearchResult $r) => $r->memberYearId,
                $this->searchService->search(
                    $effective->id,
                    $query,
                    MemberSearchService::normalizeScope($request->getQuery('scope'))
                )
            );
            $scope = 'search';
        }

        if ($memberYearIds === []) {
            return new Response('Aucun membre à exporter.', 400);
        }

        $rows = $this->exportRowBuilder->buildForMemberYears($memberYearIds, $effective->id);
        $xlsx = $this->exportService->buildSpreadsheet($rows, $role, 'Membres ' . $effective->label);

        // Counts only — the search query itself can contain a person's
        // name, so it never reaches the journal.
        $this->journalService->log(
            'core',
            'member_search_exported',
            'info',
            'Export des membres depuis la recherche',
            ['scout_year_id' => $effective->id, 'scope' => $scope, 'row_count' => count($rows)],
            AuthSession::getUserAccountId()
        );

        $filename = 'membres-' . preg_replace('/[^0-9A-Za-z_-]/', '_', $effective->label) . '.xlsx';

        return \Core\Http\SpreadsheetResponse::download($xlsx, $filename);
    }
}
