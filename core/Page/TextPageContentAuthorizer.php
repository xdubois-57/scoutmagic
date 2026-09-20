<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Page;

use Core\Service\TextNormalizerService;
use Core\View\EditableContentAuthorizer;

/**
 * A free-text page's body is written at the page's OWN role, not at the
 * write endpoint's (ARCHITECTURE.md §8.115, SECURITY.md §3).
 *
 * `POST /api/editable-content` is `role_min: admin`. A page filed in the
 * Configuration menu is read at `superadmin`. Without this, an `admin`
 * refused the page itself could still overwrite its body by naming the
 * key `page_content_{id}` — the read floor would exceed the write floor,
 * which for every other `editable()` key on this site it never does.
 *
 * ### Why this does not try to imitate the database's collation
 *
 * `editable_contents` is `utf8mb4_unicode_ci` and
 * {@see \Core\View\EditableContentRepository} compares with a plain
 * `WHERE content_key = ?`. That collation equates far more spellings than
 * it looks: case (`Page_Content_7`), accents (`pagé_content_7`), trailing
 * spaces (PAD SPACE), fullwidth digits (`page_content_７`), and every
 * primary-ignorable character — a soft hyphen dropped between two digits
 * of the id, for one. Each of those lands on the real row.
 *
 * Two attempts at modelling that equivalence were both too narrow, and
 * each miss reopened the escalation in full. So the rule is inverted, and
 * this is the shape that does not depend on guessing right:
 *
 * 1. **The exact canonical spelling** — and only it — is honoured, with
 *    the page's own role. It is the only spelling the application itself
 *    ever writes ({@see TextPage::contentKey()}).
 * 2. **Anything that could be read as a page key is refused**, whatever
 *    id it appears to name. A non-canonical spelling of a page key is
 *    never legitimate: nothing in this site produces one, so a request
 *    carrying one is either broken or probing.
 * 3. **Everything else** — `home.intro`, `banner_content_3` — is none of
 *    this authorizer's business, and keeps the endpoint's own floor.
 *
 * The test for (2) is deliberately coarse: strip the key of everything
 * that is not an ASCII alphanumeric and see whether what remains opens
 * with the page namespace. Coarse is the right direction here — refusing
 * a key that could never have collided costs a write nothing legitimate
 * was making, while abstaining on one that does collide costs the
 * escalation. It also degrades safely where `intl` is absent: an
 * un-normalisable character is stripped rather than trusted, which lands
 * in (2) rather than in (3).
 */
final class TextPageContentAuthorizer implements EditableContentAuthorizer
{
    /**
     * `page content` followed by the id — matched against the FOLDED
     * key, which is why the separators are spaces.
     */
    private const FOLDED_KEY = '/^page content (\d+)$/';

    /**
     * What a key reduces to once every non-alphanumeric is gone. A key
     * whose reduction opens with this is claiming to be a page's.
     */
    private const NAMESPACE_PREFIX = 'pagecontent';

    public function __construct(private TextPageRepository $repository)
    {
    }

    public function roleMinForKey(string $key): ?string
    {
        $folded = self::fold($key);

        if (preg_match(self::FOLDED_KEY, $folded, $matches) === 1) {
            if ($key === 'page_content_' . $matches[1]) {
                // The canonical spelling — the only one this site writes.
                //
                // **Deliberately not filtered on `is_active`.** A hidden
                // page's text is still that page's text: switching a page
                // off must not hand its body to a wider audience than the
                // page itself has.
                $page = $this->repository->findById((int) $matches[1]);

                // Naming no page is refused rather than abstained on.
                // Writing it would create a content row nothing can ever
                // name again — the orphan that deleting a page exists to
                // prevent.
                return $page?->roleMin() ?? 'superadmin';
            }

            // Some other spelling of a page key. Refused without
            // resolving which page: no legitimate caller ever sends one.
            return 'superadmin';
        }

        if (str_starts_with(str_replace(' ', '', $folded), self::NAMESPACE_PREFIX)) {
            // Claims the namespace without naming a usable id.
            return 'superadmin';
        }

        if (self::carriesSomethingNoFoldRecognises($key)) {
            // A character that neither compatibility folding nor the
            // explicit accent map knows what to do with — so nothing
            // this application writes, and something the database may
            // still equate with a key that matters. Refused rather than
            // abstained on.
            return 'superadmin';
        }

        return null;
    }

    /**
     * The key reduced to lowercase letters, digits and single spaces.
     *
     * Three steps, and each closes a spelling that reached the real row
     * while an earlier version of this class abstained:
     *
     * 1. **Compatibility decomposition** (`FORM_KD`, not `FORM_D`).
     *    Fullwidth characters — `ｐ` U+FF50, `７` U+FF17 — have a
     *    compatibility mapping and no canonical one, so `FORM_D` leaves
     *    them untouched and the step below then deletes them. `FORM_KD`
     *    folds them to ASCII, which is what `utf8mb4_unicode_ci` does
     *    too.
     * 2. **{@see TextNormalizerService::fold()}**, for the explicit
     *    accent map that does not need `intl` and for the lowercasing.
     *    Without it, `pagé_content_7` would reduce to `pagcontent7` —
     *    the letter lost rather than folded — and stop looking like a
     *    page key at all.
     * 3. Everything that is neither a letter nor a digit becomes a
     *    space, so `_`, `.`, `-`, a soft hyphen and a trailing space all
     *    read alike.
     */
    private static function fold(string $key): string
    {
        return TextNormalizerService::fold(self::compatible($key));
    }

    /**
     * Whether the key carries a character the folding above **drops**
     * rather than maps.
     *
     * The distinction matters because `fold()`'s last step turns
     * everything that is not a letter or a digit into a space — so an
     * unmappable character leaves no trace, and a key built around one
     * folds to something perfectly ordinary. `home.intro` with a
     * zero-width space in it folds to exactly `home intro`.
     *
     * Asked one character at a time, and through `fold()` itself rather
     * than through a second copy of its map: a character it answers ''
     * for is one it does not recognise. Plain ASCII is exempt — that is
     * the alphabet every key this application writes is made of.
     */
    private static function carriesSomethingNoFoldRecognises(string $key): bool
    {
        $characters = preg_split('//u', self::compatible($key), -1, PREG_SPLIT_NO_EMPTY);

        foreach ($characters ?: [] as $character) {
            if (preg_match('/^[\x20-\x7E]$/', $character) === 1) {
                continue;
            }

            if (TextNormalizerService::fold($character) === '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Compatibility decomposition, with the accents it produces removed
     * — step 1 of {@see fold()}.
     *
     * The mark-stripping is not decoration: `FORM_KD` turns « é » into
     * `e` plus a combining acute, and a bare combining acute is exactly
     * the kind of character {@see carriesSomethingNoFoldRecognises()}
     * would otherwise flag as unrecognised. It IS recognised — dropping
     * an accent is what folding does — so it must not reach that scan.
     */
    private static function compatible(string $key): string
    {
        if (!class_exists(\Normalizer::class)) {
            return $key;
        }

        $compatible = \Normalizer::normalize($key, \Normalizer::FORM_KD);
        if (!is_string($compatible)) {
            return $key;
        }

        return (string) preg_replace('/\p{Mn}/u', '', $compatible);
    }
}
