<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Modules\Gallery\Api\GalleryException;
use Modules\Groups\Api\GroupPublishException;
use Modules\Groups\Api\GroupPublisherInterface;
use Modules\Groups\Api\PostableGroup;
use Modules\Groups\Api\PublishedGroupPost;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\PostRepository;

/**
 * Api\GroupPublisherInterface: what Controller\PostController::create()
 * does for a member at the composer, done for another module — the same
 * checks in the same order, the same services, nothing skipped.
 *
 * - **Who may post where** is GroupAccessService's: a group the person
 *   can read and post in, signed by the member of theirs that belongs to
 *   it. A closed group, a past year, an incomplete profile are refused
 *   with the group's own sentence.
 * - **The post is an ordinary one**: PostService::create() applies the
 *   rate limit and the moderation, the image goes into the group's album
 *   like any post media, the link is attached like a typed one, and the
 *   group is notified. A share never slips under the group's rules.
 * - **All or nothing**: an image the gallery refuses undoes the post, as
 *   the composer does.
 */
final class GroupPublisherService implements GroupPublisherInterface
{
    public function __construct(
        private readonly GroupRepository $groups,
        private readonly PostRepository $posts,
        private readonly GroupAccessService $access,
        private readonly GroupListService $lists,
        private readonly PostService $postService,
        private readonly PostMediaService $media,
        private readonly PostLinkService $links,
        private readonly GroupSessionContextFactory $contexts,
        private readonly ?GroupNotificationService $notifications = null
    ) {
    }

    public function postableGroups(?string $email, string $role, ?int $userAccountId): array
    {
        $context = $this->contexts->build($email, $role, $userAccountId);

        $groups = [];
        foreach ($this->lists->findCurrent($context) as $item) {
            if ($this->access->canPost($item->group, $context)->allowed) {
                $groups[] = new PostableGroup($item->group->id, $item->group->name, $item->memberCount);
            }
        }

        return $groups;
    }

    public function publish(
        int $groupId,
        ?string $email,
        string $role,
        int $userAccountId,
        string $body,
        ?string $image,
        ?string $link
    ): PublishedGroupPost {
        $context = $this->contexts->build($email, $role, $userAccountId);
        $group = $this->groups->findById($groupId);
        if ($group === null || !$this->access->canRead($group, $context)) {
            throw new GroupPublishException('Ce groupe n\'existe plus, ou ne vous est pas ouvert.');
        }
        $permission = $this->access->canPost($group, $context);
        if (!$permission->allowed) {
            throw new GroupPublishException($permission->message);
        }
        $memberId = $this->access->authorMemberIdFor($group, $context);
        if ($memberId === null) {
            throw new GroupPublishException(GroupAccessService::NO_AUTHOR_MEMBER_MESSAGE);
        }

        try {
            $postId = $this->postService->create($group, $userAccountId, $memberId, $body);
        } catch (GroupsException $e) {
            throw new GroupPublishException($e->getMessage(), 0, $e);
        }

        if ($image !== null && $image !== '') {
            $this->attachImage($group, $postId, $image, $userAccountId);
        }
        if ($link !== null && $link !== '') {
            // Never throws: an unreachable page still attaches a plain link.
            $this->links->attach($group, $postId, $link, $memberId, $userAccountId);
        }

        $created = $this->posts->findById($postId);
        if ($created !== null) {
            $this->notifications?->postPublished($group, $created, $context->effectiveScoutYearId);
        }

        return new PublishedGroupPost($postId, '/groups/' . $group->id . '#post-' . $postId);
    }

    private function attachImage(
        \Modules\Groups\Repository\DiscussionGroup $group,
        int $postId,
        string $image,
        int $userAccountId
    ): void {
        $path = tempnam(sys_get_temp_dir(), 'grp-share-');
        if ($path === false || file_put_contents($path, $image) !== strlen($image)) {
            $this->undo($group, $postId);
            throw new GroupPublishException('L\'image n\'a pas pu être préparée. Réessayez plus tard.');
        }

        try {
            $this->media->addMedia($group, $postId, [[
                'name' => 'partage.jpg',
                'tmp_name' => $path,
                'error' => UPLOAD_ERR_OK,
                'size' => strlen($image),
                'type' => 'image/jpeg',
            ]], $userAccountId);
        } catch (GalleryException $e) {
            $this->undo($group, $postId);
            throw new GroupPublishException($e->getMessage(), 0, $e);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function undo(\Modules\Groups\Repository\DiscussionGroup $group, int $postId): void
    {
        $this->media->deleteAllForPost($group, $postId);
        $this->posts->delete($postId);
    }
}
