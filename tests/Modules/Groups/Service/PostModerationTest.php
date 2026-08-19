<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Modules\Gallery\Api\DelegatedAlbumManager;
use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\PostMediaRepository;
use Modules\Groups\Repository\PostRepository;
use Modules\Groups\Repository\ReplyRepository;
use Modules\Groups\Service\GroupActivityService;
use Modules\Groups\Service\GroupsException;
use Modules\Groups\Service\ModerationService;
use Modules\Groups\Service\PostMediaService;
use Modules\Groups\Service\PostService;
use Modules\Groups\Service\RateLimitService;
use Modules\Groups\Service\ReplyService;
use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmResponse;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * Publishing a post or a reply with the a-priori check and the rate limit
 * in the path — i.e. the two properties that matter once they are wired
 * together rather than in isolation:
 *
 * - a technical failure of the AI publishes the message anyway;
 * - the rate limit is spent BEFORE the LLM is asked, so an abusive burst
 *   never reaches (or bills) a provider.
 *
 * @group database
 */
#[Group('database')]
class PostModerationTest extends TestCase
{
    private \PDO $pdo;
    private GroupRepository $groupRepo;
    private PostRepository $postRepo;
    private ReplyRepository $replyRepo;
    private int $groupId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);

        $this->groupRepo = new GroupRepository($this->pdo);
        $this->postRepo = new PostRepository($this->pdo);
        $this->replyRepo = new ReplyRepository($this->pdo);
        $this->groupId = $this->groupRepo->create('Louveteaux', null, null, 1);
    }

    public function testAnAcceptedPostIsStored(): void
    {
        $service = $this->postService($this->moderation(['flagged' => false]));

        $id = $service->create($this->group(), 7, 3, 'Super sortie samedi !');

        $this->assertSame('Super sortie samedi !', $this->postRepo->findById($id)->body);
    }

    public function testAFlaggedPostIsRefusedAndNothingIsWritten(): void
    {
        $service = $this->postService($this->moderation([
            'flagged' => true,
            'reason' => 'Ce message vise une personne.',
            'suggestion' => 'Reformulation.',
        ]));

        try {
            $service->create($this->group(), 7, 3, 'texte refusé');
            $this->fail('A flagged post must not be created.');
        } catch (GroupsException $e) {
            $this->assertSame(GroupsException::TYPE_OFFENSIVE, $e->type);
            $this->assertSame('Reformulation.', $e->suggestion);
        }

        $this->assertSame(0, $this->countPosts());
    }

    /**
     * The whole point of failing open: the AI is a courtesy check, not the
     * line of defence. A provider having a bad day must not stop a group
     * from talking.
     */
    public function testATechnicalFailurePublishesTheMessageAnyway(): void
    {
        $connector = $this->createStub(LlmConnectorInterface::class);
        $connector->method('isAvailable')->willReturn(true);
        $connector->method('complete')->willThrowException(new LlmException('timeout'));

        $service = $this->postService(new ModerationService($this->settingService(), $connector));

        $id = $service->create($this->group(), 7, 3, 'un message quelconque');

        $this->assertSame('un message quelconque', $this->postRepo->findById($id)->body);
    }

    public function testAMalformedAnswerPublishesTheMessageAnyway(): void
    {
        $service = $this->postService($this->moderation(null));

        $id = $service->create($this->group(), 7, 3, 'un message quelconque');

        $this->assertSame(1, $this->countPosts());
        $this->assertSame('un message quelconque', $this->postRepo->findById($id)->body);
    }

    public function testWithoutTheLlmModuleEveryPostGoesThrough(): void
    {
        $service = $this->postService(new ModerationService($this->settingService(), null));

        $service->create($this->group(), 7, 3, 'un message quelconque');

        $this->assertSame(1, $this->countPosts());
    }

    public function testWithNoModerationServiceAtAllEveryPostGoesThrough(): void
    {
        $service = $this->postService(null);

        $service->create($this->group(), 7, 3, 'un message quelconque');

        $this->assertSame(1, $this->countPosts());
    }

    /**
     * An admin who turned the switch off gets no provider call — not a
     * call whose verdict is thrown away.
     */
    public function testTheSettingOffMeansNoLlmCallAtAll(): void
    {
        $connector = $this->createMock(LlmConnectorInterface::class);
        $connector->method('isAvailable')->willReturn(true);
        $connector->expects($this->never())->method('complete');

        $this->setSetting(ModerationService::SETTING_ENABLED, '0');
        $service = $this->postService(new ModerationService($this->settingService(), $connector));

        $service->create($this->group(), 7, 3, 'un message quelconque');

        $this->assertSame(1, $this->countPosts());
    }

    /**
     * Ordering, not just presence: the cheap check has to be spent first,
     * or a runaway script would still cost one provider call per attempt.
     */
    public function testTheRateLimitIsCheckedBeforeTheLlmIsAsked(): void
    {
        $calls = 0;
        $connector = $this->createStub(LlmConnectorInterface::class);
        $connector->method('isAvailable')->willReturn(true);
        $connector->method('complete')->willReturnCallback(function () use (&$calls): LlmResponse {
            $calls++;
            return new LlmResponse('{}', ['flagged' => false], 1, 1);
        });

        $service = $this->postService(new ModerationService($this->settingService(), $connector));

        $attempts = 0;
        try {
            for ($i = 0; $i < 60; $i++) {
                $attempts++;
                $service->create($this->group(), 7, 3, 'message ' . $i);
            }
            $this->fail('The rate limit must eventually refuse a post.');
        } catch (GroupsException $e) {
            $this->assertSame(GroupsException::TYPE_RATE_LIMITED, $e->type);
        }

        // Every refused attempt after the limit costs nothing: the number
        // of provider calls equals the number of posts actually created,
        // one fewer than the attempts made.
        $this->assertSame($attempts - 1, $calls);
        $this->assertSame($calls, $this->countPosts());
    }

    public function testEditingAPostGoesThroughTheSameCheck(): void
    {
        $service = $this->postService($this->moderation(['flagged' => false]));
        $id = $service->create($this->group(), 7, 3, 'innocent');

        // Otherwise "post something bland, then edit it" would walk
        // straight around moderation.
        $flagging = $this->postService($this->moderation([
            'flagged' => true,
            'reason' => 'raison',
            'suggestion' => null,
        ]));

        $this->expectException(GroupsException::class);
        try {
            $flagging->edit($this->postRepo->findById($id), 'texte refusé');
        } finally {
            $this->assertSame('innocent', $this->postRepo->findById($id)->body);
        }
    }

    public function testAFlaggedReplyIsRefusedAndNothingIsWritten(): void
    {
        $postId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'hello', '2026-01-01 10:00:00');
        $service = $this->replyService($this->moderation([
            'flagged' => true,
            'reason' => 'raison',
            'suggestion' => 'Reformulation.',
        ]));

        try {
            $service->create($this->group(), $postId, 7, 3, 'texte refusé');
            $this->fail('A flagged reply must not be created.');
        } catch (GroupsException $e) {
            $this->assertSame(GroupsException::TYPE_OFFENSIVE, $e->type);
        }

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM discussion_group_replies')->fetchColumn());
    }

    public function testAnAcceptedReplyIsStored(): void
    {
        $postId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'hello', '2026-01-01 10:00:00');
        $service = $this->replyService($this->moderation(['flagged' => false]));

        $id = $service->create($this->group(), $postId, 7, 3, 'D\'accord avec toi');

        $this->assertSame('D\'accord avec toi', $this->replyRepo->findById($id)->body);
    }

    private function postService(?ModerationService $moderation): PostService
    {
        return new PostService(
            $this->postRepo,
            new GroupActivityService($this->groupRepo, $this->postRepo),
            GroupsTestHelper::rateLimitService($this->pdo),
            $moderation
        );
    }

    private function replyService(?ModerationService $moderation): ReplyService
    {
        return new ReplyService(
            $this->replyRepo,
            new GroupActivityService($this->groupRepo, $this->postRepo),
            new PostMediaService(
                $this->createStub(DelegatedAlbumManager::class),
                new PostMediaRepository($this->pdo),
                $this->groupRepo,
                $this->replyRepo
            ),
            GroupsTestHelper::rateLimitService($this->pdo),
            $moderation
        );
    }

    /**
     * @param array<string, mixed>|null $parsed
     */
    private function moderation(?array $parsed): ModerationService
    {
        $connector = $this->createStub(LlmConnectorInterface::class);
        $connector->method('isAvailable')->willReturn(true);
        $connector->method('complete')->willReturn(new LlmResponse('{}', $parsed, 1, 1));

        return new ModerationService($this->settingService(), $connector);
    }

    private function settingService(): SettingService
    {
        return new SettingService(new SettingRepository($this->pdo));
    }

    private function setSetting(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (module_id, setting_key, setting_value, setting_type, label, description)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(['groups', $key, $value, 'boolean', 'Modération IA', 'Modération IA']);
    }

    private function group(): DiscussionGroup
    {
        return $this->groupRepo->findById($this->groupId);
    }

    private function countPosts(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM discussion_group_posts')->fetchColumn();
    }
}
