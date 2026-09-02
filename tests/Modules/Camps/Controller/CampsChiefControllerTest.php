<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Controller;

use Core\Audit\AuditRepository;
use Core\Audit\AuditService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\Request;
use Core\Member\SectionService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Core\View\TwigFactory;
use Modules\Camps\Controller\CampsChiefController;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Repository\ContactRepository;
use Modules\Camps\Repository\DocumentRepository;
use Modules\Camps\Repository\LinkRepository;
use Modules\Camps\Repository\PlaceRepository;
use Modules\Camps\Repository\Review;
use Modules\Camps\Repository\ReviewRepository;
use Modules\Camps\Service\CampAlbumService;
use Modules\Camps\Service\CampService;
use Modules\Camps\Service\DuplicatePlaceDetector;
use Modules\Camps\Service\PlaceArchiveService;
use Modules\Camps\Service\PlaceService;
use Modules\Camps\Service\ReviewService;
use Modules\Camps\Service\SectionDescriber;
use Core\Http\FlashMessage;
use Modules\Camps\Service\PlaceSummaryService;
use Modules\Camps\Service\SummaryOutcome;
use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmResponse;
use Modules\LlmConnector\Api\LlmTier;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Camps\CampsTestHelper;

/**
 * Creating a stay and its place from one form.
 *
 * One form writing two rows is the whole point of the screen — and the
 * whole risk: whatever it writes before the first refusal stays written.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class CampsChiefControllerTest extends TestCase
{
    private \PDO $pdo;
    private PlaceRepository $places;
    private CampRepository $camps;
    private CampsChiefController $controller;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CampsTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->places = new PlaceRepository($this->pdo);
        $this->camps = new CampRepository($this->pdo, $encryption);
        $audit = new AuditService(new AuditRepository($this->pdo, $encryption));
        $settings = new SettingService(new SettingRepository($this->pdo));
        $sections = new SectionService(
            \Core\Database\Connection::withPdo($this->pdo),
            $encryption,
            new \Core\Badge\MemberBadgeRepository($this->pdo)
        );
        $albums = new CampAlbumService($audit, null);
        $reviews = new ReviewRepository($this->pdo);

        $this->controller = new CampsChiefController(
            TwigFactory::create(
                dirname(__DIR__, 4) . '/core/View/templates',
                false,
                ['camps' => dirname(__DIR__, 4) . '/modules/camps/views']
            ),
            $this->places,
            $this->camps,
            new PlaceService($this->places, $audit),
            // With the PlaceRepository the composition root gives it: without
            // it, nothing marks a place's summary stale and this suite would
            // pass while the nightly summary silently never regenerated.
            new CampService($this->camps, $audit, $this->places),
            new SectionDescriber($sections),
            $sections,
            new EditableContentService(new EditableContentRepository($this->pdo)),
            $audit,
            $settings,
            new ContactRepository($this->pdo, $encryption),
            new LinkRepository($this->pdo),
            new DocumentRepository($this->pdo),
            $albums,
            $reviews,
            new ReviewService($reviews, $audit, $this->places),
            new DuplicatePlaceDetector($this->places, null),
            new PlaceArchiveService($this->places, $this->camps, $audit)
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'chief@test.com', 'chief');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    /**
     * @param array<string, string> $overrides
     */
    private function post(array $overrides = []): \Core\Http\Response
    {
        $body = array_merge([
            '_csrf_token' => CsrfGuard::generateToken(),
            // A brand-new place, and "create it anyway" already answered so
            // the duplicate screen does not stand in the way.
            'place_id' => '0',
            'confirm_new' => '1',
            'place_name' => 'Ferme de la Vallée',
            'place_city' => 'Mozet',
            'stay_type' => 'grand_camp',
            'status' => 'confirmed',
            'start_date' => '2027-07-12',
            'end_date' => '2027-07-19',
        ], $overrides);

        return $this->controller->store(
            new Request('POST', '/chefs/camps', [], $body, [], []),
            []
        );
    }

    private function countPlaces(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM camp_places')->fetchColumn();
    }

    public function testAValidFormCreatesThePlaceAndTheStay(): void
    {
        $response = $this->post();

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(1, $this->countPlaces());
        $this->assertCount(1, $this->camps->findByPlace($this->places->findAllVisible()[0]->id));
    }

    public function testAStayRefusedByValidationLeavesNoPlaceBehind(): void
    {
        // The form writes the place first. A stay refused afterwards used
        // to leave that place standing — a row nobody asked for, on the
        // places list, in the duplicate detector's candidates, and on the
        // map, with no stay to explain it.
        $response = $this->post(['start_date' => '', 'end_date' => '', 'year_only' => '']);

        // The form comes back rather than redirecting to an empty one, so
        // the chief still has what they typed (see § "les formulaires
        // gardent la saisie").
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $this->countPlaces(), 'A refused stay must leave no orphan place.');
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM camp_camps')->fetchColumn());
    }

    public function testAnImpossibleDateRangeLeavesNoPlaceBehindEither(): void
    {
        $this->post(['start_date' => '2027-07-19', 'end_date' => '2027-07-12']);

        $this->assertSame(0, $this->countPlaces());
    }

    public function testAnUnknownStayTypeLeavesNoPlaceBehindEither(): void
    {
        $this->post(['stay_type' => 'colonie-de-vacances']);

        $this->assertSame(0, $this->countPlaces());
    }

    public function testAStayCannotBeAttachedToAnArchivedPlace(): void
    {
        // An archived place is off every ordinary screen, and the picker
        // never offers it — so the stay would be invisible the moment it
        // was saved.
        $placeId = $this->places->create('Ferme du Bois', null, null, 'Mozet', null, null);
        $this->places->archive($placeId, true);

        $this->post(['place_id' => (string) $placeId, 'confirm_new' => '0']);

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM camp_camps')->fetchColumn());
    }

    public function testAStayIsStillAttachableToALivePlace(): void
    {
        $placeId = $this->places->create('Ferme du Bois', null, null, 'Mozet', null, null);

        $this->post(['place_id' => (string) $placeId, 'confirm_new' => '0']);

        $this->assertCount(1, $this->camps->findByPlace($placeId));
    }

    // ── The form keeps what was typed ───────────────────────────────

    public function testARefusedCreationRendersTheFormAgainWithTheTypedValues(): void
    {
        // A chief who described a new place, ticked its sections and wrote
        // a note used to lose all of it because one date was wrong, and
        // the message explaining why arrived on a blank form.
        $response = $this->post([
            'start_date' => '2027-07-19',
            'end_date' => '2027-07-12',
            'place_name' => 'Ferme de la Vallée',
            'address' => 'Chemin du Tronquoy 4',
            'postal_code' => '5340',
            'city' => 'Mozet',
            'participant_count' => '65',
            'booked_by_name' => 'Thomas Dupont',
            'price' => '2450,00',
        ]);
        $html = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Ferme de la Vallée', $html);
        $this->assertStringContainsString('Chemin du Tronquoy 4', $html);
        $this->assertStringContainsString('5340', $html);
        $this->assertStringContainsString('Mozet', $html);
        $this->assertStringContainsString('Thomas Dupont', $html);
        $this->assertStringContainsString('2450,00', $html);
        // And the reason it came back, on the screen that shows the field.
        $this->assertStringContainsString('alert-danger', $html);
    }

    public function testARefusedCreationKeepsTheTickedSections(): void
    {
        $this->pdo->exec("INSERT INTO age_branches (id, desk_code, label, sort_order) VALUES (1, 'LOU', 'Louveteaux', 1)");
        $this->pdo->exec("INSERT INTO sections (id, name, desk_code, age_branch_id, is_active) VALUES (7, 'La Meute', 'MEU', 1, 1)");

        $response = $this->post([
            'start_date' => '', 'end_date' => '', 'year_only' => '',
            'section_ids' => ['7'],
        ]);

        $this->assertMatchesRegularExpression(
            '/id="section-7"[^>]*checked/',
            (string) preg_replace('/\s+/', ' ', $response->getBody())
        );
    }

    public function testARefusedPlaceEditRendersTheFormAgainWithTheTypedValues(): void
    {
        $placeId = $this->places->create('Ferme du Bois', null, null, 'Mozet', null, null);

        $response = $this->controller->updatePlace(
            new Request('POST', '/chefs/camps/lieux/' . $placeId, [], [
                '_csrf_token' => CsrfGuard::generateToken(),
                'place_name' => '',
                'address' => 'Chemin du Tronquoy 4',
                'city' => 'Mozet',
            ], [], []),
            ['id' => (string) $placeId]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Chemin du Tronquoy 4', $response->getBody());
        $this->assertStringContainsString('alert-danger', $response->getBody());
    }

    // ── Past stays are paginated ────────────────────────────────────

    public function testThePastStaysSettingIsActuallyRead(): void
    {
        $placeId = $this->places->create('Domaine de Mozet', null, null, 'Mozet', null, null);
        foreach ([2019, 2020, 2021] as $year) {
            $this->camps->create(
                $placeId,
                \Modules\Camps\Repository\Camp::STAY_GRAND_CAMP,
                $year . '-07-12',
                $year . '-07-19',
                null,
                \Modules\Camps\Repository\Camp::STATUS_CONFIRMED,
                null,
                null,
                null,
                null,
                []
            );
        }

        $this->pdo->exec(
            "INSERT INTO settings (setting_key, setting_value, module_id, setting_type, label, description)
             VALUES ('camps_past_stays_per_page', '1', 'camps', 'number', 'x', 'x')"
        );

        $response = $this->controller->showPlace(
            new Request('GET', '/chefs/camps/lieux/' . $placeId, [], [], [], []),
            ['id' => (string) $placeId]
        );
        $html = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        // Matched on the card's own wording rather than on the bare year:
        // the page carries a 64-hex CSRF token, so a four-digit needle is
        // present by accident about once in a thousand renders — which is
        // exactly how this assertion failed in CI on a change that touched
        // neither camps nor pagination.
        $this->assertStringContainsString('juillet 2021', $html, 'The most recent past stay is the one shown.');
        $this->assertStringNotContainsString('juillet 2019', $html, 'Only one stay per page.');
        // And a way to reach the rest.
        $this->assertStringContainsString('?statut=&amp;page=2', $html);
    }

    // ── The stay page ───────────────────────────────────────────────

    private function stayPage(int $campId): string
    {
        return $this->controller->showCamp(
            new Request('GET', '/chefs/camps/sejours/' . $campId, [], [], [], []),
            ['id' => (string) $campId]
        )->getBody();
    }

    /**
     * The same controller as setUp()'s, plus the optional summary service
     * — the only dependency these two tests need and the composition root
     * wires the same way.
     */
    /**
     * The real summary service, with whichever connector the test wants —
     * the notes and the sections included, exactly as the composition
     * root builds it.
     */
    private function summaryService(LlmConnectorInterface $llm): PlaceSummaryService
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        return new PlaceSummaryService(
            $this->places,
            $this->camps,
            new ReviewRepository($this->pdo),
            new EditableContentService(new EditableContentRepository($this->pdo)),
            new SectionDescriber(new SectionService(
                \Core\Database\Connection::withPdo($this->pdo),
                $encryption,
                new \Core\Badge\MemberBadgeRepository($this->pdo)
            )),
            $llm
        );
    }

    private function controllerWithSummaries(PlaceSummaryService $summaries): CampsChiefController
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $audit = new AuditService(new AuditRepository($this->pdo, $encryption));
        $reviews = new ReviewRepository($this->pdo);
        $sections = new SectionService(
            \Core\Database\Connection::withPdo($this->pdo),
            $encryption,
            new \Core\Badge\MemberBadgeRepository($this->pdo)
        );

        return new CampsChiefController(
            TwigFactory::create(
                dirname(__DIR__, 4) . '/core/View/templates',
                false,
                ['camps' => dirname(__DIR__, 4) . '/modules/camps/views']
            ),
            $this->places,
            $this->camps,
            new PlaceService($this->places, $audit),
            // With the PlaceRepository the composition root gives it: without
            // it, nothing marks a place's summary stale and this suite would
            // pass while the nightly summary silently never regenerated.
            new CampService($this->camps, $audit, $this->places),
            new SectionDescriber($sections),
            $sections,
            new EditableContentService(new EditableContentRepository($this->pdo)),
            $audit,
            new SettingService(new SettingRepository($this->pdo)),
            new ContactRepository($this->pdo, $encryption),
            new LinkRepository($this->pdo),
            new DocumentRepository($this->pdo),
            new CampAlbumService($audit, null),
            $reviews,
            new ReviewService($reviews, $audit, $this->places),
            new DuplicatePlaceDetector($this->places, null),
            new PlaceArchiveService($this->places, $this->camps, $audit),
            null,
            null,
            $summaries
        );
    }

    private function aStay(): int
    {
        $placeId = $this->places->create('Domaine de Mozet', null, null, 'Mozet', null, null);

        return $this->camps->create(
            $placeId,
            \Modules\Camps\Repository\Camp::STAY_GRAND_CAMP,
            '2019-07-12',
            '2019-07-19',
            null,
            \Modules\Camps\Repository\Camp::STATUS_CONFIRMED,
            null,
            null,
            null,
            null,
            []
        );
    }

    public function testEachContactOffersItsEditAndDeleteControls(): void
    {
        $campId = $this->aStay();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $contacts = new ContactRepository($this->pdo, $encryption);
        $contactId = $contacts->create($campId, 'Mme Lambert', 'Propriétaire', 'lambert@example.org', null, null);

        $html = $this->stayPage($campId);

        // The two routes existed and nothing on any screen reached them.
        $this->assertStringContainsString('#contact-' . $contactId . '-modal', $html);
        $this->assertStringContainsString('action="/chefs/camps/contacts/' . $contactId . '"', $html);
        $this->assertStringContainsString(
            'action="/chefs/camps/contacts/' . $contactId . '/supprimer"',
            $html
        );
        $this->assertStringContainsString('bi-pencil', $html);
        $this->assertStringContainsString('bi-trash', $html);
        // Every destructive POST states its consequence, on the <form>.
        $this->assertMatchesRegularExpression(
            '/<form[^>]*contacts\/' . $contactId . '\/supprimer"[^>]*data-confirm="/',
            (string) preg_replace('/\s+/', ' ', $html)
        );
    }

    public function testTheEditDialogOpensOnTheRoleTheContactHolds(): void
    {
        $campId = $this->aStay();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $contacts = new ContactRepository($this->pdo, $encryption);
        $contacts->create($campId, 'Mme Lambert', 'Gestionnaire', null, '081 58 00 00', null);

        $html = (string) preg_replace('/\s+/', ' ', $this->stayPage($campId));

        $this->assertMatchesRegularExpression('/value="gestionnaire" selected/', $html);
    }

    /**
     * The note is given by clicking a star, not by opening a dropdown.
     *
     * A rating out of five is SHOWN as stars everywhere else in this
     * module; the one screen where it is given used to be the only one
     * speaking another language — and it cost two taps on a phone for
     * what is one.
     */
    public function testTheRatingIsGivenInStarsRatherThanADropdown(): void
    {
        $campId = $this->aStay();

        $html = (string) preg_replace('/\s+/', ' ', $this->stayPage($campId));

        // One radio per point of the scale, each reachable by its own label.
        for ($i = Review::MIN_RATING; $i <= Review::MAX_RATING; $i++) {
            $this->assertMatchesRegularExpression(
                '/<input type="radio" name="rating" id="review-rating-' . $i . '" value="' . $i . '"/',
                $html,
                'star ' . $i
            );
            $this->assertStringContainsString('for="review-rating-' . $i . '"', $html);
        }
        // The number is in the accessible name: five identical shapes are
        // not a rating to a screen reader.
        $this->assertStringContainsString(
            '<span class="visually-hidden">3 étoiles sur 5</span>',
            html_entity_decode($html)
        );
        $this->assertStringContainsString('<span class="visually-hidden">1 étoile sur 5</span>', html_entity_decode($html));
        // And nothing is left of the select it replaced.
        $this->assertDoesNotMatchRegularExpression('/<select[^>]*name="rating"/', $html);
    }

    /**
     * A comment with no number is a complete review, so « Pas de note »
     * stays reachable — and it is the state a stay with no review is in.
     */
    public function testTheRatingStartsWithoutOneAndCanBeGivenBackUp(): void
    {
        $campId = $this->aStay();

        $html = (string) preg_replace('/\s+/', ' ', $this->stayPage($campId));

        $this->assertMatchesRegularExpression(
            '/<input type="radio" class="btn-check" name="rating" id="review-rating-none" value="" autocomplete="off" checked>/',
            $html
        );
        $this->assertStringContainsString('Pas de note', html_entity_decode($html));
        $this->assertDoesNotMatchRegularExpression('/id="review-rating-[1-5]" value="[1-5]" autocomplete="off" checked/', $html);
    }

    public function testTheStoredRatingIsTheStarThatComesBackChecked(): void
    {
        $campId = $this->aStay();
        (new ReviewRepository($this->pdo))->save($campId, 4, null, null);

        $html = (string) preg_replace('/\s+/', ' ', $this->stayPage($campId));

        $this->assertMatchesRegularExpression(
            '/id="review-rating-4" value="4" autocomplete="off" checked/',
            $html
        );
        // Exactly one star is checked, and « pas de note » is not.
        $this->assertSame(1, preg_match_all('/id="review-rating-[1-5]"[^>]*checked/', $html));
        $this->assertDoesNotMatchRegularExpression('/id="review-rating-none"[^>]*checked/', $html);
    }

    /**
     * A cancelled stay is rated by nobody — nobody camped there — so the
     * stars are not offered at all (Service\ReviewService says the same
     * thing on the way in, which is the answer that counts).
     */
    public function testACancelledStayIsOfferedNoStars(): void
    {
        $placeId = $this->places->create('Domaine de Mozet', null, null, 'Mozet', null, null);
        $campId = $this->camps->create(
            $placeId,
            \Modules\Camps\Repository\Camp::STAY_GRAND_CAMP,
            '2019-07-12',
            '2019-07-19',
            null,
            \Modules\Camps\Repository\Camp::STATUS_CANCELLED,
            null,
            null,
            null,
            null,
            []
        );

        $html = $this->stayPage($campId);

        $this->assertStringNotContainsString('id="review-rating-1"', $html);
        $this->assertStringContainsString('review-comment', $html);
    }

    /**
     * « Écrire le résumé maintenant » failing must say WHY.
     *
     * The reported bug: a chief who had given four stars and written a
     * comment pressed the button and was told « il n'y a pas assez à
     * raconter » — one sentence covering five different causes, led by the
     * only one they could act on and the one it almost never was. Here the
     * connector has a provider and a model, just not on the tier this
     * feature asks for, and the answer must send them to the
     * administrator rather than back to their keyboard.
     */
    public function testAFailedSummarySaysWhichFailureItWas(): void
    {
        $campId = $this->aStay();
        $placeId = (int) $this->pdo->query('SELECT place_id FROM camp_camps WHERE id = ' . $campId)->fetchColumn();
        (new ReviewRepository($this->pdo))->save($campId, 4, 'Terrain plat, eau au robinet.', null);

        $llm = $this->createStub(LlmConnectorInterface::class);
        $llm->method('isAvailable')->willReturn(true);
        $llm->method('isTierAvailable')->willReturn(false);
        $llm->method('complete')->willThrowException(LlmException::noModel(LlmTier::CHEAP));

        $this->controllerWithSummaries($this->summaryService($llm))->regenerateSummary(
            new Request('POST', '/chefs/camps/lieux/' . $placeId . '/resume', [], ['_csrf_token' => CsrfGuard::generateToken()], [], []),
            ['id' => (string) $placeId]
        );

        $flash = FlashMessage::get();
        $this->assertNotNull($flash);
        $this->assertSame('error', $flash['type']);
        $this->assertSame(SummaryOutcome::Unavailable->message(), $flash['message']);
        // The material was never the problem, so the message must not say
        // it was.
        $this->assertStringNotContainsString('pas assez à raconter', $flash['message']);
    }

    /**
     * And the button is not offered in the first place: a control whose
     * only possible outcome is an error message is not a control.
     */
    public function testThePlaceSheetOffersNoSummaryButtonWhenTheTierIsMissing(): void
    {
        $campId = $this->aStay();
        $placeId = (int) $this->pdo->query('SELECT place_id FROM camp_camps WHERE id = ' . $campId)->fetchColumn();

        $llm = $this->createStub(LlmConnectorInterface::class);
        // Configured — just not for the tier a summary asks for.
        $llm->method('isAvailable')->willReturn(true);
        $llm->method('isTierAvailable')->willReturn(false);

        $html = $this->controllerWithSummaries($this->summaryService($llm))->showPlace(
            new Request('GET', '/chefs/camps/lieux/' . $placeId, [], [], [], []),
            ['id' => (string) $placeId]
        )->getBody();

        $this->assertStringNotContainsString('Écrire le résumé maintenant', html_entity_decode($html));
    }

    public function testAWrittenSummarySaysSoAndIsStored(): void
    {
        $campId = $this->aStay();
        $placeId = (int) $this->pdo->query('SELECT place_id FROM camp_camps WHERE id = ' . $campId)->fetchColumn();
        (new ReviewRepository($this->pdo))->save($campId, 4, 'Terrain plat, eau au robinet.', null);

        $llm = $this->createStub(LlmConnectorInterface::class);
        $llm->method('isAvailable')->willReturn(true);
        $llm->method('isTierAvailable')->willReturn(true);
        $llm->method('complete')->willReturn(new LlmResponse('Terrain plat, accueil constant.', null, 90, 20));

        $this->controllerWithSummaries($this->summaryService($llm))->regenerateSummary(
            new Request('POST', '/chefs/camps/lieux/' . $placeId . '/resume', [], ['_csrf_token' => CsrfGuard::generateToken()], [], []),
            ['id' => (string) $placeId]
        );

        $flash = FlashMessage::get();
        $this->assertNotNull($flash);
        $this->assertSame('success', $flash['type']);
        $this->assertSame('Terrain plat, accueil constant.', $this->places->findById($placeId)?->aiSummary);
    }

    /**
     * A note is half of what a place's summary is made of, so editing one
     * has to invalidate that summary — otherwise the sentence a chief
     * just wrote waits for the next unrelated change before the nightly
     * task ever reads it.
     *
     * It works because the note is saved through the stay form, and
     * Service\CampService::update() marks the place stale on every
     * submit. That is load-bearing now, so it is pinned here.
     */
    public function testEditingAStaysNoteMakesItsPlacesSummaryStale(): void
    {
        $campId = $this->aStay();
        $placeId = (int) $this->pdo->query('SELECT place_id FROM camp_camps WHERE id = ' . $campId)->fetchColumn();
        $this->places->saveSummary($placeId, 'Un résumé écrit hier.');
        $this->assertFalse($this->places->findById($placeId)?->aiSummaryIsStale);

        $this->controller->updateCamp(
            new Request('POST', '/chefs/camps/sejours/' . $campId, [], [
                '_csrf_token' => CsrfGuard::generateToken(),
                'stay_type' => 'grand_camp',
                'status' => 'confirmed',
                'start_date' => '2019-07-12',
                'end_date' => '2019-07-19',
                'note' => '<p>La cuisine était petite mais bien. Pas d\'endroit pour un tabou.</p>',
            ], [], []),
            ['id' => (string) $campId]
        );

        $this->assertTrue($this->places->findById($placeId)?->aiSummaryIsStale);
    }

    public function testAReviewCanBeTakenBackOff(): void
    {
        $campId = $this->aStay();
        (new ReviewRepository($this->pdo))->save($campId, 4, 'Terrain plat, eau au robinet.', null);

        $html = $this->stayPage($campId);

        $this->assertStringContainsString(
            'action="/chefs/camps/sejours/' . $campId . '/avis/supprimer"',
            $html
        );
        $this->assertStringContainsString("Retirer l'avis", html_entity_decode($html));
    }

    /**
     * The stay page keeps exactly one primary button of its own.
     *
     * It used to carry three: the page_header's « Modifier », « Ajouter
     * le contact » at the bottom of a six-field form, and « Enregistrer
     * l'avis » at the bottom of another — three ways of telling a chef
     * d'unité « the main action is here » on one screen (design.md §7.4).
     * Both forms are dialogs now, opened by outline buttons, and each
     * holds the single primary of the dialog it lives in.
     */
    /**
     * Whether to offer « Anonymiser » is decided by the controller, not by
     * a role comparison spelled out in the template. The route itself is
     * role_min: admin, so a chief offered the link would only be refused
     * on the next click — and a template naming the role strings that pass
     * is a rule nobody can change in one place.
     */
    public function testAChiefIsNotOfferedTheContactAnonymisation(): void
    {
        $campId = $this->aStay();
        $contacts = new ContactRepository($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));
        $contacts->create($campId, 'M. Delvaux', 'Propriétaire', null, null, null);

        AuthSession::login(1, 'chief@test.com', 'chief');
        $this->assertStringNotContainsString('Anonymiser', $this->stayPage($campId));

        AuthSession::login(1, 'admin@test.com', 'admin');
        $this->assertStringContainsString('Anonymiser', $this->stayPage($campId));
    }

    public function testTheStayPageOffersItsTwoFormsAsDialogs(): void
    {
        $campId = $this->aStay();

        $html = (string) preg_replace('/\s+/', ' ', $this->stayPage($campId));

        foreach (['#contact-add-modal', '#review-modal'] as $target) {
            $this->assertStringContainsString('data-bs-target="' . $target . '"', $html, $target);
        }
        // The forms themselves are untouched — same action, same method.
        $this->assertStringContainsString(
            'action="/chefs/camps/sejours/' . $campId . '/contacts" id="contact-add-form"',
            $html
        );
        $this->assertStringContainsString(
            'action="/chefs/camps/sejours/' . $campId . '/avis" id="review-form"',
            $html
        );
        // Their submit buttons reach them from the dialog footer.
        $this->assertStringContainsString('form="contact-add-form"', $html);
        $this->assertStringContainsString('form="review-form"', $html);
    }

    public function testTheStayPageShowsOneUnhiddenPrimaryAtATime(): void
    {
        $campId = $this->aStay();

        $html = $this->stayPage($campId);
        // Everything after the first dialog is inside a `.modal`, so what
        // is left in the page body is the page_header's own action.
        $body = substr($html, 0, strpos($html, '<div class="modal fade"') ?: strlen($html));

        $this->assertSame(1, substr_count($body, 'btn-primary'), $body);
    }

    // ── « Créer un camp depuis ce message » ─────────────────────────

    /**
     * A controller wired the way public/index.php wires it when
     * inbound_mail is enabled, with one unsorted message to read.
     *
     * The connector is the same optional dependency as in production: with
     * one, the place name is read out of the message body; without one,
     * the form is pre-filled with everything except a place name.
     *
     * @param list<array{from: string, to: string, message: int}> $moves
     */
    /**
     * @param \Modules\InboundMail\Api\InboundAttachment[] $attachments
     */
    private function controllerWithUnsortedMessage(
        array &$moves,
        ?\Modules\LlmConnector\Api\LlmConnectorInterface $llm = null,
        string $bodyText = 'Nous confirmons du 12 au 19 juillet 2028. Prix : 2450 €.',
        ?\Modules\Camps\Mail\AttachmentTextReader $attachmentText = null,
        array $attachments = []
    ): CampsChiefController {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $audit = new AuditService(new AuditRepository($this->pdo, $encryption));
        $settings = new SettingService(new SettingRepository($this->pdo));
        $sections = new SectionService(
            \Core\Database\Connection::withPdo($this->pdo),
            $encryption,
            new \Core\Badge\MemberBadgeRepository($this->pdo)
        );
        $reviews = new ReviewRepository($this->pdo);
        $albums = new CampAlbumService($audit, null);

        $message = new \Modules\InboundMail\Api\InboundMessage(
            id: 42,
            mailboxId: 2,
            // Attached to nothing: « créer un camp depuis ce message »
            // starts from a message no stay claimed, which since IT-07
            // means no association at all rather than one filed under a
            // reserved `unsorted` reference.
            consumerId: '',
            businessReference: '',
            linkOrigin: \Modules\InboundMail\Api\LinkOrigin::SENDER,
            subject: 'Confirmation de réservation',
            fromEmail: 'info@mozet.be',
            fromName: 'Domaine de Mozet',
            messageId: '<abc@mail>',
            inReplyTo: null,
            sentAt: new \DateTimeImmutable('2027-11-02 09:00:00'),
            bodyText: $bodyText,
            bodyHtml: '',
            attachments: $attachments
        );

        $inbound = $this->createMock(\Modules\InboundMail\Api\InboundMailInterface::class);
        $inbound->method('findForReference')->willReturn([]);
        $inbound->method('findForTriage')->willReturn([$message]);
        $inbound->method('attach')->willReturnCallback(
            function (string $c, string $reference, int $id, ?int $actor) use (&$moves): bool {
                $moves[] = ['to' => $reference, 'message' => $id];

                return true;
            }
        );

        $campService = new CampService($this->camps, $audit, $this->places);
        $placeService = new PlaceService($this->places, $audit);
        $duplicates = new DuplicatePlaceDetector($this->places, null);

        return new CampsChiefController(
            TwigFactory::create(
                dirname(__DIR__, 4) . '/core/View/templates',
                false,
                ['camps' => dirname(__DIR__, 4) . '/modules/camps/views']
            ),
            $this->places,
            $this->camps,
            $placeService,
            $campService,
            new SectionDescriber($sections),
            $sections,
            new EditableContentService(new EditableContentRepository($this->pdo)),
            $audit,
            $settings,
            new ContactRepository($this->pdo, $encryption),
            new LinkRepository($this->pdo),
            new DocumentRepository($this->pdo),
            $albums,
            $reviews,
            new ReviewService($reviews, $audit, $this->places),
            $duplicates,
            new PlaceArchiveService($this->places, $this->camps, $audit),
            $inbound,
            null,
            null,
            null,
            new \Modules\Camps\Mail\StayFromMailService(
                $this->camps,
                $campService,
                $placeService,
                $duplicates,
                new \Modules\Camps\Mail\MessageReader(),
                $settings,
                $llm,
                $attachmentText
            )
        );
    }

    /**
     * « Créer un camp depuis ce message » on a message whose booking is in
     * the PDF, which is how a real one arrives.
     *
     * The complaint this comes from: the form came back with a name and
     * nothing else, « on dirait que l'IA ne regarde pas le contrat attaché
     * à l'e-mail ». It did not — the reading stopped at the body, and the
     * body was « Bonjour, ».
     */
    public function testTheFormIsPreFilledFromTheContractInTheAttachment(): void
    {
        $storagePath = sys_get_temp_dir() . '/campsprefill_' . uniqid();
        mkdir($storagePath . '/inbound', 0700, true);

        try {
            $files = new \Core\File\FileRepository($this->pdo);
            $bytes = (string) file_get_contents(
                dirname(__DIR__, 3) . '/fixtures/pdf/camp_booking_contract.pdf'
            );
            file_put_contents($storagePath . '/inbound/contrat.pdf', $bytes);
            $fileId = $files->create(
                'inbound/contrat.pdf',
                'contrat.pdf',
                'application/pdf',
                strlen($bytes),
                'chief',
                'inbound_mail',
                null,
                false
            );

            $moves = [];
            $controller = $this->controllerWithUnsortedMessage(
                $moves,
                $this->llmNaming('Centre de camp Le Grand Pré'),
                // A one-word body and a contract, the way they really come.
                'Bonjour,',
                new \Modules\Camps\Mail\AttachmentTextReader(
                    new \Core\File\StoredFileReader(
                        $files,
                        new \Core\File\EncryptedFileStorageService(
                            $files,
                            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)),
                            $storagePath
                        ),
                        $storagePath
                    )
                ),
                [new \Modules\InboundMail\Api\InboundAttachment(
                    1,
                    42,
                    $fileId,
                    'contrat.pdf',
                    'application/pdf',
                    strlen($bytes),
                    'hash'
                )]
            );

            $html = $controller->create(
                new Request('GET', '/chefs/camps/nouveau', ['message' => '42'], [], [], []),
                []
            )->getBody();

            $this->assertStringContainsString('2026-09-18', $html);
            $this->assertStringContainsString('2026-09-20', $html);
            $this->assertStringContainsString('Centre de camp Le Grand Pré', $html);
            // Three days: the form arrives with « Petit camp » already
            // chosen, the same value the automatic path would have filed.
            $this->assertMatchesRegularExpression(
                '/value="weekend" selected/',
                (string) preg_replace('/\s+/', ' ', $html)
            );
            // The forfait, not the deposit — and the head count the
            // contract states in as many words.
            $this->assertStringContainsString('1 468,80', $html);
            $this->assertStringContainsString('value="120"', $html);
            // And where the place is: without it, a place created from a
            // message can never be geocoded and never reaches the map.
            $this->assertStringContainsString('value="Prins Boudewijnlaan"', $html);
            $this->assertStringContainsString('value="1653"', $html);
            $this->assertStringContainsString('value="Dworp"', $html);
        } finally {
            foreach (glob($storagePath . '/inbound/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($storagePath . '/inbound');
            @rmdir($storagePath);
        }
    }

    /** A connector answering one place name, read out of the body. */
    private function llmNaming(string $placeName): \Modules\LlmConnector\Api\LlmConnectorInterface
    {
        $llm = $this->createStub(\Modules\LlmConnector\Api\LlmConnectorInterface::class);
        $llm->method('isAvailable')->willReturn(true);
        // The tier the reading itself uses — canNamePlaces() asks this one,
        // not isAvailable(), and a stub that answered only the wider
        // question was silently naming nothing.
        $llm->method('isTierAvailable')->willReturn(true);
        // The whole venue, which is what one call really asks for.
        $venue = [
            'place_name' => $placeName,
            'address' => 'Prins Boudewijnlaan',
            'postal_code' => '1653',
            'city' => 'Dworp',
        ];
        $llm->method('complete')->willReturn(new \Modules\LlmConnector\Api\LlmResponse(
            (string) json_encode($venue),
            $venue,
            100,
            10
        ));

        return $llm;
    }

    public function testTheCreationFormIsPreFilledFromAnUnsortedMessage(): void
    {
        $moves = [];
        $controller = $this->controllerWithUnsortedMessage($moves, $this->llmNaming('Domaine de Mozet'));

        $html = $controller->create(
            new Request('GET', '/chefs/camps/nouveau', ['message' => '42'], [], [], []),
            []
        )->getBody();

        $this->assertStringContainsString('Domaine de Mozet', $html);
        $this->assertStringContainsString('2028-07-12', $html);
        $this->assertStringContainsString('2028-07-19', $html);
        $this->assertStringContainsString('name="message_id" value="42"', $html);
    }

    public function testWithoutTheConnectorTheFormArrivesWithoutAPlaceName(): void
    {
        $moves = [];
        $controller = $this->controllerWithUnsortedMessage($moves);

        $html = (string) preg_replace('/\s+/', ' ', $controller->create(
            new Request('GET', '/chefs/camps/nouveau', ['message' => '42'], [], [], []),
            []
        )->getBody());

        // The dates are patterns and still arrive; the name is not, and
        // the sender's display name is never proposed as one — a chief
        // types it, which is the validation this path exists for.
        $this->assertStringContainsString('2028-07-12', $html);
        $this->assertStringContainsString('name="place_name" value=""', $html);
    }

    public function testAKnownPlaceIsSelectedRatherThanDescribedAgain(): void
    {
        $placeId = $this->places->create('Domaine de Mozet', null, null, 'Mozet', null, null);
        $moves = [];
        $controller = $this->controllerWithUnsortedMessage($moves);

        $html = (string) preg_replace('/\s+/', ' ', $controller->create(
            new Request('GET', '/chefs/camps/nouveau', ['message' => '42'], [], [], []),
            []
        )->getBody());

        $this->assertMatchesRegularExpression(
            '/value="' . $placeId . '" selected/',
            $html
        );
    }

    public function testTheMessageIsFiledUnderTheStayItCreated(): void
    {
        $moves = [];
        $controller = $this->controllerWithUnsortedMessage($moves);

        $response = $controller->store(
            new Request('POST', '/chefs/camps', [], [
                '_csrf_token' => CsrfGuard::generateToken(),
                'place_id' => '0',
                'confirm_new' => '1',
                'place_name' => 'Domaine de Mozet',
                'stay_type' => 'grand_camp',
                'status' => 'to_confirm',
                'start_date' => '2028-07-12',
                'end_date' => '2028-07-19',
                'message_id' => '42',
            ], [], []),
            []
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertCount(1, $moves);
        // An association, not a move: there is no reserved reference to
        // move the message OFF any more (IT-07).
        $this->assertStringStartsWith('camp-', $moves[0]['to']);
        $this->assertSame(42, $moves[0]['message']);
    }

    public function testTheStayPageSaysSoWhenNoMailWasFiledUnderIt(): void
    {
        $campId = $this->aStay();

        // inbound_mail is absent from this controller, which is exactly the
        // shape of a site that collects no mail — the section is still
        // there and says nothing arrived.
        $this->assertStringContainsString('Correspondance', $this->stayPage($campId));
        $this->assertStringContainsString('Aucun message rattaché', $this->stayPage($campId));
    }
}
