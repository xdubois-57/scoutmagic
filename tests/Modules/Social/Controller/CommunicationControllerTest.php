<?php

declare(strict_types=1);

namespace Tests\Modules\Social\Controller;

use Core\Config\AppConfig;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\File\StoredFileReader;
use Core\File\UploadHandler;
use Core\Http\FlashMessage;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Router;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\UserAccountRepository;
use Modules\Social\Api\SocialPlatform;
use Modules\Social\Card\CardRenderer;
use Modules\Social\Card\CardService;
use Modules\Social\Controller\CommunicationController;
use Modules\Social\Meta\MetaClient;
use Modules\Social\Repository\CardRepository;
use Modules\Social\Repository\CommunicationRepository;
use Modules\Social\Repository\ConnectionRepository;
use Modules\Social\Repository\PublicationRepository;
use Modules\Social\Service\DestinationStates;
use Modules\Social\Service\GroupPublishingService;
use Tests\Modules\Social\FakeGroupPublisher;
use Modules\Social\Service\PublishingService;
use Modules\Social\Service\ShareSourceResolver;
use PHPUnit\Framework\TestCase;
use Tests\Core\Http\Controller\RecordingJournalRepository;
use Tests\Core\Http\Controller\RemoteBackupSettingsDouble;
use Tests\DatabaseTestHelper;
use Tests\Modules\Social\FakeMetaTransport;
use Tests\Modules\Social\FakePhotoPicker;
use Tests\Modules\Social\SocialTestHelper as H;
use Tests\TestTwig;
use Twig\Environment;

/**
 * « Communications »: who reaches each route through the real router and
 * guard, the page in the mockup's order, the gallery photo and the upload,
 * what leaves and stays frozen, and the history with its retry.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class CommunicationControllerTest extends TestCase
{
    private const PHOTO = 5;

    /** The album « Partager » opens the composer from, in this class. */
    public const ALBUM_ID = 42;
    public const ALBUM_TITLE = 'Week-end de rentrée';

    /**
     * Whether the one album `albumSource()` describes has a cover photo.
     * Reset in setUp, so a test that clears it cannot reach another.
     */
    private static bool $albumHasCover = true;

    private \PDO $pdo;
    private int $author;
    private int $other;
    private ConnectionRepository $connections;
    private PublicationRepository $publications;
    private CommunicationRepository $communications;
    private FakeMetaTransport $meta;
    private FakePhotoPicker $picker;
    private string $storage;
    private RecordingJournalRepository $journal;
    private ?FakeGroupPublisher $groups = null;

    protected function setUp(): void
    {
        self::$albumHasCover = true;
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            @session_start();
        }
        $_SESSION = [];
        $_POST = [];
        $_FILES = [];

        $this->pdo = DatabaseTestHelper::createTestDatabase();
        H::createTables($this->pdo);
        $this->connections = new ConnectionRepository($this->pdo, H::encryption());
        $this->publications = new PublicationRepository($this->pdo);
        $this->communications = new CommunicationRepository($this->pdo);
        $this->journal = new RecordingJournalRepository();
        $this->meta = H::transport([
            '/photos' => H::ok(['id' => 'P', 'post_id' => '42_1']),
            '/feed' => H::ok(['id' => '42_2']),
            '/media_publish' => H::ok(['id' => 'IG1']),
            '/media' => H::ok(['id' => 'C1']),
            'C1?' => H::ok(['status_code' => 'FINISHED']),
            'IG1?' => H::ok(['permalink' => 'https://www.instagram.com/p/abc/']),
        ]);
        $this->picker = new FakePhotoPicker([self::PHOTO => H::groupPhoto(), 6 => H::groupPhoto()]);
        $this->storage = sys_get_temp_dir() . '/social-comm-' . bin2hex(random_bytes(4));
        mkdir($this->storage . '/cards', 0777, true);

        $this->author = $this->account('claire@unite.be', 'Claire');
        $this->other = $this->account('autre@unite.be', 'Paul');

        $now = new \DateTimeImmutable();
        $this->connections->saveCredentials(SocialPlatform::Facebook, '123456', 'S');
        $this->connections->connect(SocialPlatform::Facebook, '42', 'Unité 25', 'PAGE', null, $now);
        $this->connections->saveCredentials(SocialPlatform::Instagram, '123456', 'S');
        $this->connections->connect(SocialPlatform::Instagram, '9', 'unite25', 'IGT', $now->modify('+60 days'), $now);
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        $_SESSION = [];
        $_POST = [];
        $_FILES = [];
        exec('rm -rf ' . escapeshellarg($this->storage));
    }

    // ————— Who may reach it —————

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function routes(): array
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/modules/social/module.json'), true);
        $cases = [];
        foreach ($manifest['routes'] as $route) {
            if (!str_ends_with($route['controller'], '\\CommunicationController')) {
                continue;
            }
            self::assertSame('chief', $route['role_min'], $route['path']);
            $cases[$route['method'] . ' ' . $route['path']] = [$route['method'], $route['path'], $route['action']];
        }
        self::assertCount(12, $cases);

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('routes')]
    public function testAnIntendantIsRefused(string $method, string $path, string $action): void
    {
        // One level below role_min (AGENTS.md: the RBAC boundary).
        AuthSession::login($this->author, 'claire@unite.be', 'intendant');

        $this->assertSame(403, $this->route($method, $path, $action, [])->getStatusCode());
        $this->assertSame([], $this->meta->requests);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('routes')]
    public function testTheAuthorGetsThrough(string $method, string $path, string $action): void
    {
        $id = $this->communication('Week-end', 'Inscriptions ouvertes', self::PHOTO);
        if (str_contains($path, 'reessayer')) {
            // Something to retry — which also freezes the communication,
            // so only for these two.
            $this->failedInstagram($id);
        }
        AuthSession::login($this->author, 'claire@unite.be', 'chief');
        $body = $method === 'POST' ? ['_csrf_token' => $this->csrf()] : [];

        $response = $this->route($method, $path, $action, $body, null, $id);

        $this->assertLessThan(400, $response->getStatusCode(), substr($response->getBody(), 0, 400));
    }

    public function testAnotherChiefsCommunicationIsNotThere(): void
    {
        $id = $this->communication('Week-end', 'Texte', self::PHOTO);
        AuthSession::login($this->other, 'autre@unite.be', 'chief');
        $params = ['id' => (string) $id];

        $this->assertSame(404, $this->controller()->edit($this->get(), $params)->getStatusCode());
        $this->assertSame(404, $this->controller()->preview($this->get(), $params)->getStatusCode());
        $this->assertSame(404, $this->controller()->picker($this->get(), $params)->getStatusCode());
        $this->assertSame(404, $this->controller()->update($this->post(['title' => 'X']), $params)->getStatusCode());
        $this->assertSame('Week-end', $this->communications->find($id)?->title);
    }

    public function testAnAdministratorMayPublishAChiefsCommunication(): void
    {
        $id = $this->communication('Week-end', 'Texte', self::PHOTO);
        AuthSession::login($this->other, 'autre@unite.be', 'admin');

        $this->assertSame(200, $this->controller()->edit($this->get(), ['id' => (string) $id])->getStatusCode());
    }

    // ————— One composer, opened from a source (#706, IT-01) —————

    /**
     * « Partager » on an album opens the composer already filled: the
     * album's title and image, a line saying what is being shared, and
     * nothing to type but the text.
     */
    public function testTheComposerOpensPrefilledFromAnAlbum(): void
    {
        $this->loginAuthor();

        $html = $this->controller()
            ->createFromSource($this->get(), ['kind' => 'album', 'id' => (string) self::ALBUM_ID])
            ->getBody();

        // Escaped, because Twig escapes: `{{ source_label }}` renders
        // « l'album » with its apostrophe as `&#039;`.
        $this->assertStringContainsString('Partage de l&#039;album', $html);
        $this->assertStringContainsString(self::ALBUM_TITLE, $html);
        // The pair travels in the form, not in the address.
        $this->assertStringContainsString('name="source_kind" value="album"', $html);
        $this->assertStringContainsString('name="source_id" value="' . self::ALBUM_ID . '"', $html);
    }

    /**
     * « Publier » must never be the first time the card is seen. Since
     * « Partager » writes no row, the prefilled composer has no
     * communication to draw a preview from, and the first version of this
     * showed a sentence promising the image « once published » instead of
     * the image — on a page where « Publier » was the only button, so
     * one POST both created the row and made an irreversible public post.
     */
    public function testThePrefilledComposerShowsTheCardItWouldPublish(): void
    {
        $this->loginAuthor();

        $html = $this->controller()
            ->createFromSource($this->get(), ['kind' => 'album', 'id' => (string) self::ALBUM_ID])
            ->getBody();

        $this->assertStringContainsString('data-card-preview', $html);
        $this->assertStringContainsString(
            'src="/medias-sociaux/nouvelle/album/' . self::ALBUM_ID . '/apercu"',
            $html
        );
        $this->assertStringNotContainsString('une fois la communication publiée', $html);
    }

    /** That preview is a real composed card, served before any row exists. */
    public function testTheSourceKeyedPreviewServesTheComposedCard(): void
    {
        $this->loginAuthor();

        $response = $this->controller()
            ->previewSource($this->get(), ['kind' => 'album', 'id' => (string) self::ALBUM_ID]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/jpeg', $response->getHeaders()['Content-Type'] ?? '');
        // Never cached: it is drawn for this caller, from an image whose
        // visibility was asked for this caller.
        $this->assertSame('private, no-store', $response->getHeaders()['Cache-Control'] ?? '');
        $this->assertStringStartsWith("\xFF\xD8", $response->getBody());
    }

    /** And it asks the source's own rule, like every other use. */
    public function testTheSourceKeyedPreviewRefusesASourceThatIsNotYours(): void
    {
        $this->loginAuthor();

        $this->assertSame(404, $this->controller()
            ->previewSource($this->get(), ['kind' => 'album', 'id' => '9999'])->getStatusCode());
        $this->assertSame(404, $this->controller()
            ->previewSource($this->get(), ['kind' => 'trombinoscope', 'id' => '1'])->getStatusCode());
    }

    /**
     * The blur is a promise about what leaves the site, so the sentence
     * has to be there for the shares that actually get blurred.
     *
     * It was not: `from_gallery` was read from the row's own
     * `gallery_media_id`, which a source-backed communication never sets
     * and can never set — while ShareSourceResolver::album() answers
     * `imageFromGallery` true, so the album's cover IS blurred. The
     * warning disappeared for exactly the case it describes.
     */
    public function testAnAlbumShareStillPromisesTheBlurItPerforms(): void
    {
        $this->loginAuthor();

        $html = $this->controller()
            ->createFromSource($this->get(), ['kind' => 'album', 'id' => (string) self::ALBUM_ID])
            ->getBody();

        $this->assertStringContainsString('Cette photo vient de la galerie', $html);
        $this->assertStringContainsString('floutée, sans exception', $html);
    }

    /**
     * An album's cover is the album's to change, so the composer offers
     * neither button — and the title it shows cannot be typed over.
     */
    public function testASourcesImageAndTitleAreNotThisPagesToChange(): void
    {
        $this->loginAuthor();

        $html = $this->controller()
            ->createFromSource($this->get(), ['kind' => 'album', 'id' => (string) self::ALBUM_ID])
            ->getBody();

        $this->assertStringNotContainsString('value="gallery"', $html);
        $this->assertStringNotContainsString('value="upload"', $html);
        $this->assertStringNotContainsString('name="title"', $html);
        $this->assertStringContainsString('readonly', $html);
    }

    /** An album nobody can share answers 404, like asking for it directly. */
    public function testASourceThatIsNotThereIsNotFound(): void
    {
        $this->loginAuthor();

        $this->assertSame(404, $this->controller()
            ->createFromSource($this->get(), ['kind' => 'album', 'id' => '9999'])->getStatusCode());
        // An unknown kind is not a source either.
        $this->assertSame(404, $this->controller()
            ->createFromSource($this->get(), ['kind' => 'trombinoscope', 'id' => '1'])->getStatusCode());
    }

    /**
     * Opening the composer writes nothing. A click on « Partager » is not
     * a decision to publish, and a row per click would leave a trail of
     * empty communications behind every look at the page.
     */
    public function testOpeningTheComposerCreatesNoRow(): void
    {
        $this->loginAuthor();
        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM social_communications')->fetchColumn();

        $this->controller()->createFromSource($this->get(), ['kind' => 'album', 'id' => (string) self::ALBUM_ID]);
        $this->controller()->createFromSource($this->get(), ['kind' => 'album', 'id' => (string) self::ALBUM_ID]);

        $this->assertSame($before, (int) $this->pdo->query('SELECT COUNT(*) FROM social_communications')->fetchColumn());
    }

    /**
     * The source a form carries is described again before anything is
     * written: a forged album id answers 404, and no row is left behind.
     */
    public function testAForgedSourceIsRefusedAndWritesNothing(): void
    {
        $this->loginAuthor();
        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM social_communications')->fetchColumn();

        $response = $this->controller()->store(
            $this->post(['source_kind' => 'album', 'source_id' => '9999', 'body' => 'Texte', 'action' => 'publish']),
            []
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame($before, (int) $this->pdo->query('SELECT COUNT(*) FROM social_communications')->fetchColumn());
        $this->assertSame([], $this->meta->requests);
    }

    /**
     * Publishing a source-backed communication records the pair, keeps no
     * copy of the album's title, and records the publication against the
     * COMMUNICATION — which is what lets the same album be shared again
     * later with another message.
     */
    public function testPublishingFromASourceRecordsThePairAndNotACopy(): void
    {
        $this->loginAuthor();

        $this->controller()->store(
            $this->post([
                'source_kind' => 'album',
                'source_id' => (string) self::ALBUM_ID,
                'body' => 'Les photos sont en ligne',
                'action' => 'publish',
                'destinations' => ['facebook'],
            ]),
            []
        );

        $row = $this->pdo->query(
            'SELECT source_kind, source_id, title FROM social_communications ORDER BY id DESC LIMIT 1'
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('album', $row['source_kind']);
        $this->assertSame(self::ALBUM_ID, (int) $row['source_id']);
        // No copy of the album's title: the card reads it from the album,
        // so a second answer here could only ever disagree with it.
        $this->assertSame('', (string) $row['title']);
        $this->assertNotNull($this->request('/photos'));
    }

    /**
     * A frozen source-backed share must not promise what it cannot keep.
     *
     * The notice said « l'image et le texte ne changent plus » for every
     * frozen communication, but `sourceBackedCommunication()` re-reads
     * the title and the image from the album at every publication, a
     * retry included: rename the album between a Facebook success and an
     * Instagram retry and the two destinations get different cards. Only
     * the caption is really frozen. The page even contradicted itself,
     * the title field's own help saying the title is changed at the
     * source. Raised in review on the pull request for IT-01; IT-02 is
     * what makes the image genuinely fixed, by storing the one the
     * browser composed.
     */
    public function testAFrozenSourceBackedShareSaysOnlyTheTextIsFixed(): void
    {
        $id = $this->sourceBackedCommunication();
        $at = new \DateTimeImmutable('-1 day');
        $this->publications->claim(
            'communication',
            $id,
            'facebook',
            false,
            $this->author,
            $at,
            $at->modify('-1 day'),
            '',
            'Les photos sont en ligne'
        );
        $this->publications->markPublished('communication', $id, 'facebook', '42_9', $at, $at);
        $this->loginAuthor();

        $html = $this->controller()->edit($this->get(), ['id' => (string) $id])->getBody();

        $this->assertStringContainsString('le texte ne change plus', $html);
        $this->assertStringNotContainsString('l\'image et le texte ne changent plus', $html);
        // And it says where the title and image really come from.
        $this->assertStringContainsString('restent ceux de', $html);
    }

    /**
     * The other side: a communication that owns its image really does
     * freeze both, so it keeps the stronger sentence.
     */
    public function testAFrozenOwnShareStillPromisesBoth(): void
    {
        $id = $this->communication('Week-end', 'Texte', self::PHOTO);
        $at = new \DateTimeImmutable('-1 day');
        $this->publications->claim(
            'communication',
            $id,
            'facebook',
            false,
            $this->author,
            $at,
            $at->modify('-1 day'),
            'Week-end',
            'Texte'
        );
        $this->publications->markPublished('communication', $id, 'facebook', '42_9', $at, $at);
        $this->loginAuthor();

        $html = $this->controller()->edit($this->get(), ['id' => (string) $id])->getBody();

        $this->assertStringContainsString('l\'image et le texte ne changent plus', $html);
    }

    /**
     * A share recorded by the RETIRED route must not look like this new
     * communication's own.
     *
     * `/partage/album/{id}` recorded its publications under
     * `('album', <albumId>)`, and this PR adds no migration rewriting
     * them. The prefilled composer carries that same pair, so reading
     * publications by it showed an older share of the album as the state
     * of a communication that does not exist yet — Facebook ticked and
     * greyed out, and the album impossible to share again. Raised in
     * review on the pull request for IT-01.
     *
     * Publishing is still keyed on the communication once it exists, so
     * this only concerns the composer before its first save.
     */
    public function testAnOldAlbumShareIsNotTheNewCommunicationsState(): void
    {
        $at = new \DateTimeImmutable('-1 day');
        // Exactly what the retired route left behind.
        $this->publications->claim(
            'album',
            self::ALBUM_ID,
            'facebook',
            false,
            $this->author,
            $at,
            $at->modify('-1 day'),
            self::ALBUM_TITLE,
            'Les photos sont en ligne'
        );
        $this->publications->markPublished('album', self::ALBUM_ID, 'facebook', '42_9', $at, $at);
        $this->loginAuthor();

        $html = $this->controller()
            ->createFromSource($this->get(), ['kind' => 'album', 'id' => (string) self::ALBUM_ID])
            ->getBody();

        // Nothing has been published by this communication, because there
        // is no communication yet.
        $this->assertStringNotContainsString('Déjà publié', $html);
        $this->assertStringNotContainsString('data-destination-state="published"', $html);
    }

    /**
     * The same for a discussion group, which keys its publications the
     * same way (`group:<id>` under the source's pair). Its own test
     * because nothing else exercises `groupsFor()`'s side of this: with
     * the guard mutated away, the whole Social suite still passed.
     */
    public function testAnOldAlbumShareToAGroupIsNotTheNewCommunicationsState(): void
    {
        $this->groups = new FakeGroupPublisher();
        $at = new \DateTimeImmutable('-1 day');
        $key = \Modules\Social\Service\GroupPublishingService::KEY_PREFIX . '3';
        $this->publications->claim(
            'album',
            self::ALBUM_ID,
            $key,
            false,
            $this->author,
            $at,
            $at->modify('-1 day'),
            self::ALBUM_TITLE,
            'Les photos sont en ligne'
        );
        $this->publications->markPublished('album', self::ALBUM_ID, $key, 'g_9', $at, $at);
        $this->loginAuthor();

        $html = $this->controller()
            ->createFromSource($this->get(), ['kind' => 'album', 'id' => (string) self::ALBUM_ID])
            ->getBody();

        // Staff Lutins is offered, not reported as already posted to.
        $this->assertStringContainsString('Staff Lutins', $html);
        $this->assertStringNotContainsString('Déjà publié', $html);
    }

    /**
     * The other side of the flag: once the row exists, its OWN
     * publications are its own and must still show.
     */
    public function testASavedCommunicationStillShowsItsOwnPublications(): void
    {
        $id = $this->communication('Week-end', 'Texte', self::PHOTO);
        $at = new \DateTimeImmutable('-1 day');
        $this->publications->claim(
            'communication',
            $id,
            'facebook',
            false,
            $this->author,
            $at,
            $at->modify('-1 day'),
            'Week-end',
            'Texte'
        );
        $this->publications->markPublished('communication', $id, 'facebook', '42_9', $at, $at);
        $this->loginAuthor();

        $html = $this->controller()->edit($this->get(), ['id' => (string) $id])->getBody();

        $this->assertStringContainsString('Déjà publié', $html);
    }

    /**
     * When the source has gone, the page must not send the visitor to it.
     *
     * `from_source` is read from the row's own `source_kind`, which
     * outlives the album, so the « this album has no image, go and choose
     * one over there » branch fired for a deleted album too — while the
     * alert further down said, correctly, that the album no longer
     * exists. The page told the visitor to go and edit something it had
     * just said was gone. Raised in review on the pull request for IT-01.
     */
    public function testAVanishedSourceIsNotAnInvitationToGoAndFixIt(): void
    {
        $this->loginAuthor();
        $id = $this->communications->create(
            '',
            'Les photos sont en ligne',
            $this->author,
            new \DateTimeImmutable(),
            'album',
            9999
        );

        $html = $this->controller()->edit($this->get(), ['id' => (string) $id])->getBody();

        // Said once, where publishing is refused.
        $this->assertStringContainsString('n&#039;existe plus', $html);
        // And not as advice to act on a source that is not there.
        $this->assertStringNotContainsString('puis revenez', $html);
        $this->assertStringNotContainsString('n\'a pas d\'image', $html);
        // Nor as « choose an image »: both image buttons are absent for a
        // source-backed communication, so there would be no way to obey.
        $this->assertStringNotContainsString('Choisissez une image', $html);
    }

    /**
     * The other half of the branch above, so the refusal is about the
     * source being GONE and not about a source-backed share as such: an
     * album that is there and simply has no cover photo still gets the
     * invitation to go and choose one.
     */
    public function testAnAlbumWithoutACoverStillSaysWhereToAddOne(): void
    {
        self::$albumHasCover = false;
        $this->loginAuthor();

        $html = $this->controller()
            ->createFromSource($this->get(), ['kind' => 'album', 'id' => (string) self::ALBUM_ID])
            ->getBody();

        $this->assertStringContainsString('n\'a pas d\'image', $html);
        $this->assertStringContainsString('puis revenez', $html);
        $this->assertStringNotContainsString('n&#039;existe plus', $html);
    }

    /**
     * Hiding the two image buttons is not the same as refusing the routes
     * behind them. A source-backed communication takes its image from the
     * album or the article, so the gallery picker has nothing to offer
     * here — and a pick accepted anyway would write `gallery_media_id`
     * where nothing ever reads it ({@see ShareSourceResolver::communication()}
     * branches on `hasSource()` first), leaving the visitor with a photo
     * chosen, no change, and no error.
     */
    public function testTheGalleryPickerRefusesASourceBackedCommunication(): void
    {
        $this->loginAuthor();
        $id = $this->sourceBackedCommunication();
        $params = ['id' => (string) $id];

        $this->assertSame(404, $this->controller()->picker($this->get(), $params)->getStatusCode());
        $this->assertSame(
            404,
            $this->controller()->pick($this->post(['media_id' => (string) self::PHOTO]), $params)->getStatusCode()
        );
        // And the column stayed empty, so nothing was recorded that the
        // resolver would never read back.
        $this->assertNull($this->communications->find($id)?->galleryMediaId);
    }

    /**
     * The picker is still there for a communication of its own — the
     * refusal above is about the source, not about the route.
     */
    public function testTheGalleryPickerStaysOpenForACommunicationOfItsOwn(): void
    {
        $this->loginAuthor();
        $params = ['id' => (string) $this->communication('Week-end', 'Texte', null)];

        $this->assertSame(200, $this->controller()->picker($this->get(), $params)->getStatusCode());
    }

    /**
     * What just left is on the history, so that is where publishing goes
     * — the composer has nothing more to say.
     */
    public function testPublishingReturnsToTheHistory(): void
    {
        $id = $this->communication('Week-end', 'Texte', self::PHOTO);
        $this->loginAuthor();

        $response = $this->controller()->update(
            $this->post(['title' => 'Week-end', 'body' => 'Texte', 'action' => 'publish', 'destinations' => ['facebook']]),
            ['id' => (string) $id]
        );

        $this->assertSame('/medias-sociaux', $response->getHeaders()['Location'] ?? '');
    }

    /** « Publier » saves; there is nothing else to press. */
    public function testThereIsNoSaveButton(): void
    {
        $this->loginAuthor();

        $html = $this->controller()->create($this->get(), [])->getBody();

        $this->assertStringNotContainsString('value="save"', $html);
        $this->assertStringNotContainsString('Enregistrer', $html);
    }

    /**
     * An album deleted since does not take its communication's page down:
     * it opens, says what happened, and refuses to publish — a chief who
     * opens it is owed an explanation, not a missing page.
     */
    public function testACommunicationWhoseSourceVanishedSaysSoRatherThan404(): void
    {
        $id = $this->communications->create('', 'Texte', $this->author, new \DateTimeImmutable(), 'album', 4321);
        $this->loginAuthor();

        $response = $this->controller()->edit($this->get(), ['id' => (string) $id]);

        $this->assertSame(200, $response->getStatusCode());
        // On the consequence rather than the cause, and apostrophe-free so
        // Twig's escaping is not what the assertion turns on.
        $this->assertStringContainsString('ne peut plus être publiée', $response->getBody());
    }

    // ————— The page —————

    public function testAnExistingCommunicationIsNotTitledNew(): void
    {
        $id = $this->communication('Week-end', 'Texte', self::PHOTO);
        $this->loginAuthor();

        $html = $this->controller()->edit($this->get(), ['id' => (string) $id])->getBody();

        $this->assertStringContainsString('<title>Communication — ', $html);
        $this->assertStringNotContainsString('Nouvelle communication', $html);
    }

    public function testPublishingAsksForConfirmationButSavingDoesNot(): void
    {
        $this->loginAuthor();

        $html = $this->controller()->create($this->get(), [])->getBody();

        $this->assertMatchesRegularExpression('#value="publish"[^>]*\s+data-confirm="Publier maintenant \?#', $html);
        $this->assertDoesNotMatchRegularExpression('#value="save"[^>]*data-confirm#', $html);
        $this->assertStringNotContainsString('<form method="post" action="/medias-sociaux" enctype="multipart/form-data" data-confirm', $html);
    }

    public function testTheNewPageFollowsTheMockupsOrder(): void
    {
        $this->loginAuthor();

        $html = $this->controller()->create($this->get(), [])->getBody();

        $positions = array_map(static fn (string $needle): int|false => strpos($html, $needle), [
            'data-communication-image', 'value="gallery"', 'value="upload"', 'Titre sur l&#039;image', 'Texte de la publication',
            'Publier sur', 'public</strong>',
        ]);
        $this->assertNotContains(false, $positions, 'Every part is there.');
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'In the mockup\'s order.');
        $this->assertStringContainsString(
            'Une image téléversée part telle quelle. Une image de la galerie est toujours floutée sur Facebook et Instagram.',
            $html
        );
    }

    public function testGalleryButtonSavesTheTextAndOpensThePicker(): void
    {
        $this->loginAuthor();

        $response = $this->controller()->store(
            $this->post(['title' => 'Week-end d\'unité', 'body' => 'Inscriptions ouvertes', 'action' => 'gallery']),
            []
        );

        $communication = $this->communications->find(1);
        $this->assertSame('Week-end d\'unité', $communication?->title);
        $this->assertSame($this->author, $communication->createdBy);
        $this->assertSame('/medias-sociaux/1/photo', $response->getHeaders()['Location'] ?? '');

        $html = $this->controller()->picker($this->get(), ['id' => '1'])->getBody();
        $this->assertStringContainsString('<button type="submit" name="media_id" value="' . self::PHOTO . '"', $html);
        $this->assertStringContainsString('aria-label="Photo 1, Camp"', $html);
        $this->assertStringContainsString('Les vignettes sont nettes', $html);
    }

    public function testOnlyAnOfferedPhotoCanBeChosen(): void
    {
        $id = $this->communication('Week-end', 'Texte', null);
        $this->loginAuthor();

        $this->controller()->pick($this->post(['media_id' => '999']), ['id' => (string) $id]);
        $this->assertNull($this->communications->find($id)?->galleryMediaId);
        $this->assertSame('error', FlashMessage::get()['type'] ?? null);

        $this->controller()->pick($this->post(['media_id' => (string) self::PHOTO]), ['id' => (string) $id]);
        $this->assertSame(self::PHOTO, $this->communications->find($id)?->galleryMediaId);
    }

    public function testAnUploadedImageReplacesTheGalleryPhotoAndIsNotBlurred(): void
    {
        $id = $this->communication('Week-end', 'Texte', self::PHOTO);
        $this->loginAuthor();
        $tmp = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents((string) $tmp, H::groupPhoto());
        $_FILES['image'] = ['name' => 'a.jpg', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize((string) $tmp), 'type' => 'image/jpeg'];

        $this->controller()->update($this->post(['title' => 'Week-end', 'body' => 'Texte', 'action' => 'upload']), ['id' => (string) $id]);

        $communication = $this->communications->find($id);
        $this->assertNull($communication?->galleryMediaId);
        $this->assertNotNull($communication->fileId);
        $html = $this->controller()->edit($this->get(), ['id' => (string) $id])->getBody();
        $this->assertStringContainsString('src="/medias-sociaux/' . $id . '/apercu"', $html);
        $this->assertStringNotContainsString('elle est floutée', $html);
        $this->assertSame(200, $this->controller()->preview($this->get(), ['id' => (string) $id])->getStatusCode());
    }

    public function testUploadingWithoutAFileSaysSo(): void
    {
        $id = $this->communication('Week-end', 'Texte', null);
        $this->loginAuthor();

        $this->controller()->update($this->post(['action' => 'upload']), ['id' => (string) $id]);

        $this->assertStringContainsString('fichier', FlashMessage::get()['message'] ?? '');
    }

    // ————— Publishing —————

    public function testAGalleryPhotoLeavesBlurredWithTheTextAndTheCommunicationFreezes(): void
    {
        $id = $this->communication('Week-end', 'Inscriptions ouvertes', self::PHOTO);
        $this->loginAuthor();

        $this->controller()->update(
            $this->post(['title' => 'Week-end', 'body' => 'Inscriptions ouvertes', 'action' => 'publish', 'destinations' => ['facebook', 'instagram']]),
            ['id' => (string) $id]
        );

        $this->assertSame('success', FlashMessage::get()['type'] ?? null);
        $this->assertStringContainsString('communication n° ' . $id, $this->journal->textOf('published'));
        $this->assertSame(1, (int) $this->pdo->query('SELECT blurred FROM social_cards')->fetchColumn(), 'Blurred.');
        $photo = $this->request('/photos');
        $this->assertSame('Inscriptions ouvertes', $photo['fields']['caption'] ?? null);

        $this->controller()->update($this->post(['title' => 'Autre titre', 'body' => 'Autre texte']), ['id' => (string) $id]);
        $this->assertSame('Week-end', $this->communications->find($id)?->title, 'Frozen once it left.');
        $html = $this->controller()->edit($this->get(), ['id' => (string) $id])->getBody();
        $this->assertStringContainsString('ne changent plus', $html);
        $this->assertStringNotContainsString('value="upload"', $html);
    }

    public function testNothingLeavesWithoutAnImage(): void
    {
        $id = $this->communication('Week-end', 'Texte', null);
        $this->loginAuthor();

        $this->controller()->update($this->post(['action' => 'publish', 'title' => 'Week-end', 'body' => 'Texte', 'destinations' => ['facebook']]), ['id' => (string) $id]);

        $this->assertStringContainsString('aucune publication ne part sans image', FlashMessage::get()['message'] ?? '');
        $this->assertSame([], $this->meta->requests);
    }

    public function testNothingLeavesWithoutATitleOnTheImage(): void
    {
        $id = $this->communication('', 'Texte', self::PHOTO);
        $this->loginAuthor();

        $this->controller()->update($this->post(['action' => 'publish', 'title' => '', 'body' => 'Texte', 'destinations' => ['facebook']]), ['id' => (string) $id]);

        $this->assertStringContainsString('titre', FlashMessage::get()['message'] ?? '');
        $this->assertSame([], $this->meta->requests);
    }

    // ————— « Ce qui est parti » —————

    public function testTheHistoryShowsEachDestinationWithItsState(): void
    {
        $id = $this->communication('Week-end', 'Inscriptions ouvertes jusqu\'au 12 octobre', self::PHOTO);
        $this->loginAuthor();
        $this->controller()->update($this->post(['action' => 'publish', 'title' => 'Week-end', 'body' => 'Inscriptions ouvertes jusqu\'au 12 octobre', 'destinations' => ['facebook']]), ['id' => (string) $id]);
        $failed = $this->communication('Hike', 'Retour en images', self::PHOTO);
        $this->failedInstagram($failed);

        $html = $this->controller()->history($this->get(), [])->getBody();

        $this->assertStringContainsString('Week-end', $html);
        $this->assertStringContainsString(', par Claire', $html);
        $this->assertStringContainsString('data-platform="facebook" data-state="published"', $html);
        $this->assertStringContainsString('href="https://www.facebook.com/42_1"', $html);
        $this->assertStringContainsString('data-platform="instagram" data-state="not_requested"', $html);
        $this->assertStringContainsString('Non demandé', $html);
        $this->assertStringContainsString('data-platform="instagram" data-state="failed"', $html);
        $this->assertStringContainsString('Proportions refusées', $html);
        $this->assertStringContainsString('href="/medias-sociaux/reessayer/communication/' . $failed . '/instagram"', $html);
        $this->assertStringContainsString('aria-label="Réessayer la publication sur Instagram"', $html);
    }

    public function testInstagramsPermalinkIsKeptForVoir(): void
    {
        $id = $this->communication('Week-end', 'Texte', self::PHOTO);
        $this->loginAuthor();

        $this->controller()->update($this->post(['action' => 'publish', 'title' => 'Week-end', 'body' => 'Texte', 'destinations' => ['instagram']]), ['id' => (string) $id]);

        $this->assertSame('https://www.instagram.com/p/abc/', $this->publications->forSource('communication', $id)['instagram']->remoteUrl);
    }

    /**
     * The retry page made the same promise unconditionally — « la même
     * image et le même texte repartiront » — and for a source-backed
     * communication the title and image are read again from the album at
     * that very retry. It now says so. Raised in review on the pull
     * request for IT-01, alongside the frozen notice in the composer.
     */
    public function testTheRetryPageSaysASourcesImageIsReadAgain(): void
    {
        $id = $this->sourceBackedCommunication();
        $this->failedInstagram($id);
        $this->loginAuthor();
        $params = ['kind' => 'communication', 'id' => (string) $id, 'platform' => 'instagram'];

        $html = $this->controller()->confirmRetry($this->get(), $params)->getBody();

        $this->assertStringContainsString('relus à la source', $html);
        $this->assertStringNotContainsString('La même image et le même texte', $html);
    }

    /**
     * And a communication that owns its image keeps the plain promise.
     */
    public function testTheRetryPageStillPromisesTheSameImageForAnOwnShare(): void
    {
        $id = $this->communication('Hike', 'Texte', self::PHOTO);
        $this->failedInstagram($id);
        $this->loginAuthor();
        $params = ['kind' => 'communication', 'id' => (string) $id, 'platform' => 'instagram'];

        $html = $this->controller()->confirmRetry($this->get(), $params)->getBody();

        $this->assertStringContainsString('La même image et le même texte', $html);
        $this->assertStringNotContainsString('relus à la source', $html);
    }

    public function testARetryIsConfirmedThenResendsTheSameText(): void
    {
        $id = $this->communication('Hike', 'Texte modifié depuis', self::PHOTO);
        $this->failedInstagram($id);
        $this->publications->claim('communication', $id, 'facebook', false, $this->author, new \DateTimeImmutable('-1 day'), new \DateTimeImmutable('-2 days'), 'Hike', 'Retour en images');
        $this->publications->markPublished('communication', $id, 'facebook', '42_9', new \DateTimeImmutable('-1 day'), new \DateTimeImmutable('-1 day'));
        $this->loginAuthor();
        $params = ['kind' => 'communication', 'id' => (string) $id, 'platform' => 'instagram'];

        $html = $this->controller()->confirmRetry($this->get(), $params)->getBody();
        $this->assertStringContainsString('Réessayer sur Instagram ?', $html);
        $this->assertStringContainsString('ne sera pas touché', $html);
        $this->assertStringContainsString('Proportions refusées', $html);

        $this->controller()->retry($this->post([]), $params);

        $this->assertTrue($this->publications->forSource('communication', $id)['instagram']->isPublished());
        $this->assertSame('Retour en images', $this->request('/media')['fields']['caption'] ?? null, 'The text that was sent first.');
        $this->assertNull($this->request('/photos'), 'Facebook is not touched.');
    }

    public function testAGroupHasItsOwnLineAndItsOwnRetry(): void
    {
        $this->groups = new FakeGroupPublisher();
        $this->groups->refusals[4] = 'Ce groupe est fermé.';
        $id = $this->communication('Week-end', 'Inscriptions ouvertes', self::PHOTO);
        $this->loginAuthor();

        $this->controller()->update(
            $this->post(['action' => 'publish', 'title' => 'Week-end', 'body' => 'Inscriptions ouvertes', 'destinations' => ['groups'], 'groups' => ['3', '4']]),
            ['id' => (string) $id]
        );

        $this->assertSame('warning', FlashMessage::get()['type'] ?? null);
        $this->assertSame(H::groupPhoto(), $this->groups->posts[0]['image'], 'Never blurred for a group.');
        $this->assertNull($this->groups->posts[0]['link'], 'A free communication has no page of its own.');
        $html = $this->controller()->history($this->get(), [])->getBody();
        $this->assertStringContainsString('data-platform="group:3" data-state="published"', $html);
        $this->assertStringContainsString('href="/groups/3#post-101"', $html);
        $this->assertStringContainsString('data-platform="group:4" data-state="failed"', $html);
        $this->assertStringContainsString('Staff d&#039;unité', $html);
        $this->assertStringContainsString('href="/medias-sociaux/reessayer/communication/' . $id . '/group:4"', $html);
        // The link as a browser follows it, through the real router.
        $routed = $this->route('GET', '/medias-sociaux/reessayer/{kind}/{id}/{platform}', 'confirmRetry', [], 'communication/' . $id . '/group:4');
        $this->assertSame(200, $routed->getStatusCode());

        unset($this->groups->refusals[4]);
        $params = ['kind' => 'communication', 'id' => (string) $id, 'platform' => 'group:4'];
        $page = $this->controller()->confirmRetry($this->get(), $params)->getBody();
        $this->assertStringContainsString('Réessayer sur Staff d&#039;unité ?', $page);
        $this->assertStringContainsString('« Staff Lutins », déjà publié', $page);

        $this->controller()->retry($this->post([]), $params);
        $this->assertTrue($this->publications->forSource('communication', $id)['group:4']->isPublished());
        $this->assertCount(2, $this->groups->posts, 'Staff Lutins is not touched.');
    }

    public function testTheWarningsSayWhatDiffersForAGroup(): void
    {
        $id = $this->communication('Week-end', 'Texte', self::PHOTO);
        $this->loginAuthor();

        $html = $this->controller()->edit($this->get(), ['id' => (string) $id])->getBody();
        $this->assertStringContainsString('floutée, sans exception, sur Facebook et Instagram.', $html);
        $this->assertStringNotContainsString('groupe de discussion', $html, 'No group offered, none mentioned.');

        $this->groups = new FakeGroupPublisher();
        $html = $this->controller()->edit($this->get(), ['id' => (string) $id])->getBody();
        $this->assertStringContainsString('dans un groupe de discussion, elle part nette', $html);
        $this->assertStringContainsString('Dans un groupe de discussion, la publication reste dans le site', $html);
    }

    public function testNothingToRetryIsNotThere(): void
    {
        $id = $this->communication('Hike', 'Texte', self::PHOTO);
        $this->loginAuthor();

        $this->assertSame(404, $this->controller()->confirmRetry($this->get(), ['kind' => 'communication', 'id' => (string) $id, 'platform' => 'instagram'])->getStatusCode());
        $this->assertSame(404, $this->controller()->confirmRetry($this->get(), ['kind' => 'nowhere', 'id' => (string) $id, 'platform' => 'instagram'])->getStatusCode());
    }

    // ————— Helpers —————

    private function communication(string $title, string $body, ?int $photo): int
    {
        $id = $this->communications->create($title, $body, $this->author, new \DateTimeImmutable());
        if ($photo !== null) {
            $this->communications->useGalleryPhoto($id, $photo, new \DateTimeImmutable());
        }

        return $id;
    }

    /** A communication whose image belongs to an album, not to itself. */
    private function sourceBackedCommunication(): int
    {
        return $this->communications->create(
            '',
            'Les photos sont en ligne',
            $this->author,
            new \DateTimeImmutable(),
            'album',
            self::ALBUM_ID
        );
    }

    private function failedInstagram(int $id): void
    {
        $at = new \DateTimeImmutable('-1 hour');
        $this->publications->claim('communication', $id, 'instagram', false, $this->author, $at, $at->modify('-1 day'), 'Hike', 'Retour en images');
        $this->publications->markFailed('communication', $id, 'instagram', 'Proportions refusées.', $at);
    }

    private function account(string $email, string $firstName): int
    {
        $encryption = H::encryption();
        $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index, first_name_encrypted) VALUES (?, ?, ?)')
            ->execute([
                $encryption->encrypt($email, 'user_accounts.email'),
                $encryption->blindIndex($email, 'email'),
                $encryption->encrypt($firstName, 'user_accounts.first_name'),
            ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function loginAuthor(): void
    {
        AuthSession::login($this->author, 'claire@unite.be', 'chief');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function route(
        string $method,
        string $path,
        string $action,
        array $body,
        ?string $tail = null,
        int $id = 1
    ): Response {
        $router = new Router();
        $router->addRoute($method, $path, CommunicationController::class, $action, 'chief');

        $configFile = sys_get_temp_dir() . '/test_social_comm_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");

        $front = new FrontController($router, $this->twig(), new AppConfig($configFile));
        $front->registerController(CommunicationController::class, $this->controller());
        // `{kind}` means two different things across these routes: what a
        // composer was opened FROM (an album), and what a retry belongs to
        // (the communication). Substituting one value everywhere drove the
        // from-source route into a 404 and read as a product fault.
        $kind = str_contains($path, '/nouvelle/') ? 'album' : 'communication';
        $concrete = $tail !== null
            ? '/medias-sociaux/reessayer/' . $tail
            : strtr($path, [
                '{kind}' => $kind,
                '{id}' => (string) ($kind === 'album' ? self::ALBUM_ID : $id),
                '{platform}' => 'instagram',
            ]);

        return $front->handle(new Request($method, $concrete, [], $body, [], []));
    }

    /**
     * The gallery as this class needs it: one album, described to whoever
     * asks. WHOSE album it is, is the gallery's own rule and
     * FakeAlbumSource's subject — here it would only stand between these
     * tests and the composer they are about.
     */
    private static function albumSource(): \Modules\Gallery\Api\AlbumShareSourceInterface
    {
        // The cover is passed in, not read from the test class: an
        // anonymous class is its own scope, so `self::` here would mean
        // this class and not the enclosing one.
        $cover = self::$albumHasCover ? \Tests\Modules\Social\SocialTestHelper::groupPhoto() : null;

        return new class ($cover) implements \Modules\Gallery\Api\AlbumShareSourceInterface {
            public function __construct(private readonly ?string $cover)
            {
            }

            public function describe(int $albumId, string $role, string $email): ?\Modules\Gallery\Api\SharedAlbum
            {
                return $albumId === CommunicationControllerTest::ALBUM_ID
                    ? new \Modules\Gallery\Api\SharedAlbum(
                        CommunicationControllerTest::ALBUM_ID,
                        CommunicationControllerTest::ALBUM_TITLE,
                        $this->cover,
                        '/gallery/' . CommunicationControllerTest::ALBUM_ID
                    )
                    : null;
            }
        };
    }

    private function controller(): CommunicationController
    {
        $settings = new RemoteBackupSettingsDouble(['base_url' => 'https://unite.example']);
        $journal = new JournalService($this->journal);
        $cards = new CardService(new CardRepository($this->pdo), new CardRenderer(), $settings, $journal, $this->storage . '/cards');
        $files = new FileRepository($this->pdo);
        $reader = new StoredFileReader($files, new EncryptedFileStorageService($files, H::encryption(), $this->storage), $this->storage);
        $publishing = new PublishingService(
            $this->connections,
            $this->publications,
            $cards,
            $settings,
            $journal,
            new MetaClient($this->meta, static function (int $seconds): void {
            })
        );

        return new CommunicationController(
            $this->twig(),
            $this->communications,
            new ShareSourceResolver(
                $settings,
                $reader,
                self::albumSource(),
                null,
                $this->communications,
                $this->picker,
                []
            ),
            new DestinationStates(
                $publishing,
                $this->publications,
                $this->connections,
                $settings,
                $this->groups === null ? null : new GroupPublishingService($this->groups, $this->publications, $journal)
            ),
            $this->publications,
            $this->connections,
            $cards,
            new UploadHandler($files, $this->storage),
            new UserAccountRepository($this->pdo, H::encryption()),
            $this->picker,
            []
        );
    }

    private function twig(): Environment
    {
        $root = dirname(__DIR__, 4);
        $twig = TestTwig::create(['social' => $root . '/modules/social/views'], ['param' => static fn (string $key): string => 'Test Unit']);
        $twig->addGlobal('site_name', 'Test Unit');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'chief');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/medias-sociaux');

        return $twig;
    }

    private function csrf(): string
    {
        $token = CsrfGuard::generateToken();
        $_POST['_csrf_token'] = $token;

        return $token;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body): Request
    {
        return new Request('POST', '/medias-sociaux/x', [], $body + ['_csrf_token' => $this->csrf()], [], []);
    }

    private function get(): Request
    {
        return new Request('GET', '/medias-sociaux/x', [], [], [], []);
    }

    /**
     * @return array{method: string, url: string, fields: array<string, string>}|null
     */
    private function request(string $fragment): ?array
    {
        foreach ($this->meta->requests as $request) {
            if (str_contains($request['url'], $fragment) && !str_contains($request['url'], '/media_publish')) {
                return $request;
            }
        }

        return null;
    }
}
