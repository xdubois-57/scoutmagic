<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Core\Security\Role;
use Modules\Gallery\Api\GalleryException;
use Modules\Groups\Api\GroupPublishException;
use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\PostRepository;
use Modules\Groups\Service\GroupAccessService;
use Modules\Groups\Service\GroupListItem;
use Modules\Groups\Service\GroupListService;
use Modules\Groups\Service\GroupNotificationService;
use Modules\Groups\Service\GroupPublisherService;
use Modules\Groups\Service\GroupSessionContext;
use Modules\Groups\Service\GroupSessionContextFactory;
use Modules\Groups\Service\GroupsException;
use Modules\Groups\Service\PostLinkService;
use Modules\Groups\Service\PostMediaService;
use Modules\Groups\Service\PostPermission;
use Modules\Groups\Service\PostService;
use PHPUnit\Framework\TestCase;

/**
 * Publishing in a group for another module goes through the composer's
 * own steps, in its order, with nothing skipped — and all or nothing.
 */
final class GroupPublisherServiceTest extends TestCase
{
    private DiscussionGroup $lutins;
    private DiscussionGroup $closed;
    private GroupAccessService $access;
    private PostService $posts;
    private PostMediaService $media;
    private PostLinkService $links;
    private PostRepository $postRepository;
    private GroupNotificationService $notifications;

    protected function setUp(): void
    {
        $this->lutins = new DiscussionGroup(3, 'Staff Lutins', 7, null, null, '2026-01-01 10:00:00', 1, '2026-01-01 09:00:00');
        $this->closed = new DiscussionGroup(4, 'Staff Pionniers', 7, null, '2026-02-01', '2026-01-01 10:00:00', 1, '2026-01-01 09:00:00');

        $this->access = $this->createStub(GroupAccessService::class);
        $this->access->method('canRead')->willReturn(true);
        $this->access->method('canPost')->willReturnCallback(
            fn (DiscussionGroup $group): PostPermission => $group->id === 3
                ? PostPermission::allow()
                : PostPermission::deny(PostPermission::REASON_CLOSED, 'Ce groupe est fermé.')
        );
        $this->access->method('authorMemberIdFor')->willReturn(42);
        $this->posts = $this->createStub(PostService::class);
        $this->media = $this->createStub(PostMediaService::class);
        $this->links = $this->createStub(PostLinkService::class);
        $this->postRepository = $this->createStub(PostRepository::class);
        $this->notifications = $this->createStub(GroupNotificationService::class);
    }

    public function testOnlyTheGroupsThePersonMayPostInAreOffered(): void
    {
        $groups = $this->service()->postableGroups('chef@unite.be', 'chief', 7);

        $this->assertCount(1, $groups);
        $this->assertSame([3, 'Staff Lutins', 6], [$groups[0]->id, $groups[0]->name, $groups[0]->memberCount]);
    }

    public function testAPostIsAnOrdinaryOneWithItsImageItsLinkAndItsNotification(): void
    {
        $this->posts = $posts = $this->createMock(PostService::class);
        $posts->expects($this->once())->method('create')->with($this->lutins, 7, 42, 'Les photos sont en ligne')
            ->willReturn(99);
        $this->media = $media = $this->createMock(PostMediaService::class);
        $media->expects($this->once())->method('addMedia')->with(
            $this->lutins,
            99,
            $this->callback(static fn (array $files): bool => count($files) === 1
                && file_get_contents($files[0]['tmp_name']) === 'JPEG'),
            7
        );
        $this->links = $links = $this->createMock(PostLinkService::class);
        $links->expects($this->once())->method('attach')
            ->with($this->lutins, 99, 'https://unite.example/gallery/3', 42, 7);
        $this->postRepository->method('findById')->willReturn(null);

        $post = $this->service()->publish(3, 'chef@unite.be', 'chief', 7, 'Les photos sont en ligne', 'JPEG', 'https://unite.example/gallery/3');

        $this->assertSame(99, $post->postId);
        $this->assertSame('/groups/3#post-99', $post->path);
    }

    public function testAGroupThatRefusesSaysItsOwnSentenceAndNothingIsWritten(): void
    {
        $this->posts = $posts = $this->createMock(PostService::class);
        $posts->expects($this->never())->method('create');

        $this->expectException(GroupPublishException::class);
        $this->expectExceptionMessage('Ce groupe est fermé.');

        $this->service()->publish(4, 'chef@unite.be', 'chief', 7, 'Texte', 'JPEG', null);
    }

    public function testTheRateLimitAndTheModerationStillApply(): void
    {
        $this->posts->method('create')->willThrowException(
            new GroupsException('Vous publiez trop vite.', GroupsException::TYPE_RATE_LIMITED)
        );

        $this->expectException(GroupPublishException::class);
        $this->expectExceptionMessage('Vous publiez trop vite.');

        $this->service()->publish(3, 'chef@unite.be', 'chief', 7, 'Texte', 'JPEG', null);
    }

    public function testAnImageTheGalleryRefusesUndoesThePost(): void
    {
        $this->posts->method('create')->willReturn(99);
        $this->media = $media = $this->createMock(PostMediaService::class);
        $media->method('addMedia')->willThrowException(new GalleryException('Image trop lourde.'));
        $media->expects($this->once())->method('deleteAllForPost')->with($this->lutins, 99);
        $this->postRepository = $postRepository = $this->createMock(PostRepository::class);
        $postRepository->expects($this->once())->method('delete')->with(99);
        $this->links = $links = $this->createMock(PostLinkService::class);
        $links->expects($this->never())->method('attach');

        $this->expectException(GroupPublishException::class);
        $this->expectExceptionMessage('Image trop lourde.');

        $this->service()->publish(3, 'chef@unite.be', 'chief', 7, 'Texte', 'JPEG', 'https://unite.example');
    }

    private function service(): GroupPublisherService
    {
        $groups = $this->createStub(GroupRepository::class);
        $groups->method('findById')->willReturnCallback(fn (int $id): ?DiscussionGroup => match ($id) {
            3 => $this->lutins,
            4 => $this->closed,
            default => null,
        });
        $lists = $this->createStub(GroupListService::class);
        $lists->method('findCurrent')->willReturn([
            new GroupListItem($this->lutins, false, false, [], false, 6),
            new GroupListItem($this->closed, false, false, [], false, 5),
        ]);
        $contexts = $this->createStub(GroupSessionContextFactory::class);
        $contexts->method('build')->willReturn(new GroupSessionContext(7, Role::CHIEF, [42], 1, true));

        return new GroupPublisherService(
            $groups,
            $this->postRepository,
            $this->access,
            $lists,
            $this->posts,
            $this->media,
            $this->links,
            $contexts,
            $this->notifications
        );
    }
}
