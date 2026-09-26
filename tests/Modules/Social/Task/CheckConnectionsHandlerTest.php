<?php

declare(strict_types=1);

namespace Tests\Modules\Social\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\TaskContext;
use Core\Security\UserAccountRepository;
use Modules\Social\Api\SocialPlatform;
use Modules\Social\Meta\MetaClient;
use Modules\Social\Repository\ConnectionRepository;
use Modules\Social\Task\CheckConnectionsHandler;
use PHPUnit\Framework\TestCase;
use Tests\Core\Http\Controller\RecordingJournalRepository;
use Tests\DatabaseTestHelper;
use Tests\Modules\Social\FakeMetaTransport;
use Tests\Modules\Social\SocialTestHelper as H;

/**
 * The nightly pass: the Instagram token renewed before it runs out, both
 * connections checked, a failure journalled once.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class CheckConnectionsHandlerTest extends TestCase
{
    private \PDO $pdo;
    private ConnectionRepository $connections;
    private RecordingJournalRepository $journal;
    private FakeMetaTransport $meta;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        H::createTables($this->pdo);
        $this->connections = new ConnectionRepository($this->pdo, H::encryption());
        $this->journal = new RecordingJournalRepository();
        $this->meta = H::transport([]);
    }

    public function testNothingConnectedMeansNoCallAndTheChainIsRearmed(): void
    {
        $this->runPass();

        $this->assertSame([], $this->meta->requests);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM scheduled_actions WHERE module_id = ? AND task_key = ?');
        $stmt->execute(['social', CheckConnectionsHandler::TASK_KEY]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testAWeekOldInstagramTokenIsRenewedThenChecked(): void
    {
        $this->instagram(new \DateTimeImmutable('-8 days'), new \DateTimeImmutable('+52 days'));
        $this->meta->answers = [
            'refresh_access_token' => H::ok(['access_token' => 'RENEWED', 'expires_in' => 5184000]),
            '/me?' => H::ok(['user_id' => '9', 'username' => 'unite25sv', 'account_type' => 'BUSINESS']),
        ];

        $this->runPass();

        $this->assertSame('RENEWED', $this->connections->secretsOf(SocialPlatform::Instagram)->accessToken);
        $connection = $this->connections->find(SocialPlatform::Instagram);
        $this->assertGreaterThan(new \DateTimeImmutable('+59 days'), $connection?->tokenExpiresAt);
        $this->assertTrue($connection?->checkOk);
        $this->assertSame(1, $this->journal->countOf('token_refreshed'));
        $this->assertStringNotContainsString('RENEWED', $this->journal->textOf('token_refreshed'));
    }

    public function testARecentTokenIsOnlyChecked(): void
    {
        $this->instagram(new \DateTimeImmutable('-2 days'), new \DateTimeImmutable('+58 days'));
        $this->meta->answers = ['/me?' => H::ok(['user_id' => '9', 'username' => 'unite25sv', 'account_type' => 'BUSINESS'])];

        $this->runPass();

        $this->assertCount(1, $this->meta->requests);
        $this->assertSame(0, $this->journal->countOf('token_refreshed'));
    }

    public function testAnExpiredTokenIsNotSentAndIsJournalledOnce(): void
    {
        $this->instagram(new \DateTimeImmutable('-70 days'), new \DateTimeImmutable('-10 days'));

        $this->runPass();
        $this->runPass();

        $this->assertSame([], $this->meta->requests, 'Meta refuses to renew an expired token; nothing is sent.');
        $this->assertFalse($this->connections->find(SocialPlatform::Instagram)?->checkOk);
        $this->assertSame(1, $this->journal->countOf('token_expired'));
    }

    public function testAWithdrawnPageIsJournalledOnceNotEveryNight(): void
    {
        $this->connections->saveCredentials(SocialPlatform::Facebook, '123456', 'S');
        $this->connections->connect(SocialPlatform::Facebook, '42', 'Unité 25', 'PAGE', null, new \DateTimeImmutable('-3 days'));
        $this->meta->answers = ['graph.facebook.com' => ['status' => 400, 'body' => (string) json_encode(
            ['error' => ['message' => 'Session invalidated', 'code' => 190]]
        )]];

        $this->runPass();
        $this->runPass();

        $this->assertFalse($this->connections->find(SocialPlatform::Facebook)?->checkOk);
        $this->assertSame(1, $this->journal->countOf('auth_failed'));
        $this->assertStringContainsString('code 190', $this->journal->textOf('auth_failed'));
    }

    public function testANightWithoutAnAnswerChangesNothing(): void
    {
        $this->connections->saveCredentials(SocialPlatform::Facebook, '123456', 'S');
        $this->connections->connect(SocialPlatform::Facebook, '42', 'Unité 25', 'PAGE', null, new \DateTimeImmutable('-3 days'));
        $this->meta->answers = ['graph.facebook.com' => null];

        $this->runPass();

        $this->assertTrue($this->connections->find(SocialPlatform::Facebook)?->checkOk);
        $this->assertSame(0, $this->journal->countOf('auth_failed'));

        // A real withdrawal the night after is still journalled.
        $this->meta->answers = ['graph.facebook.com' => ['status' => 400, 'body' => (string) json_encode(
            ['error' => ['message' => 'Session invalidated', 'code' => 190]]
        )]];
        $this->runPass();
        $this->assertSame(1, $this->journal->countOf('auth_failed'));
    }

    public function testAPageChoiceNobodyFinishedIsDroppedAfterADay(): void
    {
        $this->connections->saveCredentials(SocialPlatform::Facebook, '123456', 'S');
        $this->connections->holdPendingUserToken(SocialPlatform::Facebook, 'USER', new \DateTimeImmutable('-2 days'));

        $this->runPass();

        $this->assertSame('', $this->connections->secretsOf(SocialPlatform::Facebook)->pendingUserToken);
    }

    private function instagram(\DateTimeImmutable $refreshedAt, \DateTimeImmutable $expiresAt): void
    {
        $this->connections->saveCredentials(SocialPlatform::Instagram, '123456', 'S');
        $this->connections->connect(SocialPlatform::Instagram, '9', 'unite25sv', 'TOKEN', $expiresAt, $refreshedAt);
    }

    private function runPass(): void
    {
        (new CheckConnectionsHandler(new MetaClient($this->meta)))->handle([], new TaskContext(
            Connection::withPdo($this->pdo),
            H::encryption(),
            $this->createStub(MailService::class),
            new JournalService($this->journal),
            new SettingService(new SettingRepository($this->pdo)),
            new UserAccountRepository($this->pdo, H::encryption()),
            sys_get_temp_dir()
        ));
    }
}
