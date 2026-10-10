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
use Modules\Social\Card\CardException;
use Modules\Social\Card\CardService;
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
        private readonly JournalService $journal,
        /**
         * Composes the card a group receives (issue #706, IT-02).
         *
         * Nullable, and a null means « send the photo as it is », which
         * is what every group received before IT-02. It is a constructor
         * default rather than a required dependency so that nothing which
         * builds this service without a card service starts failing —
         * but every caller in the module passes one.
         */
        private readonly ?CardService $cards = null
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
        // Composed at most once for the whole request, and only if a
        // group is actually reached: the same bytes to every group, as
        // `PublishingService` already does for the platforms.
        $card = null;
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
                    // **The same card as every other destination** (issue
                    // #706, IT-02). A group used to receive the photo
                    // exactly as it was, unblurred, on the reasoning that
                    // the group is private and its members already see
                    // the gallery. That reasoning held while the blur was
                    // a fixed rule; it stopped holding when the blur
                    // became the chief's choice, because « the same card
                    // everywhere » is what the composer now shows and
                    // promises. The chief who wants a group to have the
                    // sharp photo moves the slider to « Net », and the
                    // page says so in words before it happens.
                    $card ??= $this->card($source),
                    // The real link survives: a group is inside the site,
                    // so it gets a clickable address, which is a separate
                    // argument from the image.
                    $source->pageUrl
                );
            } catch (CardException $e) {
                // Its own sentence, not « the site itself failed »: the
                // chief is told that the image could not be prepared and
                // that a gallery photo does not leave without its blur.
                $this->publications->markFailed($source->kind, $source->id, $key, $e->getMessage(), $now);
                $this->log($source, $groupId, 'publish_failed', 'warning', 'carte indisponible', $userId);
                $outcomes[] = new PublishOutcome(null, false, $e->getMessage(), $label);
                continue;
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

    /**
     * The card this source's groups receive, composed once for the whole
     * request — the same bytes to every group, as to every platform.
     *
     * **A gallery photo is never the fallback.** For anything else — an
     * uploaded image, an article's cover — a composition that fails
     * falls back to the image as it is rather than refusing to post: a
     * group is inside the site, and a message that arrives without its
     * card is worth more than one that does not arrive. That reasoning
     * does not reach a gallery photo, because from IT-02 the blur is the
     * chief's choice and falling back would send the photo SHARP and
     * whole, which is the one thing the slider must never do by
     * accident. There the publication is refused, with the reason, and
     * the chief can try again. Found in the review of #850.
     *
     * `PublishingService` already refuses a publication with no image at
     * all, before this is ever reached.
     *
     * @throws CardException when a gallery photo has no card to travel as
     */
    private function card(ShareSource $source): ?string
    {
        // The card the browser drew, when there is one: a group receives
        // exactly what every other destination did, down to the bytes
        // (issue #706, IT-02).
        if ($source->card !== null && $source->card !== '') {
            return $source->card;
        }

        if ($this->cards === null || $source->image === null || $source->image === '') {
            return $this->refuseOrSendAsIs($source);
        }

        try {
            return $this->cards->compose(
                $source->image,
                $source->title,
                $source->address,
                $source->imageFromGallery,
                $source->blurRatio
            );
        } catch (CardException) {
            return $this->refuseOrSendAsIs($source);
        }
    }

    /**
     * What to do when no card can be made: send the image as it is, or
     * refuse because sending it as it is would publish a gallery photo
     * with no blur on it.
     *
     * @throws CardException
     */
    private function refuseOrSendAsIs(ShareSource $source): ?string
    {
        if ($source->imageFromGallery) {
            throw new CardException(
                'L\'image à publier n\'a pas pu être préparée, et une photo de la galerie ne part '
                . 'pas sans son flou. Réessayez depuis le composeur.'
            );
        }

        return $source->image;
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
