<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Api;

/**
 * Publishing in discussion groups for another module (the social module's
 * share). Which groups a person may post in, and what a post goes
 * through, are the groups module's rules, answered here — the post is an
 * ordinary one: rate limit, moderation, notifications and reports apply.
 */
interface GroupPublisherInterface
{
    /**
     * The current groups this person may post in, by name.
     *
     * @return list<PostableGroup>
     */
    public function postableGroups(?string $email, string $role, ?int $userAccountId): array;

    /**
     * Publishes one post in one group: the text, an image as a post media
     * (never altered here) and a link shown as the post's link.
     *
     * @throws GroupPublishException when the group refuses it
     */
    public function publish(
        int $groupId,
        ?string $email,
        string $role,
        int $userAccountId,
        string $body,
        ?string $image,
        ?string $link
    ): PublishedGroupPost;
}
