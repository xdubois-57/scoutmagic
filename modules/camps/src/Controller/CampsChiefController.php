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
use Core\Security\AuthSession;
use Core\Security\Role;
use Core\View\EditableContentService;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;
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
        private ?PlaceSummaryService $summaries = null
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
        return $this->render('@camps/camp_form.html.twig', [
            'camp' => null,
            'place' => $this->places->findById((int) $request->getQuery('lieu', 0)),
            'breadcrumb_trail' => $this->trail(),
            'place_options' => $this->placeOptions($this->places->findAllVisible(), (int) $request->getQuery('lieu', 0)),
            'sections' => $this->sections->getAllWithBranches(),
            'default_country' => (string) ($this->settings->get('camps_default_country', 'camps', 'Belgique') ?? ''),
            'stay_type_options' => $this->options(CampLabels::STAY_TYPES, Camp::STAY_GRAND_CAMP),
            'status_options' => $this->options(CampLabels::STATUSES, Camp::STATUS_TO_CONFIRM),
            'note' => '',
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
            $placeId = $this->resolvePlace($request, $actorId);
            $campId = $this->campService->create(
                $placeId,
                $this->campFields($request),
                $actorId,
                fn(array $ids): ?string => $this->sectionDescriber->describeAsText($ids)
            );
            $this->saveNote($campId, (string) $request->getBody('note', ''), null, $actorId);
        } catch (CampsException $e) {
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect('/chefs/camps/nouveau');
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

        $pastReviews = $this->reviews->findByCamps(
            array_map(static fn(Camp $c): int => $c->id, $split['past'])
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
            'past' => $this->decorateCamps($past),
            'past_total' => count($split['past']),
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

        return $this->render('@camps/place_form.html.twig', [
            'place' => $place,
            'breadcrumb_current' => 'Modifier ' . $place->name,
            'breadcrumb_trail' => $this->trail($place),
        ]);
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
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect('/chefs/camps/lieux/' . $place->id . '/modifier');
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
            'contacts' => $this->contacts->findByCamp($camp->id),
            'contact_role_options' => $this->options(ContactService::ROLES, ''),
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

        return $this->render('@camps/camp_form.html.twig', [
            'camp' => $camp,
            'place' => $formPlace,
            'breadcrumb_trail' => $this->trail($formPlace, $camp),
            'place_options' => $this->placeOptions($this->places->findAllVisible(), $camp->placeId),
            'sections' => $this->sections->getAllWithBranches(),
            'default_country' => '',
            'stay_type_options' => $this->options(CampLabels::STAY_TYPES, $camp->stayType),
            'status_options' => $this->options(CampLabels::STATUSES, $camp->status),
            'note' => $this->editableContent->get(CampService::noteKey($camp->id), '') ?? '',
        ]);
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
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect('/chefs/camps/sejours/' . $camp->id . '/modifier');
        }

        FlashMessage::set('success', 'Séjour mis à jour.');

        return $this->redirect('/chefs/camps/sejours/' . $camp->id);
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
            \Modules\Camps\Mail\CampsMessageConsumer::CONSUMER_ID,
            \Modules\Camps\Mail\CampsMessageConsumer::UNSORTED_REFERENCE
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
     * A label map as <select> options, built here rather than in Twig:
     * turning a hash into an ordered list of option rows is data
     * preparation, and a template doing it ends up depending on which
     * Twig filters this version happens to ship.
     *
     * @param array<string, string> $labels
     * @return array<int, array{value: string, label: string, selected: bool}>
     */
    private function options(array $labels, string $selected): array
    {
        $options = [];
        foreach ($labels as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label, 'selected' => $value === $selected];
        }

        return $options;
    }

    /**
     * The past-stays status filter, as partials/chip_picker.html.twig
     * wants it — "Tous" first, then one chip per status.
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

    private function notFound(): Response
    {
        return (new Response('', 404))->setBody('Not Found');
    }
}
