<?php

declare(strict_types=1);

namespace Tests\Modules\Retro\Service;

use Core\Security\EncryptionService;
use Modules\Retro\Repository\Board;
use Modules\Retro\Repository\BoardRepository;
use Modules\Retro\Repository\CommentRepository;
use Modules\Retro\Repository\RateLimitRepository;
use Modules\Retro\Service\CommentService;
use Modules\Retro\Service\ModerationService;
use Modules\Retro\Service\RateLimitService;
use Modules\Retro\Service\RetroException;
use Modules\Retro\Service\VoteService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Retro\RetroTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
// This class builds its doubles once (in setUp() or a shared helper) and
// hands the same ones to every test: some tests set expectations on
// them, the others only need their answers, and PHPUnit would report
// each of those as a mock with no expectation (issue #665).
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
class CommentServiceTest extends TestCase
{
    private \PDO $pdo;
    private CommentService $service;
    private BoardRepository $boardRepository;
    private RateLimitService $rateLimitService;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RetroTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->boardRepository = new BoardRepository($this->pdo, new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));
        $commentRepository = new CommentRepository($this->pdo);
        $this->rateLimitService = new RateLimitService(new RateLimitRepository($this->pdo), $encryption);
        $this->service = new CommentService($commentRepository, null, $this->rateLimitService);
    }

    private function createBoard(array $overrides = []): Board
    {
        $defaults = ['maxCommentLength' => 140, 'status' => 'open', 'accessMode' => 'public'];
        $p = array_merge($defaults, $overrides);
        $id = $this->boardRepository->create(
            'Board', '2026-07-01', null, bin2hex(random_bytes(8)), null, $p['accessMode'], 'unlimited', 5,
            true, $p['maxCommentLength'], '7d', null, null
        );
        if ($p['status'] === 'closed') {
            $this->boardRepository->close($id);
        }

        return $this->boardRepository->findById($id);
    }

    /**
     * DoD requirement (module spec, CommentService's own class doc): posting/
     * hiding/unhiding a comment never writes to JournalService. Enforced
     * architecturally here — the class has no JournalService dependency to
     * begin with, so no code path could call it even accidentally; a
     * reflection check on the constructor makes that guarantee a regression
     * test rather than just a comment.
     */
    public function testCommentServiceHasNoJournalServiceDependency(): void
    {
        $constructor = new \ReflectionMethod(CommentService::class, '__construct');
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : '';
            $this->assertNotSame('Core\Journal\JournalService', $typeName, 'CommentService must never depend on JournalService.');
        }
    }

    public function testPostCommentCreatesAComment(): void
    {
        $board = $this->createBoard();
        $hash = $this->rateLimitService->identifierHash(null, 'cookie', 'session');

        $comment = $this->service->postComment($board, 'good', 'Super semaine !', $hash);

        $this->assertSame('good', $comment->columnKey);
        $this->assertSame('Super semaine !', $comment->body);
    }

    public function testPostCommentRejectsWhenBoardClosed(): void
    {
        $board = $this->createBoard(['status' => 'closed']);
        $hash = $this->rateLimitService->identifierHash(null, 'cookie', 'session');

        $this->expectException(RetroException::class);
        $this->service->postComment($board, 'good', 'Text', $hash);
    }

    public function testPostCommentRejectsInvalidColumn(): void
    {
        $board = $this->createBoard();
        $hash = $this->rateLimitService->identifierHash(null, 'cookie', 'session');

        $this->expectException(RetroException::class);
        $this->service->postComment($board, 'bogus-column', 'Text', $hash);
    }

    public function testPostCommentRejectsEmptyBody(): void
    {
        $board = $this->createBoard();
        $hash = $this->rateLimitService->identifierHash(null, 'cookie', 'session');

        $this->expectException(RetroException::class);
        $this->service->postComment($board, 'good', '   ', $hash);
    }

    public function testPostCommentRejectsTooLongBodyWithTooLongType(): void
    {
        $board = $this->createBoard(['maxCommentLength' => 120]);
        $hash = $this->rateLimitService->identifierHash(null, 'cookie', 'session');

        try {
            $this->service->postComment($board, 'good', str_repeat('a', 130), $hash);
            $this->fail('Expected a RetroException.');
        } catch (RetroException $e) {
            $this->assertSame(RetroException::TYPE_TOO_LONG, $e->type);
        }
    }

    public function testPostCommentRejectsPastRateLimitBeforeReachingModeration(): void
    {
        $board = $this->createBoard();
        $hash = $this->rateLimitService->identifierHash(null, 'cookie', 'session');
        for ($i = 0; $i < 10; $i++) {
            $this->service->postComment($board, 'good', 'Text ' . $i, $hash);
        }

        try {
            $this->service->postComment($board, 'good', 'One too many', $hash);
            $this->fail('Expected a RetroException.');
        } catch (RetroException $e) {
            $this->assertSame(RetroException::TYPE_RATE_LIMITED, $e->type);
        }
    }

    public function testGracefullyDegradesWithoutModerationService(): void
    {
        // $this->service was built with a null ModerationService in setUp()
        // — a comment must still post successfully, unmoderated.
        $board = $this->createBoard();
        $hash = $this->rateLimitService->identifierHash(null, 'cookie', 'session');

        $comment = $this->service->postComment($board, 'good', 'Fine without AI.', $hash);

        $this->assertSame('Fine without AI.', $comment->body);
    }

    private function flaggedModerationService(): \PHPUnit\Framework\MockObject\MockObject
    {
        $moderationService = $this->createMock(ModerationService::class);
        $moderationService->method('moderate')->willReturn([
            'flagged' => true, 'reason' => 'Propos irrespectueux détecté.', 'suggestion' => 'Une version plus polie.',
        ]);
        return $moderationService;
    }

    public function testDisabledModeNeverInvokesModerationEvenWhenTextWouldBeFlagged(): void
    {
        $moderationService = $this->flaggedModerationService();
        $moderationService->expects($this->never())->method('moderate');
        $commentRepository = new CommentRepository($this->pdo);
        $service = new CommentService($commentRepository, $moderationService, $this->rateLimitService);
        $board = $this->createBoard();
        $hash = $this->rateLimitService->identifierHash(null, 'cookie', 'session2');

        $comment = $service->postComment($board, 'good', 'Some text', $hash, 'disabled');

        $this->assertSame('Some text', $comment->body);
    }

    public function testWarningModeWithoutAcceptanceThrowsAndCarriesTheSuggestion(): void
    {
        $moderationService = $this->flaggedModerationService();
        $commentRepository = new CommentRepository($this->pdo);
        $service = new CommentService($commentRepository, $moderationService, $this->rateLimitService);
        $board = $this->createBoard();
        $hash = $this->rateLimitService->identifierHash(null, 'cookie', 'session2');

        try {
            $service->postComment($board, 'good', 'Some text', $hash, 'warning', false);
            $this->fail('Expected a RetroException.');
        } catch (RetroException $e) {
            $this->assertSame(RetroException::TYPE_OFFENSIVE, $e->type);
            $this->assertSame('Une version plus polie.', $e->suggestion);
        }
    }

    public function testWarningModeWithAcceptanceSavesWithoutInvokingModeration(): void
    {
        $moderationService = $this->flaggedModerationService();
        $moderationService->expects($this->never())->method('moderate');
        $commentRepository = new CommentRepository($this->pdo);
        $service = new CommentService($commentRepository, $moderationService, $this->rateLimitService);
        $board = $this->createBoard();
        $hash = $this->rateLimitService->identifierHash(null, 'cookie', 'session2');

        $comment = $service->postComment($board, 'good', 'Some text', $hash, 'warning', true);

        $this->assertSame('Some text', $comment->body);
    }

    public function testEnforcedModeAlwaysThrowsRegardlessOfAcceptedWarning(): void
    {
        $moderationService = $this->flaggedModerationService();
        $commentRepository = new CommentRepository($this->pdo);
        $service = new CommentService($commentRepository, $moderationService, $this->rateLimitService);
        $board = $this->createBoard();
        $hash = $this->rateLimitService->identifierHash(null, 'cookie', 'session2');

        try {
            // acceptedWarning=true must NOT bypass moderation in enforced mode.
            $service->postComment($board, 'good', 'Some text', $hash, 'enforced', true);
            $this->fail('Expected a RetroException.');
        } catch (RetroException $e) {
            $this->assertSame(RetroException::TYPE_OFFENSIVE, $e->type);
        }
    }

    private function spendBoardAiBudget(Board $board, string $budget = RateLimitService::BOARD_AI_MODERATION): void
    {
        for ($i = 0; $i < RateLimitService::BOARD_AI_DAILY_BUDGETS[$budget]; $i++) {
            $this->rateLimitService->checkAndRecordBoardAiCall($board, $budget);
        }
    }

    /**
     * Issue #1128: past a public board's daily AI budget, a comment that
     * would need moderation is refused before the paid call — and never
     * published unmoderated.
     */
    public function testAPublicBoardPastItsDailyAiBudgetRefusesBeforeModeration(): void
    {
        $moderationService = $this->createMock(ModerationService::class);
        $moderationService->method('isAvailable')->willReturn(true);
        $moderationService->expects($this->never())->method('moderate');
        $service = new CommentService(new CommentRepository($this->pdo), $moderationService, $this->rateLimitService);
        $board = $this->createBoard();
        $this->spendBoardAiBudget($board);
        // A visitor the per-address limit has never seen.
        $hash = $this->rateLimitService->identifierHash('2001:db8:bbbb:9::1', null, 'session-new');

        try {
            $service->postComment($board, 'good', 'Some text', $hash, 'enforced');
            $this->fail('Expected a RetroException.');
        } catch (RetroException $e) {
            $this->assertSame(RetroException::TYPE_RATE_LIMITED, $e->type);
        }
        $this->assertSame([], $service->listForDisplay($board->id, false));
    }

    public function testEveryModeratedCommentOnAPublicBoardCountsAgainstItsBudget(): void
    {
        $moderationService = $this->createMock(ModerationService::class);
        $moderationService->method('isAvailable')->willReturn(true);
        $moderationService->method('moderate')->willReturn(['flagged' => false, 'reason' => null, 'suggestion' => null]);
        $service = new CommentService(new CommentRepository($this->pdo), $moderationService, $this->rateLimitService);
        $board = $this->createBoard();

        $service->postComment($board, 'good', 'Some text', $this->rateLimitService->identifierHash('192.0.2.1', null, 's'), 'enforced');

        $this->assertSame(1, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM retro_rate_limits WHERE action_type = 'board_ai_moderation'"
        )->fetchColumn());
    }

    /**
     * Review of #1196: « Raccourcir » needs nothing but the link. Spending
     * its whole budget must leave comment moderation — and so commenting —
     * untouched.
     */
    public function testAnEmptiedShortenBudgetStillLetsCommentsThrough(): void
    {
        $moderationService = $this->createMock(ModerationService::class);
        $moderationService->method('isAvailable')->willReturn(true);
        $moderationService->expects($this->once())->method('moderate')
            ->willReturn(['flagged' => false, 'reason' => null, 'suggestion' => null]);
        $service = new CommentService(new CommentRepository($this->pdo), $moderationService, $this->rateLimitService);
        $board = $this->createBoard();
        $this->spendBoardAiBudget($board, RateLimitService::BOARD_AI_SHORTEN);

        $comment = $service->postComment(
            $board,
            'good',
            'Some text',
            $this->rateLimitService->identifierHash('192.0.2.1', null, 's'),
            'enforced'
        );

        $this->assertSame('Some text', $comment->body);
    }

    public function testABoardThatIsNotPublicHasNoDailyAiBudget(): void
    {
        $moderationService = $this->createMock(ModerationService::class);
        $moderationService->method('isAvailable')->willReturn(true);
        $moderationService->expects($this->once())->method('moderate')
            ->willReturn(['flagged' => false, 'reason' => null, 'suggestion' => null]);
        $service = new CommentService(new CommentRepository($this->pdo), $moderationService, $this->rateLimitService);
        $board = $this->createBoard(['accessMode' => 'identified']);
        $this->spendBoardAiBudget($board);

        $comment = $service->postComment(
            $board,
            'good',
            'Some text',
            $this->rateLimitService->identifierHash('192.0.2.1', null, 's'),
            'enforced'
        );

        $this->assertSame('Some text', $comment->body);
    }

    public function testANullModerationServiceBehavesAsDisabledRegardlessOfMode(): void
    {
        // $this->service was built with a null ModerationService in setUp().
        $board = $this->createBoard();
        $hash = $this->rateLimitService->identifierHash(null, 'cookie', 'session');

        $comment = $this->service->postComment($board, 'good', 'Fine without AI.', $hash, 'enforced');

        $this->assertSame('Fine without AI.', $comment->body);
    }

    public function testHideAndUnhide(): void
    {
        $board = $this->createBoard();
        $hash = $this->rateLimitService->identifierHash(null, 'cookie', 'session');
        $comment = $this->service->postComment($board, 'good', 'Text', $hash);

        $this->service->hide($comment->id);
        $comments = $this->service->listForDisplay($board->id, false);
        $this->assertTrue($comments[0]->hidden);

        $this->service->unhide($comment->id);
        $comments = $this->service->listForDisplay($board->id, false);
        $this->assertFalse($comments[0]->hidden);
    }

    public function testListForDisplaySortsByVotesWhenRequested(): void
    {
        $board = $this->createBoard();
        $hash = $this->rateLimitService->identifierHash(null, 'cookie', 'session');
        $commentRepository = new CommentRepository($this->pdo);
        $low = $this->service->postComment($board, 'good', 'Low', $hash);
        $high = $this->service->postComment($board, 'good', 'High', $hash);
        $commentRepository->setVotes($low->id, 1);
        $commentRepository->setVotes($high->id, 9);

        $sorted = $this->service->listForDisplay($board->id, true);

        $this->assertSame($high->id, $sorted[0]->id);
    }
}
