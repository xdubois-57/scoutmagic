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
     *
     * **The failure's sentence names « Réessayer » and not the
     * composer's checkbox**, because the chief is never reading it on
     * the composer: `publishNow()` redirects to the history whatever
     * happened, and that is where the control lives.
     *
     * It mattered little while the only way here was ticking a
     * destination the composer had just shown as failed — the chief had
     * seen the box. Since IT-05 the twin key makes « Partager » on the
     * album resolve back to the existing communication (issue #793), and
     * that composer is prefilled from the SOURCE with no history read at
     * all ({@see DestinationStates::forSource()}, `$hasOwnHistory`), so
     * it shows the destination as available and pre-ticked. The chief
     * who publishes from there was then told to tick a box no page they
     * had seen ever displayed. `Claude review` found it on PR #868; the
     * composer's own silence about that row is #881, being a decision
     * about what a prefilled page may read.
     */
    private function whyNotClaimed(ShareSource $source, SocialPlatform $platform, \DateTimeImmutable $now): string
    {
        $publication = $this->publications->forSource($source->kind, $source->id)[$platform->value] ?? null;

        return match (true) {
            $publication?->isPublished() === true => 'Déjà publié sur cette destination : un contenu n\'y part '
                . 'qu\'une fois.',
            $publication?->isRetryable($now->modify('-' . self::STALE_MINUTES . ' minutes')) === true
                => 'La publication précédente a échoué : relancez-la avec « Réessayer », sur sa ligne'
                    . ' dans « Ce qui est parti ».',
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
        return $platform === SocialPlatform::Instagram || !$this->sendsFacebookLink($source);
    }

    /**
     * Whether this send will **actually** carry a link, decided without
     * minting anything — the question {@see needsCardUrl()} and
     * {@see send()} have to answer the same way, or one of them is wrong:
     * a photo post whose card was never issued goes out with an empty
     * image URL, which is how a review of IT-04 found the legacy album
     * retry publishing nothing at all.
     *
     * It is {@see ShareSource::facebookReceivesLink()} — the decision the
     * composer showed the chief — plus the two things only the publisher
     * can know:
     *
     * - **a communication to own the page.** A retry reached through
     *   `…/reessayer/album/{albumId}/…` carries the album's own id and no
     *   row behind it; minting against that id would point Facebook at a
     *   page built from an unrelated row that happens to share the
     *   number.
     * - **a kept card for the page to show.** The public page serves
     *   `card_file_id` and nothing else — never the source's photo, which
     *   is what keeps the rest of the album out of a public address. With
     *   no card the page would carry no image at all, and that is a worse
     *   post than the photo this module published before IT-04. Found in
     *   review on the pull request for IT-04.
     *
     * **So the composer's sentence can be optimistic, exactly once**: for
     * a share not yet saved it promises a link, because the browser
     * normally posts its card with « Publier » and the publisher then
     * agrees. A browser that cannot draw one — JavaScript off, a photo
     * still loading, which
     * `Controller\CommunicationController::refreshPostedCard()` keeps
     * supported on purpose — gets a photo post instead, carrying the card
     * the server composes. That is a better post than the page would have
     * been, and the one place these two readings differ.
     */
    private function sendsFacebookLink(ShareSource $source): bool
    {
        if ($source->link !== null) {
            return true;
        }

        return $source->facebookReceivesLink()
            && $source->kind === ShareSource::KIND_COMMUNICATION
            && $source->card !== null
            && $source->card !== '';
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
        // The page is asked for HERE and not before the branch above:
        // asking is what brings it into being, and an Instagram
        // publication must leave none behind it. {@see
        // sendsFacebookLink()} holds every condition, so the card for a
        // photo post has already been issued when this answers false.
        $link = $this->sendsFacebookLink($source)
            ? ($source->link ?? $this->publicPages->ensureUrlFor($source->id, $now))
            : null;

        return $link === null
            ? $this->meta->publishFacebookPhoto($accountId, $token, $imageUrl, $caption)
            : $this->meta->publishFacebookLink($accountId, $token, $link, $caption);
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
