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
 * The role returned is the page's own {@see TextPage::roleMin()}, so the
 * two halves cannot drift: whoever may read the page is whoever may
 * write it, and both come from the menu it was filed in.
 */
final class TextPageContentAuthorizer implements EditableContentAuthorizer
{
    /**
     * `page content` followed by the id — matched against the FOLDED key,
     * which is why the separators are spaces here.
     *
     * **A case-sensitive match on the raw key would reopen the very
     * escalation this class closes.** `editable_contents` is declared
     * `utf8mb4_unicode_ci`, and `EditableContentRepository` compares with
     * a plain `WHERE content_key = ?`: the database considers
     * `Page_Content_7`, `pagé_content_7` and `page_content_7` the same
     * row. An authorizer that only recognised the third spelling would
     * abstain on the first two, hand them back to the endpoint's own
     * `admin` floor, and let the write land on the real row anyway.
     *
     * {@see TextNormalizerService::fold()} is the site's own
     * platform-independent folding — lowercase, an explicit accent map
     * that does not depend on the host's C library, and every run of
     * non-alphanumerics collapsed to one space. Matching on its output
     * covers case, accents and separator spelling in one step.
     *
     * It deliberately over-matches: a key like `page content 7`, which
     * the database would NOT equate with a page's key, is guarded too.
     * That asymmetry is the right way round. Guarding a key that is not a
     * page's costs an administrator a refusal on a key nothing uses;
     * abstaining on one that IS a page's costs the escalation.
     */
    private const KEY_PATTERN = '/^page content (\d+)$/';

    public function __construct(private TextPageRepository $repository)
    {
    }

    public function roleMinForKey(string $key): ?string
    {
        if (preg_match(self::KEY_PATTERN, TextNormalizerService::fold($key), $matches) !== 1) {
            // Not a page's key — this authorizer has nothing to say, and
            // the endpoint's own floor stands.
            return null;
        }

        // **Deliberately not `findActiveBySlug`-style filtering.** A
        // hidden page's text is still that page's text: switching a page
        // off must not hand its body to a wider audience than the page
        // itself has.
        $page = $this->repository->findById((int) $matches[1]);

        // A key of the right shape naming a page that does not exist is
        // refused rather than abstained on. Writing it would create a
        // content row nothing can ever name again — the orphan that
        // deleting a page exists to prevent — and answering `null` here
        // would let the endpoint's own floor write it.
        return $page?->roleMin() ?? 'superadmin';
    }
}
