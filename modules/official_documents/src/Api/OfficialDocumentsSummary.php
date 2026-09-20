<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\OfficialDocuments\Api;

/**
 * The whole of the block this module contributes to a member's own page: a
 * short list of documents, and the one sentence that has to sit under them.
 *
 * The member page already carries a dozen blocks, so this one stays two
 * links and a warning — that is the design, not a first iteration of
 * something larger.
 */
final class OfficialDocumentsSummary
{
    /**
     * @param list<DocumentLink> $links
     */
    public function __construct(
        public readonly array $links,
        /**
         * Why the block exists, in one French sentence: what the site
         * produces is a draft, and only the paper a parent signed counts.
         *
         * It is the module's own words rather than a line of core's
         * template because it is the module's own rule — and because the
         * one place it must NEVER appear is on the PDF itself, which is a
         * federation document the site does not write on.
         */
        public readonly string $warning
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->links === [];
    }
}
