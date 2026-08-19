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
use Modules\Groups\Service\AuthorOptionsService;
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
        $this->groupId = $this->groupService->createSectionGroup('Louveteaux', $this->sectionId, $this->currentYearId, $this->moderatorMemberId);
        $this->memberId = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'MEMBER', $this->sectionId, $this->currentYearId);
        $this->otherMemberId = GroupsTestHelper::createMemberWithPeriod($this->pdo, 'OTHER', $this->sectionId, $this->currentYearId);

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
        ?LinkPreviewFetcher $linkPreviewFetcher = null
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
        $postService = new PostService($this->postRepo, $activityService);
        $postMediaService = new PostMediaService(
            $delegatedAlbumManager ?? $this->createMock(DelegatedAlbumManager::class),
            new PostMediaRepository($this->pdo), $this->groupRepo
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
        $authorResolver = new PostAuthorResolver($memberService, $accountRepo);
        $stack = GroupsTestHelper::replyStack($this->pdo, $activityService, $postMediaService, $authorResolver);
        $feedService = new GroupFeedService(
            $this->postRepo, $authorResolver, $postService, $postMediaService, $postLinkRepo,
            $stack['replyRepository'], $stack['replyPresenter'], $stack['reactionService']
        );

        $twig = TwigFactory::create(
            dirname(__DIR__, 4) . '/core/View/templates',
            true,
            ['groups' => dirname(__DIR__, 4) . '/modules/groups/views']
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
            new AuthorOptionsService($access, $memberService)
        );
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

        $this->assertSame(403, $response->getStatusCode());
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

    public function testCreateRejectsAnInvalidLinkWithoutCreatingThePostAtAll(): void
    {
        $this->withCsrf(['body' => 'Regardez ça', 'link' => 'javascript:alert(1)']);

        $response = $this->controller([$this->memberId])->create($this->request(), $this->params());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame([], $this->postRepo->findPage($this->groupId, 10));
    }

    public function testCreateWithAValidLinkStoresAndRendersThePreviewCard(): void
    {
        $fetcher = $this->createMock(LinkPreviewFetcher::class);
        $fetcher->method('fetch')->willReturn(new \Modules\Gallery\Api\LinkPreview('Un super lien', 'Une belle description', null));
        $this->withCsrf(['body' => '', 'link' => 'https://example.com/article']);

        $createResponse = $this->controller([$this->memberId], self::AUTHOR_ACCOUNT, 'identified', true, null, $fetcher)
            ->create($this->request(), $this->params());
        $this->assertSame(302, $createResponse->getStatusCode());

        $posts = $this->postRepo->findPage($this->groupId, 10);
        $this->assertCount(1, $posts);
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

    public function testCreateWithNeitherBodyMediaNorLinkSavesNothingWithALinkFieldPresentButEmpty(): void
    {
        $this->withCsrf(['body' => '', 'link' => '']);

        $response = $this->controller([$this->memberId])->create($this->request(), $this->params());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame([], $this->postRepo->findPage($this->groupId, 10));
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

    public function testEditRejectsAMissingCsrfToken(): void
    {
        $postId = $this->seedPost();
        $_POST = ['body' => 'Corrigé'];

        $response = $this->controller([$this->memberId])->edit($this->request(), $this->params($postId));

        $this->assertSame(403, $response->getStatusCode());
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

        $this->assertSame(403, $this->controller([$this->memberId])->delete($this->request(), $this->params($postId))->getStatusCode());
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

        $this->assertSame(403, $this->controller([$this->moderatorMemberId])->pin($this->request(), $this->params($postId))->getStatusCode());
        $this->assertFalse($this->postRepo->findById($postId)->isPinned);
    }

    public function testAModeratorPinsAndUnpins(): void
    {
        $postId = $this->seedPost();

        $this->withCsrf([]);
        $this->controller([$this->moderatorMemberId])->pin($this->request(), $this->params($postId));
        $this->assertTrue($this->postRepo->findById($postId)->isPinned);

        $this->withCsrf([]);
        $this->controller([$this->moderatorMemberId])->unpin($this->request(), $this->params($postId));
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
