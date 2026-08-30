<?php

declare(strict_types=1);

namespace Tests\Modules\Attestations\Controller;

use Core\Config\AppConfig;
use Core\Config\ScoutYearService;
use Core\Database\Connection;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Modules\Attestations\Controller\BatchController;
use Modules\Attestations\Repository\BatchLineRepository;
use Modules\Attestations\Repository\BatchRepository;
use Modules\Attestations\Repository\MemberNameRepository;
use Core\Scheduler\SchedulerService;
use Modules\Attestations\Service\BatchPublicationService;
use Modules\Attestations\Service\BatchVerificationService;
use Modules\Attestations\Service\DuplicateDetector;
use Modules\Attestations\Value\AttestationCategory;
use Modules\Attestations\Value\MatchState;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Attestations\AttestationsTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The verification screen, rendered.
 *
 * What is pinned here is what the screen has to say out loud, because this
 * is the reader's only chance to catch anything: the name as printed beside
 * the member it was matched to, a row that cannot be ticked when it has no
 * destination, a bulk command whose label carries the number of rows it
 * will touch, and the two counters that answer two different questions.
 */
#[Group('database')]
class BatchControllerTest extends TestCase
{
    private \PDO $pdo;
    private Environment $twig;
    private string $storageRoot;
    private int $scoutYearId;
    private int $batchId;
    private BatchLineRepository $lines;
    private BatchRepository $batches;

    /** @var array<string, int> */
    private array $memberIds = [];

    /** @var array<string, int> */
    private array $lineIds = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        AttestationsTestHelper::createTables($this->pdo);
        $this->storageRoot = AttestationsTestHelper::createStorageRoot();
        $this->scoutYearId = AttestationsTestHelper::createScoutYear($this->pdo);
        $this->twig = $this->buildTwig();

        $connection = Connection::withPdo($this->pdo);
        $this->batches = new BatchRepository($connection);
        $this->lines = new BatchLineRepository($connection, AttestationsTestHelper::encryption());

        $this->seedBatch();

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        AuthSession::login(1, 'chef-unite@test.be', 'admin');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        $_SESSION = [];
        AttestationsTestHelper::removeDirectory($this->storageRoot);
    }

    private function seedBatch(): void
    {
        $scout = AttestationsTestHelper::createFunction($this->pdo, 'SCOUT', 'Scout');
        $leader = AttestationsTestHelper::createFunction($this->pdo, 'ANIMU', "Animateur d'unité", 'chief');

        $this->memberIds['margaux'] = AttestationsTestHelper::createMemberWithFunction(
            $this->pdo, $this->scoutYearId, 'Margaux', 'Vandenbrande', $scout
        );
        $this->memberIds['xavier'] = AttestationsTestHelper::createMemberWithFunction(
            $this->pdo, $this->scoutYearId, 'Xavier', 'Dubois', $leader
        );
        $this->memberIds['zoe_a'] = AttestationsTestHelper::createMemberWithFunction(
            $this->pdo, $this->scoutYearId, 'Zoé', 'Herremans', $scout
        );
        $this->memberIds['zoe_b'] = AttestationsTestHelper::createMemberWithFunction(
            $this->pdo, $this->scoutYearId, 'Zoé', 'Herremans', $scout
        );

        $this->batchId = $this->batches->create(
            $this->scoutYearId, AttestationCategory::Tax, 'Attestation fiscale 2025', 8, 2, 4, 1
        );

        $this->lineIds['margaux'] = $this->lines->create(
            $this->batchId, 1, 1, 2, 'VANDENBRANDE Margaux',
            $this->memberIds['margaux'], MatchState::Matched, $this->createFile()
        );
        $this->lineIds['xavier'] = $this->lines->create(
            $this->batchId, 2, 3, 4, 'DUBOIS Xavier',
            $this->memberIds['xavier'], MatchState::Matched, $this->createFile()
        );
        $this->lineIds['ambiguous'] = $this->lines->create(
            $this->batchId, 3, 5, 6, 'HERREMANS Zoé',
            null, MatchState::Ambiguous, $this->createFile(),
            [$this->memberIds['zoe_a'], $this->memberIds['zoe_b']]
        );
        $this->lineIds['unmatched'] = $this->lines->create(
            $this->batchId, 4, 7, 8, 'DELACROIX Camille',
            null, MatchState::Unmatched, $this->createFile()
        );
    }

    private function createFile(): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute(['a/' . bin2hex(random_bytes(6)), 'x.pdf', 'application/pdf', 10, 'admin']);

        return (int) $this->pdo->lastInsertId();
    }

    private function body(): string
    {
        return (string) preg_replace('/\s+/', ' ', (string) $this->frontController()
            ->handle(new Request('GET', '/admin/attestations/' . $this->batchId, [], [], [], []))
            ->getBody());
    }

    public function testTheScreenShowsTheNameAsPrintedBesideTheMemberItMatched(): void
    {
        $body = $this->body();

        $this->assertStringContainsString('VANDENBRANDE Margaux', $body);
        $this->assertStringContainsString('nom lu dans le PDF', $body);
        $this->assertStringContainsString('Margaux Vandenbrande', $body);
        $this->assertStringContainsString('2025-2026', $body);
    }

    /** Everything is distributed unless somebody says otherwise. */
    public function testAMatchedLineIsTickedByDefault(): void
    {
        $body = $this->body();

        $this->assertMatchesRegularExpression(
            '#value="' . $this->lineIds['margaux'] . '"[^>]*checked#',
            $body
        );
    }

    /**
     * A line with no member has no destination. The screen must not offer
     * it as one that will be distributed — and the server refuses it too,
     * which is the check that actually holds.
     */
    public function testALineWithNoMemberCannotBeTicked(): void
    {
        $body = $this->body();

        foreach (['ambiguous', 'unmatched'] as $key) {
            $this->assertMatchesRegularExpression(
                '#value="' . $this->lineIds[$key] . '"[^>]*disabled#',
                $body,
                $key
            );
        }
    }

    /**
     * A row with no member has no function either, so any function filter
     * would take it away — and it is exactly the row somebody still has to
     * deal with. The flag the script reads is rendered server-side.
     */
    public function testUnresolvedRowsAreMarkedAsAlwaysVisible(): void
    {
        $body = $this->body();

        $this->assertMatchesRegularExpression(
            '#data-line-id="' . $this->lineIds['ambiguous'] . '"[^>]*data-always-visible="1"#',
            $body
        );
        $this->assertMatchesRegularExpression(
            '#data-line-id="' . $this->lineIds['margaux'] . '"[^>]*data-always-visible="0"#',
            $body
        );
    }

    public function testEachMatchedRowCarriesItsMembersFunctionForTheFilter(): void
    {
        $body = $this->body();

        // `~` as the delimiter, not `#`: the escaped apostrophe the
        // template writes is `&#039;`, whose own `#` would end the pattern.
        $this->assertMatchesRegularExpression(
            '~data-line-id="' . $this->lineIds['xavier'] . '"[^>]*data-function="Animateur d&#039;unité"~',
            $body
        );
    }

    /**
     * The filter is a select bar in `multi` mode — the component for an
     * open-ended set coming from the database (design.md §1.4) — never a
     * row of chips, and it offers only functions somebody on screen holds.
     */
    public function testTheFunctionFilterIsAMultiSelectBarOfferingOnlyFunctionsInUse(): void
    {
        $body = $this->body();

        $this->assertStringContainsString('id="attestations-function-filter"', $body);
        $this->assertStringContainsString('data-mode="multi"', $body);
        $this->assertStringContainsString('Toutes les fonctions', $body);
        $this->assertStringContainsString('Animateur d&#039;unité', $body);
        $this->assertStringContainsString('Scout', $body);
    }

    /**
     * Two counters answering two different questions, and only the second
     * decides anything: how much of the batch is on screen, and how much of
     * it will be distributed.
     */
    public function testTheTwoCountersAreBothRendered(): void
    {
        $body = $this->body();

        $this->assertStringContainsString('id="attestations-visible-count"', $body);
        $this->assertStringContainsString('4 affichées sur 4', $body);
        $this->assertMatchesRegularExpression(
            '#id="attestations-selected-count"[^>]*>2<#',
            $body
        );
        $this->assertMatchesRegularExpression(
            '#id="attestations-pending-count"[^>]*>2<#',
            $body
        );
    }

    /**
     * The classic trap of this kind of screen: « tout désélectionner » on a
     * filtered page reads as the whole batch and touches part of it. The
     * label carries the number of rows the command will touch, and both
     * wordings are rendered server-side so the script invents no French.
     */
    public function testTheBulkCommandNamesTheNumberOfRowsItWillTouch(): void
    {
        $body = $this->body();

        $this->assertStringContainsString('Désélectionner les 4 lignes affichées', $body);
        $this->assertStringContainsString('data-select-label="Sélectionner les {n} lignes affichées"', $body);
        $this->assertStringContainsString('data-deselect-label="Désélectionner les {n} lignes affichées"', $body);
    }

    public function testAnAmbiguousLineOffersItsCandidatesAndNobodyElse(): void
    {
        $body = $this->body();

        $this->assertStringContainsString('Choisir un membre…', $body);
        $this->assertStringContainsString('Zoé Herremans', $body);
        $this->assertStringContainsString('Homonymie — plusieurs membres portent ce nom.', $body);
        // The two candidates, and not the other members of the batch.
        $this->assertMatchesRegularExpression(
            '#<option value="' . $this->memberIds['zoe_a'] . '"#',
            $body
        );
        $this->assertDoesNotMatchRegularExpression(
            '#name="member_id"[^>]*>.*<option value="' . $this->memberIds['xavier'] . '"#',
            $body
        );
    }

    public function testAnUnmatchedLineSaysSoAndOffersNobody(): void
    {
        $body = $this->body();

        $this->assertStringContainsString('Non apparié — aucun membre de ce nom.', $body);
        $this->assertStringContainsString('Aucun membre correspondant', $body);
    }

    public function testTheScreenWarnsThatUncheckedLinesAreDeleted(): void
    {
        $body = $this->body();

        $this->assertStringContainsString('Les lignes non cochées seront supprimées', $body);
        // Static template text, so Twig escapes nothing here.
        $this->assertStringContainsString("C'est votre seule occasion de vérifier", $body);
    }

    public function testAnUnknownBatchIs404(): void
    {
        $response = $this->frontController()
            ->handle(new Request('GET', '/admin/attestations/4242', [], [], [], []));

        $this->assertSame(404, $response->getStatusCode());
    }

    // --- the two decisions, through the routes ---------------------------

    public function testResolvingAnAmbiguityThroughTheRouteRedirectsBackToTheBatch(): void
    {
        $response = $this->frontController()->handle(new Request(
            'POST',
            '/admin/attestations/' . $this->batchId . '/rattacher',
            [],
            [
                'line_id' => (string) $this->lineIds['ambiguous'],
                'member_id' => (string) $this->memberIds['zoe_b'],
                '_csrf_token' => CsrfGuard::generateToken(),
            ],
            [],
            []
        ));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            '/admin/attestations/' . $this->batchId,
            (string) $response->getHeaders()['Location']
        );
        $this->assertSame(
            $this->memberIds['zoe_b'],
            $this->lines->findById($this->lineIds['ambiguous'])?->memberId
        );
    }

    public function testCommittingTheSelectionThroughTheRouteDeletesWhatWasNotTicked(): void
    {
        $response = $this->frontController()->handle(new Request(
            'POST',
            '/admin/attestations/' . $this->batchId . '/publier',
            [],
            [
                'line_ids' => [(string) $this->lineIds['margaux']],
                '_csrf_token' => CsrfGuard::generateToken(),
            ],
            [],
            []
        ));

        $this->assertSame(302, $response->getStatusCode());

        $remaining = $this->lines->findByBatch($this->batchId);
        $this->assertCount(1, $remaining);
        $this->assertSame($this->lineIds['margaux'], $remaining[0]->id);

        $batch = $this->batches->findById($this->batchId);
        $this->assertNotNull($batch);
        $this->assertSame(3, $batch->discardedCount);
    }

    /**
     * All or nothing (Core\Service\IntegerInput::idList): a list where one
     * element does not parse is a selection nobody made, and applying the
     * rest would delete certificates the reader meant to keep.
     */
    public function testASelectionListWithAnUnreadableIdChangesNothing(): void
    {
        $this->frontController()->handle(new Request(
            'POST',
            '/admin/attestations/' . $this->batchId . '/publier',
            [],
            [
                'line_ids' => [(string) $this->lineIds['margaux'], '2/2'],
                '_csrf_token' => CsrfGuard::generateToken(),
            ],
            [],
            []
        ));

        $this->assertCount(4, $this->lines->findByBatch($this->batchId));
    }

    public function testCommittingWithoutAValidCsrfTokenChangesNothing(): void
    {
        $this->frontController()->handle(new Request(
            'POST',
            '/admin/attestations/' . $this->batchId . '/publier',
            [],
            ['line_ids' => [(string) $this->lineIds['margaux']], '_csrf_token' => 'invalide'],
            [],
            []
        ));

        $this->assertCount(4, $this->lines->findByBatch($this->batchId));
    }

    // --- publication, and the send that follows it -----------------------

    /** Publishing puts the document on the member's own page. */
    public function testPublishingThroughTheRoutePutsTheDocumentOnTheMembersPage(): void
    {
        $this->publishMargaux();

        $documents = (new \Core\Member\MemberDocumentRepository($this->pdo))
            ->findByMemberAndYear($this->memberIds['margaux'], $this->scoutYearId);

        $this->assertCount(1, $documents);
        $this->assertSame('Attestation fiscale 2025', $documents[0]->title);
    }

    /**
     * The state that has to shout: published is not sent, and a certificate
     * nobody was told about is one the family asks for in June, by e-mail,
     * to the treasurer.
     */
    public function testThePublishedScreenAsksForTheFamiliesToBeWarned(): void
    {
        $this->publishMargaux();

        $body = $this->body();

        $this->assertStringContainsString('Familles non prévenues', $body);
        $this->assertStringContainsString('/admin/attestations/' . $this->batchId . '/prevenir', $body);
        $this->assertStringContainsString('1 attestation publiée', $body);
        $this->assertStringContainsString('3 lignes non retenues', $body);
    }

    /**
     * Nothing on a published batch can be decided any more: no tick, no
     * bulk command, no publish button — only the reading of what went
     * where, and the sentence saying why nobody here can re-open it.
     */
    public function testThePublishedScreenOffersNoControls(): void
    {
        $this->publishMargaux();

        $body = $this->body();

        $this->assertStringNotContainsString('Les lignes non cochées seront supprimées', $body);
        $this->assertStringNotContainsString('id="attestations-publish-count"', $body);
        $this->assertStringNotContainsString('lignes affichées', $body);
        $this->assertMatchesRegularExpression(
            '#value="' . $this->lineIds['margaux'] . '"[^>]*disabled#',
            $body
        );
        $this->assertStringContainsString('Vous ne pouvez pas rouvrir les documents publiés', $body);
    }

    /**
     * The send is queued, never made inside the request: a batch of two
     * hundred is two hundred SMTP round trips.
     */
    public function testAskingForTheSendQueuesTheTaskAndStopsAsking(): void
    {
        $this->publishMargaux();

        $response = $this->notifyRequest(CsrfGuard::generateToken());

        $this->assertSame(302, $response->getStatusCode());

        $row = $this->pdo->query('SELECT module_id, task_key, payload FROM scheduled_actions')
            ->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame('attestations', $row['module_id']);
        $this->assertSame('send_batch', $row['task_key']);
        $this->assertSame(['batch_id' => $this->batchId], json_decode((string) $row['payload'], true));

        $body = $this->body();
        $this->assertStringNotContainsString('Familles non prévenues', $body);
        $this->assertStringContainsString('Envoi en cours', $body);
    }

    /**
     * Pressing twice is how a family gets two copies of the same document,
     * and an e-mail does not come back.
     */
    public function testAskingForTheSendTwiceQueuesNothingMore(): void
    {
        $this->publishMargaux();
        $this->notifyRequest(CsrfGuard::generateToken());
        $this->notifyRequest(CsrfGuard::generateToken());

        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM scheduled_actions')->fetchColumn()
        );
    }

    public function testABatchThatWasNeverPublishedCannotBeSent(): void
    {
        $this->notifyRequest(CsrfGuard::generateToken());

        $this->assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM scheduled_actions')->fetchColumn()
        );
    }

    public function testAskingForTheSendWithoutAValidCsrfTokenQueuesNothing(): void
    {
        $this->publishMargaux();
        $this->notifyRequest('invalide');

        $this->assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM scheduled_actions')->fetchColumn()
        );
    }

    /**
     * Two problems with two different remedies, said out loud: a list of
     * « envoyées » read on its own looks complete.
     */
    public function testTheScreenCountsWhatCouldNotBeDeliveredAndSaysWhatItMeans(): void
    {
        $this->publishMargaux();
        $this->notifyRequest(CsrfGuard::generateToken());
        $this->pdo->prepare('UPDATE attestation_batch_lines SET delivery_state = ? WHERE batch_id = ?')
            ->execute(['no_address', $this->batchId]);

        $body = $this->body();

        $this->assertStringContainsString('Aucune adresse connue', $body);
        $this->assertStringContainsString('bien déposée sur la page du membre', $body);
    }

    /** Keeps only Margaux, which is what every published-state test needs. */
    private function publishMargaux(): void
    {
        $this->frontController()->handle(new Request(
            'POST',
            '/admin/attestations/' . $this->batchId . '/publier',
            [],
            [
                'line_ids' => [(string) $this->lineIds['margaux']],
                '_csrf_token' => CsrfGuard::generateToken(),
            ],
            [],
            []
        ));
    }

    private function notifyRequest(string $token): \Core\Http\Response
    {
        return $this->frontController()->handle(new Request(
            'POST',
            '/admin/attestations/' . $this->batchId . '/prevenir',
            [],
            ['_csrf_token' => $token],
            [],
            []
        ));
    }

    private function frontController(): FrontController
    {
        $router = new Router();
        $router->addRoute('GET', '/admin/attestations/{id}', BatchController::class, 'show', 'admin');
        $router->addRoute('POST', '/admin/attestations/{id}/rattacher', BatchController::class, 'assign', 'admin');
        $router->addRoute('POST', '/admin/attestations/{id}/publier', BatchController::class, 'publish', 'admin');
        $router->addRoute('POST', '/admin/attestations/{id}/prevenir', BatchController::class, 'notify', 'admin');

        $configFile = sys_get_temp_dir() . '/test_attestations_batch_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");

        $frontController = new FrontController($router, $this->twig, new AppConfig($configFile));

        $connection = Connection::withPdo($this->pdo);
        $encryption = AttestationsTestHelper::encryption();
        $files = new FileRepository($this->pdo);
        $journal = new JournalService(new JournalRepository($this->pdo));

        $frontController->registerController(
            BatchController::class,
            new BatchController(
                $this->twig,
                $this->batches,
                $this->lines,
                new MemberNameRepository($connection, $encryption),
                $verification = new BatchVerificationService(
                    $connection,
                    $this->batches,
                    $this->lines,
                    $files,
                    new EncryptedFileStorageService($files, $encryption, $this->storageRoot),
                    $journal
                ),
                new BatchPublicationService(
                    $connection,
                    $this->batches,
                    $this->lines,
                    $verification,
                    new \Core\Member\MemberDocumentRepository($this->pdo),
                    SchedulerService::forPdo($this->pdo),
                    $journal
                ),
                new DuplicateDetector($this->lines),
                new ScoutYearService($this->pdo)
            )
        );

        return $frontController;
    }

    private function buildTwig(): Environment
    {
        $loader = new FilesystemLoader(dirname(__DIR__, 4) . '/core/View/templates');
        $loader->addPath(dirname(__DIR__, 4) . '/modules/attestations/views', 'attestations');

        $twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);
        $twig->addFunction(new TwigFunction('asset', static fn(string $path): string => $path));
        $twig->addFilter(new TwigFilter(
            'date_fr',
            static fn($d) => $d === null || $d === ''
                ? ''
                : ($d instanceof \DateTimeInterface ? $d : new \DateTimeImmutable((string) $d))->format('d/m/Y')
        ));
        $twig->addFilter(new TwigFilter(
            'datetime_fr',
            static fn($d) => $d === null || $d === ''
                ? ''
                : ($d instanceof \DateTimeInterface ? $d : new \DateTimeImmutable((string) $d))->format('d/m/Y à H:i')
        ));

        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'test@test.be');
        $twig->addGlobal('current_user_role', 'admin');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/');
        $twig->addGlobal('csp_nonce', 'test-nonce');
        $twig->addFunction(new TwigFunction('csrf_field', static fn(): string => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('csrf_token', static fn(): string => 'test'));
        $twig->addFunction(new TwigFunction('get_flash', static fn() => null));
        $twig->addFunction(new TwigFunction('file_url', static fn(): string => ''));

        return $twig;
    }
}
