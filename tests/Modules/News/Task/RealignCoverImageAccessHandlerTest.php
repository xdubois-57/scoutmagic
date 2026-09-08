<?php

declare(strict_types=1);

namespace Tests\Modules\News\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\File\FileRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\News\Repository\Article;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Task\RealignCoverImageAccessHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\News\NewsTestHelper;

/**
 * The one-shot pass behind issue #211's preview decision: every cover
 * published before it was uploaded with the article's own floor, so a
 * « Membres connectés » one 403s inside the og:image it is now offered
 * as — and an article whose visibility changed since kept the floor of
 * the old one, in both directions.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RealignCoverImageAccessHandlerTest extends TestCase
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

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $this->authorId = (int) $this->pdo->lastInsertId();

        (new SettingService(new SettingRepository($this->pdo)))->register(
            RealignCoverImageAccessHandler::DONE_FLAG, '0', 'boolean', 'Interne', 'Desc', 'news', null, null, false
        );
    }

    /**
     * The reason the pass exists: without it the og:image of every
     * already-published members-only article answers 403 to the crawler
     * that fetches it, and the feature reads as broken rather than as
     * not-yet-backfilled.
     */
    public function testItOpensTheCoverOfAnArticleThatIsNowShareable(): void
    {
        $fileId = $this->cover('identified');
        $this->article(Article::VISIBILITY_IDENTIFIED, $fileId);

        (new RealignCoverImageAccessHandler())->handle([], $this->taskContext());

        $this->assertSame('public', $this->roleMinOf($fileId));
    }

    /**
     * And the direction that leaks, which predates #211: an article moved
     * to « Animateurs » after publication kept a cover anyone holding the
     * URL could read, because the editor uploads no file when only the
     * visibility changes.
     */
    public function testItTightensTheCoverOfAStaffOnlyArticle(): void
    {
        $fileId = $this->cover('public');
        $this->article(Article::VISIBILITY_CHIEF, $fileId);

        (new RealignCoverImageAccessHandler())->handle([], $this->taskContext());

        $this->assertSame('chief', $this->roleMinOf($fileId));
    }

    public function testItLeavesFilesThatAreNotArticleCoversAlone(): void
    {
        $cover = $this->cover('identified');
        $this->article(Article::VISIBILITY_IDENTIFIED, $cover);
        $bodyImage = $this->cover('public');
        $memberPhoto = $this->cover('identified', 'core/member_photos/someone.jpg');

        (new RealignCoverImageAccessHandler())->handle([], $this->taskContext());

        // The body image of an article is stored `public` by the product
        // itself (NewsController::uploadBodyImage()) and is nobody's
        // cover — the pass walks articles, not the storage directory.
        $this->assertSame('public', $this->roleMinOf($bodyImage));
        $this->assertSame('identified', $this->roleMinOf($memberPhoto));
    }

    public function testItIsIdempotentAndFlipsTheFlag(): void
    {
        $fileId = $this->cover('identified');
        $this->article(Article::VISIBILITY_IDENTIFIED, $fileId);

        (new RealignCoverImageAccessHandler())->handle([], $this->taskContext());
        (new RealignCoverImageAccessHandler())->handle([], $this->taskContext());

        $this->assertSame('public', $this->roleMinOf($fileId));
        $settings = new SettingService(new SettingRepository($this->pdo));
        $this->assertSame('1', $settings->get(RealignCoverImageAccessHandler::DONE_FLAG, 'news'));

        // Second run: nothing left to move, and it says so rather than
        // reporting the first run's figure again. Both entries carry the
        // same timestamp, so they are compared as a set rather than by
        // position.
        $realigned = array_map(
            static fn (array $entry): int => (int) json_decode((string) $entry['context'], true)['realigned'],
            (new JournalRepository($this->pdo))->search()
        );
        sort($realigned);
        $this->assertSame([0, 1], $realigned);
    }

    /**
     * A pass that moves an access floor without anybody watching has to
     * leave a trace of how many it moved, or a cover that is readable
     * months from now cannot be attributed to it rather than to an author.
     */
    public function testThePassSaysWhatItDid(): void
    {
        $fileId = $this->cover('identified');
        $this->article(Article::VISIBILITY_IDENTIFIED, $fileId);

        (new RealignCoverImageAccessHandler())->handle([], $this->taskContext());

        $entry = (new JournalRepository($this->pdo))->search()[0];
        $this->assertSame('news_cover_access_realigned', $entry['event_type']);
        $this->assertSame('security', $entry['level']);
        $context = json_decode((string) $entry['context'], true);
        $this->assertSame(1, $context['covers']);
        $this->assertSame(1, $context['realigned']);
    }

    private function cover(string $roleMin, ?string $path = null): int
    {
        return $this->files->create(
            $path ?? 'news/images/cover-' . bin2hex(random_bytes(4)) . '.jpg',
            'cover.jpg',
            'image/jpeg',
            1024,
            $roleMin,
            'news',
            $this->authorId
        );
    }

    private function article(string $visibility, int $imageFileId): int
    {
        $id = $this->articles->create('Titre', $visibility, false, null, null, $this->authorId);
        $this->articles->update($id, 'Titre', $visibility, false, null, null, 'Résumé.', $imageFileId);

        return $id;
    }

    private function roleMinOf(int $fileId): string
    {
        $file = $this->files->findById($fileId);
        $this->assertNotNull($file);

        return $file->roleMin;
    }

    private function taskContext(): TaskContext
    {
        return new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $this->createMock(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $this->encryption),
            sys_get_temp_dir()
        );
    }
}
