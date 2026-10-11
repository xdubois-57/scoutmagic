<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Service;

/**
 * Points the images of an article's text at their `md` rendition (#883).
 *
 * The editor used to insert an uploaded body image as its ORIGINAL,
 * `/files/{id}`. The original never goes through the offline cache —
 * SECURITY.md's rule on file access, which `public/sw.js` applies to the
 * letter: only `/files/{id}/thumb|md` may be stored — so an article read
 * offline showed every image of its text broken, and online it sent the
 * full multi-megabyte photograph where a 1024px one does.
 *
 * New images are inserted as `/files/{id}/md` since the upload produces
 * the derivatives. This rewrites the ones already stored, at display time,
 * and only where the `md` file exists: an address with nothing behind it
 * would trade a heavy image for a broken one. Nothing is written back —
 * the stored text changes the next time someone saves it, through the
 * editor, which already shows the rewritten address.
 */
final class BodyImageVariants
{
    /**
     * A `src` naming an original, in double quotes — the only quoting the
     * sanitiser (Core\Security\HtmlSanitizer, DOM-serialised) ever writes.
     */
    private const ORIGINAL_SRC = '~(<img\b[^>]*?\ssrc=")/files/(\d+)(")~i';

    /**
     * @param \Closure(int): bool $hasMedium whether the file has an `md`
     *        rendition on disk
     */
    public static function rewrite(string $html, \Closure $hasMedium): string
    {
        if (!str_contains($html, '/files/')) {
            return $html;
        }

        return (string) preg_replace_callback(
            self::ORIGINAL_SRC,
            static fn (array $m): string => $hasMedium((int) $m[2])
                ? $m[1] . '/files/' . $m[2] . '/md' . $m[3]
                : $m[0],
            $html
        );
    }
}
