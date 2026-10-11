<?php

declare(strict_types=1);

namespace Tests\Modules\Social\Controller;

use Core\Config\AppConfig;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\File\StoredFileReader;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Router;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Modules\Gallery\Api\SharedAlbum;
use Modules\News\Api\SharedArticle;
use Modules\Social\Controller\PublicPageController;
use Modules\Social\Repository\CommunicationRepository;
use Modules\Social\Repository\PublicationRepository;
use Modules\Social\Service\PublicPageService;
use Modules\Social\Service\ShareSource;
use PHPUnit\Framework\TestCase;
use Tests\Core\Http\Controller\RecordingJournalRepository;
use Tests\Core\Http\Controller\RemoteBackupSettingsDouble;
use Tests\DatabaseTestHelper;
use Tests\Modules\Social\FakeAlbumSource;
use Tests\Modules\Social\FakeArticleSource;
use Tests\Modules\Social\SocialTestHelper as H;
use Tests\TestTwig;

/**
 * The public page of a communication, through the real router and guard,
 * as Facebook and a visitor reach it: anonymous (issue #706, IT-04).
 *
 * Two things are tested here that are tested nowhere else, because
 * nothing else can see them: that an anonymous caller gets the page at
 * all, and that **what it hands out is the card and nothing else of the
 * album**. The second is the whole security argument of SECURITY.md § 6:
 * the token opens one composition the chief looked at before publishing,
 * never the gallery behind it.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class PublicPageControllerTest extends TestCase
{
    private const ALBUM_ID = 42;
    private const ARTICLE_ID = 77;

    private \PDO $pdo;
    private CommunicationRepository $communications;
    private PublicationRepository $publications;
    private PublicPageService $pages;
    private string $storage;
    private RemoteBackupSettingsDouble $settings;
    private ?SharedAlbum $album = null;
    private ?SharedArticle $article = null;

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            @session_start();
        }
        AuthSession::logout();

        $this->pdo = DatabaseTestHelper::createTestDatabase();
        H::createTables($this->pdo);
        $this->storage = sys_get_temp_dir() . '/social-public-' . bin2hex(random_bytes(4));
        mkdir($this->storage, 0777, true);
        $this->settings = new RemoteBackupSettingsDouble(['base_url' => 'https://unite.example']);
        $this->communications = new CommunicationRepository($this->pdo);
        $this->publications = new PublicationRepository($this->pdo);
        $this->pages = new PublicPageService(
            $this->communications,
            $this->settings,
            new JournalService(new RecordingJournalRepository())
        );
        $this->album = new SharedAlbum(self::ALBUM_ID, 'Week-end de rentrée', null, '/gallery/' . self::ALBUM_ID);
        $this->article = new SharedArticle(self::ARTICLE_ID, 'Inscriptions', 'https://unite.example/s/abc', null, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->storage . '/*') as $entry) {
            if (is_file((string) $entry)) {
                unlink((string) $entry);
            }
        }
        if (is_dir($this->storage)) {
            @rmdir($this->storage);
        }
    }

    public function testAnAnonymousVisitorReadsWhatWasPublished(): void
    {
        $url = $this->sharedAlbumPage();

        $response = $this->get($this->pathOf($url));
        $body = self::flattened($response->getBody());

        $this->assertSame(200, $response->getStatusCode());
        // The publication's own title and caption, not the row's columns:
        // a chief may have typed on in the composer afterwards, and this
        // page is what somebody followed a link to.
        $this->assertStringContainsString('Week-end de rentrée', $body);
        $this->assertStringContainsString('Les photos sont en ligne', $body);
        $this->assertStringContainsString('<a href="/gallery/42" class="btn btn-primary">Voir sur le site</a>', $body);
        // What the visitor finds behind that button, said before the
        // click: the mockup puts this sentence under it, and somebody
        // arriving from Facebook has no reason to expect a sign-in page.
        $this->assertStringContainsString(
            'Les photos de l\'album sont réservées aux membres de l\'unité',
            $body
        );
    }

    /**
     * The tags Facebook, WhatsApp and Messenger actually read, absolute
     * because a crawler has no page to resolve a relative address
     * against — and `noindex`, because the address IS the authorisation
     * and a search result would hand it to people nobody shared it with.
     */
    public function testThePageCarriesItsOpenGraphTagsAndRefusesIndexing(): void
    {
        $url = $this->sharedAlbumPage();

        $body = self::flattened($this->get($this->pathOf($url))->getBody());

        $this->assertStringContainsString('<meta name="robots" content="noindex">', $body);
        $this->assertStringContainsString('<meta property="og:title" content="Week-end de rentrée">', $body);
        $this->assertStringContainsString('<meta property="og:url" content="' . $url . '">', $body);
        $this->assertStringContainsString(
            '<meta property="og:image" content="' . $url . '/carte.jpg">',
            $body
        );
        $this->assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $body);
    }

    /**
     * Cached hard, unlike the hour-long card route: Facebook re-reads
     * this address long after the post, and an image that stopped
     * answering would break a preview nobody can edit any more.
     */
    public function testTheImageIsTheCardItselfAndMayBeCachedForGood(): void
    {
        $url = $this->sharedAlbumPage();

        $response = $this->get($this->pathOf($url) . '/carte.jpg');
        $headers = $response->getHeaders();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/jpeg', $headers['Content-Type'] ?? null);
        $this->assertSame(H::groupPhoto(), $response->getBody());
        $this->assertStringContainsString('immutable', $headers['Cache-Control'] ?? '');
        $this->assertStringNotContainsString('no-store', $headers['Cache-Control'] ?? '');
    }

    /**
     * **Nothing of the album but the card.**
     *
     * The communication below has a gallery photo of its own and no
     * stored card, which is the shape that would betray a route reading
     * « the image of this communication » rather than `card_file_id`:
     * the photo is there, readable, and the page must still have no
     * image to offer. A card is composed, blurred to the chief's taste
     * and looked at before publishing; a gallery photo is none of those.
     *
     * The page itself answers 200 — what was published was a title and a
     * text — and carries no `og:image` and no `/files/` address at all.
     */
    public function testNothingOfTheAlbumIsReachableBeyondTheCard(): void
    {
        $photoId = $this->storeFile('gallery');
        $id = $this->communications->create(
            '',
            'Les photos sont en ligne',
            1,
            new \DateTimeImmutable(),
            ShareSource::KIND_ALBUM,
            self::ALBUM_ID
        );
        $this->communications->useGalleryPhoto($id, $photoId, new \DateTimeImmutable());
        $this->publish($id, 'Week-end de rentrée');
        $url = (string) $this->pages->ensureUrlFor($id, new \DateTimeImmutable());

        $page = $this->get($this->pathOf($url));
        $image = $this->get($this->pathOf($url) . '/carte.jpg');

        $this->assertSame(200, $page->getStatusCode());
        $this->assertStringNotContainsString('og:image', $page->getBody());
        $this->assertStringNotContainsString('/files/', $page->getBody());
        $this->assertSame(404, $image->getStatusCode());
    }

    /**
     * A token is 64 hexadecimal characters or it is nothing, and every
     * refusal is the same answer: an address that said « this one once
     * existed » would be a way to learn which communications were
     * shared.
     */
    public function testAnUnknownOrMalformedTokenIsRefusedTheSameWay(): void
    {
        $this->assertSame(404, $this->get('/partage/' . str_repeat('a', 64))->getStatusCode());
        $this->assertSame(404, $this->get('/partage/abc')->getStatusCode());
        $this->assertSame(404, $this->get('/partage/' . str_repeat('a', 64) . '/carte.jpg')->getStatusCode());
        $this->assertSame(404, $this->get('/partage/abc/carte.jpg')->getStatusCode());
    }

    /**
     * **A communication nobody shared has no page**, and its id is not a
     * way in: the address is a token or it is nothing, which is why the
     * token is not derived from the row's id.
     */
    public function testACommunicationWithNoPageIsNotReachableByItsId(): void
    {
        $id = $this->communications->create('Titre', 'Texte', 1, new \DateTimeImmutable());

        $this->assertSame(404, $this->get('/partage/' . $id)->getStatusCode());
        $this->assertNull($this->communications->find($id)?->publicToken);
    }

    /**
     * « Voir sur le site » goes the day the album does, and the page
     * keeps showing what was published — it stays online, with no way to
     * withdraw it, source or no source.
     */
    public function testTheButtonDisappearsWithTheSourceAndThePageRemains(): void
    {
        $url = $this->sharedAlbumPage();
        $this->album = null;

        $response = $this->get($this->pathOf($url));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Les photos sont en ligne', self::flattened($response->getBody()));
        $this->assertStringNotContainsString('Voir sur le site', $response->getBody());
    }

    /**
     * An article-backed communication points at the article's own page,
     * the short address when it has one — the same one its Open Graph
     * `og:url` names, since a visitor may have been handed either form.
     */
    public function testAnArticleBackedPageLeadsToTheArticle(): void
    {
        $id = $this->communications->create(
            '',
            'À lire',
            1,
            new \DateTimeImmutable(),
            ShareSource::KIND_ARTICLE,
            self::ARTICLE_ID
        );
        $this->publish($id, 'Inscriptions');
        $url = (string) $this->pages->ensureUrlFor($id, new \DateTimeImmutable());

        $body = self::flattened($this->get($this->pathOf($url))->getBody());

        $this->assertStringContainsString('href="/news/' . self::ARTICLE_ID . '"', $body);
        // **Not the album's sentence.** An article may well be public,
        // and promising a wall in front of a page that has none would
        // turn readers away.
        $this->assertStringNotContainsString('réservées aux membres', $body);
    }

    /** A communication written from nothing has no button at all. */
    public function testACommunicationWithNoSourceHasNoButton(): void
    {
        $id = $this->communications->create('Bonne année', 'Meilleurs vœux', 1, new \DateTimeImmutable());
        $this->publish($id, 'Bonne année');
        $url = (string) $this->pages->ensureUrlFor($id, new \DateTimeImmutable());

        $response = $this->get($this->pathOf($url));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('Voir sur le site', $response->getBody());
    }

    /**
     * **The first destination that received it decides**, not whichever
     * row comes back first.
     *
     * Every destination of one « Publier » records the same title, but a
     * destination retried days later re-reads the source — so an album
     * renamed in between leaves two rows that disagree. The card the page
     * shows was drawn once, before the first destination, with the title
     * of that moment; this is what keeps the heading and the image
     * telling the same story.
     */
    public function testTheHeadingFollowsTheFirstDestinationAndNotARetryAfterARename(): void
    {
        $id = $this->communications->create(
            '',
            'Les photos sont en ligne',
            1,
            new \DateTimeImmutable(),
            ShareSource::KIND_ALBUM,
            self::ALBUM_ID
        );
        // **The earliest publication is the one the query hands back
        // LAST.** `forSource()` has no `ORDER BY`, and both engines
        // answer in the order of the index on (kind, id, destination) —
        // so « facebook » comes back before « instagram » whichever was
        // written first. Putting the retry on the destination that sorts
        // first is what makes this test able to fail: with the earliest
        // row also the first row, the page would look right whichever of
        // the two it picked.
        $this->published($id, 'instagram', 'Week-end de rentrée', '2026-10-01 09:00:00');
        $this->published($id, 'facebook', 'Week-end de rentrée (archives)', '2026-10-08 09:00:00');
        $url = (string) $this->pages->ensureUrlFor($id, new \DateTimeImmutable());

        $body = self::flattened($this->get($this->pathOf($url))->getBody());

        $this->assertStringContainsString('<h1 class="h3 mb-4">Week-end de rentrée</h1>', $body);
        $this->assertStringNotContainsString('archives', $body);
    }

    // ————— Fixtures —————

    /** An album share, published once, with its card kept and a page minted. */
    private function sharedAlbumPage(): string
    {
        $id = $this->communications->create(
            '',
            'Les photos sont en ligne',
            1,
            new \DateTimeImmutable(),
            ShareSource::KIND_ALBUM,
            self::ALBUM_ID
        );
        $this->communications->updateCardFile($id, $this->storeFile('social_card'), new \DateTimeImmutable());
        $this->publish($id, 'Week-end de rentrée');

        return (string) $this->pages->ensureUrlFor($id, new \DateTimeImmutable());
    }

    private function publish(int $id, string $title): void
    {
        $now = new \DateTimeImmutable();
        $this->publications->claim(
            ShareSource::KIND_COMMUNICATION,
            $id,
            'facebook',
            false,
            1,
            $now,
            $now->modify('-15 minutes'),
            $title,
            'Les photos sont en ligne'
        );
    }

    /** A destination that actually received the publication, at a date. */
    private function published(int $id, string $destination, string $title, string $when): void
    {
        $claimed = new \DateTimeImmutable($when);
        $this->publications->claim(
            ShareSource::KIND_COMMUNICATION,
            $id,
            $destination,
            false,
            1,
            $claimed,
            $claimed->modify('-15 minutes'),
            $title,
            'Les photos sont en ligne'
        );
        $this->publications->markPublished(
            ShareSource::KIND_COMMUNICATION,
            $id,
            $destination,
            'remote-' . $destination,
            $claimed,
            $claimed
        );
    }

    private function storeFile(string $ownerType): int
    {
        $files = new FileRepository($this->pdo);

        return (new EncryptedFileStorageService($files, H::encryption(), $this->storage))->store(
            H::groupPhoto(),
            'image/jpeg',
            'carte.jpg',
            'social',
            'chief',
            $ownerType,
            1
        );
    }

    /** The path part of an absolute page address. */
    private function pathOf(string $url): string
    {
        return (string) parse_url($url, PHP_URL_PATH);
    }

    private static function flattened(string $html): string
    {
        return (string) preg_replace('/\s+/', ' ', $html);
    }

    private function get(string $path): Response
    {
        $router = new Router();
        // Declared in the manifest's order, which is what decides which
        // of two three-segment patterns answers first.
        $router->addRoute('GET', '/partage/carte/{token}', PublicPageController::class, 'show', 'public');
        $router->addRoute('GET', '/partage/{token}', PublicPageController::class, 'show', 'public');
        $router->addRoute('GET', '/partage/{token}/carte.jpg', PublicPageController::class, 'image', 'public');

        $configFile = sys_get_temp_dir() . '/test_social_page_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test Unit', 'debug' => false];");

        $twig = TestTwig::create(['social'], ['param' => static fn (string $key): string => 'Test Unit']);
        $twig->addGlobal('site_name', 'Test Unit');
        $twig->addGlobal('is_authenticated', false);
        $twig->addGlobal('current_user_role', 'public');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', $path);

        $files = new FileRepository($this->pdo);
        $front = new FrontController($router, $twig, new AppConfig($configFile));
        $front->registerController(
            PublicPageController::class,
            new PublicPageController(
                $twig,
                $this->pages,
                $this->publications,
                new StoredFileReader(
                    $files,
                    new EncryptedFileStorageService($files, H::encryption(), $this->storage),
                    $this->storage
                ),
                new FakeAlbumSource($this->album),
                new FakeArticleSource($this->article)
            )
        );

        return $front->handle(new Request('GET', $path, [], [], [], []));
    }
}
