<?php

declare(strict_types=1);

namespace Tests\Modules\News\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\File\FilePublicIds;
use Core\File\FileRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\News\Repository\Article;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Service\BodyImageAccess;
use Modules\News\Task\RealignBodyImageAccessHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\News\NewsTestHelper;

/**
 * The one-shot pass behind issue #1110: every image inserted in an
 * article's text before it was stored `public`, whatever the article.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class RealignBodyImageAccessHandlerTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private FileRepository $files;
    private ArticleRepository $articles;
    private int $authorId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        NewsTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->files = new FileRepository($this->pdo);
        $this->articles = new ArticleRepository($this->pdo);
        FilePublicIds::useRepository($this->files);
        $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)')
            ->execute(['enc', 'idx']);
        $this->authorId = (int) $this->pdo->lastInsertId();
        (new SettingService(new SettingRepository($this->pdo)))->register(
            RealignBodyImageAccessHandler::DONE_FLAG, '0', 'boolean', 'Interne', 'Desc', 'news', null, null, false
        );
    }

    protected function tearDown(): void
    {
        FilePublicIds::useRepository(null);
    }

    /**
     * The direction that leaks: an image stored `public` in a staff
     * article closes; one in a public article stays open; one nobody saved
     * goes to the provisional floor.
     */
    public function testItClosesWhatWasPublicAndKeepsWhatShouldBe(): void
    {
        $staffImage = $this->image();
        $publicImage = $this->image();
        $orphan = $this->image();
        $this->article(Article::VISIBILITY_ADMIN, $staffImage);
        $this->article(Article::VISIBILITY_PUBLIC, $publicImage);

        (new RealignBodyImageAccessHandler())->handle([], $this->taskContext());

        $this->assertSame('admin', $this->files->findById($staffImage)?->roleMin);
        $this->assertSame('public', $this->files->findById($publicImage)?->roleMin);
        $this->assertSame(BodyImageAccess::UNSAVED_ROLE_MIN, $this->files->findById($orphan)?->roleMin);
    }

    /**
     * It moves access floors without anybody watching: it says how many,
     * at `security`, and flips its flag so it runs once.
     */
    public function testItSaysWhatItDidAndRunsOnce(): void
    {
        $this->article(Article::VISIBILITY_CHIEF, $this->image());
        $this->image();

        (new RealignBodyImageAccessHandler())->handle([], $this->taskContext());

        $entry = (new JournalRepository($this->pdo))->search()[0];
        $this->assertSame('news_body_image_access_realigned', $entry['event_type']);
        $this->assertSame('security', $entry['level']);
        $context = json_decode((string) $entry['context'], true);
        $this->assertSame(['articles' => 1, 'realigned' => 1, 'closed' => 1], $context);
        $settings = new SettingService(new SettingRepository($this->pdo));
        $this->assertSame('1', $settings->get(RealignBodyImageAccessHandler::DONE_FLAG, 'news'));
    }

    private function image(): int
    {
        return $this->files->create(
            'news/images/' . bin2hex(random_bytes(6)) . '.jpg',
            'photo.jpg',
            'image/jpeg',
            1024,
            'public',
            'news',
            $this->authorId
        );
    }

    private function article(string $visibility, int $imageId): int
    {
        $id = $this->articles->create('Camp', $visibility, false, null, null, $this->authorId);
        $this->articles->setPublication($id, Article::STATUS_PUBLISHED, '2026-01-01 00:00:00');
        $this->pdo->prepare('INSERT INTO news_forms (news_article_id) VALUES (?)')->execute([$id]);
        $this->pdo->prepare(
            "INSERT INTO news_form_fields (form_id, sort_order, field_type, confirmation_text) VALUES (?, 0, 'text', ?)"
        )->execute([
            (int) $this->pdo->lastInsertId(),
            '<p><img src="' . FilePublicIds::urlFor($imageId, 'md') . '"></p>',
        ]);

        return $id;
    }

    private function taskContext(): TaskContext
    {
        return new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $this->createStub(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $this->encryption),
            sys_get_temp_dir()
        );
    }
}
