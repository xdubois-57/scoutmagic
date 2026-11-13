<?php

declare(strict_types=1);

namespace Tests\Modules\News\Service;

use Core\File\FilePublicIds;
use Core\File\FileRepository;
use Modules\News\File\NewsCoverOwnershipChecker;
use Modules\News\Repository\Article;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Service\BodyImageAccess;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\News\NewsTestHelper;

/**
 * Issue #1110: the images inserted in an article's text are on the floor of
 * that text, and belong to the article.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class BodyImageAccessTest extends TestCase
{
    private \PDO $pdo;
    private FileRepository $files;
    private ArticleRepository $articles;
    private BodyImageAccess $access;
    private int $authorId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        NewsTestHelper::createTables($this->pdo);
        $this->files = new FileRepository($this->pdo);
        $this->articles = new ArticleRepository($this->pdo);
        $this->access = BodyImageAccess::forPdo($this->pdo);
        FilePublicIds::useRepository($this->files);
        $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)')
            ->execute(['enc', 'idx']);
        $this->authorId = (int) $this->pdo->lastInsertId();
    }

    /**
     * The floor of the text, visibility by visibility — the social
     * exception of an `identified` cover does not reach the body.
     */
    public function testEachImageTakesTheFloorOfTheTextAndBelongsToTheArticle(): void
    {
        foreach ([
            Article::VISIBILITY_PUBLIC => 'public',
            Article::VISIBILITY_DIRECT_LINK => 'public',
            Article::VISIBILITY_IDENTIFIED => 'identified',
            Article::VISIBILITY_CHIEF => 'chief',
            Article::VISIBILITY_ADMIN => 'admin',
        ] as $visibility => $expected) {
            $image = $this->image();
            $articleId = $this->article($visibility, [$image]);

            $this->access->syncArticle($articleId);

            $file = $this->files->findById($image);
            $this->assertSame($expected, $file?->roleMin, $visibility);
            $this->assertSame(NewsCoverOwnershipChecker::BODY_IMAGE_OWNER_TYPE, $file?->ownerType, $visibility);
            $this->assertSame($articleId, $file?->ownerId, $visibility);
        }
    }

    /** A variant address (`/md`) names the same file. */
    public function testAVariantAddressNamesItsFile(): void
    {
        $image = $this->image();
        $articleId = $this->article(Article::VISIBILITY_CHIEF, [], [FilePublicIds::urlFor($image, 'md')]);

        $this->access->syncArticle($articleId);

        $this->assertSame('chief', $this->files->findById($image)?->roleMin);
    }

    /** Moving the visibility moves the images, both ways. */
    public function testAChangeOfVisibilityRealignsBothWays(): void
    {
        $image = $this->image();
        $articleId = $this->article(Article::VISIBILITY_PUBLIC, [$image]);
        $this->access->syncArticle($articleId);
        $this->assertSame('public', $this->files->findById($image)?->roleMin);

        $this->setVisibility($articleId, Article::VISIBILITY_ADMIN);
        $this->access->syncArticle($articleId);
        $this->assertSame('admin', $this->files->findById($image)?->roleMin);

        $this->setVisibility($articleId, Article::VISIBILITY_PUBLIC);
        $this->access->syncArticle($articleId);
        $this->assertSame('public', $this->files->findById($image)?->roleMin);
    }

    /**
     * One file pasted into two articles follows the one that opens it
     * widest, so neither shows a broken image — online articles first, so
     * a public draft never closes it on a published one's readers.
     */
    public function testASharedImageFollowsTheWidestOnlineArticle(): void
    {
        $image = $this->image();
        $staff = $this->article(Article::VISIBILITY_ADMIN, [$image]);
        $public = $this->article(Article::VISIBILITY_PUBLIC, [$image]);

        $this->access->syncArticle($staff);

        $file = $this->files->findById($image);
        $this->assertSame('public', $file?->roleMin);
        $this->assertSame($public, $file?->ownerId);

        // The public one goes back to draft: the staff article, the only
        // one online, now governs.
        $this->articles->setPublication($public, Article::STATUS_DRAFT, null);
        $this->access->syncArticle($staff);

        $file = $this->files->findById($image);
        $this->assertSame('admin', $file?->roleMin);
        $this->assertSame($staff, $file?->ownerId);
    }

    /**
     * Only this module's text images: not a cover pasted into a text, not a
     * file stored elsewhere whose address an author copied in.
     */
    public function testOnlyTextImagesAreTouched(): void
    {
        $cover = $this->image();
        $memberPhoto = $this->image('core/member_photos/' . bin2hex(random_bytes(4)) . '.jpg', 'identified');
        $coveredArticle = $this->article(Article::VISIBILITY_PUBLIC, []);
        $this->articles->update(
            $coveredArticle,
            'Titre',
            Article::VISIBILITY_PUBLIC,
            false,
            null,
            null,
            'Résumé.',
            $cover
        );
        $articleId = $this->article(Article::VISIBILITY_ADMIN, [$cover, $memberPhoto]);

        $this->assertSame(0, $this->access->syncArticle($articleId));

        $this->assertSame('chief', $this->files->findById($cover)?->roleMin);
        $this->assertNull($this->files->findById($cover)?->ownerType);
        $this->assertSame('identified', $this->files->findById($memberPhoto)?->roleMin);
    }

    /**
     * What no saved article claims and was stored `public` before #1110
     * goes to the provisional floor; nothing else moves.
     */
    public function testUnclaimedPublicTextImagesAreClosed(): void
    {
        $orphan = $this->image(null, 'public');
        $claimed = $this->image(null, 'public');
        $this->access->syncArticle($this->article(Article::VISIBILITY_PUBLIC, [$claimed]));
        $cover = $this->image(null, 'public');
        $this->articles->update(
            $this->article(Article::VISIBILITY_PUBLIC, []),
            'Titre', Article::VISIBILITY_PUBLIC, false, null, null, 'Résumé.', $cover
        );
        $elsewhere = $this->image('core/pages/' . bin2hex(random_bytes(4)) . '.jpg', 'public');

        $this->assertSame(1, $this->access->closeUnclaimed());

        $this->assertSame(BodyImageAccess::UNSAVED_ROLE_MIN, $this->files->findById($orphan)?->roleMin);
        $this->assertSame('public', $this->files->findById($claimed)?->roleMin);
        $this->assertSame('public', $this->files->findById($cover)?->roleMin);
        $this->assertSame('public', $this->files->findById($elsewhere)?->roleMin);
    }

    /**
     * The sync runs only where FormService is given it. Null is allowed
     * for tests that build the service for something else, so the one
     * place that must pass it is checked here: without it, every saved
     * article's images would stay at the provisional floor, and a public
     * article would show broken images.
     */
    public function testTheApplicationWiresItIntoTheFormService(): void
    {
        $index = (string) file_get_contents(dirname(__DIR__, 4) . '/public/index.php');

        $this->assertMatchesRegularExpression(
            '~new \\\\Modules\\\\News\\\\Service\\\\FormService\((?:(?!\);).)*'
                . 'BodyImageAccess::forPdo\(\$pdo\)~s',
            $index
        );
    }

    private function image(?string $path = null, string $roleMin = BodyImageAccess::UNSAVED_ROLE_MIN): int
    {
        return $this->files->create(
            $path ?? 'news/images/' . bin2hex(random_bytes(6)) . '.jpg',
            'photo.jpg',
            'image/jpeg',
            1024,
            $roleMin,
            'news',
            $this->authorId
        );
    }

    /**
     * An online article whose first text field shows $imageIds, plus any
     * extra address given as is.
     *
     * @param list<int> $imageIds
     * @param list<string> $addresses
     */
    private function article(string $visibility, array $imageIds, array $addresses = []): int
    {
        $id = $this->articles->create('Titre', $visibility, false, null, null, $this->authorId);
        $this->articles->setPublication($id, Article::STATUS_PUBLISHED, '2026-01-01 00:00:00');

        $html = '<p>Texte</p>';
        foreach ($imageIds as $imageId) {
            $html .= '<p><img src="' . FilePublicIds::urlFor($imageId) . '" alt=""></p>';
        }
        foreach ($addresses as $address) {
            $html .= '<p><img src="' . $address . '" alt=""></p>';
        }

        $this->pdo->prepare('INSERT INTO news_forms (news_article_id) VALUES (?)')->execute([$id]);
        $formId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO news_form_fields (form_id, sort_order, field_type, confirmation_text) VALUES (?, 0, ?, ?)'
        )->execute([$formId, 'text', $html]);

        return $id;
    }

    private function setVisibility(int $articleId, string $visibility): void
    {
        $this->pdo->prepare('UPDATE news_articles SET visibility = ? WHERE id = ?')->execute([$visibility, $articleId]);
    }

    protected function tearDown(): void
    {
        FilePublicIds::useRepository(null);
    }
}
