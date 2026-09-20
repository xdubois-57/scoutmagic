<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\OfficialDocuments\Api;

/**
 * One official document a member's page offers, ready to draw.
 *
 * Presentation-ready in the module's own words (ARCHITECTURE.md §7.5):
 * core renders the block and owns none of its vocabulary, so a document
 * added later — or a note that changes because a sheet was filled in — costs
 * nothing outside this module.
 */
final class DocumentLink
{
    public function __construct(
        /** French, the name a parent reads: « Autorisation parentale ». */
        public readonly string $label,
        /** French, one short line under it, or '' when there is nothing to say. */
        public readonly string $note,
        public readonly string $url
    ) {
    }
}
