<?php

declare(strict_types=1);

namespace Tests\Modules\Social;

use Modules\Groups\Api\GroupPublishException;
use Modules\Groups\Api\GroupPublisherInterface;
use Modules\Groups\Api\PostableGroup;
use Modules\Groups\Api\PublishedGroupPost;

/**
 * The groups module, played: two groups a chief may post in, and every
 * post it received — the groups' own rules are GroupPublisherServiceTest's.
 */
final class FakeGroupPublisher implements GroupPublisherInterface
{
    /** @var list<array{group: int, body: string, image: ?string, link: ?string}> */
    public array $posts = [];

    /** @var array<int, string> group id => refusal */
    public array $refusals = [];

    public function postableGroups(?string $email, string $role, ?int $userAccountId): array
    {
        return $role === 'intendant' ? [] : [
            new PostableGroup(3, 'Staff Lutins', 6),
            new PostableGroup(4, 'Staff d\'unité', 14),
        ];
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
        if (isset($this->refusals[$groupId])) {
            throw new GroupPublishException($this->refusals[$groupId]);
        }
        $this->posts[] = ['group' => $groupId, 'body' => $body, 'image' => $image, 'link' => $link];

        return new PublishedGroupPost(100 + count($this->posts), '/groups/' . $groupId . '#post-' . (100 + count($this->posts)));
    }
}
