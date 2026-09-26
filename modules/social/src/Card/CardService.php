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
 * **The blur has a floor.** Its strength is the setting
 * {@see self::BLUR_SETTING}, declared non-editable, and this class never
 * uses less than {@see self::MIN_BLUR_RATIO} whatever the setting says —
 * a value lowered in the database can make the blur stronger, never
 * weaker.
 */
class CardService
{
    public const BLUR_SETTING = 'social_card_blur_ratio';

    /**
     * Calibrated on real photos (docs/chantiers/partage-social.md, IT-02):
     * at 5 % of the side, a close-up portrait's face gives nothing away
     * while the scene — a tent, a uniform, a clearing — still reads.
     */
    public const MIN_BLUR_RATIO = 0.05;

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
        \DateTimeImmutable $now
    ): IssuedCard {
        $jpeg = $this->renderer->render($contents, $title, $address, $fromGallery, $this->blurRatio());

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
        if (@file_put_contents($this->directory . '/' . $fileName, $jpeg) === false) {
            $this->cards->delete($cardId);

            throw new CardException('L\'image n\'a pas pu être enregistrée. Réessayez plus tard.');
        }

        return new IssuedCard($cardId, self::ROUTE_PREFIX . $token, $expiresAt);
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
     * The setting, never below the floor.
     */
    public function blurRatio(): float
    {
        $value = $this->settings->get(self::BLUR_SETTING, 'social', (string) self::MIN_BLUR_RATIO);
        $ratio = is_numeric($value) ? (float) $value : self::MIN_BLUR_RATIO;

        return min(0.5, max(self::MIN_BLUR_RATIO, $ratio));
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
