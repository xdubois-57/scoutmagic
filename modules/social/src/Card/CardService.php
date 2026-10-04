<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Card;

use Core\Config\SettingService;
use Core\Journal\JournalService;
use Modules\Social\Repository\CardRepository;

/**
 * Composes a card, keeps it for an hour behind a random address, and
 * serves it to whoever holds that address — Meta's servers, in practice.
 *
 * **Why a public address at all.** Instagram does not take an upload: it
 * fetches the media from a URL at the moment of publishing, and a gallery
 * photo sits behind `/files/{id}` and `FileAccessGuard`, where Meta cannot
 * follow. So the card — never the original — is reachable, for an hour,
 * at `/partage/carte/{token}`. SECURITY.md describes this as a deliberate
 * exception to « every download goes through /files/{id} », and why it is
 * narrow: a composed, blurred image; 256 random bits; an hour; every
 * access journalled.
 *
 * **The blur has NO floor any more** (issue #706, IT-02). It used to: a
 * gallery photo was blurred at `MIN_BLUR_RATIO` = 0.05 at the least,
 * whatever the setting said, and the composer promised « floutée, sans
 * exception ». The floor is gone, because the strength became the chief's
 * to choose with a slider that reaches « Net » — and a slider whose left
 * end does nothing is a lie told in an interface. A gallery photo CAN now
 * leave sharp; the composer says so in words before it does
 * ({@see \Modules\Social\Controller\CommunicationController}).
 *
 * {@see self::BLUR_SETTING} survives as **where the slider starts**, not
 * as a rule — still non-editable, so no screen offers it. Its shipped
 * default halves, to {@see self::DEFAULT_BLUR_RATIO}.
 *
 * **An existing site keeps its own stored value**, and that is deliberate
 * rather than overlooked: `SettingRepository::updateDefaultValue()` moves
 * a stored value only for a `url`-typed setting, and
 * `pruneUndeclaredSettings()` deletes only `editable` rows, so neither
 * mechanism can quietly change this one. A site that has been publishing
 * at 0.05 therefore keeps 0.05 as the slider's starting position — the
 * conservative migration, since nothing it already publishes changes
 * shape — and any chief can move the slider from there. An administrator
 * who wants the new default applied runs the « Paramètres par défaut »
 * maintenance task, which resets stored values to their declared ones.
 */
class CardService
{
    public const BLUR_SETTING = 'social_card_blur_ratio';

    /**
     * Where the slider starts when a site has no stored value of its own.
     *
     * Half the old floor. At 5 % of the side a close-up portrait gave
     * nothing away, which is why the floor sat there; at 2.5 % the scene
     * reads more and a face is still not identifiable, and the chief who
     * wants either extreme now has a slider for it.
     */
    public const DEFAULT_BLUR_RATIO = 0.025;

    /** The strongest blur the slider offers — past this the photo is a smear. */
    public const MAX_BLUR_RATIO = 0.2;

    /** How long Meta has to fetch the card. Publishing takes seconds; retries within the hour are covered. */
    public const LIFETIME_MINUTES = 60;

    public const ROUTE_PREFIX = '/partage/carte/';

    /** Where the cards are kept, under the site's `storage/`. */
    public const DIRECTORY = 'social/cards';

    public function __construct(
        private readonly CardRepository $cards,
        private readonly CardRenderer $renderer,
        private readonly SettingService $settings,
        private readonly JournalService $journal,
        private readonly string $directory
    ) {
    }

    /**
     * @param bool $fromGallery whether the background is a gallery photo —
     *        if it is, it is blurred; there is no other way through
     * @throws CardException when the image cannot be used
     */
    public function issue(
        string $contents,
        string $title,
        string $address,
        bool $fromGallery,
        \DateTimeImmutable $now,
        ?float $blurRatio = null
    ): IssuedCard {
        $jpeg = $this->renderer->render($contents, $title, $address, $fromGallery, $this->blurOrDefault($blurRatio));

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
            throw new CardException('L\'image n\'a pas pu être enregistrée. Réessayez plus tard.');
        }

        $token = bin2hex(random_bytes(32));
        $fileName = bin2hex(random_bytes(16)) . '.jpg';
        $expiresAt = $now->modify('+' . self::LIFETIME_MINUTES . ' minutes');

        // The row first, then the file: whatever stops in between leaves a
        // row without a file — a 404, and a row the purge deletes — never
        // a file no row points to, which nothing would ever delete.
        $cardId = $this->cards->create(self::hash($token), $fileName, $fromGallery, $now, $expiresAt);
        $path = $this->directory . '/' . $fileName;
        // A short write (a full disk) returns a byte count, not false.
        if (@file_put_contents($path, $jpeg) !== strlen($jpeg)) {
            // A failed write can still leave part of a file. It goes first;
            // if it cannot, the row stays, and the purge — which removes
            // the file before the row — tries again once it has expired.
            if (!is_file($path) || @unlink($path)) {
                $this->cards->delete($cardId);
            }

            throw new CardException('L\'image n\'a pas pu être enregistrée. Réessayez plus tard.');
        }

        return new IssuedCard($cardId, self::ROUTE_PREFIX . $token, $expiresAt);
    }

    /**
     * The card's bytes, composed and stored nowhere — no file, no row, no
     * address issued.
     *
     * Two callers, and the name says what they share rather than what
     * either one does with it: the composer's fallback preview, and a
     * discussion group, which receives the card itself from issue #706,
     * IT-02 and keeps it in its own media storage. It was called
     * `preview()` while the composer was the only caller; publishing to a
     * group through something named « preview » would have read as a
     * mistake.
     *
     * {@see issue()} is the other half: the same composition, kept on
     * disk behind a token, for Meta's servers to fetch.
     *
     * @throws CardException when the image cannot be used
     */
    public function compose(
        string $contents,
        string $title,
        string $address,
        bool $fromGallery,
        ?float $blurRatio = null
    ): string {
        return $this->renderer->render($contents, $title, $address, $fromGallery, $this->blurOrDefault($blurRatio));
    }

    /**
     * The strength to draw with: the one chosen for this communication,
     * or this site's starting position when nothing was chosen.
     *
     * **Null and zero are different answers**, which is the whole reason
     * the parameter is nullable: null is « nobody ever moved the slider »
     * — every row written before the slider existed — and zero is a chief
     * who moved it to « Net » on purpose.
     */
    private function blurOrDefault(?float $blurRatio): float
    {
        return $blurRatio === null ? $this->blurRatio() : self::clampBlurRatio($blurRatio);
    }

    /**
     * The file a token opens, or null — unknown, malformed, expired or gone
     * all answer the same. Every card served is journalled, with its id
     * and nothing else: the token is the key, and a journal line is no
     * place for a key.
     */
    public function open(string $token, \DateTimeImmutable $now): ?string
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        $card = $this->cards->findLive(self::hash($token), $now);
        if ($card === null) {
            return null;
        }

        $path = $this->directory . '/' . basename($card['file_name']);
        if (!is_file($path)) {
            return null;
        }

        $this->cards->recordServed($card['id']);
        $this->journal->log(
            'social',
            'card_served',
            'info',
            'Image de publication téléchargée depuis son adresse temporaire',
            ['card_id' => $card['id']]
        );

        return $path;
    }

    /**
     * Deletes expired cards, file first then row: a row without its file
     * answers 404 like an expired one, a file without its row would be
     * forgotten on disk.
     *
     * @return int how many were deleted
     */
    public function purgeExpired(\DateTimeImmutable $now): int
    {
        $count = 0;
        foreach ($this->cards->findExpired($now) as $card) {
            $path = $this->directory . '/' . basename($card['file_name']);
            if (is_file($path) && !@unlink($path)) {
                continue;
            }
            $this->cards->delete($card['id']);
            $count++;
        }

        return $count;
    }

    /**
     * Where the slider starts: this site's stored value, or the shipped
     * default, clamped to what the slider can express.
     *
     * **Zero is a legitimate answer** — « Net » — which is why the lower
     * bound is 0 and not a floor. A value outside the slider's range is
     * brought back into it rather than refused: it can only have got
     * there by hand in the database, and a composer that would not open
     * is worse than one that opens on the nearest position it can show.
     */
    public function blurRatio(): float
    {
        $value = $this->settings->get(self::BLUR_SETTING, 'social', (string) self::DEFAULT_BLUR_RATIO);
        $ratio = is_numeric($value) ? (float) $value : self::DEFAULT_BLUR_RATIO;

        return self::clampBlurRatio($ratio);
    }

    /**
     * One ratio brought into the slider's range — the one place that
     * decides what « a blur strength » may be, so the form, the stored
     * value and the setting cannot disagree about it.
     */
    public static function clampBlurRatio(float $ratio): float
    {
        return min(self::MAX_BLUR_RATIO, max(0.0, $ratio));
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
