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
        /**
         * Where an album's Facebook post points (issue #706, IT-04).
         * Asked at the moment of publishing and never earlier, because
         * asking is what brings the page into being.
         */
        private readonly PublicPageService $publicPages,
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
                if ($this->needsCardUrl($source, $platform)) {
                    $card ??= $this->cards->issue($this->cardBytes($source), $source->imageFromGallery, $now);
                }
                $remoteId = $this->send($source, $platform, $caption, $base, $card, $now);
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
     * The card to publish: the one the browser drew, or a composition for
     * a share made before it drew any (issue #706, IT-02).
     *
     * **`CardRenderer` has left the path a NEW share takes**, which is
     * what the chantier asks: the browser composes, the server checks and
     * keeps. It has not left the repository, and the second branch here
     * is why — a share created before IT-02 has no card stored, and a
     * retry of one that failed has to remain possible. Pretending
     * otherwise would turn an old failed publication into one nobody can
     * ever complete.
     *
     * @throws CardException when the stored card is not usable and no
     *         composition can replace it
     */
    private function cardBytes(ShareSource $source): string
    {
        if ($source->card !== null && $source->card !== '') {
            return $source->card;
        }

        return $this->cards->compose(
            (string) $source->image,
            $source->title,
            $source->address,
            $source->imageFromGallery,
            // The strength this share kept, or the site's starting
            // position when the slider was never moved.
            $source->blurRatio
        );
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
     * Whether this publication needs an image to exist at all.
     *
     * **Not the same question as « does it receive an image »**, which is
     * what this used to say and what an album's Facebook post made false
     * (issue #706, IT-04): that post carries a LINK, and still needs the
     * card, because the card is what the public page it points at shows
     * and therefore what Facebook builds its preview from. An album with
     * no cover photo is refused on Facebook for that reason, as it always
     * was — `link` being null for an album is what keeps it refused, and
     * is why this line did not have to change when the album's post did.
     *
     * An article is the opposite case: its link carries its own page,
     * whose cover the news module publishes, so this module needs no
     * image for it. {@see needsCardUrl()} answers the other question.
     */
    public function needsImage(ShareSource $source, SocialPlatform $platform): bool
    {
        return $platform === SocialPlatform::Instagram || $source->link === null;
    }

    /**
     * Whether this publication needs the card at a URL Meta can fetch —
     * the hour-long route of {@see CardService::issue()}.
     *
     * Instagram always, since it fetches rather than accepts an upload;
     * Facebook only for a photo post. An album's link post needs the card
     * ({@see needsImage()}) and not an address for it: what Facebook
     * fetches is the public page, which serves the same bytes from its
     * own durable address. Issuing the ephemeral one too would write a
     * file and a row for a URL nobody reads.
     */
    private function needsCardUrl(ShareSource $source, SocialPlatform $platform): bool
    {
        return $platform === SocialPlatform::Instagram || !$source->facebookReceivesLink();
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
        if ($this->needsImage($source, $platform) && !$source->hasImageToPublish()) {
            // `spokenKind()`, not `kind`: a saved source-backed share is
            // recorded as a `communication`, and deciding by that alone
            // answered « Choisissez d'abord une image » beside every
            // destination — advice with no button to obey, the composer
            // hiding both for a source-backed share.
            return match ($source->spokenKind()) {
                ShareSource::KIND_ALBUM => 'Cet album n\'a pas encore de photo de couverture.',
                ShareSource::KIND_ARTICLE
                    => 'Cette actualité n\'a pas d\'image : Instagram n\'accepte que des images.',
                default => 'Choisissez d\'abord une image : aucune publication ne part sans image.',
            };
        }

        // **What the public page needs, checked before anything is
        // recorded** (issue #706, IT-04). An album's Page post is a LINK
        // to the communication's public page, and that page shows the
        // kept card and nothing else — so the card has to be there, and a
        // communication has to be there to own the page.
        //
        // Refused here rather than reshaped into a photo post in
        // `send()`: a refusal at this point claims nothing, freezes
        // nothing and leaves the chief free to try again, while a silent
        // fallback would publish something other than what the composer
        // promised — the sin this module already refuses to commit over
        // the card itself. Found in review on the pull request for IT-04,
        // which had the card's absence reaching Facebook as a link to a
        // page with no image at all.
        if (
            $platform === SocialPlatform::Facebook
            && $source->link === null
            && $source->kind === ShareSource::KIND_COMMUNICATION
            && $source->facebookReceivesLink()
            && ($source->card === null || $source->card === '')
        ) {
            // Worded for BOTH places this appears: beside the
            // destination in the composer, where `DestinationStates`
            // reads it to mark Facebook « indisponible », and on a
            // refused publication. « Réessayez » would be a lie in the
            // first — a browser that cannot draw will not draw on the
            // second attempt either — so it names the cause and stops.
            return 'Cette communication n\'a pas d\'image enregistrée : sur la Page, un album part en lien'
                . ' vers une page de ce site qui doit la montrer.';
        }

        return null;
    }

    private function send(
        ShareSource $source,
        SocialPlatform $platform,
        string $caption,
        string $base,
        ?IssuedCard $card,
        \DateTimeImmutable $now
    ): string {
        $connection = $this->connections->find($platform);
        $accountId = (string) $connection?->accountId;
        $token = $this->connections->secretsOf($platform)->accessToken;
        $imageUrl = $card === null ? '' : $base . $card->path;

        if ($platform === SocialPlatform::Instagram) {
            return $this->meta->publishInstagramImage($accountId, $token, $imageUrl, $caption);
        }

        // Resolved HERE and not before the branch above: for an album the
        // answer is a public page that does not exist yet, and asking for
        // it creates one. An Instagram publication must not leave a public
        // page behind it (issue #706, IT-04).
        //
        // {@see ShareSource::facebookReceivesLink()} holds the decision —
        // the same one the composer read before « Publier » — and
        // `refusal()` has already turned down the album whose page would
        // have nothing to show.
        $link = $source->facebookReceivesLink() ? $this->facebookLink($source, $now) : null;

        return $link === null
            ? $this->meta->publishFacebookPhoto($accountId, $token, $imageUrl, $caption)
            : $this->meta->publishFacebookLink($accountId, $token, $link, $caption);
    }

    /**
     * The address Facebook is pointed at: an article's own page, or the
     * communication's public page, minted at this instant if this is the
     * first share that needs it.
     *
     * **Null for a share with no communication row**, and that is not the
     * mismatch `facebookReceivesLink()` exists to prevent: the public
     * page belongs to the COMMUNICATION, and a retry reached through
     * `…/reessayer/album/{albumId}/…` carries the album's own id with no
     * row behind it. It keeps going as the photo post it has always been,
     * and nothing ever promised it otherwise — the composer is not on
     * that path at all. Minting against that id would point Facebook at a
     * page built from an unrelated row that happens to share the number.
     */
    private function facebookLink(ShareSource $source, \DateTimeImmutable $now): ?string
    {
        if ($source->link !== null) {
            return $source->link;
        }

        return $source->kind === ShareSource::KIND_COMMUNICATION
            ? $this->publicPages->ensureUrlFor($source->id, $now)
            : null;
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
            $platform->label() . ' : ' . self::kindLabel($source->kind) . ' n° ' . $source->id . ', ' . $message,
            $context,
            $userId
        );
    }

    /** How the journal names a kind of content. */
    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            ShareSource::KIND_ALBUM => 'album',
            ShareSource::KIND_ARTICLE => 'actualité',
            default => 'communication',
        };
    }
}
