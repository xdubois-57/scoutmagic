<?php

declare(strict_types=1);

namespace Tests\Modules\Retro\Service;

use Core\Security\EncryptionService;
use Modules\Retro\Repository\Board;
use Modules\Retro\Repository\RateLimitRepository;
use Modules\Retro\Service\RateLimitService;
use Modules\Retro\Service\RetroException;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Retro\RetroTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RateLimitServiceTest extends TestCase
{
    private RateLimitService $service;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RetroTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->service = new RateLimitService(new RateLimitRepository($this->pdo), $encryption);
    }

    private function recorded(string $actionType): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM retro_rate_limits WHERE action_type = ?');
        $stmt->execute([$actionType]);

        return (int) $stmt->fetchColumn();
    }

    public function testIdentifierHashNeverReturnsTheRawCookieValue(): void
    {
        $hash = $this->service->identifierHash(null, 'my-cookie-value', 'session-id');

        $this->assertStringNotContainsString('my-cookie-value', $hash);
    }

    public function testIdentifierHashIsStableForTheSameCookie(): void
    {
        $a = $this->service->identifierHash(null, 'cookie-x', 'session-1');
        $b = $this->service->identifierHash(null, 'cookie-x', 'session-2');

        $this->assertSame($a, $b);
    }

    public function testIdentifierHashFallsBackToSessionIdWhenNoIpOrCookie(): void
    {
        $a = $this->service->identifierHash(null, null, 'session-a');
        $b = $this->service->identifierHash(null, null, 'session-b');

        $this->assertNotSame($a, $b);
    }

    public function testIdentifierHashKeysOnTheIpNotTheAttackerControlledCookie(): void
    {
        // Same IP, different cookie/session → same bucket: discarding the
        // cookie (or rotating the session) can no longer mint a fresh bucket
        // for the paid endpoints (audit M13).
        $a = $this->service->identifierHash('203.0.113.7', 'cookie-1', 'session-1');
        $b = $this->service->identifierHash('203.0.113.7', 'cookie-2', 'session-2');
        $this->assertSame($a, $b);

        // Different IP → different bucket.
        $c = $this->service->identifierHash('203.0.113.8', 'cookie-1', 'session-1');
        $this->assertNotSame($a, $c);

        // The IP wins over the cookie fallback entirely.
        $withoutCookie = $this->service->identifierHash('203.0.113.7', null, 'session-x');
        $this->assertSame($a, $withoutCookie);
    }

    public function testCheckAndRecordAllowsActionsUnderTheLimit(): void
    {
        $hash = $this->service->identifierHash(null, 'cookie', 'session');

        for ($i = 0; $i < 5; $i++) {
            $this->service->checkAndRecord($hash, 'comment');
        }

        // Allowed *and* counted: an action that passes without being
        // recorded is an action the limit can never be reached from.
        $this->assertSame(5, $this->recorded('comment'));
    }

    public function testCheckAndRecordThrowsPastTheLimit(): void
    {
        $hash = $this->service->identifierHash(null, 'cookie', 'session');
        for ($i = 0; $i < 10; $i++) {
            $this->service->checkAndRecord($hash, 'comment');
        }

        $this->expectException(RetroException::class);
        $this->service->checkAndRecord($hash, 'comment');
    }

    public function testCheckAndRecordThrownExceptionHasRateLimitedType(): void
    {
        $hash = $this->service->identifierHash(null, 'cookie', 'session');
        for ($i = 0; $i < 10; $i++) {
            $this->service->checkAndRecord($hash, 'comment');
        }

        try {
            $this->service->checkAndRecord($hash, 'comment');
            $this->fail('Expected a RetroException.');
        } catch (RetroException $e) {
            $this->assertSame(RetroException::TYPE_RATE_LIMITED, $e->type);
        }
    }

    public function testCommentAndVoteLimitsAreIndependent(): void
    {
        $hash = $this->service->identifierHash(null, 'cookie', 'session');
        for ($i = 0; $i < 10; $i++) {
            $this->service->checkAndRecord($hash, 'comment');
        }

        // 'vote' has a separate, higher limit — must not be affected by
        // 'comment' having just been exhausted.
        $this->service->checkAndRecord($hash, 'vote');

        $this->assertSame(1, $this->recorded('vote'));
        $this->assertSame(10, $this->recorded('comment'));
    }

    /**
     * checkAndRecord() falls back to PHP_INT_MAX for an action type that is
     * not in LIMITS, so adding a call site without adding the entry throttles
     * nothing at all. "shorten" backs a public, AI-billed route.
     */
    public function testShortenIsThrottled(): void
    {
        $hash = $this->service->identifierHash(null, null, 'session-shorten');

        for ($i = 0; $i < 5; $i++) {
            $this->service->checkAndRecord($hash, 'shorten');
        }

        $this->expectException(RetroException::class);
        $this->service->checkAndRecord($hash, 'shorten');
    }

    public function testShortenLimitIsIndependentFromTheCommentLimit(): void
    {
        $hash = $this->service->identifierHash(null, null, 'session-mixed');

        for ($i = 0; $i < 5; $i++) {
            $this->service->checkAndRecord($hash, 'shorten');
        }

        // Exhausting the shorten budget must not consume the comment budget.
        $this->service->checkAndRecord($hash, 'comment');

        $this->expectException(RetroException::class);
        $this->service->checkAndRecord($hash, 'shorten');
    }
    /**
     * Issue #1128: an IPv6 subscriber owns a whole /64 and can rotate
     * through it — the per-visitor counter must see one visitor.
     */
    public function testTwoAddressesOfOneIpv6SubscriberShareABucket(): void
    {
        $a = $this->service->identifierHash('2001:db8:aaaa:1::1', 'cookie-1', 'session-1');
        $b = $this->service->identifierHash('2001:db8:aaaa:1:9f3c:12ab:77de:4', 'cookie-2', 'session-2');

        $this->assertSame($a, $b);
    }

    public function testTwoIpv6SubscribersKeepTheirOwnBucket(): void
    {
        $this->assertNotSame(
            $this->service->identifierHash('2001:db8:aaaa:1::1', null, 's'),
            $this->service->identifierHash('2001:db8:aaaa:2::1', null, 's')
        );
    }

    public function testAnIpv4MappedAddressSharesItsIpv4Bucket(): void
    {
        $this->assertSame(
            $this->service->identifierHash('203.0.113.7', null, 's'),
            $this->service->identifierHash('::ffff:203.0.113.7', null, 's')
        );
    }

    public function testRotatingIpv6AddressesNoLongerEscapesTheShortenLimit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->service->checkAndRecord(
                $this->service->identifierHash('2001:db8:aaaa:1::' . dechex($i), null, 's' . $i),
                'shorten'
            );
        }

        $this->expectException(RetroException::class);
        $this->service->checkAndRecord($this->service->identifierHash('2001:db8:aaaa:1::99', null, 's99'), 'shorten');
    }

    private function board(int $id, string $accessMode): Board
    {
        return new Board(
            id: $id,
            title: 'Board',
            boardDate: '2026-07-01',
            calendarEventId: null,
            token: 'token-' . $id,
            shortCode: null,
            status: 'open',
            accessMode: $accessMode,
            voteMode: 'unlimited',
            voteBudget: 5,
            votesVisible: true,
            maxCommentLength: 140,
            autoCloseDelay: '7d',
            autoCloseAt: null,
            aiSummary: null,
            createdBy: null,
            closeNotifyEnabled: false,
            closeNotifyEmail: null,
            createdAt: '2026-07-01 10:00:00',
            closedAt: null
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function boardAiBudgets(): array
    {
        return [
            'shorten' => [RateLimitService::BOARD_AI_SHORTEN],
            'moderation' => [RateLimitService::BOARD_AI_MODERATION],
        ];
    }

    private function spend(Board $board, string $budget): void
    {
        for ($i = 0; $i < RateLimitService::BOARD_AI_DAILY_BUDGETS[$budget]; $i++) {
            $this->service->checkAndRecordBoardAiCall($board, $budget);
        }
    }

    /**
     * Issue #1128: past its daily budget a public board refuses every paid
     * AI call, whichever address asks — the budget is the board's.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('boardAiBudgets')]
    public function testAPublicBoardRefusesAiCallsPastItsDailyBudget(string $budget): void
    {
        $board = $this->board(1, 'public');
        $this->spend($board, $budget);

        try {
            $this->service->checkAndRecordBoardAiCall($board, $budget);
            $this->fail('Expected a RetroException.');
        } catch (RetroException $e) {
            $this->assertSame(RetroException::TYPE_RATE_LIMITED, $e->type);
        }

        // Another board has its own budget.
        $this->service->checkAndRecordBoardAiCall($this->board(2, 'public'), $budget);
        $this->assertSame(RateLimitService::BOARD_AI_DAILY_BUDGETS[$budget] + 1, $this->recorded($budget));
    }

    /**
     * Review of #1196: « Raccourcir » is open to anybody holding the link,
     * moderation decides whether a comment can be posted at all. Draining
     * the first must not shut the second.
     */
    public function testTheShortenAndModerationBudgetsAreSeparate(): void
    {
        $board = $this->board(1, 'public');
        $this->spend($board, RateLimitService::BOARD_AI_SHORTEN);

        $this->service->checkAndRecordBoardAiCall($board, RateLimitService::BOARD_AI_MODERATION);

        $this->assertSame(1, $this->recorded(RateLimitService::BOARD_AI_MODERATION));
    }

    public function testAnUnknownBudgetIsAProgrammingError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->checkAndRecordBoardAiCall($this->board(1, 'public'), 'shorten');
    }

    public function testTheBoardBudgetCountsOverADayNotTheVisitorWindow(): void
    {
        $budget = RateLimitService::BOARD_AI_MODERATION;
        $board = $this->board(1, 'public');
        $this->service->checkAndRecordBoardAiCall($board, $budget);
        $hash = (string) $this->pdo->query('SELECT identifier_hash FROM retro_rate_limits')->fetchColumn();
        $this->pdo->exec('DELETE FROM retro_rate_limits');

        $insert = $this->pdo->prepare('INSERT INTO retro_rate_limits (identifier_hash, action_type, created_at) VALUES (?, ?, ?)');
        $twentyHoursAgo = (new \DateTimeImmutable('-20 hours'))->format('Y-m-d H:i:s');
        for ($i = 0; $i < RateLimitService::BOARD_AI_DAILY_BUDGETS[$budget]; $i++) {
            $insert->execute([$hash, $budget, $twentyHoursAgo]);
        }

        $this->expectException(RetroException::class);
        $this->service->checkAndRecordBoardAiCall($board, $budget);
    }

    public function testTheBoardBudgetStoresNoBoardIdInClear(): void
    {
        $this->service->checkAndRecordBoardAiCall($this->board(42, 'public'), RateLimitService::BOARD_AI_SHORTEN);

        $hash = (string) $this->pdo->query('SELECT identifier_hash FROM retro_rate_limits')->fetchColumn();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->assertSame($encryption->blindIndex('board:42', 'retro_rate_limit'), $hash);
        $this->assertStringNotContainsString('board', $hash);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function boardsThatAreNotPublic(): array
    {
        return ['animators' => ['animators'], 'identified' => ['identified']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('boardsThatAreNotPublic')]
    public function testABoardThatIsNotPublicHasNoDailyBudget(string $accessMode): void
    {
        $board = $this->board(1, $accessMode);
        foreach (array_keys(RateLimitService::BOARD_AI_DAILY_BUDGETS) as $budget) {
            for ($i = 0; $i <= RateLimitService::BOARD_AI_DAILY_BUDGETS[$budget]; $i++) {
                $this->service->checkAndRecordBoardAiCall($board, $budget);
            }
            $this->assertSame(0, $this->recorded($budget));
        }
    }

    /**
     * The tests run on SQLite with a TEXT column; production has an ENUM,
     * and a value missing from it fails the INSERT on every request. That
     * is how « Raccourcir » broke unnoticed (issue #1128) — so the list
     * the service writes and the list the schema accepts are held equal.
     */
    public function testTheSchemaAcceptsEveryActionTypeTheServiceRecords(): void
    {
        $schema = (string) file_get_contents(dirname(__DIR__, 4) . '/modules/retro/schema.sql');
        $this->assertSame(
            1,
            preg_match('/CREATE TABLE retro_rate_limits \(.*?action_type ENUM\(([^)]*)\)/s', $schema, $match)
        );
        preg_match_all("/'([^']+)'/", $match[1], $values);

        $this->assertEqualsCanonicalizing(RateLimitService::ACTION_TYPES, $values[1]);
    }
}
