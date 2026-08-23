<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Controller;

use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Member\MemberProfile;
use Core\Member\MemberService;
use Core\Member\SectionMembershipRepository;
use Core\ScoutYear\EffectiveScoutYear;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Security\UserAccount;
use Core\Security\UserAccountRepository;
use Core\View\TwigFactory;
use Core\File\FileRepository;
use Core\File\UploadHandler;
use Modules\Gallery\Api\DelegatedAlbum;
use Modules\Gallery\Api\DelegatedAlbumManager;
use Modules\Gallery\Api\DelegatedMedia;
use Modules\Gallery\Api\LinkPreviewFetcher;
use Modules\Gallery\Service\GalleryException;
use Modules\Groups\Controller\PostController;
use Modules\Groups\Repository\GroupMemberRepository;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\GroupSectionRepository;
use Modules\Groups\Repository\LinkFetchLogRepository;
use Modules\Groups\Repository\PostLinkRepository;
use Modules\Groups\Repository\PostMediaRepository;
use Modules\Groups\Repository\PostRepository;
use Modules\Groups\Service\GroupAccessService;
use Modules\Groups\Service\GroupActivityService;
use Modules\Groups\Service\GroupFeedService;
use Modules\Groups\Service\GroupService;
use Modules\Groups\Service\GroupSessionContextFactory;
use Modules\Groups\Service\LinkFetchThrottleService;
use Modules\Groups\Service\PostAuthorResolver;
use Modules\Groups\Service\PostLinkService;
use Modules\Groups\Service\PostMediaService;
use Modules\Groups\Service\PostService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * CSRF and the authorisation boundary of every post action, in both
 * directions, plus the 404-not-403 rule on every post route.
 *
 * @group database
 */
#[Group('database')]
class PostControllerTest extends TestCase
{
    private \PDO $pdo;
    private GroupRepository $groupRepo;
    private PostRepository $postRepo;
    private GroupService $groupService;
    private int $currentYearId;
    private int $sectionId;
    private int $groupId;
    private int $moderatorMemberId;
    private int $memberId;
    private int $otherMemberId;

    private const AUTHOR_ACCOUNT = 7;
    private const OTHER_ACCOUNT = 8;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);

        $this->currentYearId = GroupsTestHelper::createScoutYear($this->pdo, '2025-2026', true);
        $this->sectionId = GroupsTestHelper::createSection($this->pdo, 'LOU', 'Louveteaux');

        $this->groupRepo = new GroupRepository($this->pdo);
        $this->postRepo = new PostRepository($this->pdo);
        $this->groupService = new GroupService(
            $this->groupRepo,
            new GroupSectionRepository($this->pdo),
            new GroupMemberRepository($this->pdo)
        );

        $this->moderatorMemberId = GroupsTestHelper::createMember($this->pdo, 'MOD');
        // The moderator acts as OTHER_ACCOUNT in this file, and the flag
        // names the login it belongs to (schema.sql) — so that is who it
        // is granted to.
        $this->groupId = $this->groupService->createSectionGroup(
            'Louveteaux',
            $this->sectionId,
            $this->currentYearId,
            $this->moderatorMemberId,
            self::OTHER_ACCOUNT
        );
        $this->memberId = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'MEMBER', $this->sectionId, $this->currentYearId);
        $this->otherMemberId = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'OTHER', $this->sectionId, $this->currentYearId);
        // Nameable for the same reason the shared fixture's member is —
        // "vu par" lists people, and a member with no member_year and no
        // account has nothing to be named by.
        GroupsTestHelper::giveMemberAnAccount(
            $this->pdo,
            $this->otherMemberId,
            'luc@example.test',
            'Luc',
            'Bernard',
            'Luc',
            'Baloo',
            $this->currentYearId,
            self::OTHER_ACCOUNT
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        $_POST = [];
        $_FILES = [];
    }

    /**
     * @param int[] $linkedMemberIds
     */
    private function controller(
        array $linkedMemberIds,
        int $accountId = self::AUTHOR_ACCOUNT,
        string $role = 'identified',
        bool $completeProfile = true,
        ?DelegatedAlbumManager $delegatedAlbumManager = null,
        ?LinkPreviewFetcher $linkPreviewFetcher = null,
        ?\Modules\Calendar\Api\CalendarEventLookupInterface $eventLookup = null
    ): PostController {
        AuthSession::login($accountId, 'parent@test.be', $role);

        $sectionRepo = new GroupSectionRepository($this->pdo);
        $memberRepo = new GroupMemberRepository($this->pdo);
        $access = new GroupAccessService($memberRepo, $sectionRepo, new SectionMembershipRepository($this->pdo));

        $memberService = $this->createMock(MemberService::class);
        $memberService->method('getLinkedMembers')->willReturn(array_map(fn(int $id) => $this->profile($id), $linkedMemberIds));
        $memberService->method('findDisplayNamesByMemberIds')->willReturn([$this->memberId => 'Akéla']);

        $accountRepo = $this->createMock(UserAccountRepository::class);
        $accountRepo->method('findById')->willReturn(new UserAccount(
            $accountId,
            'parent@test.be',
            $completeProfile ? 'Marie' : null,
            $completeProfile ? 'Dupont' : null,
            null,
            false,
            null
        ));
        $accountRepo->method('findNamesByIds')->willReturn([$accountId => ['first_name' => 'Marie', 'last_name' => 'Dupont']]);

        $resolver = $this->createMock(ScoutYearResolver::class);
        $resolver->method('getEffectiveYear')->willReturn(new EffectiveScoutYear($this->currentYearId, '2025-2026', null));

        $activityService = new GroupActivityService($this->groupRepo, $this->postRepo);
        $postService = new PostService($this->postRepo, $activityService, GroupsTestHelper::rateLimitService($this->pdo));
        $postMediaService = new PostMediaService(
            $delegatedAlbumManager ?? $this->createMock(DelegatedAlbumManager::class),
            new PostMediaRepository($this->pdo), $this->groupRepo, new \Modules\Groups\Repository\ReplyRepository($this->pdo)
        );
        $postLinkRepo = new PostLinkRepository($this->pdo);
        $postLinkService = new PostLinkService(
            $linkPreviewFetcher ?? $this->createMock(LinkPreviewFetcher::class),
            new LinkFetchThrottleService(new LinkFetchLogRepository($this->pdo)),
            $postLinkRepo,
            new UploadHandler(new FileRepository($this->pdo), sys_get_temp_dir()),
            new FileRepository($this->pdo),
            sys_get_temp_dir()
        );
        $authorResolver = new PostAuthorResolver(GroupsTestHelper::identityService($this->pdo));
        $stack = GroupsTestHelper::replyStack($this->pdo, $activityService, $postMediaService, $authorResolver);
        $readStateService = new \Modules\Groups\Service\GroupReadStateService(
            new \Modules\Groups\Repository\GroupReadRepository($this->pdo),
            $access
        );
        // Null unless a test supplies one — which is production's own
        // "calendar disabled" wiring, so every other test in this file
        // exercises the degraded path for free.
        $eventService = new \Modules\Groups\Service\PostEventService($eventLookup);
        $pollService = new \Modules\Groups\Service\PollService(
            new \Modules\Groups\Repository\PollRepository($this->pdo)
        );
        $feedService = new GroupFeedService(
            $this->postRepo, $authorResolver, $postService, $postMediaService, $postLinkRepo,
            $stack['replyRepository'], $stack['replyPresenter'], $stack['reactionService'], $stack['reportService'],
            $readStateService, $eventService, $pollService
        );

        $twig = TwigFactory::create(
            dirname(__DIR__, 4) . '/core/View/templates',
            true,
            [
                'groups' => dirname(__DIR__, 4) . '/modules/groups/views',
                // show.html.twig includes @gallery/partials/lightbox.html.twig —
                // groups hard-requires gallery, and production registers every
                // enabled module's namespace (public/index.php), so the test
                // environment has to as well or the page cannot render.
                'gallery' => dirname(__DIR__, 4) . '/modules/gallery/views',
            ]
        );
        foreach (['site_name' => 'Test', 'is_authenticated' => true, 'current_user_email' => 'p@t.be',
                  'current_user_role' => $role, 'config_mode' => false, 'cookie_consent_given' => true,
                  'menus' => null, 'csp_nonce' => 'test'] as $key => $value) {
            $twig->addGlobal($key, $value);
        }
        $twig->addFunction(new \Twig\TwigFunction('param', fn(...$a) => ''));

        return new PostController(
            $twig,
            $this->groupRepo,
            $this->postRepo,
            $access,
            $feedService,
            $postService,
            new GroupSessionContextFactory($memberService, $accountRepo, $resolver),
            $postMediaService,
            $postLinkService,
            $stack['replyService'],
            $stack['reportService'],
            null,
            new \Modules\Groups\Service\SeenByService($readStateService, GroupsTestHelper::identityService($this->pdo)),
            new \Modules\Groups\Service\MentionService(
                $this->recipientResolverFor([$this->memberId, $this->otherMemberId]),
                $memberService
            ),
            $eventService,
            $pollService,
            GroupsTestHelper::identityService($this->pdo)
        );
    }

    /**
     * A stubbed recipient resolver answering "these are the group's
     * members" — the real one reaches for blind indexes and encrypted
     * addresses, none of which this file's fixtures set up.
     *
     * @param int[] $memberIds
     */
    private function recipientResolverFor(array $memberIds): \Modules\Groups\Service\GroupRecipientResolver
    {
        $resolver = $this->createStub(\Modules\Groups\Service\GroupRecipientResolver::class);
        $resolver->method('memberIdsFor')->willReturn($memberIds);

        return $resolver;
    }

    private function profile(int $memberId): MemberProfile
    {
        return new MemberProfile(
            $memberId * 100, $memberId, 'DESK' . $memberId, 'Marie', 'Dupont', 'Akéla',
            null, null, null, null, null, null, null, null, false, false, [], [], '2025-2026'
        );
    }

    private function withCsrf(array $body): void
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        $_POST = $body + ['_csrf_token' => $token];
    }

    private function request(): Request
    {
        return new Request('POST', '/groups/' . $this->groupId . '/posts', [], $_POST, [], []);
    }

    /**
     * Same request a plain form POST sends, plus the header groups.js
     * attaches to its fetch() — the composer's own dynamic-posting path
     * (module spec: "no reload to publish a post").
     */
    private function ajaxRequest(): Request
    {
        return new Request(
            'POST',
            '/groups/' . $this->groupId . '/posts',
            [],
            $_POST,
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']
        );
    }

    /**
     * Populates $_FILES['media'] in PHP's own multi-file shape (one array
     * per property, not one array per file) — same shape a real
     * <input type="file" name="media[]" multiple> submits, which is what
     * Core\Http\Request::getFiles() expects to unpack.
     */
    private function withMediaFiles(int $count): void
    {
        $_FILES['media'] = [
            'name' => array_fill(0, $count, 'photo.jpg'),
            'tmp_name' => array_fill(0, $count, '/tmp/fake'),
            'error' => array_fill(0, $count, 0),
            'size' => array_fill(0, $count, 100),
            'type' => array_fill(0, $count, 'image/jpeg'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function params(?int $postId = null): array
    {
        $params = ['id' => (string) $this->groupId];
        if ($postId !== null) {
            $params['postId'] = (string) $postId;
        }

        return $params;
    }

    private function seedPost(int $minutesAgo = 1, int $accountId = self::AUTHOR_ACCOUNT, bool $pinned = false): int
    {
        $at = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify("-{$minutesAgo} minutes")->format('Y-m-d H:i:s');

        return GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'Bonjour', $at, $accountId, $this->memberId, $pinned);
    }

    // --- create --------------------------------------------------------

    public function testCreateRejectsAMissingCsrfToken(): void
    {
        $_POST = ['body' => 'Bonjour'];

        $response = $this->controller([$this->memberId])->create($this->request(), $this->params());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            \Core\Http\Controller\AbstractController::SESSION_EXPIRED_MESSAGE,
            \Core\Http\FlashMessage::get()['message'] ?? null
        );
        $this->assertSame([], $this->postRepo->findPage($this->groupId, 10));
    }

    public function testCreateIs404ForANonMember(): void
    {
        $outsider = GroupsTestHelper::createMember($this->pdo, 'OUT');
        $this->withCsrf(['body' => 'Bonjour']);

        $response = $this->controller([$outsider])->create($this->request(), $this->params());

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame([], $this->postRepo->findPage($this->groupId, 10));
    }

    public function testCreateStoresThePostForAMember(): void
    {
        $this->withCsrf(['body' => "Bonjour\nà tous"]);

        $response = $this->controller([$this->memberId])->create($this->request(), $this->params());

        $this->assertSame(302, $response->getStatusCode());
        $posts = $this->postRepo->findPage($this->groupId, 10);
        $this->assertCount(1, $posts);
        $this->assertSame("Bonjour\nà tous", $posts[0]->body);
        $this->assertSame(self::AUTHOR_ACCOUNT, $posts[0]->authorUserAccountId);
        $this->assertSame($this->memberId, $posts[0]->authorMemberId);
    }

    /**
     * The dynamic-posting path (groups.js): the same X-Requested-With
     * header a fetch() sends gets JSON back — a rendered post-card
     * fragment, not the redirect a plain form POST gets — so the
     * composer never has to reload the page to publish a post.
     */
    public function testCreatingAPostViaAjaxReturnsAJsonFragmentInsteadOfARedirect(): void
    {
        $this->withCsrf(['body' => 'Bonjour à tous']);

        $response = $this->controller([$this->memberId])->create($this->ajaxRequest(), $this->params());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaders()['Content-Type']);
        $body = json_decode($response->getBody(), true);
        $this->assertStringContainsString('Bonjour à tous', $body['html']);
        $this->assertCount(1, $this->postRepo->findPage($this->groupId, 10));
    }

    public function testCreatingAnEmptyPostViaAjaxIs400WithNoFlashMessage(): void
    {
        $this->withCsrf(['body' => '   ']);

        $response = $this->controller([$this->memberId])->create($this->ajaxRequest(), $this->params());

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->postRepo->findPage($this->groupId, 10));
        $this->assertArrayNotHasKey('_flash_message', $_SESSION);
    }

    public function testCreatingAPostViaAjaxOverTheRateLimitReturnsTheRefusalAsJson(): void
    {
        $controller = $this->controller([$this->memberId]);
        for ($i = 0; $i < 15; $i++) {
            $this->withCsrf(['body' => 'Message ' . $i]);
            $controller->create($this->request(), $this->params());
        }

        $this->withCsrf(['body' => 'Un de trop']);
        $response = $controller->create($this->ajaxRequest(), $this->params());

        $this->assertSame(422, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('rate_limited', $body['type']);
        $this->assertCount(15, $this->postRepo->findPage($this->groupId, 20));
    }

    public function testCreatePostsAJavascriptSchemeAsPlainTextRatherThanAttachingItAsALink(): void
    {
        // No manual "Lien" field any more: firstUrlIn() only ever matches
        // starting from an http(s):// prefix, so this is never even a
        // link candidate — it is stored as ordinary body text (Twig's
        // autoescaping keeps it inert on render) instead of failing the
        // post the way an explicitly-entered invalid link used to.
        $this->withCsrf(['body' => 'Regardez ça: javascript:alert(1)']);

        $response = $this->controller([$this->memberId])->create($this->request(), $this->params());

        $this->assertSame(302, $response->getStatusCode());
        $posts = $this->postRepo->findPage($this->groupId, 10);
        $this->assertCount(1, $posts);
        $this->assertSame('Regardez ça: javascript:alert(1)', $posts[0]->body);
        $this->assertNull((new PostLinkRepository($this->pdo))->findForPost($posts[0]->id));
    }

    public function testCreateDetectsTheUrlInTheBodyStoresAndRendersThePreviewCardAndStripsItFromTheText(): void
    {
        $fetcher = $this->createMock(LinkPreviewFetcher::class);
        $fetcher->method('fetch')->willReturn(new \Modules\Gallery\Api\LinkPreview('Un super lien', 'Une belle description', null));
        $this->withCsrf(['body' => 'Regarde ça: https://example.com/article trop bien']);

        $createResponse = $this->controller([$this->memberId], self::AUTHOR_ACCOUNT, 'identified', true, null, $fetcher)
            ->create($this->request(), $this->params());
        $this->assertSame(302, $createResponse->getStatusCode());

        $posts = $this->postRepo->findPage($this->groupId, 10);
        $this->assertCount(1, $posts);
        // The URL itself never stays in the body once it is going to be
        // represented by the preview card underneath — Facebook-style.
        $this->assertSame('Regarde ça: trop bien', $posts[0]->body);
        $link = (new PostLinkRepository($this->pdo))->findForPost($posts[0]->id);
        $this->assertSame('https://example.com/article', $link->url);
        $this->assertSame('Un super lien', $link->title);

        // And it actually renders through the real templates — not just
        // stored — proving post_link.html.twig has no syntax error and
        // is reachable from the feed.
        $feedResponse = $this->controller([$this->memberId])->feed(
            new Request('GET', '/groups/' . $this->groupId . '/feed', [], [], [], []),
            $this->params()
        );
        $this->assertSame(200, $feedResponse->getStatusCode());
        $this->assertStringContainsString('Un super lien', $feedResponse->getBody());
        $this->assertStringContainsString('Une belle description', $feedResponse->getBody());
        $this->assertStringContainsString('example.com', $feedResponse->getBody());
        $this->assertStringContainsString('https://example.com/article', $feedResponse->getBody());
    }

    public function testCreateWithOnlyAUrlAndNoOtherTextIsStillPostableOnceStripped(): void
    {
        // The body is empty AFTER stripping the URL, but the post is still
        // saved — a link alone counts as content, same as it always has.
        $fetcher = $this->createMock(LinkPreviewFetcher::class);
        $fetcher->method('fetch')->willReturn(null);
        $this->withCsrf(['body' => 'https://example.com/article']);

        $response = $this->controller([$this->memberId], self::AUTHOR_ACCOUNT, 'identified', true, null, $fetcher)
            ->create($this->request(), $this->params());

        $this->assertSame(302, $response->getStatusCode());
        $posts = $this->postRepo->findPage($this->groupId, 10);
        $this->assertCount(1, $posts);
        $this->assertSame('', $posts[0]->body);
        $this->assertSame('https://example.com/article', (new PostLinkRepository($this->pdo))->findForPost($posts[0]->id)->url);
    }

    public function testCreateIsRefusedOnAClosedGroupServerSide(): void
    {
        $this->groupRepo->setClosed($this->groupId, '2026-02-01 00:00:00');
        $this->withCsrf(['body' => 'Bonjour']);

        $response = $this->controller([$this->memberId])->create($this->request(), $this->params());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame([], $this->postRepo->findPage($this->groupId, 10));
    }

    public function testCreateIsRefusedWithAnIncompleteProfile(): void
    {
        $this->withCsrf(['body' => 'Bonjour']);

        $response = $this->controller([$this->memberId], self::AUTHOR_ACCOUNT, 'identified', false)->create($this->request(), $this->params());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame([], $this->postRepo->findPage($this->groupId, 10));
    }

    public function testCreateNeverSignsThePostAsALinkedMemberWhoIsNotInThisGroup(): void
    {
        // A parent linked to two children, only one of whom is in the
        // group: asking to post as the other must not work.
        $outsideChild = GroupsTestHelper::createMember($this->pdo, 'CHILD_OUTSIDE');
        $this->withCsrf(['body' => 'Bonjour', 'author_member_id' => (string) $outsideChild]);

        $this->controller([$this->memberId, $outsideChild])->create($this->request(), $this->params());

        $posts = $this->postRepo->findPage($this->groupId, 10);
        $this->assertCount(1, $posts);
        $this->assertSame($this->memberId, $posts[0]->authorMemberId, 'the forged author_member_id must be ignored');
    }

    // --- linkPreview -----------------------------------------------------

    private function linkPreviewRequest(): Request
    {
        return new Request('POST', '/groups/' . $this->groupId . '/link-preview', [], $_POST, [], []);
    }

    public function testLinkPreviewRejectsAMissingCsrfToken(): void
    {
        $_POST = ['body' => 'https://example.com'];

        $response = $this->controller([$this->memberId])->linkPreview($this->linkPreviewRequest(), $this->params());

        $this->assertSame(403, $response->getStatusCode());
        $decoded = json_decode($response->getBody(), true);
        $this->assertSame(
            \Core\Http\Controller\AbstractController::SESSION_EXPIRED_MESSAGE,
            $decoded['error'] ?? null
        );
    }

    public function testLinkPreviewIs404ForANonMemberRatherThan403(): void
    {
        $outsider = GroupsTestHelper::createMember($this->pdo, 'OUTLP');
        $this->withCsrf(['body' => 'https://example.com']);

        $response = $this->controller([$outsider])->linkPreview($this->linkPreviewRequest(), $this->params());

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testLinkPreviewIsRefusedOnAClosedGroup(): void
    {
        $this->groupRepo->setClosed($this->groupId, '2026-02-01 00:00:00');
        $this->withCsrf(['body' => 'https://example.com']);

        $response = $this->controller([$this->memberId])->linkPreview($this->linkPreviewRequest(), $this->params());

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testLinkPreviewReturnsANullUrlWhenTheDraftHasNoLinkWithoutCallingTheFetcher(): void
    {
        $fetcher = $this->createMock(LinkPreviewFetcher::class);
        $fetcher->expects($this->never())->method('fetch');
        $this->withCsrf(['body' => 'Un message tout à fait normal']);

        $response = $this->controller([$this->memberId], self::AUTHOR_ACCOUNT, 'identified', true, null, $fetcher)
            ->linkPreview($this->linkPreviewRequest(), $this->params());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['url' => null], json_decode($response->getBody(), true));
    }

    public function testLinkPreviewResolvesTheFirstUrlInTheDraftAndReturnsADataUriImageWithoutStoringAnything(): void
    {
        $fetcher = $this->createMock(LinkPreviewFetcher::class);
        $fetcher->expects($this->once())->method('fetch')->with('https://example.com/article')
            ->willReturn(new \Modules\Gallery\Api\LinkPreview('Titre', 'Description', base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
            )));
        $this->withCsrf(['body' => 'Regarde https://example.com/article trop bien']);

        $response = $this->controller([$this->memberId], self::AUTHOR_ACCOUNT, 'identified', true, null, $fetcher)
            ->linkPreview($this->linkPreviewRequest(), $this->params());

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('https://example.com/article', $body['url']);
        $this->assertSame('Titre', $body['title']);
        $this->assertSame('Description', $body['description']);
        $this->assertStringStartsWith('data:image/png;base64,', $body['image_data_uri']);

        // A preview only — nothing was written, unlike create().
        $this->assertSame([], $this->postRepo->findPage($this->groupId, 10));
    }

    // --- media -----------------------------------------------------------

    public function testCreateRejectsAFifthMediaWithoutCreatingThePostAtAll(): void
    {
        $manager = $this->createMock(DelegatedAlbumManager::class);
        $manager->expects($this->never())->method('ensureAlbum');
        $manager->expects($this->never())->method('addMedia');

        $this->withMediaFiles(5);
        $this->withCsrf(['body' => 'Cinq médias']);

        $response = $this->controller([$this->memberId], self::AUTHOR_ACCOUNT, 'identified', true, $manager)
            ->create($this->request(), $this->params());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame([], $this->postRepo->findPage($this->groupId, 10), 'the whole post must be rejected, not truncated to 4');
        $flash = FlashMessage::get();
        $this->assertNotNull($flash);
        $this->assertSame('error', $flash['type']);
        $this->assertStringContainsString('4', $flash['message']);
    }

    public function testCreateAcceptsExactlyFourMedia(): void
    {
        $manager = $this->createMock(DelegatedAlbumManager::class);
        $manager->method('ensureAlbum')->willReturn(new DelegatedAlbum(1, 'Louveteaux', '2026-01-01'));
        $manager->method('addMedia')->willReturnCallback(
            fn() => new DelegatedMedia(random_int(1000, 9999), 'photo', 'pending', 0, 'photo.jpg', '2026-01-01 10:00:00')
        );

        $this->withMediaFiles(4);
        $this->withCsrf(['body' => 'Quatre médias']);

        $response = $this->controller([$this->memberId], self::AUTHOR_ACCOUNT, 'identified', true, $manager)
            ->create($this->request(), $this->params());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertCount(1, $this->postRepo->findPage($this->groupId, 10));
    }

    public function testCreateAcceptsAMediaOnlyPostWithNoBodyText(): void
    {
        $manager = $this->createMock(DelegatedAlbumManager::class);
        $manager->method('ensureAlbum')->willReturn(new DelegatedAlbum(1, 'Louveteaux', '2026-01-01'));
        $manager->method('addMedia')->willReturn(new DelegatedMedia(1, 'photo', 'pending', 0, 'photo.jpg', '2026-01-01 10:00:00'));

        $this->withMediaFiles(1);
        $this->withCsrf(['body' => '']);

        $this->controller([$this->memberId], self::AUTHOR_ACCOUNT, 'identified', true, $manager)
            ->create($this->request(), $this->params());

        $posts = $this->postRepo->findPage($this->groupId, 10);
        $this->assertCount(1, $posts);
        $this->assertSame('', $posts[0]->body);
    }

    public function testCreateWithNeitherBodyNorMediaSavesNothing(): void
    {
        $this->withCsrf(['body' => '']);

        $this->controller([$this->memberId])->create($this->request(), $this->params());

        $this->assertSame([], $this->postRepo->findPage($this->groupId, 10));
    }

    public function testCreateRollsBackTheWholePostWhenAVideoIsRefusedServerSide(): void
    {
        // Reproduces the "videos disabled" case: gallery's own addMedia()
        // already refuses server-side (Api\DelegatedAlbumManager::
        // videoUploadAllowed()'s docblock) — this asserts the whole post
        // disappears, not just the media, matching the module spec
        // ("never a silent failure or a stuck upload").
        $manager = $this->createMock(DelegatedAlbumManager::class);
        $manager->method('ensureAlbum')->willReturn(new DelegatedAlbum(1, 'Louveteaux', '2026-01-01'));
        $manager->method('addMedia')->willThrowException(new GalleryException("L'envoi de vidéos est désactivé."));

        $this->withMediaFiles(1);
        $this->withCsrf(['body' => 'Une vidéo']);

        $response = $this->controller([$this->memberId], self::AUTHOR_ACCOUNT, 'identified', true, $manager)
            ->create($this->request(), $this->params());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame([], $this->postRepo->findPage($this->groupId, 10), 'no half-saved post');
        $flash = FlashMessage::get();
        $this->assertNotNull($flash);
        $this->assertSame('error', $flash['type']);
        $this->assertStringContainsString('vidéo', $flash['message']);
    }

    // --- edit ----------------------------------------------------------

    /**
     * Only the body comes back, never the whole card: groups.js swaps
     * that one <p> so the reply thread expanded underneath survives.
     */
    public function testEditingAPostViaAjaxReturnsOnlyTheReRenderedBody(): void
    {
        $postId = $this->seedPost();
        $this->withCsrf(['body' => 'Texte corrigé']);

        $response = $this->controller([$this->memberId])->edit($this->ajaxRequest(), $this->params($postId));

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertStringContainsString('Texte corrigé', $body['html']);
        $this->assertStringContainsString('post-body-' . $postId, $body['html']);
        $this->assertStringNotContainsString('<article', $body['html'], 'the whole card must not come back');
        $this->assertSame('Texte corrigé', $this->postRepo->findById($postId)->body);
    }

    public function testDeletingAPostViaAjaxAcknowledgesInsteadOfRedirecting(): void
    {
        $postId = $this->seedPost();
        $this->withCsrf([]);

        $response = $this->controller([$this->memberId])->delete($this->ajaxRequest(), $this->params($postId));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['deleted' => true], json_decode($response->getBody(), true));
        $this->assertNull($this->postRepo->findById($postId));
    }

    public function testEditRejectsAMissingCsrfToken(): void
    {
        $postId = $this->seedPost();
        $_POST = ['body' => 'Corrigé'];

        $response = $this->controller([$this->memberId])->edit($this->request(), $this->params($postId));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            \Core\Http\Controller\AbstractController::SESSION_EXPIRED_MESSAGE,
            \Core\Http\FlashMessage::get()['message'] ?? null
        );
        $this->assertSame('Bonjour', $this->postRepo->findById($postId)->body);
    }

    public function testTheAuthorMayEditInsideTheWindow(): void
    {
        $postId = $this->seedPost(2);
        $this->withCsrf(['body' => 'Corrigé']);

        $response = $this->controller([$this->memberId])->edit($this->request(), $this->params($postId));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('Corrigé', $this->postRepo->findById($postId)->body);
    }

    public function testTheAuthorMayNotEditOnceTheWindowHasPassed(): void
    {
        $postId = $this->seedPost(20);
        $this->withCsrf(['body' => 'Corrigé']);

        $response = $this->controller([$this->memberId])->edit($this->request(), $this->params($postId));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Bonjour', $this->postRepo->findById($postId)->body);
    }

    public function testAForgedClientTimestampDoesNotReopenTheEditWindow(): void
    {
        $postId = $this->seedPost(45);
        $this->withCsrf([
            'body' => 'Corrigé',
            // Everything a client could plausibly try to forge.
            'created_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            'edited_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            'edit_window_minutes' => '600',
        ]);

        $response = $this->controller([$this->memberId])->edit($this->request(), $this->params($postId));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Bonjour', $this->postRepo->findById($postId)->body);
    }

    public function testAnotherMemberMayNotEditSomeoneElsesPost(): void
    {
        $postId = $this->seedPost(1, self::AUTHOR_ACCOUNT);
        $this->withCsrf(['body' => 'Détourné']);

        $response = $this->controller([$this->otherMemberId], self::OTHER_ACCOUNT)->edit($this->request(), $this->params($postId));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Bonjour', $this->postRepo->findById($postId)->body);
    }

    public function testAModeratorMayNotEditSomeoneElsesPostEither(): void
    {
        // Moderation covers deleting and pinning, never rewriting words.
        $postId = $this->seedPost(1, self::AUTHOR_ACCOUNT);
        $this->withCsrf(['body' => 'Réécrit']);

        $response = $this->controller([$this->moderatorMemberId], self::OTHER_ACCOUNT)->edit($this->request(), $this->params($postId));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Bonjour', $this->postRepo->findById($postId)->body);
    }

    public function testEditIs404ForANonMember(): void
    {
        $postId = $this->seedPost();
        $outsider = GroupsTestHelper::createMember($this->pdo, 'OUT2');
        $this->withCsrf(['body' => 'Corrigé']);

        $this->assertSame(404, $this->controller([$outsider])->edit($this->request(), $this->params($postId))->getStatusCode());
    }

    // --- delete --------------------------------------------------------

    public function testDeleteRejectsAMissingCsrfToken(): void
    {
        $postId = $this->seedPost();
        $_POST = [];

        $this->assertSame(302, $this->controller([$this->memberId])->delete($this->request(), $this->params($postId))->getStatusCode());
        \Core\Http\FlashMessage::get();
        $this->assertNotNull($this->postRepo->findById($postId));
    }

    public function testTheAuthorMayDeleteTheirOwnPostEvenAfterTheEditWindow(): void
    {
        $postId = $this->seedPost(120);
        $this->withCsrf([]);

        $this->controller([$this->memberId])->delete($this->request(), $this->params($postId));

        $this->assertNull($this->postRepo->findById($postId));
    }

    public function testAModeratorMayDeleteAnyPost(): void
    {
        $postId = $this->seedPost(1, self::AUTHOR_ACCOUNT);
        $this->withCsrf([]);

        $this->controller([$this->moderatorMemberId], self::OTHER_ACCOUNT)->delete($this->request(), $this->params($postId));

        $this->assertNull($this->postRepo->findById($postId));
    }

    /**
     * A moderator removing someone else's message is a moderation
     * decision and is recorded as one — with ids only, never the
     * message's text (module spec, SECURITY.md §11).
     */
    public function testAModeratorsDeletionIsJournaledWithIdsOnly(): void
    {
        $postId = $this->seedPost(1, self::AUTHOR_ACCOUNT);
        $this->withCsrf([]);

        $this->controller([$this->moderatorMemberId], self::OTHER_ACCOUNT)->delete($this->request(), $this->params($postId));

        $rows = $this->pdo
            ->query("SELECT event_type, description, context FROM event_log WHERE category = 'groups'")
            ->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertSame(['group_post_moderator_deleted'], array_column($rows, 'event_type'));
        $this->assertSame(
            ['group_id' => $this->groupId, 'post_id' => $postId],
            json_decode((string) $rows[0]['context'], true)
        );
    }

    /**
     * An author tidying up after themselves is not a moderation decision,
     * so it leaves no moderation entry — even when that author happens to
     * be a moderator of the group.
     */
    public function testAnAuthorDeletingTheirOwnPostIsNotJournaledAsModeration(): void
    {
        $postId = $this->seedPost(1, self::AUTHOR_ACCOUNT);
        $this->withCsrf([]);

        $this->controller([$this->memberId], self::AUTHOR_ACCOUNT)->delete($this->request(), $this->params($postId));

        $count = (int) $this->pdo
            ->query("SELECT COUNT(*) FROM event_log WHERE category = 'groups' AND event_type = 'group_post_moderator_deleted'")
            ->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testAnOrdinaryMemberMayNotDeleteSomeoneElsesPost(): void
    {
        $postId = $this->seedPost(1, self::AUTHOR_ACCOUNT);
        $this->withCsrf([]);

        $response = $this->controller([$this->otherMemberId], self::OTHER_ACCOUNT)->delete($this->request(), $this->params($postId));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNotNull($this->postRepo->findById($postId));
    }

    public function testDeleteIs404ForANonMember(): void
    {
        $postId = $this->seedPost();
        $outsider = GroupsTestHelper::createMember($this->pdo, 'OUT3');
        $this->withCsrf([]);

        $this->assertSame(404, $this->controller([$outsider])->delete($this->request(), $this->params($postId))->getStatusCode());
        $this->assertNotNull($this->postRepo->findById($postId));
    }

    // --- pin / unpin ---------------------------------------------------

    public function testPinRejectsAMissingCsrfToken(): void
    {
        $postId = $this->seedPost();
        $_POST = [];

        $this->assertSame(302, $this->controller([$this->moderatorMemberId], self::OTHER_ACCOUNT)->pin($this->request(), $this->params($postId))->getStatusCode());
        \Core\Http\FlashMessage::get();
        $this->assertFalse($this->postRepo->findById($postId)->isPinned);
    }

    public function testAModeratorPinsAndUnpins(): void
    {
        $postId = $this->seedPost();

        $this->withCsrf([]);
        $this->controller([$this->moderatorMemberId], self::OTHER_ACCOUNT)->pin($this->request(), $this->params($postId));
        $this->assertTrue($this->postRepo->findById($postId)->isPinned);

        $this->withCsrf([]);
        $this->controller([$this->moderatorMemberId], self::OTHER_ACCOUNT)->unpin($this->request(), $this->params($postId));
        $this->assertFalse($this->postRepo->findById($postId)->isPinned);
    }

    public function testAnOrdinaryMemberMayNotPinEvenTheirOwnPost(): void
    {
        $postId = $this->seedPost();
        $this->withCsrf([]);

        $response = $this->controller([$this->memberId])->pin($this->request(), $this->params($postId));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($this->postRepo->findById($postId)->isPinned);
    }

    public function testPinIs404ForANonMember(): void
    {
        $postId = $this->seedPost();
        $outsider = GroupsTestHelper::createMember($this->pdo, 'OUT4');
        $this->withCsrf([]);

        $this->assertSame(404, $this->controller([$outsider])->pin($this->request(), $this->params($postId))->getStatusCode());
    }

    // --- polls ----------------------------------------------------------

    /**
     * @return array<int, array{id: int, label: string}>
     */
    private function pollOptionsOf(int $postId): array
    {
        $repo = new \Modules\Groups\Repository\PollRepository($this->pdo);
        $poll = $repo->findByPostId($postId);
        $this->assertNotNull($poll, 'expected a poll on post ' . $postId);

        return $repo->optionsForPolls([$poll['id']])[$poll['id']];
    }

    private function seedPostWithPoll(): int
    {
        $this->withCsrf([
            'body' => 'Sondage',
            'poll_question' => 'Qui vient ?',
            'poll_options' => ['Samedi', 'Dimanche'],
        ]);
        $this->controller([$this->memberId])->create($this->request(), $this->params());

        return $this->postRepo->findPage($this->groupId, 10)[0]->id;
    }

    public function testAPostCanCarryAPollAndTheFeedRendersIt(): void
    {
        $postId = $this->seedPostWithPoll();

        $body = $this->controller([$this->memberId])
            ->feed(new Request('GET', '/groups/1/feed', [], [], [], []), $this->params())
            ->getBody();

        $this->assertNotNull((new \Modules\Groups\Repository\PollRepository($this->pdo))->findByPostId($postId));
        $this->assertStringContainsString('Qui vient ?', $body);
        $this->assertStringContainsString('Samedi', $body);
    }

    /**
     * A poll is text of its own, so it keeps an otherwise empty post
     * alive — the same way a photo or a link does.
     */
    public function testAPollAloneIsEnoughToPublish(): void
    {
        $this->withCsrf([
            'body' => '',
            'poll_question' => 'Qui vient ?',
            'poll_options' => ['Samedi', 'Dimanche'],
        ]);

        $this->controller([$this->memberId])->create($this->request(), $this->params());

        $this->assertCount(1, $this->postRepo->findPage($this->groupId, 10));
    }

    /**
     * A half-filled poll is not a poll, so it cannot rescue an empty
     * post — Service\PollService::normalise() refuses it before the
     * emptiness check runs.
     */
    public function testAHalfFilledPollDoesNotRescueAnEmptyPost(): void
    {
        $this->withCsrf(['body' => '', 'poll_question' => 'Qui vient ?', 'poll_options' => ['Samedi']]);

        $this->controller([$this->memberId])->create($this->request(), $this->params());

        $this->assertSame([], $this->postRepo->findPage($this->groupId, 10));
    }

    public function testAPostWithNoPollFieldsCarriesNoPoll(): void
    {
        $this->withCsrf(['body' => 'Juste un message']);

        $this->controller([$this->memberId])->create($this->request(), $this->params());

        $postId = $this->postRepo->findPage($this->groupId, 10)[0]->id;
        $this->assertNull((new \Modules\Groups\Repository\PollRepository($this->pdo))->findByPostId($postId));
    }

    public function testAMemberVotesAndGetsTheRefreshedPollBack(): void
    {
        $postId = $this->seedPostWithPoll();
        $options = $this->pollOptionsOf($postId);
        $this->withCsrf(['option_id' => (string) $options[0]['id']]);

        $response = $this->controller([$this->otherMemberId], self::OTHER_ACCOUNT)
            ->vote($this->ajaxRequest(), $this->params($postId));

        $this->assertSame(200, $response->getStatusCode());
        $html = json_decode($response->getBody(), true)['html'];
        $this->assertStringContainsString('1 personne a répondu', $html);
    }

    public function testAPlainFormVoteRedirectsToTheAnchoredPost(): void
    {
        $postId = $this->seedPostWithPoll();
        $options = $this->pollOptionsOf($postId);
        $this->withCsrf(['option_id' => (string) $options[0]['id']]);

        $response = $this->controller([$this->otherMemberId], self::OTHER_ACCOUNT)
            ->vote($this->request(), $this->params($postId));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/groups/' . $this->groupId . '#post-' . $postId, $response->getHeaders()['Location']);
    }

    public function testVotingRejectsAMissingCsrfToken(): void
    {
        $postId = $this->seedPostWithPoll();
        $options = $this->pollOptionsOf($postId);
        $_POST = ['option_id' => (string) $options[0]['id']];

        $response = $this->controller([$this->otherMemberId], self::OTHER_ACCOUNT)
            ->vote($this->request(), $this->params($postId));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            \Core\Http\Controller\AbstractController::SESSION_EXPIRED_MESSAGE,
            \Core\Http\FlashMessage::get()['message'] ?? null
        );
    }

    public function testVotingIs404ForANonMember(): void
    {
        $postId = $this->seedPostWithPoll();
        $options = $this->pollOptionsOf($postId);
        $outsider = GroupsTestHelper::createMember($this->pdo, 'OUTPOLL');
        $this->withCsrf(['option_id' => (string) $options[0]['id']]);

        $response = $this->controller([$outsider], self::OTHER_ACCOUNT)->vote($this->request(), $this->params($postId));

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * Voting is writing: a closed group refuses it for the same reason it
     * refuses a reply, which is why a poll needs no "closed" flag of its
     * own.
     */
    public function testAClosedGroupRefusesAVote(): void
    {
        $postId = $this->seedPostWithPoll();
        $options = $this->pollOptionsOf($postId);
        $this->groupRepo->setClosed($this->groupId, '2026-02-01 00:00:00');
        $this->withCsrf(['option_id' => (string) $options[0]['id']]);

        $response = $this->controller([$this->otherMemberId], self::OTHER_ACCOUNT)
            ->vote($this->request(), $this->params($postId));

        $this->assertSame(403, $response->getStatusCode());
    }

    // --- a poll answered per member -------------------------------------

    /**
     * A "une réponse par membre" poll asks a parent about their children,
     * and a parent's children are not all in the same section. The picker
     * offers every member this account reaches — the group's own first —
     * rather than only the ones this group is built from: offering two of
     * four does not protect anything, it quietly produces a count that is
     * short.
     */
    public function testAMemberScopedPollOffersEveryLinkedMemberAndNotOnlyTheGroupsOwn(): void
    {
        $this->seedPostWithMemberScopedPoll();
        $sibling = $this->siblingInAnotherSection();

        $body = $this->controller([$sibling, $this->memberId])
            ->feed(new Request('GET', '/groups/1/feed', [], [], [], []), $this->params())
            ->getBody();

        $this->assertStringContainsString('<option value="' . $this->memberId . '"', $body);
        $this->assertStringContainsString('<option value="' . $sibling . '"', $body);
        // The group's own member is the picker's default, whatever order
        // the account carries its members in — that default is what a
        // no-JavaScript submit sends.
        $this->assertStringContainsString('name="voter_member_id" value="' . $this->memberId . '"', $body);
    }

    /**
     * And the answer for that child is really recorded as that child's,
     * not silently re-attributed to the one who is in the section.
     */
    public function testAParentAnswersForAChildWhoIsNotInThisGroupsSection(): void
    {
        $postId = $this->seedPostWithMemberScopedPoll();
        $sibling = $this->siblingInAnotherSection();
        $options = $this->pollOptionsOf($postId);
        $this->withCsrf([
            'option_id' => (string) $options[0]['id'],
            'voter_member_id' => (string) $sibling,
        ]);

        $response = $this->controller([$this->memberId, $sibling])
            ->vote($this->ajaxRequest(), $this->params($postId));

        $this->assertSame(200, $response->getStatusCode());
        $repo = new \Modules\Groups\Repository\PollRepository($this->pdo);
        $pollId = $repo->findByPostId($postId)['id'];
        $this->assertSame(
            [$options[0]['id']],
            $repo->ownBallots([$pollId], ['m:' . $sibling])[$pollId]['m:' . $sibling] ?? []
        );
    }

    private function siblingInAnotherSection(): int
    {
        return GroupsTestHelper::createMemberWithPeriod(
            $this->pdo,
            'SIBLING',
            GroupsTestHelper::createSection($this->pdo, 'ECL', 'Éclaireurs'),
            $this->currentYearId
        );
    }

    private function seedPostWithMemberScopedPoll(): int
    {
        $this->withCsrf([
            'body' => 'Sondage',
            'poll_question' => 'Quel enfant vient ?',
            'poll_options' => ['Samedi', 'Dimanche'],
            'poll_vote_scope' => \Modules\Groups\Service\PollService::SCOPE_MEMBER,
        ]);
        $this->controller([$this->memberId])->create($this->request(), $this->params());

        return $this->postRepo->findPage($this->groupId, 10)[0]->id;
    }

    public function testAnOptionFromAnotherPostsPollIsRefused(): void
    {
        $postId = $this->seedPostWithPoll();
        $otherPostId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'Autre', '2026-01-02 10:00:00', self::AUTHOR_ACCOUNT, $this->memberId);
        (new \Modules\Groups\Service\PollService(new \Modules\Groups\Repository\PollRepository($this->pdo)))
            ->attachTo($otherPostId, [
                'question' => 'Autre ?',
                'options' => ['Oui', 'Non'],
                'vote_scope' => \Modules\Groups\Service\PollService::SCOPE_ACCOUNT,
                'allow_multiple' => false,
            ]);
        $foreign = $this->pollOptionsOf($otherPostId)[0]['id'];
        $this->withCsrf(['option_id' => (string) $foreign]);

        $response = $this->controller([$this->otherMemberId], self::OTHER_ACCOUNT)
            ->vote($this->request(), $this->params($postId));

        $this->assertSame(400, $response->getStatusCode());
    }

    // --- linked calendar event ------------------------------------------

    private function eventLookup(?\Modules\Calendar\Api\EventSummary $event): \Modules\Calendar\Api\CalendarEventLookupInterface
    {
        $lookup = $this->createStub(\Modules\Calendar\Api\CalendarEventLookupInterface::class);
        $lookup->method('findEventById')->willReturn($event);
        $lookup->method('findEventsInWindow')->willReturn($event !== null ? [$event] : []);

        return $lookup;
    }

    private function summary(int $id = 9): \Modules\Calendar\Api\EventSummary
    {
        return new \Modules\Calendar\Api\EventSummary($id, 'Réunion de section', 'Louveteaux', '2026-03-14', '2026-03-14');
    }

    public function testAPostCanCarryACalendarEventAndShowsItInTheFeed(): void
    {
        $this->withCsrf(['body' => 'On en parle samedi', 'calendar_event_id' => '9']);
        $controller = $this->controller([$this->memberId], self::AUTHOR_ACCOUNT, 'identified', true, null, null, $this->eventLookup($this->summary()));

        $controller->create($this->request(), $this->params());

        $posts = $this->postRepo->findPage($this->groupId, 10);
        $this->assertSame(9, $posts[0]->calendarEventId);

        $body = $controller->feed(new Request('GET', '/groups/' . $this->groupId . '/feed', [], [], [], []), $this->params())->getBody();
        $this->assertStringContainsString('Réunion de section', $body);
        $this->assertStringContainsString('/calendar?month=2026-03', $body);
    }

    /**
     * The id is re-resolved against the calendar's own visibility rules
     * before anything is stored, so one typed into the form by hand
     * cannot attach an event this member may not see.
     */
    public function testAnEventTheMemberMayNotSeeIsNotAttached(): void
    {
        $this->withCsrf(['body' => 'On en parle samedi', 'calendar_event_id' => '9']);

        $this->controller([$this->memberId], self::AUTHOR_ACCOUNT, 'identified', true, null, null, $this->eventLookup(null))
            ->create($this->request(), $this->params());

        $this->assertNull($this->postRepo->findPage($this->groupId, 10)[0]->calendarEventId);
    }

    /**
     * The whole point of the nullable interface: with the calendar module
     * switched off, posting still works and the card simply shows no
     * event line (ARCHITECTURE.md §7.5).
     */
    public function testWithTheCalendarDisabledAPostStillPublishesWithNoEventLine(): void
    {
        $this->withCsrf(['body' => 'On en parle samedi', 'calendar_event_id' => '9']);
        $controller = $this->controller([$this->memberId]);

        $response = $controller->create($this->request(), $this->params());

        $this->assertSame(302, $response->getStatusCode());
        $post = $this->postRepo->findPage($this->groupId, 10)[0];
        $this->assertNull($post->calendarEventId);
        $this->assertStringNotContainsString(
            'bi-calendar-event',
            $controller->feed(new Request('GET', '/groups/1/feed', [], [], [], []), $this->params())->getBody()
        );
    }

    /**
     * A stale id — the event was deleted after the post was written —
     * renders as no line at all rather than as a broken link. That is why
     * schema.sql carries no foreign key on the column.
     */
    public function testAPostWhoseEventHasSinceDisappearedRendersWithoutIt(): void
    {
        $postId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'On en parle', '2026-01-10 10:00:00', self::AUTHOR_ACCOUNT, $this->memberId);
        $this->postRepo->setCalendarEventId($postId, 9);

        $body = $this->controller([$this->memberId], self::AUTHOR_ACCOUNT, 'identified', true, null, null, $this->eventLookup(null))
            ->feed(new Request('GET', '/groups/1/feed', [], [], [], []), $this->params())
            ->getBody();

        $this->assertStringNotContainsString('bi-calendar-event', $body);
    }

    // --- mentions -------------------------------------------------------

    private function mentionSearchRequest(string $q): Request
    {
        return new Request('GET', '/groups/' . $this->groupId . '/mention-search', ['q' => $q], [], [], []);
    }

    public function testMentionSearchOffersTheGroupsOwnMembers(): void
    {
        $response = $this->controller([$this->memberId])->mentionSearch($this->mentionSearchRequest('Ak'), $this->params());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            [['id' => $this->memberId, 'label' => 'Akéla', 'mention' => 'Akéla']],
            json_decode($response->getBody(), true)
        );
    }

    /**
     * Unlike the invite box's member-search, this one is open to every
     * member of the group — it returns names they already see on the
     * members page — but it is still 404 for somebody outside it.
     */
    public function testMentionSearchIs404ForANonMember(): void
    {
        $outsider = GroupsTestHelper::createMember($this->pdo, 'OUTMENTION');

        $response = $this->controller([$outsider], self::OTHER_ACCOUNT)
            ->mentionSearch($this->mentionSearchRequest('Ak'), $this->params());

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testMentionSearchReturnsNothingForAnEmptyQuery(): void
    {
        $response = $this->controller([$this->memberId])->mentionSearch($this->mentionSearchRequest(''), $this->params());

        $this->assertSame([], json_decode($response->getBody(), true));
    }

    /**
     * The autocomplete is a typing aid, not the mechanism: the field
     * carries the endpoint, and picking a name only ever types plain
     * text into the body.
     */
    public function testTheComposerAndTheReplyBoxBothCarryTheMentionEndpoint(): void
    {
        GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'Bonjour', '2026-01-10 10:00:00', self::AUTHOR_ACCOUNT, $this->memberId);

        $body = $this->controller([$this->memberId])
            ->feed(new Request('GET', '/groups/' . $this->groupId . '/feed', [], [], [], []), $this->params())
            ->getBody();

        $this->assertStringContainsString('data-mention-url="/groups/' . $this->groupId . '/mention-search"', $body);
    }

    // --- seen by --------------------------------------------------------

    private function markGroupRead(int $memberId, string $at): void
    {
        (new \Modules\Groups\Repository\GroupReadRepository($this->pdo))->markRead($this->groupId, $memberId, $at);
    }

    private function seenByRequest(int $postId): Request
    {
        return new Request('GET', '/groups/' . $this->groupId . '/posts/' . $postId . '/seen-by', [], [], [], []);
    }

    public function testTheAuthorSeesWhoOpenedTheGroupAfterTheirPost(): void
    {
        $postId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'Bonjour', '2026-01-10 10:00:00', self::AUTHOR_ACCOUNT, $this->memberId);
        $this->markGroupRead($this->otherMemberId, '2026-01-10 11:00:00');

        $response = $this->controller([$this->memberId])->seenBy($this->seenByRequest($postId), $this->params($postId));

        $this->assertSame(200, $response->getStatusCode());
        // The endpoint answers JSON ({html: …}), so the accented name is
        // \u-escaped in the raw body — decode before asserting on it.
        // The account, then its memberships — the shape every name in this
        // module now takes (Service\MemberIdentityService).
        $this->assertStringContainsString('Luc Bernard (Baloo)', json_decode($response->getBody(), true)['html']);
    }

    /**
     * Somebody who opened the group BEFORE the message was published has
     * not seen it — the whole point of comparing against created_at.
     */
    public function testAVisitOlderThanThePostDoesNotCount(): void
    {
        $postId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'Bonjour', '2026-01-10 10:00:00', self::AUTHOR_ACCOUNT, $this->memberId);
        $this->markGroupRead($this->otherMemberId, '2026-01-09 09:00:00');

        $html = json_decode(
            $this->controller([$this->memberId])->seenBy($this->seenByRequest($postId), $this->params($postId))->getBody(),
            true
        )['html'];

        $this->assertStringContainsString('Personne n\'a encore ouvert', $html);
    }

    /**
     * The author's own visit is not a read receipt for their own message.
     */
    public function testTheAuthorsOwnVisitNeverCountsTowardsTheirOwnPost(): void
    {
        $postId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'Bonjour', '2026-01-10 10:00:00', self::AUTHOR_ACCOUNT, $this->memberId);
        $this->markGroupRead($this->memberId, '2026-01-10 11:00:00');

        $html = json_decode(
            $this->controller([$this->memberId])->seenBy($this->seenByRequest($postId), $this->params($postId))->getBody(),
            true
        )['html'];

        $this->assertStringContainsString('Personne n\'a encore ouvert', $html);
    }

    /**
     * A read mark says where somebody has been in a conversation. Only
     * the person asking "did my own message reach anyone?" gets that
     * answer — a moderator included, since moderation is about what was
     * said and not about who was reading.
     */
    public function testAnotherMemberGets404OnSomebodyElsesSeenByList(): void
    {
        $postId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'Bonjour', '2026-01-10 10:00:00', self::AUTHOR_ACCOUNT, $this->memberId);

        $response = $this->controller([$this->otherMemberId], self::OTHER_ACCOUNT)
            ->seenBy($this->seenByRequest($postId), $this->params($postId));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testAModeratorGetsNoPrivilegedViewOfWhoRead(): void
    {
        $postId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'Bonjour', '2026-01-10 10:00:00', self::AUTHOR_ACCOUNT, $this->memberId);

        $response = $this->controller([$this->moderatorMemberId], self::OTHER_ACCOUNT)
            ->seenBy($this->seenByRequest($postId), $this->params($postId));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testSeenByIs404ForANonMember(): void
    {
        $postId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'Bonjour', '2026-01-10 10:00:00', self::AUTHOR_ACCOUNT, $this->memberId);
        $outsider = GroupsTestHelper::createMember($this->pdo, 'OUTSEEN');

        $response = $this->controller([$outsider], self::OTHER_ACCOUNT)
            ->seenBy($this->seenByRequest($postId), $this->params($postId));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testSeenByIs404ForAPostFromAnotherGroup(): void
    {
        $otherGroupId = $this->groupService->createSectionGroup('Autre', $this->sectionId, $this->currentYearId, $this->moderatorMemberId);
        $foreignPostId = GroupsTestHelper::createPostAt($this->pdo, $otherGroupId, 'Ailleurs', '2026-01-10 10:00:00', self::AUTHOR_ACCOUNT, $this->memberId);

        $response = $this->controller([$this->memberId])
            ->seenBy($this->seenByRequest($foreignPostId), $this->params($foreignPostId));

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * The count on the card, not the dialog behind it: rendered for the
     * author only, and only in the feed the author is looking at.
     */
    public function testTheFeedShowsTheSeenCountToTheAuthorAndToNobodyElse(): void
    {
        GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'Bonjour', '2026-01-10 10:00:00', self::AUTHOR_ACCOUNT, $this->memberId);
        $this->markGroupRead($this->otherMemberId, '2026-01-10 11:00:00');
        $feedRequest = new Request('GET', '/groups/' . $this->groupId . '/feed', [], [], [], []);

        $authorBody = $this->controller([$this->memberId])->feed($feedRequest, $this->params())->getBody();
        $otherBody = $this->controller([$this->otherMemberId], self::OTHER_ACCOUNT)->feed($feedRequest, $this->params())->getBody();

        $this->assertStringContainsString('Vu par 1 membre', $authorBody);
        $this->assertStringNotContainsString('Vu par', $otherBody);
    }

    // --- feed and cross-group isolation --------------------------------

    public function testFeedIs404ForANonMember(): void
    {
        $outsider = GroupsTestHelper::createMember($this->pdo, 'OUT5');

        $response = $this->controller([$outsider])->feed(new Request('GET', '/groups/1/feed', [], [], [], []), $this->params());

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testFeedRendersTheNextPageForAMember(): void
    {
        for ($i = 0; $i < GroupFeedService::PAGE_SIZE + 2; $i++) {
            GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'post ' . $i, '2026-01-01 10:' . str_pad((string) $i, 2, '0', STR_PAD_LEFT) . ':00', self::AUTHOR_ACCOUNT, $this->memberId);
        }

        $response = $this->controller([$this->memberId])->feed(new Request('GET', '/groups/1/feed', [], [], [], []), $this->params());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Charger plus', $response->getBody());
    }

    public function testAPostFromAnotherGroupIs404EvenForAMemberOfBoth(): void
    {
        // The group in the URL is what was authorised — a post id from
        // elsewhere must not be reachable through it.
        $otherGroupId = $this->groupService->createSectionGroup('Autre', $this->sectionId, $this->currentYearId, $this->moderatorMemberId);
        $foreignPostId = GroupsTestHelper::createPostAt($this->pdo, $otherGroupId, 'Ailleurs', '2026-01-01 10:00:00', self::AUTHOR_ACCOUNT, $this->memberId);
        $this->withCsrf([]);

        $response = $this->controller([$this->memberId])->delete($this->request(), $this->params($foreignPostId));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertNotNull($this->postRepo->findById($foreignPostId));
    }
}
