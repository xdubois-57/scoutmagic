<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Service;

use Core\Journal\JournalService;
use Modules\Groups\Api\GroupPublishException;
use Modules\Groups\Api\GroupPublisherInterface;
use Modules\Groups\Api\PostableGroup;
use Modules\Social\Repository\PublicationRepository;

/**
 * Publishing in the site's own discussion groups — the internal
 * destination (docs/chantiers/CHANTIER-partage-social.md, IT-05).
 *
 * **None of Meta's constraints apply.** No card is composed: the photo
 * goes as it is, as a post media, **never blurred** — the group is
 * private and its members already see the gallery. The content's page
 * goes as a real clickable link.
 *
 * **Each group is a destination of its own**: its own post, its own row
 * in social_publications (`group:{id}`), its own status and the « once »
 * rule applied group by group — the same claim as Facebook and Instagram.
 *
 * **Which groups, and what a post goes through, are the groups module's
 * rules** (Api\GroupPublisherInterface): only a group the person may post
 * in is accepted, and the post is an ordinary one — rate limit,
 * moderation, reports. Without the groups module this service is not
 * built and the destination simply is not offered.
 */
final class GroupPublishingService
{
    public const KEY_PREFIX = 'group:';

    public function __construct(
        private readonly GroupPublisherInterface $groups,
        private readonly PublicationRepository $publications,
        private readonly JournalService $journal
    ) {
    }

    /**
     * @return list<PostableGroup>
     */
    public function postableGroups(?string $email, string $role, ?int $userAccountId): array
    {
        try {
            return $this->groups->postableGroups($email, $role, $userAccountId);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param list<int> $groupIds the groups ticked
     * @param list<int> $retries  the failed ones whose retry was confirmed
     * @return list<PublishOutcome>
     */
    public function publish(
        ShareSource $source,
        array $groupIds,
        string $caption,
        array $retries,
        ?string $email,
        string $role,
        int $userId,
        \DateTimeImmutable $now
    ): array {
        $postable = [];
        foreach ($this->postableGroups($email, $role, $userId) as $group) {
            $postable[$group->id] = $group;
        }

        $outcomes = [];
        foreach (array_values(array_unique($groupIds)) as $groupId) {
            $group = $postable[$groupId] ?? null;
            $label = $group->name ?? 'Groupe n° ' . $groupId;
            $refusal = match (true) {
                $group === null => 'Vous ne pouvez pas publier dans ce groupe.',
                $source->blockedReason !== null => $source->blockedReason,
                $source->image === null || $source->image === '' => 'Aucune publication ne part sans image.',
                default => null,
            };
            if ($refusal !== null) {
                $outcomes[] = new PublishOutcome(null, false, $refusal, $label);
                continue;
            }

            $key = self::KEY_PREFIX . $groupId;
            $claimed = $this->publications->claim(
                $source->kind,
                $source->id,
                $key,
                in_array($groupId, $retries, true),
                $userId,
                $now,
                $now->modify('-' . PublishingService::STALE_MINUTES . ' minutes'),
                $source->title,
                $caption,
                $label
            );
            if (!$claimed) {
                $outcomes[] = new PublishOutcome(null, false, $this->whyNotClaimed($source, $key, $now), $label);
                continue;
            }

            try {
                $post = $this->groups->publish(
                    $groupId,
                    $email,
                    $role,
                    $userId,
                    $caption,
                    $source->image,
                    $source->pageUrl
                );
            } catch (GroupPublishException $e) {
                $this->publications->markFailed($source->kind, $source->id, $key, $e->getMessage(), $now);
                $this->log($source, $groupId, 'publish_failed', 'warning', 'refusée par le groupe', $userId);
                $outcomes[] = new PublishOutcome(null, false, $e->getMessage(), $label);
                continue;
            } catch (\Throwable $e) {
                $message = 'La publication a échoué sur le site lui-même. Réessayez plus tard.';
                $this->publications->markFailed($source->kind, $source->id, $key, $message, $now);
                $this->log($source, $groupId, 'publish_failed', 'error', 'interrompue : ' . $e::class, $userId);
                $outcomes[] = new PublishOutcome(null, false, $message, $label);
                continue;
            }

            $this->publications->markPublished(
                $source->kind,
                $source->id,
                $key,
                (string) $post->postId,
                $now,
                $now,
                $post->path
            );
            $this->log($source, $groupId, 'published', 'info', 'publication faite', $userId);
            $outcomes[] = new PublishOutcome(null, true, 'Publié.', $label);
        }

        return $outcomes;
    }

    /**
     * The group id a destination key names, or null when it names none.
     */
    public static function groupIdOf(string $key): ?int
    {
        if (!str_starts_with($key, self::KEY_PREFIX)) {
            return null;
        }
        $id = substr($key, strlen(self::KEY_PREFIX));

        return ctype_digit($id) && (int) $id > 0 ? (int) $id : null;
    }

    private function whyNotClaimed(ShareSource $source, string $key, \DateTimeImmutable $now): string
    {
        $publication = $this->publications->forSource($source->kind, $source->id)[$key] ?? null;

        return match (true) {
            $publication?->isPublished() === true
                => 'Déjà publié dans ce groupe : un contenu n\'y part qu\'une fois.',
            $publication?->isRetryable($now->modify('-' . PublishingService::STALE_MINUTES . ' minutes')) === true
                => 'La publication précédente a échoué : cochez « Je confirme : réessayer » pour la relancer.',
            default => 'Une publication y est déjà en cours.',
        };
    }

    private function log(
        ShareSource $source,
        int $groupId,
        string $event,
        string $level,
        string $message,
        int $userId
    ): void {
        $this->journal->log(
            'social',
            $event,
            $level,
            'Groupe de discussion : ' . PublishingService::kindLabel($source->kind) . ' n° ' . $source->id
                . ', ' . $message,
            ['source' => $source->kind, 'source_id' => $source->id, 'platform' => 'group', 'group_id' => $groupId],
            $userId
        );
    }
}
