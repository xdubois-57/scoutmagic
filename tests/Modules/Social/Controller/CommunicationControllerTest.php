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
use Core\View\TwigFactory;
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
        self::assertCount(10, $cases);

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
        $this->assertStringNotContainsString('<form method="post" action="/communications" enctype="multipart/form-data" data-confirm', $html);
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
        $this->assertSame('/communications/1/photo', $response->getHeaders()['Location'] ?? '');

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
        $this->assertStringContainsString('src="/communications/' . $id . '/apercu"', $html);
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
        $this->assertStringContainsString('href="/communications/reessayer/communication/' . $failed . '/instagram"', $html);
        $this->assertStringContainsString('aria-label="Réessayer la publication sur Instagram"', $html);
    }

    public function testInstagramsPermalinkIsKeptForVoir(): void
    {
        $id = $this->communication('Week-end', 'Texte', self::PHOTO);
        $this->loginAuthor();

        $this->controller()->update($this->post(['action' => 'publish', 'title' => 'Week-end', 'body' => 'Texte', 'destinations' => ['instagram']]), ['id' => (string) $id]);

        $this->assertSame('https://www.instagram.com/p/abc/', $this->publications->forSource('communication', $id)['instagram']->remoteUrl);
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
        $this->assertStringContainsString('href="/communications/reessayer/communication/' . $id . '/group:4"', $html);
        // The link as a browser follows it, through the real router.
        $routed = $this->route('GET', '/communications/reessayer/{kind}/{id}/{platform}', 'confirmRetry', [], 'communication/' . $id . '/group:4');
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
        $concrete = $tail !== null
            ? '/communications/reessayer/' . $tail
            : strtr($path, ['{kind}' => 'communication', '{id}' => (string) $id, '{platform}' => 'instagram']);

        return $front->handle(new Request($method, $concrete, [], $body, [], []));
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
            new ShareSourceResolver($settings, $reader, null, null, $this->communications, $this->picker, []),
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
        $twig = TwigFactory::create($root . '/core/View/templates', false, ['social' => $root . '/modules/social/views']);
        $twig->addGlobal('site_name', 'Test Unit');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'chief');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/communications');
        $twig->addFunction(new \Twig\TwigFunction('param', static fn (string $key): string => 'Test Unit'));

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
        return new Request('POST', '/communications/x', [], $body + ['_csrf_token' => $this->csrf()], [], []);
    }

    private function get(): Request
    {
        return new Request('GET', '/communications/x', [], [], [], []);
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
