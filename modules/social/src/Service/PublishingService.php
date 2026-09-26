<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Service;

use Core\Config\SettingService;
use Core\Journal\JournalService;
use Modules\Social\Api\SocialPlatform;
use Modules\Social\Card\CardException;
use Modules\Social\Card\CardService;
use Modules\Social\Card\IssuedCard;
use Modules\Social\Meta\MetaClient;
use Modules\Social\Meta\MetaException;
use Modules\Social\Repository\ConnectionRepository;
use Modules\Social\Repository\PublicationRepository;

/**
 * Publishes one content to the destinations a person ticked — each one
 * once, and never twice (docs/chantiers/CHANTIER-partage-social.md).
 *
 * **The rule is a claim, not a check.** Every destination is taken through
 * {@see PublicationRepository::claim()} before Meta is called: the unique
 * key on (content, destination) refuses a second publication whatever the
 * page showed, and a failed destination is taken again only when the
 * person confirmed the retry. The destinations already published are
 * simply not touched.
 *
 * **What leaves.** Instagram always receives an image, never a link. A
 * Facebook Page receives a link post for an article — its Open Graph
 * preview — and an image post for anything else. The image is the card
 * ({@see CardService}), composed once per publication and fetched by Meta
 * from its one-hour address; a background from the gallery is blurred.
 *
 * **The journal** names the content by kind and id and the destination,
 * never a caption; a failure keeps Meta's redacted detail.
 */
class PublishingService
{
    /** Instagram's limit; Facebook's is far above it. */
    public const CAPTION_MAX_LENGTH = 2200;

    /** A destination left `pending` longer than this was interrupted, and may be retried. */
    public const STALE_MINUTES = 15;

    public function __construct(
        private readonly ConnectionRepository $connections,
        private readonly PublicationRepository $publications,
        private readonly CardService $cards,
        private readonly SettingService $settings,
        private readonly JournalService $journal,
        private readonly MetaClient $meta = new MetaClient()
    ) {
    }

    /**
     * @param list<SocialPlatform> $destinations the ones ticked
     * @param list<SocialPlatform> $retries      the failed ones whose retry was confirmed
     * @return list<PublishOutcome>
     */
    public function publish(
        ShareSource $source,
        array $destinations,
        string $caption,
        array $retries,
        ?int $userId,
        \DateTimeImmutable $now
    ): array {
        if ($source->blockedReason !== null) {
            return array_map(
                fn (SocialPlatform $p) => new PublishOutcome($p, false, $source->blockedReason),
                $destinations
            );
        }

        $caption = trim($caption);
        if (mb_strlen($caption) > self::CAPTION_MAX_LENGTH) {
            $message = 'La légende dépasse ' . self::CAPTION_MAX_LENGTH . ' caractères, la limite d\'Instagram.';

            return array_map(fn (SocialPlatform $p) => new PublishOutcome($p, false, $message), $destinations);
        }

        $base = rtrim((string) ($this->settings->get('base_url') ?: ''), '/');
        $card = null;
        $outcomes = [];

        foreach ($destinations as $platform) {
            $refusal = $this->refusal($source, $platform, $base, $now);
            if ($refusal !== null) {
                $outcomes[] = new PublishOutcome($platform, false, $refusal);
                continue;
            }

            $claimed = $this->publications->claim(
                $source->kind,
                $source->id,
                $platform->value,
                in_array($platform, $retries, true),
                $userId,
                $now,
                $now->modify('-' . self::STALE_MINUTES . ' minutes'),
                $source->title,
                $caption
            );
            if (!$claimed) {
                $outcomes[] = new PublishOutcome($platform, false, $this->whyNotClaimed($source, $platform, $now));
                continue;
            }

            try {
                if ($this->needsImage($source, $platform)) {
                    $card ??= $this->cards->issue(
                        (string) $source->image,
                        $source->title,
                        $source->address,
                        $source->imageFromGallery,
                        $now
                    );
                }
                $remoteId = $this->send($source, $platform, $caption, $base, $card);
            } catch (MetaException | CardException $e) {
                $this->markFailed($source, $platform, $e->getMessage(), $now);
                $this->log(
                    $source,
                    $platform,
                    'publish_failed',
                    'warning',
                    'publication refusée : ' . $e->getMessage(),
                    $userId,
                    $e instanceof MetaException ? $e->detail : null
                );
                $outcomes[] = new PublishOutcome($platform, false, $e->getMessage());
                continue;
            } catch (\Throwable $e) {
                // Anything else (a secret that no longer decrypts, the
                // database): the row must not stay pending, and neither the
                // page nor the journal gets the exception's text.
                $message = 'La publication a échoué sur le site lui-même. Réessayez plus tard.';
                $this->markFailed($source, $platform, $message, $now);
                $this->log(
                    $source,
                    $platform,
                    'publish_failed',
                    'error',
                    'publication interrompue : ' . $e::class,
                    $userId
                );
                $outcomes[] = new PublishOutcome($platform, false, $message);
                continue;
            }

            $this->publications->markPublished(
                $source->kind,
                $source->id,
                $platform->value,
                $remoteId,
                $now,
                $now,
                $this->remoteUrl($platform, $remoteId)
            );
            $this->log($source, $platform, 'published', 'info', 'publication faite', $userId);
            $outcomes[] = new PublishOutcome($platform, true, 'Publié.');
        }

        return $outcomes;
    }

    /**
     * claim() refuses for three reasons, and each gets its own sentence:
     * already published, a failure whose retry was not confirmed, or a
     * publication in progress.
     */
    private function whyNotClaimed(ShareSource $source, SocialPlatform $platform, \DateTimeImmutable $now): string
    {
        $publication = $this->publications->forSource($source->kind, $source->id)[$platform->value] ?? null;

        return match (true) {
            $publication?->isPublished() === true => 'Déjà publié sur cette destination : un contenu n\'y part '
                . 'qu\'une fois.',
            $publication?->isRetryable($now->modify('-' . self::STALE_MINUTES . ' minutes')) === true
                => 'La publication précédente a échoué : cochez « Je confirme : réessayer » pour la relancer.',
            default => 'Une publication y est déjà en cours.',
        };
    }

    /**
     * Where the post can be seen, for « Voir » in the history. Facebook's
     * address is the post's id; Instagram's has to be asked, and not
     * getting it never turns a published post into a failure.
     */
    private function remoteUrl(SocialPlatform $platform, string $remoteId): ?string
    {
        if ($platform === SocialPlatform::Facebook) {
            return 'https://www.facebook.com/' . rawurlencode($remoteId);
        }

        try {
            return $this->meta->instagramPermalink($remoteId, $this->connections->secretsOf($platform)->accessToken);
        } catch (\Throwable) {
            return null;
        }
    }

    private function markFailed(
        ShareSource $source,
        SocialPlatform $platform,
        string $message,
        \DateTimeImmutable $claimedAt
    ): void {
        $this->publications->markFailed($source->kind, $source->id, $platform->value, $message, $claimedAt);
    }

    /**
     * Whether this destination receives the card rather than a link.
     */
    public function needsImage(ShareSource $source, SocialPlatform $platform): bool
    {
        return $platform === SocialPlatform::Instagram || $source->link === null;
    }

    /**
     * Why this destination cannot be published to right now, or null.
     */
    public function refusal(
        ShareSource $source,
        SocialPlatform $platform,
        string $base,
        \DateTimeImmutable $now
    ): ?string
    {
        $connection = $this->connections->find($platform);
        if ($connection === null || !$connection->isUsable($now)) {
            return 'Ce compte n\'est pas connecté, ou Meta n\'accepte plus son autorisation.';
        }
        if ($base === '') {
            return 'Renseignez d\'abord l\'adresse du site dans Configuration > Réglages.';
        }
        if ($this->needsImage($source, $platform) && ($source->image === null || $source->image === '')) {
            return match ($source->kind) {
                ShareSource::KIND_ALBUM => 'Cet album n\'a pas encore de photo de couverture.',
                ShareSource::KIND_ARTICLE
                    => 'Cette actualité n\'a pas d\'image : Instagram n\'accepte que des images.',
                default => 'Choisissez d\'abord une image : aucune publication ne part sans image.',
            };
        }

        return null;
    }

    private function send(
        ShareSource $source,
        SocialPlatform $platform,
        string $caption,
        string $base,
        ?IssuedCard $card
    ): string {
        $connection = $this->connections->find($platform);
        $accountId = (string) $connection?->accountId;
        $token = $this->connections->secretsOf($platform)->accessToken;
        $imageUrl = $card === null ? '' : $base . $card->path;

        return match (true) {
            $platform === SocialPlatform::Instagram
                => $this->meta->publishInstagramImage($accountId, $token, $imageUrl, $caption),
            $source->link !== null
                => $this->meta->publishFacebookLink($accountId, $token, $source->link, $caption),
            default => $this->meta->publishFacebookPhoto($accountId, $token, $imageUrl, $caption),
        };
    }

    private function log(
        ShareSource $source,
        SocialPlatform $platform,
        string $event,
        string $level,
        string $message,
        ?int $userId,
        ?string $detail = null
    ): void {
        $context = ['source' => $source->kind, 'source_id' => $source->id, 'platform' => $platform->value];
        if ($detail !== null) {
            $context['detail'] = $detail;
        }

        $this->journal->log(
            'social',
            $event,
            $level,
            $platform->label() . ' : ' . ($source->kind === ShareSource::KIND_ALBUM ? 'album' : 'actualité')
                . ' n° ' . $source->id . ', ' . $message,
            $context,
            $userId
        );
    }
}
