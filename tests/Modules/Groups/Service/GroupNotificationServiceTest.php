<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalService;
use Core\Member\MemberEmailRepository;
use Core\Member\SectionMembershipRepository;
use Core\Notification\NotificationPreferenceRepository;
use Core\Notification\NotificationRepository;
use Core\Notification\NotificationService;
use Core\Notification\PushSubscriptionRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\EncryptionService;
use Core\Security\RoleResolver;
use Core\Security\UserAccountRepository;
use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Repository\GroupMemberRepository;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\GroupSectionRepository;
use Modules\Groups\Repository\Post;
use Modules\Groups\Repository\PostRepository;
use Modules\Groups\Repository\Reply;
use Modules\Groups\Repository\ReplyRepository;
use Modules\Groups\Service\GroupNotificationService;
use Modules\Groups\Service\GroupRecipientResolver;
use Modules\Groups\Support\ReportedAuthor;
use Modules\Groups\Support\Timestamps;
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
     * The author of a message is never told about their own message.
     *
     * NotificationService::dispatch() only suppresses the actor's push and
     * email — the in-app row is still written, and that row is what feeds
     * the unread badge and the home page's « Du nouveau dans vos groupes ».
     * So the exclusion has to happen here, in the module, before dispatch()
     * ever sees the list: core's own behaviour is shared with the calendar,
     * the gallery, the camps and the finances and must not move.
     */
    public function testTheAuthorIsNotNotifiedAboutTheirOwnPost(): void
    {
        $post = $this->post('Je publie chez moi.');

        $this->service([
            ['userAccountId' => self::AUTHOR_ACCOUNT, 'memberId' => self::AUTHOR_MEMBER],
        ])->postPublished($this->group(), $post, 1);

        $this->assertSame([], $this->rows());
    }

    public function testTheOtherMembersStillReceiveThePostTheAuthorIsExcludedFrom(): void
    {
        $post = $this->post('On part au camp samedi !');

        $this->service([
            ['userAccountId' => 10, 'memberId' => 1],
            ['userAccountId' => self::AUTHOR_ACCOUNT, 'memberId' => self::AUTHOR_MEMBER],
            ['userAccountId' => 11, 'memberId' => 2],
        ])->postPublished($this->group(), $post, 1);

        $rows = $this->rows();
        $this->assertSame([10, 11], array_map(
            static fn(array $row): int => (int) $row['user_account_id'],
            $rows
        ));
        $this->assertSame('Nouveau message — Louveteaux', $rows[0]['title']);
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
        // Not « Réponse à votre message » any more: for an earlier replier
        // it is not their message (issue #1349).
        $this->assertSame('Nouvelle réponse — Louveteaux', $rows[0]['title']);
        $this->assertSame('Oui, on sera là !', $rows[0]['body']);
        $this->assertSame('/groups/' . $this->groupId . '/posts/' . $post->id, $rows[0]['url']);
    }

    /** The author answering under a post nobody else has answered tells nobody: there is nobody else. */
    public function testReplyingToYourOwnUnansweredPostNotifiesNobody(): void
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

    // ---- type 2: who a reply reaches (issue #1349) ----
    //
    // Everything below runs on the REAL recipient resolver over real
    // accounts and members: who may still open the group is the whole
    // question, and a stub would only repeat the answer it was given.

    /**
     * The validated target: A posts; B answers — A hears; C answers — A
     * and B hear; A answers — B and C hear. Before, B heard nothing from
     * C, and A answering told nobody at all.
     */
    public function testEveryoneWhoWroteInTheThreadHearsOfTheNextReply(): void
    {
        $people = $this->participants(['a', 'b', 'c']);
        $post = $this->postBy($people['a']);
        $service = $this->realService();

        $service->replyReceived($this->group(), $post, $this->replyBy($post, $people['b']), $this->yearId);
        $this->assertSame([$people['a']['account']], $this->recipientsOfLastReply());

        $service->replyReceived($this->group(), $post, $this->replyBy($post, $people['c']), $this->yearId);
        $this->assertSame($this->sorted([$people['a']['account'], $people['b']['account']]), $this->recipientsOfLastReply());

        $service->replyReceived($this->group(), $post, $this->replyBy($post, $people['a']), $this->yearId);
        $this->assertSame($this->sorted([$people['b']['account'], $people['c']['account']]), $this->recipientsOfLastReply());
    }

    /**
     * One notification per account for one reply: however many replies
     * it wrote, and whether or not it also wrote the post.
     */
    public function testEachParticipantIsNotifiedOnceWhateverTheyWrote(): void
    {
        $people = $this->participants(['a', 'b', 'd']);
        $post = $this->postBy($people['a']);
        $this->replyBy($post, $people['b']);
        $this->replyBy($post, $people['b']);
        $this->replyBy($post, $people['a']);

        $this->realService()->replyReceived($this->group(), $post, $this->replyBy($post, $people['d']), $this->yearId);

        $this->assertSame($this->sorted([$people['a']['account'], $people['b']['account']]), $this->recipientsOfLastReply());
    }

    /**
     * Reading a thread, reacting to it, or answering ANOTHER post makes
     * nobody a participant here — and the rest of the group is not told
     * about every reply.
     */
    public function testReadersReactorsAndOtherThreadsAreNotParticipants(): void
    {
        $people = $this->participants(['a', 'c', 'reader', 'reactor', 'elsewhere']);
        $post = $this->postBy($people['a']);
        $otherPost = $this->postBy($people['reader']);
        $this->replyBy($otherPost, $people['elsewhere']);
        $this->pdo->prepare(
            'INSERT INTO discussion_group_post_reactions (post_id, member_id, reaction_key, created_at) VALUES (?, ?, ?, ?)'
        )->execute([$post->id, $people['reactor']['member'], 'thumbs_up', '2026-01-01 10:01:00']);
        $this->pdo->prepare(
            'INSERT INTO discussion_group_reads (group_id, member_id, last_read_at) VALUES (?, ?, ?)'
        )->execute([$this->groupId, $people['reader']['member'], '2026-01-01 10:02:00']);

        $this->realService()->replyReceived($this->group(), $post, $this->replyBy($post, $people['c']), $this->yearId);

        $this->assertSame([$people['a']['account']], $this->recipientsOfLastReply());
    }

    /** Somebody who answered and has since left the group cannot open the link: not told. */
    public function testAParticipantWhoLeftTheGroupIsNotNotified(): void
    {
        $people = $this->participants(['a', 'b', 'c']);
        $post = $this->postBy($people['a']);
        $this->replyBy($post, $people['b']);
        (new GroupMemberRepository($this->pdo))->remove($this->groupId, $people['b']['member']);

        $this->realService()->replyReceived($this->group(), $post, $this->replyBy($post, $people['c']), $this->yearId);

        $this->assertSame([$people['a']['account']], $this->recipientsOfLastReply());
    }

    /**
     * The maintainer's decision of 21 November 2026: the ACCOUNT that
     * wrote is told, not the account behind the membership it signed with.
     * A teenager answers from their own secondary address under their
     * membership, whose Desk address is a parent's; the next reply reaches
     * the teenager, and not the parent who never wrote a word here.
     */
    public function testTheAccountThatWroteIsNotifiedNotTheMembershipsDeskAccount(): void
    {
        $people = $this->participants(['a', 'c']);
        $parent = GroupsTestHelper::seedAccountWithMembers(
            $this->pdo, 'parent@test.be', 'Paul', 'Parent', [['first_name' => 'Théo']], $this->yearId
        );
        $child = $parent['member_ids'][0];
        (new GroupMemberRepository($this->pdo))->add($this->groupId, $child);
        $teen = $this->accountWithSecondaryAddress('theo@test.be', $child);

        $post = $this->postBy($people['a']);
        $this->replyBy($post, ['account' => $teen, 'member' => $child]);

        $this->realService()->replyReceived($this->group(), $post, $this->replyBy($post, $people['c']), $this->yearId);

        $recipients = $this->recipientsOfLastReply();
        $this->assertSame($this->sorted([$people['a']['account'], $teen]), $recipients);
        $this->assertNotContains($parent['account_id'], $recipients);
    }

    /**
     * Issue #158 carried over: a site admin writes in a group none of
     * their members belongs to, signing with a borrowed member. They can
     * still open the group, so they still hear the answers — by account,
     * the way every participant now does.
     */
    public function testASiteAdminWhoWroteWithABorrowedMemberIsStillNotified(): void
    {
        $people = $this->participants(['b']);
        $admin = GroupsTestHelper::seedAccountWithMembers(
            $this->pdo, 'admin@test.be', 'Ada', 'Admin', [['first_name' => 'Enfant']], $this->yearId
        );
        $post = $this->postBy(['account' => $admin['account_id'], 'member' => $admin['member_ids'][0]]);

        $this->realService(adminEmail: 'admin@test.be')
            ->replyReceived($this->group(), $post, $this->replyBy($post, $people['b']), $this->yearId);

        $this->assertSame([$admin['account_id']], $this->recipientsOfLastReply());
    }

    /** The same author without the admin role cannot open the group: not told. */
    public function testAnOrdinaryAuthorOutsideTheGroupIsNotNotified(): void
    {
        $people = $this->participants(['b']);
        $outsider = GroupsTestHelper::seedAccountWithMembers(
            $this->pdo, 'outsider@test.be', 'Olga', 'Out', [['first_name' => 'Enfant']], $this->yearId
        );
        $post = $this->postBy(['account' => $outsider['account_id'], 'member' => $outsider['member_ids'][0]]);

        $this->realService()->replyReceived($this->group(), $post, $this->replyBy($post, $people['b']), $this->yearId);

        $this->assertSame([], $this->recipientsOfLastReply());
    }

    /**
     * Every earlier replier, not the last page a thread opens on
     * (ReplyService::PAGE_SIZE): the oldest ones took part as much as the
     * newest.
     */
    public function testEveryEarlierReplierCountsBeyondTheVisiblePage(): void
    {
        $names = ['a', 'z'];
        for ($i = 1; $i <= 8; $i++) {
            $names[] = 'r' . $i;
        }
        $people = $this->participants($names);
        $post = $this->postBy($people['a']);
        $expected = [$people['a']['account']];
        for ($i = 1; $i <= 8; $i++) {
            $this->replyBy($post, $people['r' . $i]);
            $expected[] = $people['r' . $i]['account'];
        }

        $this->realService()->replyReceived($this->group(), $post, $this->replyBy($post, $people['z']), $this->yearId);

        $this->assertSame($this->sorted($expected), $this->recipientsOfLastReply());
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
            Timestamps::at('-2 hours'),
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

    // ---- type 5: a mention ----

    public function testAMentionNotifiesEachNamedMemberWithTheMessageText(): void
    {
        $post = $this->post('Peux-tu apporter les tentes @Akéla ?');

        $this->service([['userAccountId' => 55, 'memberId' => 9]])
            ->mentioned($this->group(), $post->id, [9], $post->body, false, self::ACTOR_ACCOUNT, 1);

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame('groups.mentioned', $rows[0]['type_id']);
        $this->assertSame(55, $rows[0]['user_account_id']);
        $this->assertSame('Vous êtes cité — Louveteaux', $rows[0]['title']);
        $this->assertSame('Peux-tu apporter les tentes @Akéla ?', $rows[0]['body']);
        $this->assertSame('/groups/' . $this->groupId . '/posts/' . $post->id, $rows[0]['url']);
    }

    public function testAMessageNamingNobodyNotifiesNobody(): void
    {
        $post = $this->post('Rendez-vous samedi');

        $this->service([['userAccountId' => 55, 'memberId' => 9]])
            ->mentioned($this->group(), $post->id, [], $post->body, false, self::ACTOR_ACCOUNT, 1);

        $this->assertSame([], $this->rows());
    }

    /**
     * Membership is re-checked at dispatch, exactly as it is for every
     * other audience here: a name that resolved when the message was
     * written must not notify somebody who has since left the group — the
     * deep link would 404 for them.
     */
    public function testAMentionOfSomebodyNoLongerInTheGroupSendsNothing(): void
    {
        $post = $this->post('Merci @Akéla');
        // A stub of its own rather than resolverReturning(): that helper
        // already answers isCurrentMember() with true, and a second
        // willReturn() on the same method does not replace the first.
        $resolver = $this->createStub(GroupRecipientResolver::class);
        $resolver->method('isCurrentMember')->willReturn(false);

        $this->serviceWith($resolver)
            ->mentioned($this->group(), $post->id, [9], $post->body, false, self::ACTOR_ACCOUNT, 1);

        $this->assertSame([], $this->rows());
    }

    /**
     * Same rule as every other payload in this class: a hidden item's
     * text never reaches a lock screen, whoever it named.
     */
    public function testAMentionInAHiddenItemCarriesNoText(): void
    {
        $post = $this->post('propos masqués @Akéla', hidden: true);

        $this->service([['userAccountId' => 55, 'memberId' => 9]])
            ->mentioned($this->group(), $post->id, [9], $post->body, true, self::ACTOR_ACCOUNT, 1);

        $this->assertSame('Ce contenu a été masqué.', $this->rows()[0]['body']);
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

    // ---- an author the group's own membership does not contain ----

    /**
     * The same author, the same borrowed member, one item down: a reaction
     * to a REPLY they wrote. Resolved from the reply's own two identities,
     * so the answer cannot differ from the one a post gets.
     */
    public function testASiteAdminIsNotifiedOfAReactionToAReplySignedByABorrowedMember(): void
    {
        $post = $this->post('sujet');
        $reply = $this->reply($post->id, 'ma réponse', accountId: self::AUTHOR_ACCOUNT, memberId: self::AUTHOR_MEMBER);

        $this->serviceWith($this->resolverForAuthorOutsideTheGroup(authorIsSiteAdmin: true))
            ->reactionOnReply($this->group(), $post, $reply, self::ACTOR_ACCOUNT, 1);

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame(self::AUTHOR_ACCOUNT, $rows[0]['user_account_id']);
        $this->assertSame('Réaction à votre réponse — Louveteaux', $rows[0]['title']);
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

    private int $yearId = 0;

    /**
     * One account per name, each with one member explicitly invited to the
     * group, joined the way production joins them (by the address's blind
     * index) — for the current scout year.
     *
     * @param string[] $names
     * @return array<string, array{account: int, member: int}>
     */
    private function participants(array $names): array
    {
        $this->yearId = GroupsTestHelper::createScoutYear($this->pdo, '2025-2026', true);
        $members = new GroupMemberRepository($this->pdo);

        $people = [];
        foreach ($names as $name) {
            $seeded = GroupsTestHelper::seedAccountWithMembers(
                $this->pdo, $name . '@test.be', ucfirst($name), 'Test', [['first_name' => ucfirst($name)]], $this->yearId
            );
            $members->add($this->groupId, $seeded['member_ids'][0]);
            $people[$name] = ['account' => $seeded['account_id'], 'member' => $seeded['member_ids'][0]];
        }

        return $people;
    }

    /** An account under $email, reaching $memberId through a confirmed secondary address. */
    private function accountWithSecondaryAddress(string $email, int $memberId): int
    {
        $this->pdo->prepare(
            'INSERT INTO member_emails (member_id, email_encrypted, email_blind_index, source, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $memberId,
            $this->encryption->encrypt($email, 'member_emails.email'),
            $this->encryption->blindIndex($email, 'email'),
            'manual',
            'valid',
            '2026-01-01 00:00:00',
        ]);
        $this->pdo->prepare(
            'INSERT INTO user_accounts (email_encrypted, email_blind_index, created_at) VALUES (?, ?, ?)'
        )->execute([
            $this->encryption->encrypt($email, 'user_accounts.email'),
            $this->encryption->blindIndex($email, 'email'),
            '2026-01-01 00:00:00',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array{account: int, member: int} $author */
    private function postBy(array $author): Post
    {
        $id = GroupsTestHelper::createPostAt(
            $this->pdo,
            $this->groupId,
            'Qui vient samedi ?',
            '2026-01-01 10:00:00',
            $author['account'],
            $author['member']
        );
        $post = $this->postRepo->findById($id);
        \assert($post !== null);

        return $post;
    }

    /** @param array{account: int, member: int} $author */
    private function replyBy(Post $post, array $author): Reply
    {
        return $this->reply($post->id, 'Moi !', accountId: $author['account'], memberId: $author['member']);
    }

    /**
     * The accounts the most recent dispatch wrote a reply notification
     * for, sorted — then forgotten, so the next call reads the next one.
     *
     * @return int[]
     */
    private function recipientsOfLastReply(): array
    {
        $accounts = array_map(
            static fn(array $row): int => $row['user_account_id'],
            array_values(array_filter(
                $this->rows(),
                static fn(array $row): bool => $row['type_id'] === 'groups.reply_received'
            ))
        );
        $this->pdo->exec('DELETE FROM notifications');

        return $this->sorted($accounts);
    }

    /**
     * @param int[] $ids
     * @return int[]
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    /**
     * The service over the real resolver and the real reply repository —
     * what the composition root wires. $adminEmail is the one address the
     * role resolver calls a site admin.
     */
    private function realService(?string $adminEmail = null): GroupNotificationService
    {
        $roleResolver = $this->createStub(RoleResolver::class);
        $roleResolver->method('resolve')->willReturnCallback(
            static fn(string $email): string => $email === $adminEmail ? 'admin' : 'identified'
        );

        return new GroupNotificationService(
            new GroupRecipientResolver(
                new GroupMemberRepository($this->pdo),
                new GroupSectionRepository($this->pdo),
                new SectionMembershipRepository($this->pdo),
                new MemberYearRepository($this->pdo),
                new MemberEmailRepository($this->pdo, $this->encryption),
                new UserAccountRepository($this->pdo, $this->encryption),
                $roleResolver,
                new ScoutYearService($this->pdo)
            ),
            GroupsTestHelper::reactionNoticeThrottle($this->pdo, new SettingService(new SettingRepository($this->pdo))),
            $this->notifications,
            $this->replyRepo
        );
    }

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
        $resolver->method('isExplicitModeratorAccount')->willReturn($moderatorAuthor);
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

    /**
     * A resolver for the one case the group's own membership cannot
     * describe: the item's author is not in the group at all. What decides
     * whether they still hear about their own message is then the account
     * — a site admin can open every group, anybody else cannot.
     */
    private function resolverForAuthorOutsideTheGroup(bool $authorIsSiteAdmin): GroupRecipientResolver
    {
        $resolver = $this->createStub(GroupRecipientResolver::class);
        $resolver->method('isCurrentMember')->willReturn(false);
        $resolver->method('accountIdForMember')->willReturn(null);
        $resolver->method('isSiteAdminAccount')->willReturn($authorIsSiteAdmin);

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
        // The real forGroupExcluding() is forGroup() piped through
        // excluding(); stubbed the same way here, so a test that puts the
        // post's own author inside $recipients sees them dropped exactly
        // as the resolver would drop them.
        $resolver->method('forGroupExcluding')->willReturnCallback(
            static function (DiscussionGroup $group, int $scoutYearId, array $excludedAccountIds) use ($recipients): array {
                $flip = array_flip($excludedAccountIds);

                return array_values(array_filter(
                    $recipients,
                    static fn(array $r): bool => !isset($flip[$r['userAccountId']])
                ));
            }
        );
        $resolver->method('moderatorsFor')->willReturn($recipients);
        // The real one answers which of the accounts handed to it can
        // still open the group; here, those of $recipients.
        $resolver->method('accountsThatCanRead')->willReturnCallback(
            static function (DiscussionGroup $group, int $scoutYearId, array $accountIds) use ($recipients): array {
                $wanted = array_flip($accountIds);

                return array_values(array_filter(
                    $recipients,
                    static fn(array $r): bool => isset($wanted[$r['userAccountId']])
                ));
            }
        );
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
            'title' => $this->encryption->decrypt($row['title'], 'notifications.title'),
            'body' => $this->encryption->decrypt($row['body'], 'notifications.body'),
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
