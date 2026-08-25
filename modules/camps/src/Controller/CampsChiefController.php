<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Controller;

use Core\Audit\AuditService;
use Core\Audit\AuditSource;
use Core\Config\SettingService;
use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthSession;
use Core\Security\Role;
use Core\View\EditableContentService;
use Modules\Camps\Mail\CampsMessageConsumer;
use Modules\Camps\Mail\StayFromMailService;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Repository\Contact;
use Modules\Camps\Repository\ContactRepository;
use Modules\Camps\Repository\DocumentRepository;
use Modules\Camps\Repository\FieldProposalRepository;
use Modules\Camps\Repository\LinkRepository;
use Modules\Camps\Repository\Place;
use Modules\Camps\Repository\PlaceRepository;
use Modules\Camps\Repository\Review;
use Modules\Camps\Repository\ReviewRepository;
use Modules\Camps\Service\CampAlbumService;
use Modules\Camps\Service\CampLabels;
use Modules\Camps\Service\CampService;
use Modules\Camps\Service\CampsException;
use Modules\Camps\Service\ContactService;
use Modules\Camps\Service\DuplicatePlaceDetector;
use Modules\Camps\Service\PlaceArchiveService;
use Modules\Camps\Service\PlaceService;
use Modules\Camps\Service\PlaceSummaryService;
use Modules\Camps\Service\ReviewService;
use Modules\Camps\Service\SectionDescriber;
use Modules\InboundMail\Api\InboundMailInterface;
use Twig\Environment;

/**
 * The module's three screens: the list, a place's sheet, a stay's detail
 * — plus the forms behind them. Every route is role_min chief, declared
 * in module.json and enforced by the Router; nothing here re-checks a
 * role.
 */
class CampsChiefController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private PlaceRepository $places,
        private CampRepository $camps,
        private PlaceService $placeService,
        private CampService $campService,
        private SectionDescriber $sectionDescriber,
        private SectionService $sections,
        private EditableContentService $editableContent,
        private AuditService $audit,
        private SettingService $settings,
        private ContactRepository $contacts,
        private LinkRepository $links,
        private DocumentRepository $documents,
        private CampAlbumService $albumService,
        private ReviewRepository $reviews,
        private ReviewService $reviewService,
        private DuplicatePlaceDetector $duplicates,
        private PlaceArchiveService $archiveService,
        private ?InboundMailInterface $inboundMail = null,
        private ?FieldProposalRepository $proposals = null,
        private ?PlaceSummaryService $summaries = null,
        private ?ScoutYearResolver $scoutYears = null,
        /**
         * Only « Créer un camp depuis ce message » needs it: the reading
         * that pre-fills this form is the same one that would have created
         * the stay by itself with `camps_auto_create_from_mail` on.
         */
        private ?StayFromMailService $stayFromMail = null
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $term = trim((string) $request->getQuery('q', ''));
        $archived = $request->getQuery('archives') === '1';
        $today = $this->today();

        $places = $this->places->search($term, $archived);
        $upcoming = $archived ? [] : $this->camps->findUpcoming($today);

        // The search box filters places in SQL; the upcoming list is
        // filtered here against the SAME places, so a search that hides a
        // place never leaves one of its stays visible above it.
        if ($term !== '' && !$archived) {
            $visibleIds = array_map(static fn(Place $p): int => $p->id, $places);
            $upcoming = array_values(array_filter(
                $upcoming,
                static fn(Camp $c): bool => in_array($c->placeId, $visibleIds, true)
            ));
        }

        // Each upcoming card names its place, so the places are indexed
        // once here rather than looked up per card in the template.
        $placesById = [];
        foreach ($places as $place) {
            $placesById[$place->id] = $place;
        }

        return $this->render('@camps/list.html.twig', [
            'search_term' => $term,
            'archived' => $archived,
            'places' => $this->decoratePlaces($places),
            'map_places' => $archived ? [] : $this->mapPlaces(),
            'unsorted_mail_count' => $archived ? 0 : $this->unsortedMailCount(),
            'places_without_coordinates' => $archived ? 0 : $this->countWithoutCoordinates($places),
            'upcoming' => array_map(
                fn(array $entry): array => $entry + ['place' => $placesById[$entry['camp']->placeId] ?? null],
                $this->decorateCamps($upcoming)
            ),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function create(Request $request, array $params): Response
    {
        $place = $this->places->findById((int) $request->getQuery('lieu', 0));
        $submitted = $this->campFormValues(null, $place, '');

        // « Créer un camp depuis ce message » — the unsorted screen's way
        // out when nothing could be attributed automatically.
        $messageId = (int) $request->getQuery('message', 0);
        if ($messageId > 0) {
            $submitted = $this->prefilledFromMessage($submitted, $messageId);
            $place = $this->places->findById((int) $submitted['place_id']) ?? $place;
        }

        return $this->renderCampForm(null, $place, $submitted, []);
    }

    /**
     * The stay form, filled in with what one unsorted message says.
     *
     * The reading comes from Mail\StayFromMailService — the same one that
     * creates the stay by itself when the setting allows it, so a chief
     * doing it by hand and the automatic path can never disagree about
     * what a message states. Nothing is invented: a message the reader
     * cannot make sense of simply leaves the form empty.
     *
     * @param array<string, mixed> $submitted
     * @return array<string, mixed>
     */
    private function prefilledFromMessage(array $submitted, int $messageId): array
    {
        if ($this->inboundMail === null || $this->stayFromMail === null) {
            return $submitted;
        }

        $message = $this->inboundMail->findOneForReference(
            CampsMessageConsumer::CONSUMER_ID,
            CampsMessageConsumer::UNSORTED_REFERENCE,
            $messageId
        );
        if ($message === null) {
            return $submitted;
        }

        $values = $this->stayFromMail->readValues($message);
        $placeId = $this->stayFromMail->matchPlaceIdFor($message, $values['place_name']);

        return array_merge($submitted, [
            // A place the module already knows is SELECTED rather than
            // described again — the whole point of the detector.
            'place_id' => $placeId !== null ? (string) $placeId : '',
            'place_name' => $placeId === null ? $values['place_name'] : '',
            'start_date' => $values['start_date'],
            'end_date' => $values['end_date'],
            'price' => $values['price'],
            'message_id' => (string) $messageId,
        ]);
    }

    /**
     * One form creates the stay and, when no existing place was picked,
     * its place too. Two screens would mean a chief has to think of the
     * place before the camp, which is the opposite of the order in which
     * they learn about either.
     *
     * @param array<string, string> $params
     */
    public function store(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/chefs/camps/nouveau')) !== null) {
            return $guard;
        }

        $actorId = AuthSession::getUserAccountId();

        // Creating a new place: offer the ones that may already be it,
        // once. "Créer quand même" comes back with confirm_new=1 and the
        // check is skipped — a warning that cannot be dismissed is a
        // warning that gets worked around.
        if ((int) $request->getBody('place_id', 0) <= 0 && $request->getBody('confirm_new') !== '1') {
            $candidates = $this->duplicates->findCandidates($this->placeFields($request));
            if ($candidates !== []) {
                return $this->render('@camps/place_duplicate.html.twig', [
                    'candidates' => $candidates,
                    'submitted' => $request->getBodyAll(),
                    'breadcrumb_current' => 'Ce lieu existe peut-être déjà',
                    'breadcrumb_trail' => $this->trail(),
                ]);
            }
        }

        try {
            // The stay's own fields are validated FIRST, before anything is
            // written. resolvePlace() creates a place when the form carries
            // a new one, and a stay refused afterwards used to leave that
            // place behind — a row nobody asked for, on the places list, in
            // the duplicate detector's candidates, and on the map.
            //
            // Validated here and again inside create(): the second call is
            // the service's own contract with every other caller, and
            // paying for it twice is cheaper than a place nobody wanted.
            $campFields = $this->campFields($request);
            $this->campService->validate($campFields);

            $placeId = $this->resolvePlace($request, $actorId);
            $campId = $this->campService->create(
                $placeId,
                $campFields,
                $actorId,
                fn(array $ids): ?string => $this->sectionDescriber->describeAsText($ids)
            );
            $this->saveNote($campId, (string) $request->getBody('note', ''), null, $actorId);
            $this->fileMessageUnder($campId, (int) $request->getBody('message_id', 0));
        } catch (CampsException $e) {
            // The form comes back with what was typed in it. A redirect to
            // an empty form threw away a place description, a set of
            // sections and a note over one bad date — and the message
            // explaining why arrived on a screen that no longer showed the
            // field it was about.
            return $this->renderCampForm(
                null,
                $this->places->findById((int) $request->getBody('place_id', 0)),
                $this->submittedCampValues($request),
                [$e->getMessage()]
            );
        }

        FlashMessage::set('success', 'Séjour enregistré.');

        return $this->redirect('/chefs/camps/sejours/' . $campId);
    }

    /**
     * @param array<string, string> $params
     */
    public function showPlace(Request $request, array $params): Response
    {
        $place = $this->places->findById((int) ($params['id'] ?? 0));
        if ($place === null) {
            return $this->notFound();
        }

        $today = $this->today();
        $split = $this->campService->split($this->camps->findByPlace($place->id), $today);
        $statusFilter = (string) $request->getQuery('statut', '');
        $past = $statusFilter !== '' && isset(CampLabels::STATUSES[$statusFilter])
            ? array_values(array_filter($split['past'], static fn(Camp $c): bool => $c->status === $statusFilter))
            : $split['past'];

        // A place the unit went back to every year for fifteen years is
        // exactly the place whose sheet is worth reading, and it was the
        // one that rendered fifteen cards nobody asked for. The setting
        // has existed since the module shipped; nothing read it.
        $perPage = max(1, (int) ($this->settings->get('camps_past_stays_per_page', 'camps', '20') ?? 20));
        $pastTotal = count($past);
        $pageCount = max(1, (int) ceil($pastTotal / $perPage));
        $page = min($pageCount, max(1, (int) $request->getQuery('page', 1)));
        $pastPage = array_slice($past, ($page - 1) * $perPage, $perPage);

        $pastReviews = $this->reviews->findByCamps(
            array_map(static fn(Camp $c): int => $c->id, $pastPage)
        );

        return $this->render('@camps/place.html.twig', [
            'place' => $place,
            'latest_rating' => $this->reviews->latestRatingForPlace($place->id),
            // Merging and archiving are the module's admin-only actions:
            // not rendered below that role rather than rendered disabled,
            // because a visible button that always refuses is a trap.
            'is_unit_chief' => Role::fromString(AuthSession::getRole())->hasAccess(Role::ADMIN),
            'archive_warning' => $this->archiveService->pendingWarning($place, $today),
            'summary_available' => $this->summaries !== null && $this->summaries->isAvailable(),
            'reviews' => $pastReviews,
            'breadcrumb_current' => $place->name,
            'breadcrumb_trail' => $this->trail(),
            'upcoming' => $this->decorateCamps($split['upcoming']),
            'past' => $this->decorateCamps($pastPage),
            'past_total' => count($split['past']),
            'past_page' => $page,
            'past_page_count' => $pageCount,
            'past_base_url' => '/chefs/camps/lieux/' . $place->id
                . '?statut=' . rawurlencode($statusFilter) . '&page=',
            'status_filter' => $statusFilter,
            'status_chips' => $this->statusChips($statusFilter),
            'audit_page' => $this->audit->page(PlaceService::ENTITY_TYPE, $place->id, 1, AuditService::DEFAULT_PER_PAGE),
            'audit_labels' => CampLabels::FIELD_LABELS,
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function editPlace(Request $request, array $params): Response
    {
        $place = $this->places->findById((int) ($params['id'] ?? 0));
        if ($place === null) {
            return $this->notFound();
        }

        return $this->renderPlaceForm($place, $this->placeFormValues($place), []);
    }

    /**
     * @param array<string, string> $params
     */
    public function updatePlace(Request $request, array $params): Response
    {
        $place = $this->places->findById((int) ($params['id'] ?? 0));
        if ($place === null) {
            return $this->notFound();
        }
        if (($guard = $this->guardCsrf($request, '/chefs/camps/lieux/' . $place->id)) !== null) {
            return $guard;
        }

        try {
            $this->placeService->update($place, $this->placeFields($request), AuthSession::getUserAccountId());
        } catch (CampsException $e) {
            return $this->renderPlaceForm(
                $place,
                $this->submittedPlaceValues($request),
                [$e->getMessage()]
            );
        }

        FlashMessage::set('success', 'Lieu mis à jour.');

        return $this->redirect('/chefs/camps/lieux/' . $place->id);
    }

    /**
     * @param array<string, string> $params
     */
    public function showCamp(Request $request, array $params): Response
    {
        $camp = $this->camps->findById((int) ($params['id'] ?? 0));
        if ($camp === null) {
            return $this->notFound();
        }
        $place = $this->places->findById($camp->placeId);
        if ($place === null) {
            return $this->notFound();
        }
        $today = $this->today();

        return $this->render('@camps/camp.html.twig', [
            'camp' => $this->decorateCamp($camp),
            'place' => $place,
            'breadcrumb_current' => CampLabels::dateRange($camp->startDate, $camp->endDate, $camp->yearOnly),
            'breadcrumb_trail' => $this->trail($place),
            'note' => $this->editableContent->get(CampService::noteKey($camp->id), '') ?? '',
            'contacts' => $this->decorateContacts($this->contacts->findByCamp($camp->id)),
            'contact_role_options' => $this->options(ContactService::ROLES, ''),
            // Decided here rather than by a role comparison in the
            // template: what an anonymisation needs is a rule about a
            // right, and a template spelling out which role strings pass
            // is a rule nobody can change in one place. The route itself
            // is the authority (module.json, role_min: admin); this only
            // decides whether to OFFER what that route would allow.
            'can_anonymise_contacts' => Role::fromString(AuthSession::getRole())->hasAccess(Role::ADMIN),
            // Only whether photos are POSSIBLE, never how many. Counting
            // them would mean resolving the album, and resolving it
            // CREATES it (DelegatedAlbumManager::ensureAlbum is
            // create-if-missing) — one gallery row written for every camp
            // page anybody merely opens. The album is created on the
            // photos page instead, which is the page actually about them.
            'album_available' => $this->albumService->isAvailable(),
            'proposals' => $this->proposals !== null ? $this->proposals->findByCamp($camp->id) : [],
            'review' => $this->reviews->findByCamp($camp->id),
            'review_open' => $this->reviewService->isOpen($camp, $today),
            'review_allows_rating' => $this->reviewService->allowsRating($camp),
            'rating_options' => $this->ratingOptions($this->reviews->findByCamp($camp->id)),
            'links' => $this->links->findByCamp($camp->id),
            'messages' => $this->campMessages($camp->id),
            'document_count' => $this->documents->countByCamp($camp->id),
            'audit_page' => $this->audit->page(CampService::ENTITY_TYPE, $camp->id, 1, AuditService::DEFAULT_PER_PAGE),
            'audit_labels' => CampLabels::FIELD_LABELS,
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function editCamp(Request $request, array $params): Response
    {
        $camp = $this->camps->findById((int) ($params['id'] ?? 0));
        if ($camp === null) {
            return $this->notFound();
        }

        $formPlace = $this->places->findById($camp->placeId);
        $note = $this->editableContent->get(CampService::noteKey($camp->id), '') ?? '';

        return $this->renderCampForm($camp, $formPlace, $this->campFormValues($camp, $formPlace, $note), []);
    }

    /**
     * @param array<string, string> $params
     */
    public function updateCamp(Request $request, array $params): Response
    {
        $camp = $this->camps->findById((int) ($params['id'] ?? 0));
        if ($camp === null) {
            return $this->notFound();
        }
        if (($guard = $this->guardCsrf($request, '/chefs/camps/sejours/' . $camp->id)) !== null) {
            return $guard;
        }

        $actorId = AuthSession::getUserAccountId();
        try {
            $this->campService->update(
                $camp,
                $this->campFields($request),
                $actorId,
                fn(array $ids): ?string => $this->sectionDescriber->describeAsText($ids)
            );
            $this->saveNote(
                $camp->id,
                (string) $request->getBody('note', ''),
                $this->editableContent->get(CampService::noteKey($camp->id), '') ?? '',
                $actorId
            );
        } catch (CampsException $e) {
            return $this->renderCampForm(
                $camp,
                $this->places->findById($camp->placeId),
                $this->submittedCampValues($request),
                [$e->getMessage()]
            );
        }

        FlashMessage::set('success', 'Séjour mis à jour.');

        return $this->redirect('/chefs/camps/sejours/' . $camp->id);
    }

    // ── The two forms, and what they keep ───────────────────────────

    /**
     * Renders the stay form from one bag of values, whether they come from
     * the stored stay or from the POST that was just refused. One renderer
     * so a field added to the form cannot be filled on the way in and
     * dropped on the way back.
     *
     * @param array<string, mixed> $submitted
     * @param string[] $errors
     */
    private function renderCampForm(?Camp $camp, ?Place $place, array $submitted, array $errors): Response
    {
        return $this->render('@camps/camp_form.html.twig', [
            'camp' => $camp,
            'place' => $place,
            'breadcrumb_trail' => $camp !== null ? $this->trail($place, $camp) : $this->trail(),
            'place_options' => $this->placeOptions(
                $this->places->findAllVisible(),
                (int) ($submitted['place_id'] ?? 0)
            ),
            'sections' => $this->sections->getAllWithBranches(),
            'stay_type_options' => $this->options(CampLabels::STAY_TYPES, (string) ($submitted['stay_type'] ?? '')),
            'status_options' => $this->options(CampLabels::STATUSES, (string) ($submitted['status'] ?? '')),
            'booked_by_candidates' => $this->bookedByCandidates(),
            'submitted' => $submitted,
            'errors' => $errors,
            'note' => (string) ($submitted['note'] ?? ''),
        ]);
    }

    /**
     * @param array<string, mixed> $submitted
     * @param string[] $errors
     */
    private function renderPlaceForm(Place $place, array $submitted, array $errors): Response
    {
        return $this->render('@camps/place_form.html.twig', [
            'place' => $place,
            'submitted' => $submitted,
            'errors' => $errors,
            'breadcrumb_current' => 'Modifier ' . $place->name,
            'breadcrumb_trail' => $this->trail($place),
        ]);
    }

    /**
     * The stay form's fields as the stored stay holds them — the starting
     * point a chief opening the form sees.
     *
     * @return array<string, mixed>
     */
    private function campFormValues(?Camp $camp, ?Place $place, string $note): array
    {
        return [
            'place_id' => $camp !== null
                ? (string) $camp->placeId
                : ($place !== null ? (string) $place->id : ''),
            'place_name' => '',
            'address' => '',
            'postal_code' => '',
            'city' => '',
            'country' => (string) ($this->settings->get('camps_default_country', 'camps', 'Belgique') ?? ''),
            'website_url' => '',
            'stay_type' => $camp !== null ? $camp->stayType : Camp::STAY_GRAND_CAMP,
            'start_date' => $camp !== null ? ($camp->startDate ?? '') : '',
            'end_date' => $camp !== null ? ($camp->endDate ?? '') : '',
            'year_only' => $camp !== null && $camp->yearOnly !== null ? (string) $camp->yearOnly : '',
            'status' => $camp !== null ? $camp->status : Camp::STATUS_TO_CONFIRM,
            'price' => $camp !== null && $camp->priceCents !== null
                ? number_format($camp->priceCents / 100, 2, ',', ' ')
                : '',
            'participant_count' => $camp !== null && $camp->participantCount !== null
                ? (string) $camp->participantCount
                : '',
            'booked_by_member_id' => $camp !== null && $camp->bookedByMemberId !== null
                ? (string) $camp->bookedByMemberId
                : '',
            'booked_by_name' => $camp !== null ? ($camp->bookedByName ?? '') : '',
            'section_ids' => array_map('strval', $camp !== null ? $camp->sectionIds : []),
            'note' => $note,
            // The unsorted message this stay is being created from, when
            // there is one — carried through the form so the message ends
            // up filed under the stay it produced.
            'message_id' => '',
        ];
    }

    /**
     * The same bag, straight off the POST that was just refused. Strings
     * throughout, exactly as they were typed — re-formatting a price a
     * chief mistyped would hide the mistake they have to correct.
     *
     * @return array<string, mixed>
     */
    private function submittedCampValues(Request $request): array
    {
        $sections = $request->getBody('section_ids');

        return [
            'place_id' => (string) $request->getBody('place_id', ''),
            'place_name' => (string) $request->getBody('place_name', ''),
            'address' => (string) $request->getBody('address', ''),
            'postal_code' => (string) $request->getBody('postal_code', ''),
            'city' => (string) $request->getBody('city', ''),
            'country' => (string) $request->getBody('country', ''),
            'website_url' => (string) $request->getBody('website_url', ''),
            'stay_type' => (string) $request->getBody('stay_type', Camp::STAY_GRAND_CAMP),
            'start_date' => (string) $request->getBody('start_date', ''),
            'end_date' => (string) $request->getBody('end_date', ''),
            'year_only' => (string) $request->getBody('year_only', ''),
            'status' => (string) $request->getBody('status', Camp::STATUS_TO_CONFIRM),
            'price' => (string) $request->getBody('price', ''),
            'participant_count' => (string) $request->getBody('participant_count', ''),
            'booked_by_member_id' => (string) $request->getBody('booked_by_member_id', ''),
            'booked_by_name' => (string) $request->getBody('booked_by_name', ''),
            'section_ids' => is_array($sections) ? array_map('strval', $sections) : [],
            'note' => (string) $request->getBody('note', ''),
            'message_id' => (string) $request->getBody('message_id', ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function placeFormValues(Place $place): array
    {
        return [
            'place_name' => $place->name,
            'address' => $place->address ?? '',
            'postal_code' => $place->postalCode ?? '',
            'city' => $place->city ?? '',
            'country' => $place->country ?? '',
            'website_url' => $place->websiteUrl ?? '',
            'latitude' => $place->latitude !== null ? (string) $place->latitude : '',
            'longitude' => $place->longitude !== null ? (string) $place->longitude : '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function submittedPlaceValues(Request $request): array
    {
        return [
            'place_name' => (string) $request->getBody('place_name', ''),
            'address' => (string) $request->getBody('address', ''),
            'postal_code' => (string) $request->getBody('postal_code', ''),
            'city' => (string) $request->getBody('city', ''),
            'country' => (string) $request->getBody('country', ''),
            'website_url' => (string) $request->getBody('website_url', ''),
            'latitude' => (string) $request->getBody('latitude', ''),
            'longitude' => (string) $request->getBody('longitude', ''),
        ];
    }

    /**
     * Who could plausibly have booked a camp: this scout year's staff, as
     * `booked_by_member_id` wants them (a `members` id, which outlives the
     * year the person was on staff).
     *
     * The list is the one a chief already reads on « Staffs » — no new
     * category of data reaches this screen, and no new endpoint had to
     * open a member search to a role that does not have one. A stay booked
     * eight years ago by somebody long gone keeps the free-text name, which
     * is why the field stays a text input with suggestions rather than
     * becoming a picker that refuses everybody else.
     *
     * @return array<int, array{id: int, name: string}>
     */
    private function bookedByCandidates(): array
    {
        if ($this->scoutYears === null) {
            return [];
        }

        $year = $this->scoutYears->getEffectiveYear(
            ScoutYearSession::getPreviewId(),
            Role::fromString(AuthSession::getRole())
        );

        $names = [];
        foreach ($this->sections->getAllWithBranches() as $section) {
            foreach ($this->sections->getSectionStaff((int) $section['id'], $year->id) as $profile) {
                $name = trim($profile->firstName . ' ' . $profile->lastName);
                if ($name !== '') {
                    $names[$profile->memberId] = $name;
                }
            }
        }

        asort($names, SORT_NATURAL | SORT_FLAG_CASE);

        $candidates = [];
        foreach ($names as $memberId => $name) {
            $candidates[] = ['id' => $memberId, 'name' => $name];
        }

        return $candidates;
    }

    /**
     * Picks the existing place the form named, or creates one from the
     * fields typed alongside it.
     */
    private function resolvePlace(Request $request, ?int $actorId): int
    {
        $existingId = (int) $request->getBody('place_id', 0);
        if ($existingId > 0) {
            $place = $this->places->findById($existingId);
            if ($place === null) {
                throw new CampsException('Ce lieu n\'existe pas ou plus.');
            }
            // An archived place is off every ordinary screen. A stay
            // attached to one would be invisible the moment it was saved,
            // and the picker never offers it — so an id that names one came
            // from a stale page or a forged POST either way.
            if ($place->isArchived) {
                throw new CampsException(
                    'Ce lieu est archivé : désarchivez-le d\'abord si vous voulez y rattacher un séjour.'
                );
            }

            return $place->id;
        }

        return $this->placeService->create($this->placeFields($request), $actorId);
    }

    /**
     * The note is stored as editable content but audited as a field of the
     * camp: its history belongs on the camp's timeline with everything
     * else, not in a separate place a reader would have to know about.
     */
    private function saveNote(int $campId, string $note, ?string $previous, ?int $actorId): void
    {
        $note = trim($note);
        $previous = $previous !== null ? trim($previous) : null;
        if ($note === ($previous ?? '')) {
            return;
        }

        $key = CampService::noteKey($campId);
        if ($note === '') {
            $this->editableContent->delete($key);
        } else {
            $this->editableContent->set($key, $note, 'rich_text', $actorId ?? 0);
        }

        // The note is free text a chief typed, and can be long. The
        // history records THAT it changed, never its two versions — a
        // timeline is not a diff viewer, and storing two paragraphs per
        // edit would bury every other change under them.
        $this->audit->record(
            CampService::ENTITY_TYPE,
            $campId,
            'note',
            null,
            null,
            AuditSource::Human,
            $note === '' ? 'Note effacée' : ($previous === null || $previous === '' ? 'Note ajoutée' : 'Note modifiée'),
            null,
            $actorId
        );
    }

    /**
     * @return array<string, string|null>
     */
    private function placeFields(Request $request): array
    {
        $fields = [
            'name' => (string) $request->getBody('place_name', ''),
            'address' => (string) $request->getBody('address', ''),
            'postal_code' => (string) $request->getBody('postal_code', ''),
            'city' => (string) $request->getBody('city', ''),
            'country' => (string) $request->getBody('country', ''),
            'website_url' => (string) $request->getBody('website_url', ''),
        ];

        // Coordinates travel only when the submitted form actually
        // carried them. Setting the keys unconditionally would mean the
        // camp-creation form — which has no coordinate inputs — posts two
        // empty values, and PlaceService would read that as "clear the
        // point", wiping a pin a chief had placed by hand.
        foreach (['latitude', 'longitude'] as $key) {
            $value = $request->getBody($key);
            if ($value !== null) {
                $fields[$key] = (string) $value;
            }
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private function campFields(Request $request): array
    {
        return [
            'stay_type' => (string) $request->getBody('stay_type', Camp::STAY_GRAND_CAMP),
            'start_date' => (string) $request->getBody('start_date', ''),
            'end_date' => (string) $request->getBody('end_date', ''),
            'year_only' => (string) $request->getBody('year_only', ''),
            'status' => (string) $request->getBody('status', Camp::STATUS_TO_CONFIRM),
            'price' => (string) $request->getBody('price', ''),
            'participant_count' => (string) $request->getBody('participant_count', ''),
            'booked_by_member_id' => (string) $request->getBody('booked_by_member_id', ''),
            'booked_by_name' => (string) $request->getBody('booked_by_name', ''),
            'section_ids' => is_array($request->getBody('section_ids')) ? $request->getBody('section_ids') : [],
        ];
    }

    /**
     * @param Place[] $places
     * @return array<int, array<string, mixed>>
     */
    private function decoratePlaces(array $places): array
    {
        $decorated = [];
        foreach ($places as $place) {
            $decorated[] = [
                'place' => $place,
                'stay_count' => $this->camps->countByPlace($place->id),
                // The most recent stay that actually carries a number,
                // with its year — never an average across the years. A
                // place is what it was like last time; a mean would let a
                // bad 2019 drag down a field that has changed hands
                // since, and would hide when the opinion is from.
                'latest_rating' => $this->reviews->latestRatingForPlace($place->id),
            ];
        }

        return $decorated;
    }

    /**
     * Each contact with the picker already pointing at the role it holds,
     * so the edit dialog opens on the current value rather than on « — ».
     * Computed here rather than in Twig: turning a stored label back into
     * the key its <option> carries is data preparation.
     *
     * @param Contact[] $contacts
     * @return array<int, array<string, mixed>>
     */
    private function decorateContacts(array $contacts): array
    {
        return array_map(fn(Contact $contact): array => [
            'contact' => $contact,
            'role_options' => $this->options(
                ContactService::ROLES,
                ContactService::roleKeyForLabel($contact->roleLabel)
            ),
        ], $contacts);
    }

    /**
     * @param Camp[] $camps
     * @return array<int, array<string, mixed>>
     */
    private function decorateCamps(array $camps): array
    {
        return array_map(fn(Camp $camp): array => $this->decorateCamp($camp), $camps);
    }

    /**
     * @return array<string, mixed>
     */
    private function decorateCamp(Camp $camp): array
    {
        return [
            'camp' => $camp,
            'label' => CampLabels::dateRange($camp->startDate, $camp->endDate, $camp->yearOnly),
            'stay_type_label' => CampLabels::stayType($camp->stayType),
            'status_label' => CampLabels::status($camp->status),
            'status_tone' => CampLabels::statusTone($camp->status),
            'price' => CampLabels::money($camp->priceCents),
            'sections' => $this->sectionDescriber->describe($camp->sectionIds),
        ];
    }

    /**
     * "Régénérer" on a place sheet — the one place a summary is written
     * synchronously, because a chief pressed a button and is waiting for
     * it. Everywhere else it is the daily task's job.
     *
     * @param array<string, string> $params
     */
    public function regenerateSummary(Request $request, array $params): Response
    {
        $place = $this->places->findById((int) ($params['id'] ?? 0));
        if ($place === null) {
            return $this->notFound();
        }
        if (($guard = $this->guardCsrf($request, '/chefs/camps/lieux/' . $place->id)) !== null) {
            return $guard;
        }

        $written = $this->summaries !== null && $this->summaries->refresh($place);
        FlashMessage::set(
            $written ? 'success' : 'error',
            $written
                ? 'Résumé régénéré.'
                : 'Le résumé n\'a pas pu être régénéré : il n\'y a pas assez à raconter, ou le connecteur IA n\'est pas disponible.'
        );

        return $this->redirect('/chefs/camps/lieux/' . $place->id);
    }

    /**
     * Moves the unsorted message a stay was created from onto that stay.
     *
     * A plain move() between two references of this module's own consumer
     * — the same one the « Rattacher » button uses. Without it, « Créer un
     * camp depuis ce message » would leave the message sitting in the
     * unsorted pile next to the stay it just produced.
     */
    private function fileMessageUnder(int $campId, int $messageId): void
    {
        if ($this->inboundMail === null || $messageId <= 0) {
            return;
        }

        $this->inboundMail->move(
            CampsMessageConsumer::CONSUMER_ID,
            CampsMessageConsumer::UNSORTED_REFERENCE,
            CampsMessageConsumer::referenceFor($campId),
            $messageId
        );
    }

    /**
     * The correspondence filed under this stay.
     *
     * A message could be attached to a stay — automatically, or by hand
     * from the unsorted screen — and then read nowhere: the stay's page
     * never mentioned it. Empty when inbound_mail is absent, which is
     * exactly true on a site that collects no mail.
     *
     * @return \Modules\InboundMail\Api\InboundMessage[]
     */
    private function campMessages(int $campId): array
    {
        if ($this->inboundMail === null) {
            return [];
        }

        return $this->inboundMail->findForReference(
            CampsMessageConsumer::CONSUMER_ID,
            CampsMessageConsumer::referenceFor($campId)
        );
    }

    /**
     * How many messages are waiting in the unsorted pile. Zero when
     * inbound_mail is absent — the optional dependency degrades to the
     * banner never appearing, which is exactly right on a site that
     * collects no mail.
     */
    private function unsortedMailCount(): int
    {
        if ($this->inboundMail === null) {
            return 0;
        }

        return count($this->inboundMail->findForReference(
            CampsMessageConsumer::CONSUMER_ID,
            CampsMessageConsumer::UNSORTED_REFERENCE
        ));
    }

    /**
     * The pins, as public/assets/js/camps-map.js wants them. Places
     * only — a place camped on four times is one pin, not four.
     *
     * @return array<int, array{name: string, locality: string, lat: float, lng: float, url: string}>
     */
    private function mapPlaces(): array
    {
        $pins = [];
        foreach ($this->places->findMappable() as $place) {
            $pins[] = [
                'name' => $place->name,
                'locality' => $place->locality(),
                'lat' => (float) $place->latitude,
                'lng' => (float) $place->longitude,
                'url' => '/chefs/camps/lieux/' . $place->id,
            ];
        }

        return $pins;
    }

    /**
     * How many live places the map cannot show. Said under the map rather
     * than left implicit: a chief comparing a six-pin map against a
     * twelve-place list would otherwise conclude the map is broken.
     *
     * @param Place[] $places
     */
    private function countWithoutCoordinates(array $places): int
    {
        return count(array_filter($places, static fn(Place $p): bool => !$p->hasCoordinates()));
    }

    /**
     * The place picker: the known places, preceded by the "a new one"
     * entry that opens the fields below it.
     *
     * @param Place[] $places
     * @return array<int, array{value: string, label: string, selected: bool}>
     */
    private function placeOptions(array $places, int $selectedId): array
    {
        $options = [[
            'value' => '',
            'label' => 'Un nouveau lieu…',
            'selected' => $selectedId <= 0,
        ]];
        foreach ($places as $place) {
            $locality = $place->locality();
            $options[] = [
                'value' => (string) $place->id,
                'label' => $place->name . ($locality !== '' ? ' — ' . $locality : ''),
                'selected' => $place->id === $selectedId,
            ];
        }

        return $options;
    }

    /**
     * The past-stays status filter, as partials/nav_rail.html.twig
     * wants it — "Tous" first, then one tab per status.
     *
     * @return array<int, array{id: string, label: string, selected: bool}>
     */
    private function statusChips(string $current): array
    {
        $chips = [['id' => '', 'label' => 'Tous', 'selected' => $current === '']];
        foreach (CampLabels::STATUSES as $value => $label) {
            $chips[] = ['id' => $value, 'label' => $label, 'selected' => $current === $value];
        }

        return $chips;
    }

    /**
     * The real ancestor PAGES above the current one, as
     * partials/breadcrumb_bar.html.twig's `breadcrumb_trail` wants them:
     * {label, url} pairs that render as actual links.
     *
     * This is what replaces the mockup's « ‹ Camps » button, which
     * design.md §7.3 forbids — the breadcrumb is this site's only back
     * affordance. It is a controller variable rather than a module.json
     * `parents` entry because both halves are dynamic: a place's name and
     * a place's id are not things a static manifest can know.
     *
     * @return array<int, array{label: string, url: string}>
     */
    private function trail(?Place $place = null, ?Camp $camp = null): array
    {
        $trail = [['label' => 'Camps', 'url' => '/chefs/camps']];
        if ($place !== null) {
            $trail[] = ['label' => $place->name, 'url' => '/chefs/camps/lieux/' . $place->id];
        }
        if ($camp !== null) {
            $trail[] = [
                'label' => CampLabels::dateRange($camp->startDate, $camp->endDate, $camp->yearOnly),
                'url' => '/chefs/camps/sejours/' . $camp->id,
            ];
        }

        return $trail;
    }

    /**
     * The rating picker, with "pas de note" first — a chief who wants to
     * write only a comment must not have to give a number to do it.
     *
     * @return array<int, array{value: string, label: string, selected: bool}>
     */
    private function ratingOptions(?Review $review): array
    {
        $current = $review?->rating;
        $options = [['value' => '', 'label' => 'Pas de note', 'selected' => $current === null]];
        for ($i = Review::MIN_RATING; $i <= Review::MAX_RATING; $i++) {
            $options[] = [
                'value' => (string) $i,
                'label' => $i . ' / ' . Review::MAX_RATING,
                'selected' => $current === $i,
            ];
        }

        return $options;
    }

    private function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today');
    }
}
