<?php

declare(strict_types=1);

namespace Tests\Modules\Retro\Controller;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Module\ModuleManager;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\ScoutYear\EffectiveScoutYear;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Modules\Retro\Controller\RetroChiefController;
use Modules\Retro\Repository\Board;
use Modules\Retro\Repository\BoardRepository;
use Modules\Retro\Repository\CommentRepository;
use Modules\Retro\Service\BoardService;
use Modules\Retro\Service\RetroException;
use PHPUnit\Framework\TestCase;
use Tests\Core\Mail\Template\EmailTemplateRendererFactory;
use Tests\DatabaseTestHelper;
use Tests\Modules\Retro\RetroTestHelper;
use Tests\TestTwig;
use Twig\Environment;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RetroChiefControllerTest extends TestCase
{
    private \PDO $pdo;
    private BoardRepository $boardRepository;
    private SettingService $settingService;
    private BoardService $boardService;
    private RetroChiefController $controller;
    private Environment $twig;
    private ScoutYearResolver $scoutYearResolver;
    private ModuleManager $moduleManager;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RetroTestHelper::createTables($this->pdo);

        $this->boardRepository = new BoardRepository($this->pdo, new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));
        $this->settingService = new SettingService(new SettingRepository($this->pdo));
        $this->boardService = $this->createMock(BoardService::class);
        $this->boardService->method('publicUrl')->willReturn('/r/token');

        $scoutYearResolver = $this->createMock(ScoutYearResolver::class);
        $scoutYearResolver->method('getEffectiveYear')->willReturn(new EffectiveScoutYear(1, '2025-2026', null));
        $moduleManager = $this->createMock(ModuleManager::class);
        $moduleManager->method('getEnabledModuleIds')->willReturn([]);
        $this->scoutYearResolver = $scoutYearResolver;
        $this->moduleManager = $moduleManager;

        $moduleViews = dirname(__DIR__, 4) . '/modules/retro/views';
        $twig = TestTwig::create(['retro' => $moduleViews]);
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'chief');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('csp_nonce', 'test-nonce');
        $this->twig = $twig;

        $this->controller = new RetroChiefController(
            $twig, $this->boardRepository, $this->boardService, $this->settingService, $scoutYearResolver, $moduleManager
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(3, 'chief@test.be', 'chief');
        // The session outlives each test in this process: a flash left by
        // the previous one would satisfy an assertion here.
        FlashMessage::get();
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    private function csrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        return $token;
    }

    public function testIndexRendersSuccessfully(): void
    {
        $response = $this->controller->index(new Request('GET', '/retro', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCreateAllowedForIntendantByDefault(): void
    {
        AuthSession::logout();
        AuthSession::login(3, 'intendant@test.be', 'intendant');

        $response = $this->controller->create(new Request('GET', '/retro/create', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCreateForbiddenBelowIntendant(): void
    {
        AuthSession::logout();
        AuthSession::login(3, 'identified@test.be', 'identified');

        $response = $this->controller->create(new Request('GET', '/retro/create', [], [], [], []), []);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testCreateForbiddenWhenSettingRaisesTheThresholdToAdmin(): void
    {
        $this->settingService->register('retro_role_min_create_board', 'admin', 'select', 'l', 'd', 'retro');
        $this->settingService->set('retro_role_min_create_board', 'admin', 'retro');
        // Logged in as 'chief' (setUp) — below the raised 'admin' threshold.

        $response = $this->controller->create(new Request('GET', '/retro/create', [], [], [], []), []);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testStoreRequiresCsrf(): void
    {
        $request = new Request('POST', '/retro', [], ['_csrf_token' => 'bad', 'title' => 'Camp'], [], []);

        $response = $this->controller->store($request, []);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testStoreCreatesABoardAndRedirects(): void
    {
        $this->boardService->expects($this->once())->method('create')->willReturn(
            $this->boardRepository->findById($this->boardRepository->create(
                'Camp', '2026-07-01', null, 'tok', null, true, 'unlimited', 5, true, 'cookie', 140, '7d', null, 3
            ))
        );
        $token = $this->csrfToken();
        $request = new Request('POST', '/retro', [], [
            '_csrf_token' => $token, 'title' => 'Camp', 'vote_mode' => 'unlimited', 'anti_duplicate_mode' => 'cookie',
            'max_comment_length' => '140', 'auto_close_delay' => '7d',
        ], [], []);

        $response = $this->controller->store($request, []);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testStoreRedirectsBackToFormOnValidationError(): void
    {
        $this->boardService->method('create')->willThrowException(new RetroException('Le titre est obligatoire.'));
        $token = $this->csrfToken();
        $request = new Request('POST', '/retro', [], ['_csrf_token' => $token, 'title' => ''], [], []);

        $response = $this->controller->store($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/retro/create', (string) ($response->getHeaders()['Location'] ?? ''));
    }

    public function testEditReturns404ForUnknownBoard(): void
    {
        $response = $this->controller->edit(new Request('GET', '/retro/999/edit', [], [], [], []), ['id' => '999']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testEditRendersForAnExistingBoard(): void
    {
        $id = $this->boardRepository->create('Camp', '2026-07-01', null, 'tok', null, true, 'unlimited', 5, true, 'cookie', 140, '7d', null, 3);

        $response = $this->controller->edit(new Request('GET', '/retro/' . $id . '/edit', [], [], [], []), ['id' => (string) $id]);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCloseRequiresCsrf(): void
    {
        $request = new Request('POST', '/retro/1/close', [], ['_csrf_token' => 'bad'], [], []);

        $response = $this->controller->close($request, ['id' => '1']);

        $this->assertSame(302, $response->getStatusCode());
        $this->boardService->expects($this->never())->method('close');
    }

    public function testCloseForbiddenBelowChiefEvenIfCreateThresholdIsLower(): void
    {
        AuthSession::logout();
        AuthSession::login(3, 'intendant@test.be', 'intendant');
        // The refusal AND what it protects. A 403 alone is satisfied by a
        // controller that does the work first and refuses afterwards —
        // measured, on this very method (issue #387).
        $this->boardService->expects($this->never())->method('close');
        $token = $this->csrfToken();

        $response = $this->controller->close(new Request('POST', '/retro/1/close', [], ['_csrf_token' => $token], [], []), ['id' => '1']);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testCloseCallsBoardServiceWhenAuthorized(): void
    {
        $this->boardService->expects($this->once())->method('close');
        $token = $this->csrfToken();

        $response = $this->controller->close(new Request('POST', '/retro/1/close', [], ['_csrf_token' => $token], [], []), ['id' => '1']);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testRegenerateLinkForbiddenBelowChief(): void
    {
        AuthSession::logout();
        AuthSession::login(3, 'intendant@test.be', 'intendant');
        // The refusal AND what it protects. A 403 alone is satisfied by a
        // controller that does the work first and refuses afterwards —
        // measured, on this very method (issue #387).
        $this->boardService->expects($this->never())->method('regenerateLink');
        $token = $this->csrfToken();

        $response = $this->controller->regenerateLink(new Request('POST', '/retro/1/regenerate-link', [], ['_csrf_token' => $token], [], []), ['id' => '1']);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testReopenRequiresCsrf(): void
    {
        $this->boardService->expects($this->never())->method('reopen');

        $response = $this->controller->reopen(new Request('POST', '/retro/1/reopen', [], ['_csrf_token' => 'bad'], [], []), ['id' => '1']);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testReopenForbiddenBelowChief(): void
    {
        AuthSession::logout();
        AuthSession::login(3, 'intendant@test.be', 'intendant');
        // The refusal AND what it protects. A 403 alone is satisfied by a
        // controller that does the work first and refuses afterwards —
        // measured, on this very method (issue #387).
        $this->boardService->expects($this->never())->method('reopen');
        $token = $this->csrfToken();

        $response = $this->controller->reopen(new Request('POST', '/retro/1/reopen', [], ['_csrf_token' => $token], [], []), ['id' => '1']);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testReopenCallsBoardServiceWhenAuthorized(): void
    {
        $this->boardService->expects($this->once())->method('reopen');
        $token = $this->csrfToken();

        $response = $this->controller->reopen(new Request('POST', '/retro/1/reopen', [], ['_csrf_token' => $token], [], []), ['id' => '1']);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testArchiveForbiddenBelowChief(): void
    {
        AuthSession::logout();
        AuthSession::login(3, 'intendant@test.be', 'intendant');
        // The refusal AND what it protects. A 403 alone is satisfied by a
        // controller that does the work first and refuses afterwards —
        // measured, on this very method (issue #387).
        $this->boardService->expects($this->never())->method('archive');
        $token = $this->csrfToken();

        $response = $this->controller->archive(new Request('POST', '/retro/1/archive', [], ['_csrf_token' => $token], [], []), ['id' => '1']);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testArchiveCallsBoardServiceWhenAuthorized(): void
    {
        $this->boardService->expects($this->once())->method('archive');
        $token = $this->csrfToken();

        $response = $this->controller->archive(new Request('POST', '/retro/1/archive', [], ['_csrf_token' => $token], [], []), ['id' => '1']);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testUnarchiveForbiddenBelowChief(): void
    {
        AuthSession::logout();
        AuthSession::login(3, 'intendant@test.be', 'intendant');
        // The refusal AND what it protects. A 403 alone is satisfied by a
        // controller that does the work first and refuses afterwards —
        // measured, on this very method (issue #387).
        $this->boardService->expects($this->never())->method('unarchive');
        $token = $this->csrfToken();

        $response = $this->controller->unarchive(new Request('POST', '/retro/1/unarchive', [], ['_csrf_token' => $token], [], []), ['id' => '1']);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testUnarchiveCallsBoardServiceWhenAuthorized(): void
    {
        $this->boardService->expects($this->once())->method('unarchive');
        $token = $this->csrfToken();

        $response = $this->controller->unarchive(new Request('POST', '/retro/1/unarchive', [], ['_csrf_token' => $token], [], []), ['id' => '1']);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testIndexSeparatesArchivedBoardsFromActiveOnes(): void
    {
        $this->boardRepository->create('Active', '2026-07-01', null, 'tok-active', null, true, 'unlimited', 5, true, 'cookie', 140, '7d', null, 3);
        $archivedId = $this->boardRepository->create('Archived', '2026-06-01', null, 'tok-archived', null, true, 'unlimited', 5, true, 'cookie', 140, '7d', null, 3);
        $this->boardRepository->close($archivedId);
        $this->boardRepository->archive($archivedId);

        $response = $this->controller->index(new Request('GET', '/retro', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Active', $response->getBody());
        $this->assertStringContainsString('Rétrospectives archivées (1)', $response->getBody());
    }

    // --- what a chief reads when a board refuses the gesture (issue #449, lot 5) ---
    // Six `catch (RetroException $e)` blocks, one per gesture, none of them
    // ever executed before this batch. Every test below reaches its branch
    // through the REAL BoardService and a real row in a real state — an
    // emptied title, a board still open, a board merely closed, an id that
    // matches nothing. None of them stubs a throw: the point is not that the
    // `catch` exists but that the sentence the chief reads is the service's
    // own, and that it is the right one for the gesture they pressed.
    //
    // For close/reopen/archive/unarchive the flash is the ONLY observable
    // difference between success and refusal: all four redirect to /retro
    // either way, so a test asserting 302 and the Location passes on both
    // paths and proves nothing. Hence each asserts the error text AND the
    // absence of a success flash. update() and regenerateLink() differ —
    // they come back to /retro/<id>/edit, and update() is the one gesture
    // whose destination itself changes between success and refusal.
    //
    // reopen() and archive() share a precondition (status must be 'closed'),
    // so an open board refuses both: only the exact message distinguishes
    // them, which is why testArchive... asserts it is not reopen's sentence.

    /**
     * A controller wired to the real BoardService rather than setUp()'s mock,
     * so refusals come from the real preconditions and carry the real French
     * messages. The member, section and mail collaborators are stubs because
     * the constructor requires them and NO test in this file reaches any of
     * them: member/section are resolved only when a form context is rendered,
     * and the close notification only fires for a board that was still open
     * and carries a notify address — neither happens here.
     */
    private function controllerWithRealBoardService(): RetroChiefController
    {
        $boardService = new BoardService(
            $this->boardRepository,
            new CommentRepository($this->pdo),
            $this->createStub(MemberService::class),
            $this->createStub(SectionService::class),
            new SchedulerService(new SchedulerRepository($this->pdo)),
            new JournalService(new JournalRepository($this->pdo)),
            $this->createStub(MailService::class),
            EmailTemplateRendererFactory::shippedOnlyForModule($this->twig, 'retro'),
            'Test Unit',
            'https://example.test'
        );

        return new RetroChiefController(
            $this->twig,
            $this->boardRepository,
            $boardService,
            $this->settingService,
            $this->scoutYearResolver,
            $this->moduleManager
        );
    }

    private function makeBoard(string $title, string $token): int
    {
        return $this->boardRepository->create(
            $title, '2026-07-01', null, $token, null, true, 'unlimited', 5, true, 'cookie', 140, '7d', null, 3
        );
    }

    /**
     * @return array{type: string, message: string}
     */
    private function flash(): array
    {
        $flash = FlashMessage::get();
        self::assertNotNull($flash, 'the gesture set no flash message at all');

        return $flash;
    }

    private function statusOf(int $id): string
    {
        $board = $this->boardRepository->findById($id);
        self::assertNotNull($board);

        return $board->status;
    }

    public function testUpdateComesBackToTheFormWithTheReasonWhenTheTitleIsEmptied(): void
    {
        $id = $this->makeBoard('Camp 2026', 'tok-update-empty');
        $request = new Request('POST', '/retro/' . $id, [], [
            '_csrf_token' => $this->csrfToken(), 'title' => '   ',
        ], [], []);

        $response = $this->controllerWithRealBoardService()->update($request, ['id' => (string) $id]);

        $this->assertSame(302, $response->getStatusCode());
        // Back to the form, carrying the id — not to the list, and not to
        // /retro/0/edit, which is where a lost id would send the chief.
        $this->assertSame('/retro/' . $id . '/edit', $response->getHeaders()['Location'] ?? '');
        $flash = $this->flash();
        $this->assertSame('error', $flash['type']);
        $this->assertSame('Le titre est obligatoire.', $flash['message']);
        $board = $this->boardRepository->findById($id);
        $this->assertNotNull($board);
        $this->assertSame('Camp 2026', $board->title, 'the refused update still changed the title');
    }

    public function testUpdateGoesBackToTheListOnlyWhenItSucceeds(): void
    {
        // The contrast that gives the assertion above its meaning: the
        // destination is what separates an accepted edit from a refused one.
        $id = $this->makeBoard('Camp 2026', 'tok-update-ok');
        $request = new Request('POST', '/retro/' . $id, [], [
            '_csrf_token' => $this->csrfToken(), 'title' => 'Camp 2026 revu', 'board_date' => '2026-07-02',
            'vote_mode' => 'unlimited', 'anti_duplicate_mode' => 'cookie',
            'max_comment_length' => '140', 'auto_close_delay' => '7d',
        ], [], []);

        $response = $this->controllerWithRealBoardService()->update($request, ['id' => (string) $id]);

        $this->assertSame('/retro', $response->getHeaders()['Location'] ?? '');
        $flash = $this->flash();
        $this->assertSame('success', $flash['type']);
        $this->assertSame('Rétrospective mise à jour.', $flash['message']);
        $board = $this->boardRepository->findById($id);
        $this->assertNotNull($board);
        $this->assertSame('Camp 2026 revu', $board->title);
    }

    public function testCloseReportsAnUnknownBoardInsteadOfClaimingSuccess(): void
    {
        $request = new Request('POST', '/retro/999/close', [], ['_csrf_token' => $this->csrfToken()], [], []);

        $response = $this->controllerWithRealBoardService()->close($request, ['id' => '999']);

        // Same 302 to /retro as a successful close: only the flash tells the
        // chief that nothing happened.
        $this->assertSame(302, $response->getStatusCode());
        $flash = $this->flash();
        $this->assertSame('error', $flash['type']);
        $this->assertSame('Rétrospective introuvable.', $flash['message']);
    }

    public function testClosingAnAlreadyClosedBoardIsAcceptedRatherThanRefused(): void
    {
        // BoardService::close() returns early on a board that is not open
        // rather than throwing — so a chief who presses the button twice,
        // or who arrives on a stale list, is told it is closed instead of
        // being handed an error. Locked down because the opposite reading
        // is the natural one: the method's own docblock claimed it threw.
        $id = $this->makeBoard('Camp 2026', 'tok-close-twice');
        $this->boardRepository->close($id);
        $request = new Request('POST', '/retro/' . $id . '/close', [], ['_csrf_token' => $this->csrfToken()], [], []);

        $this->controllerWithRealBoardService()->close($request, ['id' => (string) $id]);

        $flash = $this->flash();
        $this->assertSame('success', $flash['type']);
        $this->assertSame('Rétrospective clôturée.', $flash['message']);
        $this->assertSame('closed', $this->statusOf($id));
    }

    public function testReopenRefusesABoardThatWasNeverClosedAndSaysWhy(): void
    {
        $id = $this->makeBoard('Camp 2026', 'tok-reopen-open');
        $request = new Request('POST', '/retro/' . $id . '/reopen', [], ['_csrf_token' => $this->csrfToken()], [], []);

        $this->controllerWithRealBoardService()->reopen($request, ['id' => (string) $id]);

        $flash = $this->flash();
        $this->assertSame('error', $flash['type']);
        $this->assertSame('Seule une rétrospective clôturée peut être réouverte.', $flash['message']);
        $this->assertSame('open', $this->statusOf($id));
    }

    public function testArchiveRefusesAnOpenBoardWithItsOwnReasonNotReopensOne(): void
    {
        $id = $this->makeBoard('Camp 2026', 'tok-archive-open');
        $request = new Request('POST', '/retro/' . $id . '/archive', [], ['_csrf_token' => $this->csrfToken()], [], []);

        $this->controllerWithRealBoardService()->archive($request, ['id' => (string) $id]);

        $flash = $this->flash();
        $this->assertSame('error', $flash['type']);
        // archive() and reopen() both require status 'closed', so an open
        // board refuses both. Only the sentence says which button was
        // pressed — a controller wiring archive() to reopen() would pass
        // every other assertion in this test.
        $this->assertSame('Seule une rétrospective clôturée peut être archivée.', $flash['message']);
        $this->assertSame('open', $this->statusOf($id));
    }

    public function testUnarchiveRefusesABoardThatIsMerelyClosed(): void
    {
        $id = $this->makeBoard('Camp 2026', 'tok-unarchive-closed');
        $this->boardRepository->close($id);
        $request = new Request('POST', '/retro/' . $id . '/unarchive', [], ['_csrf_token' => $this->csrfToken()], [], []);

        $this->controllerWithRealBoardService()->unarchive($request, ['id' => (string) $id]);

        $flash = $this->flash();
        $this->assertSame('error', $flash['type']);
        $this->assertSame('Cette rétrospective n\'est pas archivée.', $flash['message']);
        $this->assertSame('closed', $this->statusOf($id));
    }

    public function testRegenerateLinkOnAnUnknownBoardComesBackToItsFormWithTheReason(): void
    {
        $request = new Request('POST', '/retro/999/regenerate-link', [], ['_csrf_token' => $this->csrfToken()], [], []);

        $response = $this->controllerWithRealBoardService()->regenerateLink($request, ['id' => '999']);

        $this->assertSame('/retro/999/edit', $response->getHeaders()['Location'] ?? '');
        $flash = $this->flash();
        $this->assertSame('error', $flash['type']);
        $this->assertSame('Rétrospective introuvable.', $flash['message']);
    }

    public function testRegenerateLinkReplacesTheTokenWhenTheBoardExists(): void
    {
        // The contrast for the test above: same destination on both paths,
        // so the flash and the token are the only witnesses.
        $id = $this->makeBoard('Camp 2026', 'tok-regenerate');
        $request = new Request('POST', '/retro/' . $id . '/regenerate-link', [], ['_csrf_token' => $this->csrfToken()], [], []);

        $response = $this->controllerWithRealBoardService()->regenerateLink($request, ['id' => (string) $id]);

        $this->assertSame('/retro/' . $id . '/edit', $response->getHeaders()['Location'] ?? '');
        $flash = $this->flash();
        $this->assertSame('success', $flash['type']);
        // Both halves: the second one is the only warning the chief gets that
        // the link they may have already shared has just stopped working.
        $this->assertSame(
            'Lien régénéré — l\'ancien lien ne fonctionne plus.',
            $flash['message']
        );
        $board = $this->boardRepository->findById($id);
        $this->assertNotNull($board);
        $this->assertNotSame('tok-regenerate', $board->token, 'the old link still works');
    }
}
