<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Api;

/**
 * One article of the previous site, as {@see NewsImportWriterInterface}
 * writes it.
 */
final class ImportedArticle
{
    /**
     * @param ?string $summary null when the old site had none — never invented (D4)
     * @param string $bodyHtml the body, HTML, sanitized by the writer; an
     *        `<img>` whose `src` is a key of $bodyImages is stored and pointed
     *        at the stored file, any other `<img>` is dropped
     * @param \DateTimeImmutable $publishedAt the historical publication moment, not in the future
     * @param string $visibility one of {@see NewsImportWriterInterface::VISIBILITIES}
     * @param ?ImportedImage $coverImage null when the old site had none (D4)
     * @param array<string, ImportedImage> $bodyImages `src` as written in $bodyHtml => image
     * @param string $importSource `<format>:<installation id>`, never a person
     * @param int $importedBy the account running the import, the article's `created_by`
     */
    public function __construct(
        public readonly string $title,
        public readonly ?string $summary,
        public readonly string $bodyHtml,
        public readonly \DateTimeImmutable $publishedAt,
        public readonly string $visibility,
        public readonly ?ImportedImage $coverImage,
        public readonly array $bodyImages,
        public readonly string $importSource,
        public readonly int $importedBy
    ) {
    }
}
