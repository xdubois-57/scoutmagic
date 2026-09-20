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
     * The one spelling the application writes — `page_content_` followed
     * by the id, matched on the raw bytes.
     */
    private const CANONICAL_KEY = '/^page_content_(\d+)$/';

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
        if (preg_match(self::CANONICAL_KEY, $key, $matches) === 1) {
            // **Deliberately not filtered on `is_active`.** A hidden
            // page's text is still that page's text: switching a page off
            // must not hand its body to a wider audience than the page
            // itself has.
            $page = $this->repository->findById((int) $matches[1]);

            // A canonical key naming no page is refused rather than
            // abstained on. Writing it would create a content row nothing
            // can ever name again — the orphan that deleting a page
            // exists to prevent — and answering `null` would let the
            // endpoint's own floor write it.
            return $page?->roleMin() ?? 'superadmin';
        }

        if (str_starts_with(self::reduce($key), self::NAMESPACE_PREFIX)) {
            // Some other spelling of a page key. The database may well
            // consider it the same row; this authorizer does not need to
            // know which one, because no legitimate caller ever sends it.
            return 'superadmin';
        }

        return null;
    }

    /**
     * The key reduced to bare letters and digits.
     *
     * Not a normalisation and not meant to be one: it is a test for
     * "could this be claiming the page namespace". Characters the
     * database ignores or folds — a soft hyphen, a fullwidth digit, an
     * accent, a separator, a trailing space — all disappear here too,
     * which is what makes the answer safe without matching the
     * collation's rules one by one.
     *
     * {@see TextNormalizerService::fold()} does the half that needs
     * care: it lowercases and folds accents through an explicit map that
     * does not depend on the host having `intl`, so « pagé » reduces to
     * « page » rather than losing the letter altogether. Removing its
     * spaces afterwards does the rest. Stripping non-alphanumerics
     * without folding first would turn `pagé_content_7` into
     * `pagcontent7` — which no longer opens with the namespace, and
     * would abstain on exactly the spelling the database equates.
     */
    private static function reduce(string $key): string
    {
        return str_replace(' ', '', TextNormalizerService::fold($key));
    }
}
