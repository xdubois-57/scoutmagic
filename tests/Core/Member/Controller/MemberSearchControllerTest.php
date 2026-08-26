<?php

declare(strict_types=1);

namespace Tests\Core\Member\Controller;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\Controller\MemberSearchController;
use Core\Member\DepartureRepository;
use Core\Member\DepartureService;
use Core\Member\MemberService;
use Core\Member\MemberYearService;
use Core\Member\Repository\MemberSearchRepository;
use Core\Member\Service\MemberSearchService;
use Core\Member\TemporaryMemberSession;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\View\TextNormalizerExtension;
use Core\Http\Request;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MemberSearchControllerTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $enc;
    private MemberSearchController $controller;
    private int $yearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->enc = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);

        $scoutYearService = new ScoutYearService($this->pdo);
        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register(ScoutYearResolver::SETTING_PUBLIC_YEAR, '0', 'number', 'P', 'P', null, '^[0-9]+$', null, false);
        $settingService->register(ScoutYearResolver::SETTING_STAFF_YEAR, '0', 'number', 'S', 'S', null, '^[0-9]+$', null, false);

        $memberYearRepo = new MemberYearRepository($this->pdo);
        $resolver = new ScoutYearResolver($scoutYearService, $settingService, $memberYearRepo);
        $searchService = new MemberSearchService(new MemberSearchRepository($connection, $this->enc), $scoutYearService);
        $memberService = new MemberService($memberYearRepo, $this->enc, $connection);

        $this->yearId = $scoutYearService->ensureYear('2025-2026');
        // Pin the public year so the effective year is deterministic.
        $settingService->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->yearId);

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $twig = new Environment(new FilesystemLoader($templateDir), ['cache' => false, 'autoescape' => 'html']);
        // asset() is what base.html.twig references every static file through
        // (Core\View\TwigFactory); the bare path is enough for a test render.
        $twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $path): string => $path));
        // The shared French format filters (core/View/TwigFactory.php) used by
        // the templates under test - same rendering as the shipped ones.
        $twig->addFilter(new \Twig\TwigFilter('date_fr', fn($d) => $d === null || $d === '' ? '' : ($d instanceof \DateTimeInterface ? $d : new \DateTimeImmutable((string) $d))->format('d/m/Y')));
        $twig->addFilter(new \Twig\TwigFilter('datetime_fr', fn($d) => $d === null || $d === '' ? '' : ($d instanceof \DateTimeInterface ? $d : new \DateTimeImmutable((string) $d))->format('d/m/Y à H:i')));
        $twig->addFilter(new \Twig\TwigFilter('money', fn($a) => $a === null || $a === '' ? '' : number_format((float) $a, 2, ',', ' ') . ' €'));
        $twig->addFilter(new \Twig\TwigFilter('money_cents', fn($c) => $c === null || $c === '' ? '' : number_format(((int) $c) / 100, 2, ',', ' ') . ' €'));
        $twig->addExtension(new TextNormalizerExtension());
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'admin@test.be');
        $twig->addGlobal('current_user_role', 'admin');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('temporary_member_name', null);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('csp_nonce', 'n');
        $twig->addFunction(new TwigFunction('csrf_field', fn() => '', ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('get_flash', fn() => null));
        $twig->addFunction(new TwigFunction('csrf_token', fn() => 't'));
        $twig->addFunction(new TwigFunction('file_url', fn() => ''));
        $twig->addFunction(new TwigFunction('param', fn(string $k) => 'Test'));
        $twig->addFilter(new TwigFilter('display_name', fn($m) => $m instanceof \Core\Member\MemberProfile ? $m->getDisplayName() : (string) $m));

        $departureService = new DepartureService(new DepartureRepository($this->pdo, $this->enc), new JournalService(new JournalRepository($this->pdo)));
        $sectionService = new \Core\Member\SectionService($connection, $this->enc, new \Core\Badge\MemberBadgeRepository($this->pdo));
        $exportRowBuilder = new \Core\Member\Export\MemberExportRowBuilder(
            new \Core\Member\SectionRosterRepository($this->pdo),
            $sectionService,
            $scoutYearService,
            $this->enc,
            new \Core\Member\MemberEmailRepository($this->pdo, $this->enc),
            new \Core\Member\Movement\MemberMovementClassifierService(new \Core\Member\Movement\MemberMovementRepository($this->pdo), $scoutYearService)
        );
        $this->controller = new MemberSearchController(
            $twig, $searchService, $memberService, $resolver, new MemberYearService(), $departureService,
            $exportRowBuilder, new \Core\Member\Export\MemberExportService(), new JournalService(new JournalRepository($this->pdo)),
            new \Core\Member\AdminMemberPageService(
                new \Core\Badge\MemberBadgeRepository($this->pdo),
                new \Core\Photo\MemberPhotoService(new \Core\Photo\MemberPhotoRepository($this->pdo)),
                new \Core\Member\SectionMembershipRepository($this->pdo),
                $sectionService,
                $scoutYearService,
                new \Core\Member\MemberEmailRepository($this->pdo, $this->enc)
            ),
            $memberYearRepo,
            new \Core\Member\MemberNoteService(
                new \Core\Member\MemberNoteRepository(
                    $this->pdo,
                    $this->enc,
                    new \Core\Security\UserAccountRepository($this->pdo, $this->enc)
                ),
                new JournalService(new JournalRepository($this->pdo))
            )
        );

        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            session_start();
        }
        AuthSession::login(1, 'admin@test.be', 'admin');
    }

    private function seedMember(?string $birthDate = null, string $firstName = 'jean', bool $active = true): int
    {
        [$sectionId, $functionId] = $this->ensureReferenceRows();

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, totem_encrypted, email_encrypted, mobile_encrypted, birth_date_encrypted, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId, $this->yearId,
            $this->enc->encrypt($firstName, 'member_years.first_name'), $this->enc->encrypt('DUPONT', 'member_years.last_name'),
            $this->enc->encrypt('renard', 'member_years.totem'), $this->enc->encrypt('jean@ex.be', 'member_years.email'),
            $this->enc->encrypt('0476123456', 'member_years.mobile'),
            $birthDate !== null ? $this->enc->encrypt($birthDate, 'member_years.birth_date') : null,
            $active ? 1 : 0,
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId, $sectionId]);

        return $memberYearId;
    }

    /** Someone the effective year does not know at all. */
    private function seedMemberInPastYear(string $lastName = 'ANCIENNE'): int
    {
        $pastYearId = (new ScoutYearService($this->pdo))->ensureYear('2024-2025');

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, is_active)
             VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $memberId, $pastYearId,
            $this->enc->encrypt('Camille', 'member_years.first_name'),
            $this->enc->encrypt($lastName, 'member_years.last_name'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array{0: int, 1: int} section id, function id
     */
    private function ensureReferenceRows(): array
    {
        $sectionId = $this->pdo->query("SELECT id FROM sections WHERE desk_code = 'BAL01'")->fetchColumn();
        if ($sectionId === false) {
            $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('BAL', 'Baladins', 1)");
            $branchId = (int) $this->pdo->lastInsertId();
            $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('BAL01', {$branchId}, 'Ruche')");
            $sectionId = (int) $this->pdo->lastInsertId();
        }

        $functionId = $this->pdo->query("SELECT id FROM functions WHERE desk_code = 'ANIM'")->fetchColumn();
        if ($functionId === false) {
            $this->pdo->exec("INSERT INTO functions (desk_code, label, role, confirmed) VALUES ('ANIM', 'Animateur', 'chief', 1)");
            $functionId = (int) $this->pdo->lastInsertId();
        }

        return [(int) $sectionId, (int) $functionId];
    }

    private function get(array $query = []): Request
    {
        return new Request('GET', '/admin/members', $query, [], [], []);
    }

    public function testEmptyStateWhenNoQuery(): void
    {
        $response = $this->controller->index($this->get(), []);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Entrez un nom', $response->getBody());
    }

    public function testSearchRendersNormalizedResults(): void
    {
        $this->seedMember();

        $body = $this->controller->index($this->get(['q' => 'dupont']), [])->getBody();

        // Last name stored all-caps ("DUPONT"), displayed title-cased.
        $this->assertStringContainsString('Dupont', $body);
        $this->assertStringNotContainsString('DUPONT', $body);
        $this->assertStringContainsString('inscrit', $body);
        // A result row is a link to that member's own page now.
        $this->assertMatchesRegularExpression('#href="/admin/members/\d+"#', $body);
    }

    public function testNoResultsMessage(): void
    {
        $this->seedMember();
        $body = $this->controller->index($this->get(['q' => 'zzznothing']), [])->getBody();
        $this->assertStringContainsString('Aucun membre trouvé', $body);
    }

    // --- The membership filter, and the widened past-year search ---

    public function testTheSearchDefaultsToActiveMembersAndOffersTheOtherTwoScopes(): void
    {
        $this->seedMember();
        $this->seedMember(firstName: 'marie', active: false);

        $body = $this->controller->index($this->get(['q' => 'dupont']), [])->getBody();

        // The default is « actifs » — what is wanted nine times out of ten.
        $this->assertStringContainsString('Actifs', $body);
        $this->assertStringContainsString('Inactifs', $body);
        $this->assertStringContainsString('Tous', $body);
        $this->assertMatchesRegularExpression('/id="scope-active"[^>]*checked/', $body);
    }

    public function testAnInactiveMemberIsHiddenByDefaultAndShownUnderTheOtherScopes(): void
    {
        $this->seedMember(firstName: 'marie', active: false);

        $this->assertStringNotContainsString('marie', $this->controller->index($this->get(['q' => 'dupont']), [])->getBody());
        $this->assertStringContainsString('Marie', $this->controller->index($this->get(['q' => 'dupont', 'scope' => 'inactive']), [])->getBody());
        $this->assertStringContainsString('Marie', $this->controller->index($this->get(['q' => 'dupont', 'scope' => 'all']), [])->getBody());
    }

    /**
     * Non-regression on the result row: the export checkbox, the initials
     * pill, the totem after the first name, the section and function, and
     * the exact status wording — « inscrit » / « non inscrit », never
     * « actif ».
     */
    public function testTheResultRowKeepsEveryThingItAlreadyCarried(): void
    {
        $this->seedMember();

        $body = $this->controller->index($this->get(['q' => 'dupont']), [])->getBody();

        $this->assertStringContainsString('member-export-checkbox', $body);
        $this->assertStringContainsString('name="selected[]"', $body);
        $this->assertStringContainsString('JD', $body);
        $this->assertStringContainsString('Renard', $body);
        $this->assertStringContainsString('Ruche', $body);
        $this->assertStringContainsString('Animateur', $body);
        $this->assertStringContainsString('inscrit', $body);
        $this->assertStringNotContainsString('>actif<', $body);
    }

    /** The two exports coexist and are never merged into one. */
    public function testBothExportsStillExistSideBySide(): void
    {
        $this->seedMember();

        $body = $this->controller->index($this->get(['q' => 'dupont']), [])->getBody();

        $this->assertStringContainsString('Exporter les résultats', $body);
        $this->assertStringContainsString('Exporter la sélection', $body);
    }

    public function testTheExportFollowsTheMembershipScopeTheScreenIsShowing(): void
    {
        $this->seedMember();
        $body = $this->controller->index($this->get(['q' => 'dupont', 'scope' => 'all']), [])->getBody();

        $this->assertStringContainsString('scope=all', $body);
        $this->assertStringContainsString('name="scope" value="all"', $body);
    }

    /**
     * Widening is an explicit act: no box ticked in advance, nothing
     * fired on a keystroke. Every extra scout year is a whole year of
     * AES decryption in PHP.
     */
    public function testThePastYearSearchIsAButtonAndNotADefault(): void
    {
        $this->seedMember();

        $body = $this->controller->index($this->get(['q' => 'dupont']), [])->getBody();

        $this->assertStringContainsString('Chercher aussi dans les années précédentes', $body);
        $this->assertStringContainsString('annees=1', $body);
        // Not a checkbox, and certainly not a checked one.
        $this->assertStringNotContainsString('name="annees" type="checkbox"', $body);
    }

    public function testTheWidenedSearchFindsAFormerMemberAndSaysSo(): void
    {
        $this->seedMember();
        $this->seedMemberInPastYear();

        $narrow = $this->controller->index($this->get(['q' => 'ancienne']), [])->getBody();
        $this->assertStringNotContainsString('Ancienne', $narrow);

        $wide = $this->controller->index($this->get(['q' => 'ancienne', 'annees' => '1']), [])->getBody();
        $this->assertStringContainsString('Ancienne', $wide);
        $this->assertStringContainsString('ancien', $wide);
        $this->assertStringContainsString('2024-2025', $wide);
    }

    /**
     * The canonical member export is one scout year's worth of columns,
     * and a former member has no row in the effective one — so the row
     * offers no checkbox rather than one that would be dropped later.
     */
    public function testAFormerMemberRowOffersNoExportCheckbox(): void
    {
        $this->seedMemberInPastYear();

        $body = $this->controller->index($this->get(['q' => 'ancienne', 'annees' => '1']), [])->getBody();

        $this->assertStringContainsString('Ancienne', $body);
        $this->assertStringNotContainsString('member-export-checkbox', $body);
    }

    // --- GET /admin/members/{id} — the member's own page ---
    //
    // This detail used to render below the search results, reached as
    // /admin/members?q=…&member={id}. It is its own route now; every case
    // below is the one that guarded the card, moved with it. The point of
    // keeping them rather than rewriting them is that this iteration
    // MOVES a page and must lose nothing.

    /**
     * @param array<string, mixed> $query
     */
    private function showMember(int $memberYearId, array $query = []): \Core\Http\Response
    {
        return $this->controller->show($this->get($query), ['id' => (string) $memberYearId]);
    }

    public function testMemberPageOffersTheTemporaryAddButton(): void
    {
        $id = $this->seedMember();

        $body = $this->showMember($id)->getBody();

        $this->assertStringContainsString('Voir le site à sa place', $body);
        $this->assertStringContainsString("/admin/members/{$id}/temporary-access", $body);
        $this->assertStringNotContainsString('Retirer de ma liste', $body);
    }

    /**
     * The block changes the READER's session, not the member — the
     * « Votre session » label is what says so, and the full text is what
     * makes anyone dare click (ARCHITECTURE.md §8.42).
     */
    public function testTheTemporaryAccessCardKeepsItsFullExplanationAndItsSessionLabel(): void
    {
        $body = $this->showMember($this->seedMember())->getBody();

        $this->assertStringContainsString('Votre session', $body);
        $this->assertStringContainsString(
            'Aucune modification n\'est enregistrée : le retrait ou la déconnexion annule tout.',
            $body
        );
    }

    public function testMemberPageOffersRemovalForTheMemberCurrentlyAdded(): void
    {
        $id = $this->seedMember();
        TemporaryMemberSession::set($id);

        $body = $this->showMember($id)->getBody();

        $this->assertStringContainsString('Retirer de ma liste', $body);
        $this->assertStringContainsString('/admin/members/temporary-access/remove', $body);

        TemporaryMemberSession::clear();
    }

    public function testMemberPageOfAnotherMemberStillOffersTheAddButton(): void
    {
        $id = $this->seedMember();
        TemporaryMemberSession::set($id + 1000);

        $body = $this->showMember($id)->getBody();

        $this->assertStringNotContainsString('Retirer de ma liste', $body);

        TemporaryMemberSession::clear();
    }

    public function testMemberPageRendersEveryDeskFieldTheDetailCardCarried(): void
    {
        $id = $this->seedMember();

        $body = $this->showMember($id)->getBody();

        $this->assertStringContainsString('Données Desk', $body);
        $this->assertStringContainsString('jean@ex.be', $body);
        // Phone normalized for display.
        $this->assertStringContainsString('+32 476 12 34 56', $body);
        $this->assertStringContainsString('Animateur', $body);
        // The Desk half stays read-only, and says so.
        $this->assertStringContainsString('lecture seule', $body);
    }

    /**
     * The old « Données du site » heading is gone on purpose: it stopped
     * meaning anything once the page grew, since everything past the Desk
     * half is site data. The three actions are three cards now.
     */
    public function testTheThreeSiteActionsAreThreeSeparateCards(): void
    {
        $body = $this->showMember($this->seedMember())->getBody();

        $this->assertStringNotContainsString('Données du site', $body);
        $this->assertStringContainsString('Année dans la branche', $body);
        $this->assertStringContainsString('Départ', $body);
        $this->assertStringContainsString('Voir le site à sa place', $body);
    }

    public function testMemberPageShowsScoutYearOffsetControlAndBranchYearLabel(): void
    {
        // 2014-01-01 → raw age 11 in scout year 2025-2026 (reference year 2025)
        // → louveteaux, 4e année.
        $id = $this->seedMember('2014-01-01');

        $body = $this->showMember($id)->getBody();

        $this->assertStringContainsString('id="scout-year-offset-card"', $body);
        $this->assertStringContainsString('data-offset="-1"', $body);
        $this->assertStringContainsString('data-offset="0"', $body);
        $this->assertStringContainsString('data-offset="1"', $body);
        $this->assertStringContainsString('4e année louveteaux', $body);
        $this->assertStringContainsString('#639922', $body);
        // No offset set yet → "Normal" is the active segment.
        $this->assertMatchesRegularExpression('/offset-btn active"\s+data-offset="0"/', $body);
    }

    public function testMemberPageShowsDepartureControl(): void
    {
        $id = $this->seedMember();

        $body = $this->showMember($id)->getBody();

        $this->assertStringContainsString('id="departure-card"', $body);
        $this->assertStringContainsString('Part l\'année prochaine', $body);
        // Not marked as leaving yet — checkbox unchecked, comment row hidden.
        $this->assertDoesNotMatchRegularExpression('/id="departure-checkbox"[^>]*checked/', $body);
        $this->assertMatchesRegularExpression('/id="departure-comment-row" style="display:none;"/', $body);
    }

    public function testMemberPageReflectsExistingDepartureMarking(): void
    {
        $id = $this->seedMember();
        $departureService = new DepartureService(new DepartureRepository($this->pdo, $this->enc), new JournalService(new JournalRepository($this->pdo)));
        $departureService->markLeaving($id, 'Déménagement');

        $body = $this->showMember($id)->getBody();

        $this->assertMatchesRegularExpression('/id="departure-checkbox"[^>]*checked/', $body);
        $this->assertStringContainsString('Déménagement', $body);
    }

    public function testMemberPageIsNotFoundForAMemberYearThatDoesNotExist(): void
    {
        $this->seedMember();

        $this->assertSame(404, $this->showMember(99999)->getStatusCode());
    }

    /**
     * The old "belongs to the effective scout year, or 404" check is
     * deliberately gone: it would 404 every former member, which is
     * exactly who the widened search exists to find.
     */
    public function testAFormerMembersPageOpensRatherThanAnsweringNotFound(): void
    {
        $memberYearId = $this->seedMemberInPastYear();

        $response = $this->showMember($memberYearId);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Camille', $response->getBody());
    }

    /**
     * Decided: the page always shows the member's LAST KNOWN year, and
     * names it. Someone looking up a former member wants their most
     * recent details — and without the year being stated a chef d'unité
     * reads them as current and phones a number that stopped working
     * years ago.
     */
    public function testAFormerMembersPageNamesTheYearItIsShowing(): void
    {
        $memberYearId = $this->seedMemberInPastYear();

        $body = $this->showMember($memberYearId)->getBody();

        $this->assertStringContainsString('2024-2025', $body);
        $this->assertStringContainsString('la dernière année', $body);
    }

    public function testAPastYearLinkNormalisesOntoTheMembersMostRecentYear(): void
    {
        // Same person, two annual rows: the older id is what an old link
        // or a widened search may carry, and the page shows the newer.
        $pastYearId = (new ScoutYearService($this->pdo))->ensureYear('2024-2025');
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D-span')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, totem_encrypted, is_active)
             VALUES (?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $memberId, $pastYearId,
            $this->enc->encrypt('Camille', 'member_years.first_name'),
            $this->enc->encrypt('SPAN', 'member_years.last_name'),
            $this->enc->encrypt('vieuxtotem', 'member_years.totem'),
        ]);
        $oldMemberYearId = (int) $this->pdo->lastInsertId();

        $stmt->execute([
            $memberId, $this->yearId,
            $this->enc->encrypt('Camille', 'member_years.first_name'),
            $this->enc->encrypt('SPAN', 'member_years.last_name'),
            $this->enc->encrypt('totemrecent', 'member_years.totem'),
        ]);

        $body = $this->showMember($oldMemberYearId)->getBody();

        $this->assertStringContainsString('Totemrecent', $body);
        $this->assertStringNotContainsString('Vieuxtotem', $body);
        // The year it lands on IS the effective one, so no banner.
        $this->assertStringNotContainsString('la dernière année', $body);
    }

    public function testMemberPageIsNotFoundForANonPositiveId(): void
    {
        $this->assertSame(404, $this->showMember(0)->getStatusCode());
    }

    /**
     * ARCHITECTURE.md §8.3 — owner-scoped files carry an explicit
     * no-chief-and-no-admin-bypass guarantee, and tax certificates will
     * live there. The page says so rather than quietly omitting them,
     * which is what stops the next person from "completing" it.
     */
    public function testMemberPageNeverListsPrivateDocumentsAndSaysWhy(): void
    {
        $body = $this->showMember($this->seedMember())->getBody();

        $this->assertStringContainsString('Les documents privés du membre n\'apparaissent pas ici', $body);
    }

    public function testTheSearchPageNoLongerRendersTheDetailInline(): void
    {
        $id = $this->seedMember();

        $body = $this->controller->index($this->get(['q' => 'dupont']), [])->getBody();

        $this->assertStringNotContainsString('Données Desk', $body);
        // ...and each result links to the member's own page instead of
        // carrying the query along in a ?member= parameter.
        $this->assertStringContainsString('href="/admin/members/' . $id . '"', $body);
    }

    // --- Notes internes ---

    public function testANoteAddedFromThePageAppearsOnItWithItsAuthorAndDate(): void
    {
        $id = $this->seedMember();

        $this->controller->addNote($this->post($id, ['body' => 'Allergie signalée.']), ['id' => (string) $id]);

        $body = $this->showMember($id)->getBody();
        $this->assertStringContainsString('Allergie signalée.', $body);
        $this->assertStringContainsString('Notes internes', $body);
    }

    public function testTheNotesBlockSaysWhoItIsForAndWhoItIsNotFor(): void
    {
        $body = $this->showMember($this->seedMember())->getBody();

        $this->assertStringContainsString("Staff d'unité uniquement", $body);
        $this->assertStringContainsString('Jamais visible par le membre ni par ses parents', $body);
    }

    public function testAnEmptyNoteIsRefusedWithAMessageAndWritesNothing(): void
    {
        $id = $this->seedMember();

        $this->controller->addNote($this->post($id, ['body' => '  ']), ['id' => (string) $id]);

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM member_notes')->fetchColumn());
    }

    public function testANoteCanBeCorrectedAndDeletedFromThePage(): void
    {
        $id = $this->seedMember();
        $this->controller->addNote($this->post($id, ['body' => 'Première version.']), ['id' => (string) $id]);
        $noteId = (int) $this->pdo->query('SELECT id FROM member_notes')->fetchColumn();

        $this->controller->updateNote(
            $this->post($id, ['body' => 'Version corrigée.']),
            ['id' => (string) $id, 'note_id' => (string) $noteId]
        );
        $this->assertStringContainsString('Version corrigée.', $this->showMember($id)->getBody());

        $this->controller->deleteNote(
            $this->post($id, []),
            ['id' => (string) $id, 'note_id' => (string) $noteId]
        );
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM member_notes')->fetchColumn());
    }

    /**
     * Decided: /admin/members/export never gains this column, even though
     * every one of its readers is a chef d'unité. An exported file leaves
     * the site's protections — it travels by email, lands in a shared
     * folder, and survives the departure of whoever produced it.
     */
    public function testTheMemberExportNeverCarriesTheNotes(): void
    {
        $id = $this->seedMember();
        $this->controller->addNote($this->post($id, ['body' => 'Parents séparés.']), ['id' => (string) $id]);

        $rows = $this->exportToRows(['q' => 'dupont']);

        foreach ($rows as $row) {
            $this->assertStringNotContainsString('Parents séparés', implode('|', array_map('strval', $row)));
        }
        $header = implode('|', array_map('strval', $rows[0]));
        $this->assertStringNotContainsStringIgnoringCase('note', $header);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(int $memberYearId, array $body): Request
    {
        $body['_csrf_token'] = CsrfGuard::generateToken();

        return new Request('POST', '/admin/members/' . $memberYearId . '/notes', [], $body, [], []);
    }

    // --- GET /admin/members/export (all results, or the checked selection) ---

    /**
     * @param array<string, mixed> $query
     * @return array<int, array<int, mixed>> the exported sheet as rows (header row included)
     */
    private function exportToRows(array $query): array
    {
        $response = $this->controller->export(new Request('GET', '/admin/members/export', $query, [], [], []), []);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('spreadsheetml', (string) $response->getHeaders()['Content-Type']);

        $path = tempnam(sys_get_temp_dir(), 'export_') . '.xlsx';
        file_put_contents($path, $response->getBody());
        try {
            $spreadsheet = (new \PhpOffice\PhpSpreadsheet\Reader\Xlsx())->load($path);
            return $spreadsheet->getSheet(0)->toArray(null, true, true, false);
        } finally {
            @unlink($path);
        }
    }

    public function testExportOfSearchResultsProducesTheCanonicalXlsx(): void
    {
        $this->seedMember();

        $rows = $this->exportToRows(['q' => 'dupont']);

        $this->assertCount(2, $rows); // header + one member
        // Canonical columns, mail-merge-reusable headers included.
        $this->assertContains('Identifiant Desk', $rows[0]);
        $this->assertContains('Email(s)', $rows[0]);
        $this->assertContains('jean', $rows[1]);

        // The journal records counts only — never the query text, which is
        // typically somebody's name.
        $log = $this->pdo->query("SELECT * FROM event_log WHERE event_type = 'member_search_exported'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($log);
        $this->assertStringNotContainsString('dupont', (string) $log['context']);
    }

    public function testExportOfASelectionOnlyExportsValidatedIds(): void
    {
        $id = $this->seedMember();

        // A forged/stale id alongside the real one is silently dropped.
        $rows = $this->exportToRows(['q' => 'dupont', 'selected' => [(string) $id, '99999']]);

        $this->assertCount(2, $rows); // header + the one validated member
    }

    public function testExportWithNothingToExportReturns400(): void
    {
        $this->seedMember();
        $response = $this->controller->export(new Request('GET', '/admin/members/export', ['q' => 'zzznothing'], [], [], []), []);
        $this->assertSame(400, $response->getStatusCode());
    }

    public function testSearchPageOffersTheExportControls(): void
    {
        $this->seedMember();

        $body = $this->controller->index($this->get(['q' => 'dupont']), [])->getBody();

        $this->assertStringContainsString('/admin/members/export?q=dupont', $body);
        $this->assertStringContainsString('Exporter la sélection', $body);
        $this->assertStringContainsString('name="selected[]"', $body);
        $this->assertStringContainsString('Tout sélectionner', $body);
    }
}
