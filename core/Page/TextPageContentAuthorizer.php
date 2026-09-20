<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Page;

use Core\View\EditableContentAuthorizer;

/**
 * A free-text page's body is written at the page's OWN role, not at the
 * write endpoint's (ARCHITECTURE.md §8.115, SECURITY.md §3).
 *
 * `POST /api/editable-content` is `role_min: admin`. A page filed in the
 * Configuration menu is read at `superadmin`. Without this, an `admin`
 * refused the page itself could still overwrite its body.
 *
 * The role returned is the page's own {@see TextPage::roleMin()}, so the
 * two halves cannot drift: whoever may read the page is whoever may
 * write it, and both come from the menu it was filed in.
 *
 * It is handed a page **id**, read from `editable_contents.text_page_id`
 * by the caller — never a content key to interpret. The reason is
 * written at length on {@see EditableContentAuthorizer}: a key is
 * compared by a case- and accent-insensitive collation, and no parser of
 * it can be trusted to agree with the write that follows.
 */
final class TextPageContentAuthorizer implements EditableContentAuthorizer
{
    /** What `editable_contents.text_page_id` means. */
    public const OWNER_KIND = 'text_page';

    public function __construct(private TextPageRepository $repository)
    {
    }

    public function roleMinForOwner(string $ownerKind, int $ownerId): ?string
    {
        if ($ownerKind !== self::OWNER_KIND) {
            return null;
        }

        // **Deliberately not filtered on `is_active`.** A hidden page's
        // text is still that page's text: switching a page off must not
        // hand its body to a wider audience than the page itself has.
        $page = $this->repository->findById($ownerId);

        // A row claiming a page that is gone is refused rather than
        // abstained on. It should not be possible — the foreign key
        // cascades — and if it ever is, the narrowest answer is the only
        // safe one.
        return $page?->roleMin() ?? 'superadmin';
    }
}
