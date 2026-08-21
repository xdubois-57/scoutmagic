<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Modules\Rental\Repository\RentalAssetRepository;

/**
 * Turns an asset name into a stable, unique, URL-safe slug.
 *
 * **Stable** is the load-bearing word: a slug is minted once, at creation,
 * and never re-derived from a later rename. "Local Saint-Georges" becoming
 * "Local Saint-Georges (rénové)" must not break the link a renter already
 * has, so Service\RentalAssetService only ever passes an explicit slug
 * through — it never regenerates one behind the operator's back.
 *
 * Collisions are resolved by appending `-2`, `-3`, … rather than by
 * rejecting the name: two units genuinely can own two things called "Le
 * local", and refusing the second would be a worse answer than
 * disambiguating it. Archived assets still occupy their slug, so an old
 * public link can never be silently repointed at a different asset.
 */
class RentalSlugGenerator
{
    /** Matches rental_assets.slug's column width, leaving room for a suffix. */
    private const MAX_LENGTH = 150;

    public function __construct(private RentalAssetRepository $assetRepository)
    {
    }

    /**
     * A slug for $name that no other asset holds.
     *
     * @param int|null $exceptId The asset being renamed, so it does not collide with its own current slug.
     */
    public function generate(string $name, ?int $exceptId = null): string
    {
        $base = self::slugify($name);

        if (!$this->assetRepository->slugExists($base, $exceptId)) {
            return $base;
        }

        // Bounded rather than a `while (true)`: an unbounded loop here would
        // hang a request if the uniqueness check ever misbehaved. 200 is far
        // past any real number of same-named assets, and the timestamp
        // fallback below still guarantees a usable slug rather than an error.
        for ($suffix = 2; $suffix <= 200; $suffix++) {
            $candidate = $base . '-' . $suffix;
            if (!$this->assetRepository->slugExists($candidate, $exceptId)) {
                return $candidate;
            }
        }

        return $base . '-' . bin2hex(random_bytes(4));
    }

    /**
     * Lowercase ASCII, words joined by single hyphens.
     *
     * Accents are transliterated rather than stripped so "Local Saint-Géry"
     * yields `local-saint-gery`, not `local-saint-gry`. A name with no
     * transliterable character at all (an emoji, a name in a non-Latin
     * script) would otherwise produce an empty slug and a broken URL, so it
     * falls back to a generic stem the collision loop then makes unique.
     */
    public static function slugify(string $name): string
    {
        $slug = trim($name);

        // The degree sign has no letter to transliterate to, and iconv
        // builds disagree on what to do with it: glibc (Linux, where this
        // runs in CI and in production) drops it silently, but some
        // libiconv builds (macOS/BSD, common in local development) expand
        // it to a literal "^0" — turning "n°2" into "n-02" instead of
        // "n-2". Replaced with a plain separator here so the result never
        // depends on that.
        $slug = str_replace('°', ' ', $slug);

        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT', $slug);
        if ($transliterated !== false) {
            $slug = $transliterated;
        }

        // Same portability problem for accented letters themselves: glibc's
        // TRANSLIT substitutes the plain letter directly ("é" -> "e"), but
        // libiconv builds mark it with a leading backtick/caret/quote
        // instead ("é" -> "'e", "ê" -> "^e", "ë" -> "\"e", …) — dropped here
        // so "Géry" reads "gery" everywhere, not "g-ery" only on the
        // machines where this happens to be run.
        $slug = (string) preg_replace('/[\'"`^]+(?=[a-zA-Z])/', '', $slug);

        $slug = strtolower($slug);
        // Whatever iconv could not transliterate at all — drop every
        // remaining non-alphanumeric run rather than trying to enumerate it.
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        if (strlen($slug) > self::MAX_LENGTH) {
            $slug = rtrim(substr($slug, 0, self::MAX_LENGTH), '-');
        }

        return $slug !== '' ? $slug : 'bien';
    }
}
