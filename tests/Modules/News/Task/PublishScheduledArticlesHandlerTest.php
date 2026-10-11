<?php

declare(strict_types=1);

namespace Tests\Modules\News\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Notification\NotificationService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\News\Repository\Article;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Task\PublishScheduledArticlesHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\News\NewsTestHelper;

/**
 * « Votre actualité est en ligne » (issue #845, part 2): to its author,
 * once the scheduled time has come, exactly once — and never for a
 * publication somebody made by hand.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class PublishScheduledArticlesHandlerTest extends TestCase
{
    private \PDO $pdo;
    private ArticleRepository $articles;
    private int $authorId;
    /** @var list<array{type: string, to: list<int>, title: string, body: string, url: ?string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        NewsTestHelper::createTables($this->pdo);
        $this->articles = new ArticleRepository($this->pdo);

        $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)')->execute(['enc', 'idx']);
        $this->authorId = (int) $this->pdo->lastInsertId();
    }

    public function testTheAuthorIsToldOnceTheArticleIsOutAndOnlyOnce(): void
    {
        $id = $this->scheduled('Souper', '-1 minute');

        $this->runFor($id);
        $this->runFor($id);

        $this->assertSame([[
            'type' => 'news.article_published',
            'to' => [$this->authorId],
            'title' => 'Actualité en ligne',
            'body' => 'Votre actualité « Souper » est en ligne.',
            'url' => '/news/' . $id,
        ]], $this->sent);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM event_log WHERE event_type = 'article_online'")->fetchColumn());
    }

    /**
     * A row left for a time since moved later finds the article not online
     * yet, and leaves the notice to the run for the new time.
     */
    public function testNothingIsSentBeforeTheTime(): void
    {
        $id = $this->scheduled('Souper', '+1 hour');

        $this->runFor($id);

        $this->assertSame([], $this->sent);
        $this->assertSame(1, $this->noticeFlag($id), 'the notice waits for its time');
    }

    public function testAManualPublicationIsNeverAnnounced(): void
    {
        $id = $this->articles->create('Souper', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId);
        $this->articles->setPublication($id, Article::STATUS_PUBLISHED, (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'));

        $this->runFor($id);

        $this->assertSame([], $this->sent);
    }

    public function testADeletedOrUnpublishedArticleIsSkipped(): void
    {
        $draft = $this->scheduled('Retirée', '-1 minute');
        $this->articles->setPublication($draft, Article::STATUS_DRAFT, null);

        $this->runFor($draft);
        $this->runFor(999);

        $this->assertSame([], $this->sent);
    }

    private function scheduled(string $title, string $when): int
    {
        $id = $this->articles->create($title, Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId);
        $this->articles->setPublication(
            $id,
            Article::STATUS_PUBLISHED,
            (new \DateTimeImmutable($when))->format('Y-m-d H:i:s'),
            announce: true
        );

        return $id;
    }

    private function noticeFlag(int $id): int
    {
        return (int) $this->pdo->query("SELECT publication_notice_pending FROM news_articles WHERE id = {$id}")->fetchColumn();
    }

    private function runFor(int $articleId): void
    {
        (new PublishScheduledArticlesHandler())->handle(['article_id' => $articleId], $this->context());
    }

    private function context(): TaskContext
    {
        $notifications = $this->createStub(NotificationService::class);
        $notifications->method('dispatch')->willReturnCallback(function (string $type, array $recipients, array $payload): void {
            $this->sent[] = [
                'type' => $type,
                'to' => array_map(static fn(array $r): int => (int) $r['userAccountId'], $recipients),
                'title' => (string) $payload['title'],
                'body' => (string) $payload['body'],
                'url' => $payload['url'] ?? null,
            ];
        });
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        return new TaskContext(
            Connection::withPdo($this->pdo),
            $encryption,
            $this->createStub(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $encryption),
            sys_get_temp_dir(),
            $notifications
        );
    }
}
