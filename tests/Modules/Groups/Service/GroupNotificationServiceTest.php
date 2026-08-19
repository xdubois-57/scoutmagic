<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Notification\NotificationPreferenceRepository;
use Core\Notification\NotificationRepository;
use Core\Notification\NotificationService;
use Core\Notification\PushSubscriptionRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\Post;
use Modules\Groups\Repository\PostRepository;
use Modules\Groups\Repository\Reply;
use Modules\Groups\Repository\ReplyRepository;
use Modules\Groups\Service\GroupNotificationService;
use Modules\Groups\Service\GroupRecipientResolver;
use Modules\Groups\Support\ReportedAuthor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * What each of the four notifications actually carries, and to whom —
 * asserted against the rows core really writes, so the type ids, the
 * payloads and the deep links are all exercised through
 * NotificationService::dispatch() rather than through a double.
 *
 * @group database
 */
#[Group('database')]
class GroupNotificationServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private GroupRepository $groupRepo;
    private PostRepository $postRepo;
    private ReplyRepository $replyRepo;
    private NotificationService $notifications;
    private int $groupId;
    private const AUTHOR_ACCOUNT = 41;
    private const AUTHOR_MEMBER = 3;
    private const ACTOR_ACCOUNT = 42;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->groupRepo = new GroupRepository($this->pdo);
        $this->postRepo = new PostRepository($this->pdo);
        $this->replyRepo = new ReplyRepository($this->pdo);
        $this->groupId = $this->groupRepo->create('Louveteaux', null, null, 1);

        // No RoleResolver: dispatch() then skips its role_min re-check
        // rather than rejecting everyone — the documented narrow-unit-test
        // degradation (Core\Notification\NotificationService's docblock).
        $this->notifications = new NotificationService(
            new NotificationRepository($this->pdo, $this->encryption),
            new PushSubscriptionRepository($this->pdo, $this->encryption),
            new NotificationPreferenceRepository($this->pdo),
            null,
            new SettingService(new SettingRepository($this->pdo)),
            new JournalService(new JournalRepository($this->pdo)),
            new SchedulerService(new SchedulerRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $this->encryption)
        );
        $this->notifications->registerModuleTypes('groups', $this->manifestNotifications());
    }

    // ---- type 1: a new post ----

    public function testANewPostNotifiesEveryMemberWithTheGroupNameAndAnExcerpt(): void
    {
        $post = $this->post('On part au camp samedi !');

        $this->service([
            ['userAccountId' => 10, 'memberId' => 1],
            ['userAccountId' => 11, 'memberId' => 2],
        ])->postPublished($this->group(), $post, 1);

        $rows = $this->rows();
        $this->assertCount(2, $rows);
        $this->assertSame('groups.post_published', $rows[0]['type_id']);
        $this->assertSame('Nouveau message — Louveteaux', $rows[0]['title']);
        $this->assertSame('On part au camp samedi !', $rows[0]['body']);
        $this->assertSame('/groups/' . $this->groupId . '/posts/' . $post->id, $rows[0]['url']);
    }

    public function testALongPostBodyIsTruncatedRatherThanSentWhole(): void
    {
        $post = $this->post(str_repeat('a', 500));

        $this->service([['userAccountId' => 10, 'memberId' => 1]])
            ->postPublished($this->group(), $post, 1);

        $body = $this->rows()[0]['body'];
        $this->assertLessThanOrEqual(120, mb_strlen($body));
        $this->assertStringEndsWith('…', $body);
    }

    public function testAMediaOnlyPostSaysSoRatherThanSendingAnEmptyBody(): void
    {
        $post = $this->post('');

        $this->service([['userAccountId' => 10, 'memberId' => 1]])
            ->postPublished($this->group(), $post, 1);

        $this->assertSame('Message sans texte.', $this->rows()[0]['body']);
    }

    /**
     * The rule that matters most here: a hidden item's text must never
     * leave through a notification. A push lands on a lock screen and
     * outlives the message it quotes, so the excerpt an already-hidden
     * post would carry is replaced outright.
     */
    public function testAHiddenPostNeverCarriesItsOwnTextIntoAPayload(): void
    {
        $post = $this->post('texte signalé et masqué', hidden: true);

        $this->service([['userAccountId' => 10, 'memberId' => 1]])
            ->postPublished($this->group(), $post, 1);

        $row = $this->rows()[0];
        $this->assertStringNotContainsString('signalé et masqué', $row['body']);
        $this->assertStringNotContainsString('signalé et masqué', $row['title']);
        $this->assertSame('Ce contenu a été masqué.', $row['body']);
    }

    // ---- type 2: a reply ----

    public function testAReplyNotifiesThePostsAuthorWithTheReplysOwnText(): void
    {
        $post = $this->post('question');
        $reply = $this->reply($post->id, 'Oui, on sera là !', accountId: self::ACTOR_ACCOUNT, memberId: 9);

        $this->service([['userAccountId' => self::AUTHOR_ACCOUNT, 'memberId' => self::AUTHOR_MEMBER]])
            ->replyReceived($this->group(), $post, $reply, 1);

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame('groups.reply_received', $rows[0]['type_id']);
        $this->assertSame(self::AUTHOR_ACCOUNT, $rows[0]['user_account_id']);
        $this->assertSame('Réponse à votre message — Louveteaux', $rows[0]['title']);
        $this->assertSame('Oui, on sera là !', $rows[0]['body']);
    }

    public function testReplyingToYourOwnPostNotifiesNobody(): void
    {
        $post = $this->post('question');
        $reply = $this->reply($post->id, 'je me réponds', accountId: self::AUTHOR_ACCOUNT, memberId: self::AUTHOR_MEMBER);

        $this->service([['userAccountId' => self::AUTHOR_ACCOUNT, 'memberId' => self::AUTHOR_MEMBER]])
            ->replyReceived($this->group(), $post, $reply, 1);

        $this->assertSame([], $this->rows());
    }

    public function testAHiddenReplyNeverCarriesItsOwnTextIntoAPayload(): void
    {
        $post = $this->post('question');
        $reply = $this->reply($post->id, 'réponse masquée', accountId: self::ACTOR_ACCOUNT, memberId: 9, hidden: true);

        $this->service([['userAccountId' => self::AUTHOR_ACCOUNT, 'memberId' => self::AUTHOR_MEMBER]])
            ->replyReceived($this->group(), $post, $reply, 1);

        $this->assertStringNotContainsString('masquée', $this->rows()[0]['body']);
    }

    public function testAReplyUnderAHiddenPostCarriesNoExcerptEither(): void
    {
        $post = $this->post('sujet masqué', hidden: true);
        $reply = $this->reply($post->id, 'réponse visible', accountId: self::ACTOR_ACCOUNT, memberId: 9);

        $this->service([['userAccountId' => self::AUTHOR_ACCOUNT, 'memberId' => self::AUTHOR_MEMBER]])
            ->replyReceived($this->group(), $post, $reply, 1);

        $this->assertSame('Ce contenu a été masqué.', $this->rows()[0]['body']);
    }

    // ---- type 3: a reaction ----

    /**
     * A reaction has no content of its own, so there is nothing to quote —
     * and quoting the post back to the person who wrote it would add
     * nothing. Asserted as "the post's text appears nowhere", which is
     * stronger than checking the exact wording.
     */
    public function testAReactionNotificationCarriesNoExcerptAtAll(): void
    {
        $post = $this->post('le texte de mon message');

        $this->service([['userAccountId' => self::AUTHOR_ACCOUNT, 'memberId' => self::AUTHOR_MEMBER]])
            ->reactionOnPost($this->group(), $post, self::ACTOR_ACCOUNT, 1);

        $row = $this->rows()[0];
        $this->assertSame('groups.reaction_received', $row['type_id']);
        $this->assertStringNotContainsString('le texte de mon message', $row['body']);
        $this->assertStringNotContainsString('le texte de mon message', $row['title']);
        $this->assertSame('Quelqu\'un a réagi à votre message.', $row['body']);
    }

    public function testReactingToYourOwnPostNotifiesNobody(): void
    {
        $post = $this->post('hello');

        $this->service([['userAccountId' => self::AUTHOR_ACCOUNT, 'memberId' => self::AUTHOR_MEMBER]])
            ->reactionOnPost($this->group(), $post, self::AUTHOR_ACCOUNT, 1);

        $this->assertSame([], $this->rows());
    }

    /**
     * The debounce, end to end: a burst of reactions from different people
     * on one post is one notification for its author.
     */
    public function testABurstOfReactionsOnOnePostProducesOneNotification(): void
    {
        $post = $this->post('populaire');
        $service = $this->service([['userAccountId' => self::AUTHOR_ACCOUNT, 'memberId' => self::AUTHOR_MEMBER]]);

        foreach (range(50, 61) as $actorAccountId) {
            $service->reactionOnPost($this->group(), $post, $actorAccountId, 1);
        }

        $this->assertCount(1, $this->rows());
    }

    public function testAReactionPastTheWindowProducesASecondNotification(): void
    {
        $post = $this->post('populaire');
        $service = $this->service([['userAccountId' => self::AUTHOR_ACCOUNT, 'memberId' => self::AUTHOR_MEMBER]]);

        $service->reactionOnPost($this->group(), $post, 50, 1);
        $service->reactionOnPost($this->group(), $post, 51, 1);
        $this->assertCount(1, $this->rows());

        $stmt = $this->pdo->prepare('UPDATE discussion_group_post_reaction_notices SET notified_at = ? WHERE post_id = ?');
        $stmt->execute([
            (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-2 hours')->format('Y-m-d H:i:s'),
            $post->id,
        ]);

        $service->reactionOnPost($this->group(), $post, 52, 1);
        $this->assertCount(2, $this->rows());
    }

    public function testAReactionOnAReplyNotifiesTheReplysAuthorAndDeepLinksToThePost(): void
    {
        $post = $this->post('sujet');
        $reply = $this->reply($post->id, 'ma réponse', accountId: self::AUTHOR_ACCOUNT, memberId: self::AUTHOR_MEMBER);

        $this->service([['userAccountId' => self::AUTHOR_ACCOUNT, 'memberId' => self::AUTHOR_MEMBER]])
            ->reactionOnReply($this->group(), $post, $reply, self::ACTOR_ACCOUNT, 1);

        $row = $this->rows()[0];
        $this->assertSame('Réaction à votre réponse — Louveteaux', $row['title']);
        $this->assertStringNotContainsString('ma réponse', $row['body']);
        // A reply has no page of its own — the post is what a member opens.
        $this->assertSame('/groups/' . $this->groupId . '/posts/' . $post->id, $row['url']);
    }

    // ---- type 4: a report ----

    /**
     * The body names neither the reporter nor the reported text: the
     * reporter is never revealed to anyone (prompt 9), and the text is
     * exactly what a report says might not belong on a screen.
     */
    public function testAReportNotifiesModeratorsWithoutNamingReporterOrText(): void
    {
        $post = $this->post('le texte signalé');

        $this->service([['userAccountId' => 70, 'memberId' => 7]])
            ->itemReported($this->group(), $post->id, 'post', self::ACTOR_ACCOUNT);

        $row = $this->rows()[0];
        $this->assertSame('groups.item_reported', $row['type_id']);
        $this->assertSame(70, $row['user_account_id']);
        $this->assertSame('Contenu signalé — Louveteaux', $row['title']);
        $this->assertStringNotContainsString('le texte signalé', $row['body']);
        $this->assertStringNotContainsString((string) self::ACTOR_ACCOUNT, $row['body']);
        $this->assertSame('/groups/' . $this->groupId . '/posts/' . $post->id, $row['url']);
    }

    /**
     * The conflict of interest prompt 12 closes: when the reported item
     * was written by one of the group's own moderators, the report has to
     * reach somebody outside that group's moderation.
     */
    public function testAReportOnAModeratorsOwnItemEscalatesToSiteAdmins(): void
    {
        $post = $this->post('le texte signalé');
        $resolver = $this->escalatingResolver(moderatorAuthor: true);

        $this->serviceWith($resolver)->itemReported(
            $this->group(),
            $post->id,
            'post',
            self::ACTOR_ACCOUNT,
            new ReportedAuthor(self::AUTHOR_ACCOUNT, self::AUTHOR_MEMBER)
        );

        $row = $this->rows()[0];
        $this->assertStringContainsString('(modérateur)', $row['title']);
        $this->assertStringContainsString('écrit par un modérateur', $row['body']);
        $this->assertStringContainsString('relecture indépendante', $row['body']);
    }

    public function testAReportOnAnOrdinaryMembersItemDoesNotEscalate(): void
    {
        $post = $this->post('le texte signalé');
        $resolver = $this->escalatingResolver(moderatorAuthor: false);

        $this->serviceWith($resolver)->itemReported(
            $this->group(),
            $post->id,
            'post',
            self::ACTOR_ACCOUNT,
            new ReportedAuthor(self::AUTHOR_ACCOUNT, self::AUTHOR_MEMBER)
        );

        $row = $this->rows()[0];
        $this->assertStringNotContainsString('(modérateur)', $row['title']);
        $this->assertStringContainsString('attend votre relecture', $row['body']);
    }

    /**
     * The item's author never hears that their own content was reported —
     * moderator or not. Telling them would tell them a report exists,
     * which is exactly what a reporter is promised will not happen.
     */
    public function testTheItemsAuthorNeverReceivesTheReportNotification(): void
    {
        $post = $this->post('le texte signalé');
        // The author IS in the moderator list, so only the exclusion can
        // keep them out of it.
        $resolver = $this->escalatingResolver(
            moderatorAuthor: true,
            moderators: [
                ['userAccountId' => self::AUTHOR_ACCOUNT, 'memberId' => self::AUTHOR_MEMBER],
                ['userAccountId' => 70, 'memberId' => 7],
            ]
        );

        $this->serviceWith($resolver)->itemReported(
            $this->group(),
            $post->id,
            'post',
            self::ACTOR_ACCOUNT,
            new ReportedAuthor(self::AUTHOR_ACCOUNT, self::AUTHOR_MEMBER)
        );

        $this->assertSame([70], array_column($this->rows(), 'user_account_id'));
    }

    public function testTheReporterIsStillExcludedAlongsideTheAuthor(): void
    {
        $post = $this->post('le texte signalé');
        $resolver = $this->escalatingResolver(
            moderatorAuthor: false,
            moderators: [
                ['userAccountId' => self::ACTOR_ACCOUNT, 'memberId' => 8],
                ['userAccountId' => self::AUTHOR_ACCOUNT, 'memberId' => self::AUTHOR_MEMBER],
                ['userAccountId' => 70, 'memberId' => 7],
            ]
        );

        $this->serviceWith($resolver)->itemReported(
            $this->group(),
            $post->id,
            'post',
            self::ACTOR_ACCOUNT,
            new ReportedAuthor(self::AUTHOR_ACCOUNT, self::AUTHOR_MEMBER)
        );

        $this->assertSame([70], array_column($this->rows(), 'user_account_id'));
    }

    public function testAReportedReplySaysItIsAReply(): void
    {
        $post = $this->post('sujet');

        $this->service([['userAccountId' => 70, 'memberId' => 7]])
            ->itemReported($this->group(), $post->id, 'reply', null);

        $this->assertStringContainsString('réponse', $this->rows()[0]['body']);
    }

    // ---- degradation ----

    /**
     * Every method here is fire-and-forget: a post that published
     * perfectly well is never rolled back because a notification could not
     * be sent.
     */
    public function testWithoutANotificationServiceNothingIsSentAndNothingThrows(): void
    {
        $post = $this->post('hello');
        $service = new GroupNotificationService(
            $this->createStub(GroupRecipientResolver::class),
            GroupsTestHelper::reactionNoticeThrottle($this->pdo, new SettingService(new SettingRepository($this->pdo))),
            null
        );

        $service->postPublished($this->group(), $post, 1);

        $this->assertSame([], $this->rows());
    }

    public function testADispatchFailureNeverEscapesToTheCaller(): void
    {
        $post = $this->post('hello');
        $failing = $this->createStub(NotificationService::class);
        $failing->method('dispatch')->willThrowException(new \RuntimeException('provider down'));

        $service = new GroupNotificationService(
            $this->resolverReturning([['userAccountId' => 10, 'memberId' => 1]]),
            GroupsTestHelper::reactionNoticeThrottle($this->pdo, new SettingService(new SettingRepository($this->pdo))),
            $failing
        );

        $service->postPublished($this->group(), $post, 1);

        $this->assertSame([], $this->rows());
    }

    // ---- fixtures ----

    /**
     * A resolver whose escalation answer and moderator list are both
     * fixed by the test — the two things itemReported() branches on.
     *
     * @param array<int, array{userAccountId: int, memberId: ?int}>|null $moderators
     */
    private function escalatingResolver(bool $moderatorAuthor, ?array $moderators = null): GroupRecipientResolver
    {
        $moderators ??= [['userAccountId' => 70, 'memberId' => 7]];

        $resolver = $this->createStub(GroupRecipientResolver::class);
        $resolver->method('isExplicitModerator')->willReturn($moderatorAuthor);
        $resolver->method('moderatorsFor')->willReturn($moderators);
        $resolver->method('moderatorsAndSiteAdminsFor')->willReturn($moderators);
        $resolver->method('excluding')->willReturnCallback(
            static function (array $list, array $excluded): array {
                $flip = array_flip($excluded);
                return array_values(array_filter($list, static fn(array $r): bool => !isset($flip[$r['userAccountId']])));
            }
        );

        return $resolver;
    }

    private function serviceWith(GroupRecipientResolver $resolver): GroupNotificationService
    {
        return new GroupNotificationService(
            $resolver,
            GroupsTestHelper::reactionNoticeThrottle($this->pdo, new SettingService(new SettingRepository($this->pdo))),
            $this->notifications
        );
    }

    /**
     * @param array<int, array{userAccountId: int, memberId: ?int}> $recipients
     */
    private function service(array $recipients): GroupNotificationService
    {
        return new GroupNotificationService(
            $this->resolverReturning($recipients),
            GroupsTestHelper::reactionNoticeThrottle($this->pdo, new SettingService(new SettingRepository($this->pdo))),
            $this->notifications
        );
    }

    /**
     * @param array<int, array{userAccountId: int, memberId: ?int}> $recipients
     */
    private function resolverReturning(array $recipients): GroupRecipientResolver
    {
        $resolver = $this->createStub(GroupRecipientResolver::class);
        $resolver->method('forGroup')->willReturn($recipients);
        $resolver->method('moderatorsFor')->willReturn($recipients);
        $resolver->method('isCurrentMember')->willReturn(true);
        $resolver->method('accountIdForMember')->willReturnCallback(
            static fn(int $memberId): ?int => $recipients[0]['userAccountId'] ?? null
        );
        $resolver->method('excluding')->willReturnCallback(
            static function (array $list, array $excluded): array {
                $flip = array_flip($excluded);
                return array_values(array_filter($list, static fn(array $r): bool => !isset($flip[$r['userAccountId']])));
            }
        );

        return $resolver;
    }

    private function group(): DiscussionGroup
    {
        $group = $this->groupRepo->findById($this->groupId);
        \assert($group !== null);

        return $group;
    }

    private function post(string $body, bool $hidden = false): Post
    {
        $id = GroupsTestHelper::createPostAt(
            $this->pdo,
            $this->groupId,
            $body,
            '2026-01-01 10:00:00',
            self::AUTHOR_ACCOUNT,
            self::AUTHOR_MEMBER
        );
        if ($hidden) {
            $this->postRepo->setHiddenAt($id, '2026-02-01 00:00:00');
        }

        $post = $this->postRepo->findById($id);
        \assert($post !== null);

        return $post;
    }

    private function reply(int $postId, string $body, int $accountId, int $memberId, bool $hidden = false): Reply
    {
        $id = GroupsTestHelper::createReplyAt($this->pdo, $postId, $body, '2026-01-01 10:05:00', $accountId, $memberId);
        if ($hidden) {
            $this->replyRepo->setHiddenAt($id, '2026-02-01 00:00:00');
        }

        $reply = $this->replyRepo->findById($id);
        \assert($reply !== null);

        return $reply;
    }

    /**
     * The rows core actually wrote, decrypted — title and body are
     * encrypted at rest, so reading them back is the only way to assert
     * what a payload really carried.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rows(): array
    {
        $rows = $this->pdo->query('SELECT * FROM notifications ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(fn(array $row): array => [
            'user_account_id' => (int) $row['user_account_id'],
            'type_id' => (string) $row['type_id'],
            'title' => $this->encryption->decrypt($row['title']),
            'body' => $this->encryption->decrypt($row['body']),
            'url' => $row['url'],
        ], $rows);
    }

    /**
     * The module's OWN declarations, read from module.json rather than
     * retyped here: a test that registers a hand-written copy would keep
     * passing after the manifest changed.
     *
     * @return array<int, array{id: string, label: string, description: string, group: string, role_min: string, channels: array{in_app: string, push: string, email: string}}>
     */
    private function manifestNotifications(): array
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/modules/groups/module.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        return $manifest['notifications'];
    }
}
