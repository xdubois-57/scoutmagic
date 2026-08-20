<?php

declare(strict_types=1);

namespace Tests\Modules\Retro\Controller;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Cookie\CookieConsentService;
use Core\Http\Request;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Modules\Retro\Controller\RetroBoardController;
use Modules\Retro\Repository\BoardRepository;
use Modules\Retro\Repository\CommentRepository;
use Modules\Retro\Repository\RateLimitRepository;
use Modules\Retro\Repository\VoteRepository;
use Modules\Retro\Service\BoardService;
use Modules\Retro\Service\CommentService;
use Modules\Retro\Service\ModerationService;
use Modules\Retro\Service\RateLimitService;
use Modules\Retro\Service\VoteService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Retro\RetroTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RetroBoardControllerTest extends TestCase
{
    private \PDO $pdo;
    private BoardRepository $boardRepository;
    private CommentRepository $commentRepository;
    private RetroBoardController $controller;
    private \PHPUnit\Framework\MockObject\MockObject $boardService;
    private Environment $twig;
    private RateLimitService $rateLimitService;
    private SettingService $settingService;
    private ScoutYearService $scoutYearService;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RetroTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->boardRepository = new BoardRepository($this->pdo, new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));
        $this->commentRepository = new CommentRepository($this->pdo);
        $voteRepository = new VoteRepository($this->pdo);
        $this->rateLimitService = new RateLimitService(new RateLimitRepository($this->pdo), $encryption);
        $commentService = new CommentService($this->commentRepository, null, $this->rateLimitService);
        $voteService = new VoteService($voteRepository, $this->commentRepository, $encryption);
        $this->boardService = $this->createMock(BoardService::class);
        $this->boardService->method('publicUrl')->willReturn('/r/dummy-token');
        $this->settingService = new SettingService(new SettingRepository($this->pdo));
        $this->scoutYearService = new ScoutYearService($this->pdo);

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $moduleViews = dirname(__DIR__, 4) . '/modules/retro/views';
        $loader = new FilesystemLoader($templateDir);
        $loader->addPath($moduleViews, 'retro');
        $this->twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);
        $this->twig->addGlobal('site_name', 'Test');
        $this->twig->addGlobal('is_authenticated', false);
        $this->twig->addGlobal('current_user_role', 'public');
        $this->twig->addGlobal('config_mode', false);
        $this->twig->addGlobal('cookie_consent_given', true);
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('csp_nonce', 'test-nonce');
        $this->twig->addFunction(new TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $this->twig->addFunction(new TwigFunction('get_flash', fn() => null));
        $this->twig->addFunction(new TwigFunction('csrf_token', fn() => 'test'));
        $this->twig->addFunction(new TwigFunction('file_url', fn() => ''));
        $this->twig->addFunction(new TwigFunction('param', fn() => ''));

        $this->controller = new RetroBoardController(
            $this->twig, $this->boardRepository, $this->commentRepository, $commentService, $voteService, $this->boardService,
            $this->rateLimitService, null, new CookieConsentService(), $this->settingService, $this->scoutYearService
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    private function createBoard(array $overrides = []): array
    {
        $defaults = [
            'voteMode' => 'unlimited', 'antiDuplicateMode' => 'cookie', 'votesVisible' => true, 'voteBudget' => 5,
        ];
        $p = array_merge($defaults, $overrides);
        $token = bin2hex(random_bytes(8));
        $id = $this->boardRepository->create(
            'Camp', '2026-07-01', null, $token, null, true, $p['voteMode'], $p['voteBudget'],
            $p['votesVisible'], $p['antiDuplicateMode'], 140, 'none', null, null
        );

        return [$id, $token];
    }

    private function csrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        return $token;
    }

    public function testShowReturns404ForUnknownToken(): void
    {
        $response = $this->controller->show(new Request('GET', '/r/bogus', [], [], [], []), ['token' => 'bogus']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testShowRendersSuccessfullyWithComments(): void
    {
        [$id, $token] = $this->createBoard();
        $this->commentRepository->create($id, 'good', 'Super ambiance !');
        $this->commentRepository->create($id, 'improve', 'Le repas.');

        $response = $this->controller->show(new Request('GET', '/r/' . $token, [], [], [], []), ['token' => $token]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Super ambiance', $response->getBody());
    }

    public function testShowRedactsHiddenCommentBodyForNonChief(): void
    {
        [$id, $token] = $this->createBoard();
        $commentId = $this->commentRepository->create($id, 'good', 'Propos injurieux');
        $this->commentRepository->setHidden($commentId, true);

        $response = $this->controller->show(new Request('GET', '/r/' . $token, [], [], [], []), ['token' => $token]);

        $this->assertStringNotContainsString('Propos injurieux', $response->getBody());
        $this->assertStringContainsString('Mot masqué', $response->getBody());
    }

    public function testPollReturns404ForUnknownToken(): void
    {
        $response = $this->controller->poll(new Request('GET', '/r/bogus/poll', [], [], [], []), ['token' => 'bogus']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testPollReturnsSerializedComments(): void
    {
        [$id, $token] = $this->createBoard();
        $this->commentRepository->create($id, 'good', 'Text');

        $response = $this->controller->poll(new Request('GET', '/r/' . $token . '/poll', [], [], [], []), ['token' => $token]);

        $decoded = json_decode($response->getBody(), true);
        $this->assertSame('open', $decoded['status']);
        $this->assertCount(1, $decoded['comments']);
        $this->assertSame('Text', $decoded['comments'][0]['body']);
    }

    private function jsonRequest(string $path, array $data): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', $path, [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn(json_encode($data));
        return $request;
    }

    public function testAddCommentRequiresCsrf(): void
    {
        [, $token] = $this->createBoard();

        $response = $this->controller->addComment($this->jsonRequest('/r/' . $token . '/comments', ['_csrf_token' => 'bad', 'column' => 'good', 'body' => 'Text']), ['token' => $token]);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testAddCommentSucceeds(): void
    {
        [, $token] = $this->createBoard();
        $csrf = $this->csrfToken();

        $response = $this->controller->addComment(
            $this->jsonRequest('/r/' . $token . '/comments', ['_csrf_token' => $csrf, 'column' => 'good', 'body' => 'Super ambiance']),
            ['token' => $token]
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertSame('Super ambiance', $decoded['comment']['body']);
    }

    public function testAddCommentResolvesModerationModeFromSettingsNotFromClientAndIncludesSuggestion(): void
    {
        $this->settingService->register('retro_moderation_mode', 'disabled', 'select', 'Modération', '', 'retro');
        $this->settingService->set('retro_moderation_mode', 'enforced', 'retro');

        $moderationService = $this->createMock(ModerationService::class);
        $moderationService->method('isAvailable')->willReturn(true);
        $moderationService->method('moderate')->willReturn([
            'flagged' => true, 'reason' => 'Propos irrespectueux.', 'suggestion' => 'Une version plus polie.',
        ]);
        $commentService = new CommentService($this->commentRepository, $moderationService, $this->rateLimitService);
        $controller = new RetroBoardController(
            $this->twig, $this->boardRepository, $this->commentRepository, $commentService,
            new VoteService(new VoteRepository($this->pdo), $this->commentRepository, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))),
            $this->boardService, $this->rateLimitService, $moderationService, new CookieConsentService(),
            $this->settingService, $this->scoutYearService
        );
        [, $token] = $this->createBoard();
        $csrf = $this->csrfToken();

        // A client claiming a different mode must have zero effect — the
        // controller resolves the mode itself from settings, never trusts input.
        $response = $controller->addComment(
            $this->jsonRequest('/r/' . $token . '/comments', [
                '_csrf_token' => $csrf, 'column' => 'good', 'body' => 'Some text',
                'moderation_mode' => 'disabled', 'accepted_warning' => true,
            ]),
            ['token' => $token]
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertSame('offensive', $decoded['type']);
        $this->assertSame('Une version plus polie.', $decoded['suggestion']);
        $this->assertSame('enforced', $decoded['moderation_mode']);
    }

    public function testAddCommentForcesDisabledModeWhenModerationServiceIsUnavailableEvenIfSettingSaysOtherwise(): void
    {
        $this->settingService->register('retro_moderation_mode', 'disabled', 'select', 'Modération', '', 'retro');
        $this->settingService->set('retro_moderation_mode', 'enforced', 'retro');

        $moderationService = $this->createMock(ModerationService::class);
        $moderationService->method('isAvailable')->willReturn(false);
        $moderationService->expects($this->never())->method('moderate');
        $commentService = new CommentService($this->commentRepository, $moderationService, $this->rateLimitService);
        $controller = new RetroBoardController(
            $this->twig, $this->boardRepository, $this->commentRepository, $commentService,
            new VoteService(new VoteRepository($this->pdo), $this->commentRepository, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))),
            $this->boardService, $this->rateLimitService, $moderationService, new CookieConsentService(),
            $this->settingService, $this->scoutYearService
        );
        [, $token] = $this->createBoard();
        $csrf = $this->csrfToken();

        $response = $controller->addComment(
            $this->jsonRequest('/r/' . $token . '/comments', ['_csrf_token' => $csrf, 'column' => 'good', 'body' => 'Some text']),
            ['token' => $token]
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
    }

    public function testAddCommentNeverAppearsInTheJournal(): void
    {
        [, $token] = $this->createBoard();
        $csrf = $this->csrfToken();

        $this->controller->addComment(
            $this->jsonRequest('/r/' . $token . '/comments', ['_csrf_token' => $csrf, 'column' => 'good', 'body' => 'Text']),
            ['token' => $token]
        );

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM event_log')->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testToggleLikeRequiresLoginWhenAntiDuplicateModeIsAuth(): void
    {
        [, $token] = $this->createBoard(['antiDuplicateMode' => 'auth']);
        $commentId = $this->commentRepository->create($this->boardRepository->findByToken($token)->id, 'good', 'Text');
        $csrf = $this->csrfToken();

        $response = $this->controller->toggleLike(
            $this->jsonRequest('/r/' . $token . '/comments/' . $commentId . '/vote', ['_csrf_token' => $csrf]),
            ['token' => $token, 'comment_id' => (string) $commentId]
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testToggleLikeSucceedsInCookieMode(): void
    {
        [$id, $token] = $this->createBoard();
        $commentId = $this->commentRepository->create($id, 'good', 'Text');
        $csrf = $this->csrfToken();

        $response = $this->controller->toggleLike(
            $this->jsonRequest('/r/' . $token . '/comments/' . $commentId . '/vote', ['_csrf_token' => $csrf]),
            ['token' => $token, 'comment_id' => (string) $commentId]
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertTrue($decoded['liked']);
    }

    public function testToggleLikeHidesVoteCountWhenResultsNotRevealed(): void
    {
        [$id, $token] = $this->createBoard(['votesVisible' => false]);
        $commentId = $this->commentRepository->create($id, 'good', 'Text');
        $csrf = $this->csrfToken();

        $response = $this->controller->toggleLike(
            $this->jsonRequest('/r/' . $token . '/comments/' . $commentId . '/vote', ['_csrf_token' => $csrf]),
            ['token' => $token, 'comment_id' => (string) $commentId]
        );

        $decoded = json_decode($response->getBody(), true);
        $this->assertNull($decoded['votes']);
    }

    public function testAddPointAndRemovePointForBudgetMode(): void
    {
        [$id, $token] = $this->createBoard(['voteMode' => 'budget', 'voteBudget' => 3]);
        $commentId = $this->commentRepository->create($id, 'good', 'Text');
        $csrf = $this->csrfToken();

        $addResponse = $this->controller->addPoint(
            $this->jsonRequest('/r/' . $token . '/comments/' . $commentId . '/vote/add', ['_csrf_token' => $csrf]),
            ['token' => $token, 'comment_id' => (string) $commentId]
        );
        $added = json_decode($addResponse->getBody(), true);
        $this->assertSame(2, $added['remaining']);

        $removeResponse = $this->controller->removePoint(
            $this->jsonRequest('/r/' . $token . '/comments/' . $commentId . '/vote/remove', ['_csrf_token' => $this->csrfToken()]),
            ['token' => $token, 'comment_id' => (string) $commentId]
        );
        $removed = json_decode($removeResponse->getBody(), true);
        $this->assertSame(3, $removed['remaining']);
    }

    public function testHideCommentForbiddenForNonChief(): void
    {
        [$id, $token] = $this->createBoard();
        $commentId = $this->commentRepository->create($id, 'good', 'Text');
        $csrf = $this->csrfToken();

        $response = $this->controller->hideComment(
            $this->jsonRequest('/r/' . $token . '/comments/' . $commentId . '/hide', ['_csrf_token' => $csrf]),
            ['token' => $token, 'comment_id' => (string) $commentId]
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testHideCommentForbiddenForAChiefWhoIsNotChefDunite(): void
    {
        AuthSession::login(3, 'chief@test.be', 'chief');
        $this->boardService->method('isUnitChief')->willReturn(false);
        [$id, $token] = $this->createBoard();
        $commentId = $this->commentRepository->create($id, 'good', 'Text');
        $csrf = $this->csrfToken();

        $response = $this->controller->hideComment(
            $this->jsonRequest('/r/' . $token . '/comments/' . $commentId . '/hide', ['_csrf_token' => $csrf]),
            ['token' => $token, 'comment_id' => (string) $commentId]
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testShowRevealsHiddenCommentBodyOnlyForChefDunite(): void
    {
        AuthSession::login(3, 'chief@test.be', 'chief');
        $this->boardService->method('isUnitChief')->willReturn(true);
        [$id, $token] = $this->createBoard();
        $commentId = $this->commentRepository->create($id, 'good', 'Propos injurieux');
        $this->commentRepository->setHidden($commentId, true);

        $response = $this->controller->show(new Request('GET', '/r/' . $token, [], [], [], []), ['token' => $token]);

        $this->assertStringContainsString('Propos injurieux', $response->getBody());
    }

    public function testShowRedactsHiddenCommentBodyForAChiefWhoIsNotChefDunite(): void
    {
        AuthSession::login(3, 'chief@test.be', 'chief');
        $this->boardService->method('isUnitChief')->willReturn(false);
        [$id, $token] = $this->createBoard();
        $commentId = $this->commentRepository->create($id, 'good', 'Propos injurieux');
        $this->commentRepository->setHidden($commentId, true);

        $response = $this->controller->show(new Request('GET', '/r/' . $token, [], [], [], []), ['token' => $token]);

        $this->assertStringNotContainsString('Propos injurieux', $response->getBody());
    }

    public function testHideAndUnhideCommentAsChefDunite(): void
    {
        AuthSession::login(3, 'chief@test.be', 'chief');
        $this->boardService->method('isUnitChief')->willReturn(true);
        [$id, $token] = $this->createBoard();
        $commentId = $this->commentRepository->create($id, 'good', 'Text');

        $hideResponse = $this->controller->hideComment(
            $this->jsonRequest('/r/' . $token . '/comments/' . $commentId . '/hide', ['_csrf_token' => $this->csrfToken()]),
            ['token' => $token, 'comment_id' => (string) $commentId]
        );
        $this->assertTrue(json_decode($hideResponse->getBody(), true)['success']);
        $this->assertTrue($this->commentRepository->findById($commentId)->hidden);

        $unhideResponse = $this->controller->unhideComment(
            $this->jsonRequest('/r/' . $token . '/comments/' . $commentId . '/unhide', ['_csrf_token' => $this->csrfToken()]),
            ['token' => $token, 'comment_id' => (string) $commentId]
        );
        $this->assertTrue(json_decode($unhideResponse->getBody(), true)['success']);
        $this->assertFalse($this->commentRepository->findById($commentId)->hidden);
    }

    public function testHideCommentNeverAppearsInTheJournal(): void
    {
        AuthSession::login(3, 'chief@test.be', 'chief');
        $this->boardService->method('isUnitChief')->willReturn(true);
        [$id, $token] = $this->createBoard();
        $commentId = $this->commentRepository->create($id, 'good', 'Text');

        $this->controller->hideComment(
            $this->jsonRequest('/r/' . $token . '/comments/' . $commentId . '/hide', ['_csrf_token' => $this->csrfToken()]),
            ['token' => $token, 'comment_id' => (string) $commentId]
        );

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM event_log')->fetchColumn();
        $this->assertSame(0, $count);
    }

    /**
     * The "unlimited" mode confusion the user reported traced back to the
     * poll response never telling the client which comments the current
     * voter had already liked, so the like button silently reset to
     * "un-liked" on every poll re-render even though the vote was still
     * recorded server-side. This and the next test guard that fix.
     */
    public function testPollReflectsYouVotedAfterLiking(): void
    {
        [$id, $token] = $this->createBoard();
        $commentId = $this->commentRepository->create($id, 'good', 'Text');
        $this->controller->toggleLike(
            $this->jsonRequest('/r/' . $token . '/comments/' . $commentId . '/vote', ['_csrf_token' => $this->csrfToken()]),
            ['token' => $token, 'comment_id' => (string) $commentId]
        );

        $response = $this->controller->poll(new Request('GET', '/r/' . $token . '/poll', [], [], [], []), ['token' => $token]);

        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['comments'][0]['youVoted']);
    }

    public function testShowReflectsYouVotedAfterLiking(): void
    {
        [$id, $token] = $this->createBoard();
        $commentId = $this->commentRepository->create($id, 'good', 'Text');
        $this->controller->toggleLike(
            $this->jsonRequest('/r/' . $token . '/comments/' . $commentId . '/vote', ['_csrf_token' => $this->csrfToken()]),
            ['token' => $token, 'comment_id' => (string) $commentId]
        );

        $response = $this->controller->show(new Request('GET', '/r/' . $token, [], [], [], []), ['token' => $token]);

        $this->assertStringContainsString('bi-heart-fill', $response->getBody());
    }

    public function testShortenReturns422WhenAiUnavailable(): void
    {
        [, $token] = $this->createBoard();
        $csrf = $this->csrfToken();

        $response = $this->controller->shorten(
            $this->jsonRequest('/r/' . $token . '/shorten', ['_csrf_token' => $csrf, 'body' => 'Some long text']),
            ['token' => $token]
        );

        $this->assertSame(422, $response->getStatusCode());
    }

    /**
     * POST /r/{token}/shorten is role_min "public", and every call is a paid
     * LLM round-trip. Service\CommentService::postComment() guards its own
     * moderation call with a board-open check, a rate limit and a length cap
     * before reaching the LLM; this route reached it with none of the three.
     * The tests below pin each guard, and assert the LLM is not called.
     */
    private function rebuildControllerWithModeration(ModerationService $moderationService): void
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $commentService = new CommentService($this->commentRepository, null, $this->rateLimitService);
        $voteService = new VoteService(new VoteRepository($this->pdo), $this->commentRepository, $encryption);

        $this->controller = new RetroBoardController(
            $this->twig, $this->boardRepository, $this->commentRepository, $commentService, $voteService, $this->boardService,
            $this->rateLimitService, $moderationService, new CookieConsentService(), $this->settingService, $this->scoutYearService
        );
    }

    private function alwaysAvailableModeration(int $expectedShortenCalls): ModerationService
    {
        $moderationService = $this->createMock(ModerationService::class);
        $moderationService->method('isAvailable')->willReturn(true);
        $moderationService->expects($this->exactly($expectedShortenCalls))
            ->method('shorten')
            ->willReturn('Version raccourcie.');

        return $moderationService;
    }

    public function testShortenSucceedsOnAnOpenBoardWithAReasonableBody(): void
    {
        $this->rebuildControllerWithModeration($this->alwaysAvailableModeration(1));
        [, $token] = $this->createBoard();
        $csrf = $this->csrfToken();

        $response = $this->controller->shorten(
            $this->jsonRequest('/r/' . $token . '/shorten', ['_csrf_token' => $csrf, 'body' => str_repeat('a', 200)]),
            ['token' => $token]
        );

        $this->assertSame(200, $response->getStatusCode());
        $decoded = json_decode($response->getBody(), true);
        $this->assertTrue($decoded['success']);
        $this->assertSame('Version raccourcie.', $decoded['body']);
    }

    public function testShortenIsRefusedOnAClosedBoardWithoutCallingTheLlm(): void
    {
        $this->rebuildControllerWithModeration($this->alwaysAvailableModeration(0));
        [$boardId, $token] = $this->createBoard();
        $this->boardRepository->close($boardId);
        $csrf = $this->csrfToken();

        $response = $this->controller->shorten(
            $this->jsonRequest('/r/' . $token . '/shorten', ['_csrf_token' => $csrf, 'body' => 'Un commentaire']),
            ['token' => $token]
        );

        $this->assertSame(422, $response->getStatusCode());
        $decoded = json_decode($response->getBody(), true);
        $this->assertStringContainsString('clôturé', (string) $decoded['error']);
    }

    public function testShortenRefusesAnOversizedBodyWithoutCallingTheLlm(): void
    {
        $this->rebuildControllerWithModeration($this->alwaysAvailableModeration(0));
        [, $token] = $this->createBoard();
        $csrf = $this->csrfToken();

        // maxCommentLength is 140 here, so anything past 4x that is refused.
        $response = $this->controller->shorten(
            $this->jsonRequest('/r/' . $token . '/shorten', ['_csrf_token' => $csrf, 'body' => str_repeat('a', 600)]),
            ['token' => $token]
        );

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testShortenRefusesAnEmptyBodyWithoutCallingTheLlm(): void
    {
        $this->rebuildControllerWithModeration($this->alwaysAvailableModeration(0));
        [, $token] = $this->createBoard();
        $csrf = $this->csrfToken();

        $response = $this->controller->shorten(
            $this->jsonRequest('/r/' . $token . '/shorten', ['_csrf_token' => $csrf, 'body' => '   ']),
            ['token' => $token]
        );

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testShortenIsRateLimitedSoAnAnonymousBurstCannotDrainTheAiBudget(): void
    {
        // RateLimitService::LIMITS['shorten'] is 5 per window.
        $this->rebuildControllerWithModeration($this->alwaysAvailableModeration(5));
        [, $token] = $this->createBoard();

        $statuses = [];
        for ($i = 0; $i < 8; $i++) {
            $csrf = $this->csrfToken();
            $statuses[] = $this->controller->shorten(
                $this->jsonRequest('/r/' . $token . '/shorten', ['_csrf_token' => $csrf, 'body' => 'Un commentaire à raccourcir']),
                ['token' => $token]
            )->getStatusCode();
        }

        $this->assertSame([200, 200, 200, 200, 200, 422, 422, 422], $statuses);
    }
}
