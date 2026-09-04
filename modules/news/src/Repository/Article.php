<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Repository;

final class Article
{
    public const VISIBILITY_PUBLIC = 'public';
    /**
     * Reserved to identified members — animés, their parents and the
     * staff, once signed in. A real rung of the role ladder, unlike
     * VISIBILITY_DIRECT_LINK below, which means "unlisted" and grants
     * everyone who holds the address.
     */
    public const VISIBILITY_IDENTIFIED = 'identified';
    public const VISIBILITY_CHIEF = 'chief';
    public const VISIBILITY_ADMIN = 'admin';
    public const VISIBILITY_DIRECT_LINK = 'direct_link';

    /** @var string[] */
    public const VISIBILITIES = [
        self::VISIBILITY_PUBLIC,
        self::VISIBILITY_IDENTIFIED,
        self::VISIBILITY_CHIEF,
        self::VISIBILITY_ADMIN,
        self::VISIBILITY_DIRECT_LINK,
    ];

    /**
     * Visibilities whose article a caller with no session may read, and
     * therefore the only ones whose title, summary and cover image may
     * reach a social-network crawler as og: metadata. Everything else
     * has a body worth protecting and a preview worth protecting with
     * it — see Service\ArticleService::isSociallyShareable().
     *
     * @var string[]
     */
    public const PUBLICLY_READABLE_VISIBILITIES = [self::VISIBILITY_PUBLIC, self::VISIBILITY_DIRECT_LINK];

    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $visibility,
        public readonly bool $hasForm,
        public readonly bool $isIndexed,
        public readonly ?string $seoKeywords,
        public readonly ?string $seoStopDate,
        public readonly ?string $shortUrlCode,
        public readonly int $createdBy,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly ?string $summary = null,
        public readonly ?int $imageFileId = null
    ) {
    }

    /**
     * Effective SEO indexing state — always computed live (module spec
     * §16: no cron for this). noindex when is_indexed is off, OR
     * seo_stop_date has passed, OR visibility is direct_link or
     * identified (enforced again here as a rendering-time safety net on
     * top of Service\ArticleService already refusing to persist
     * is_indexed=true for either of those in the first place).
     */
    public function isEffectivelyIndexed(?\DateTimeImmutable $now = null): bool
    {
        if (!$this->isIndexed || in_array($this->visibility,
            [self::VISIBILITY_DIRECT_LINK, self::VISIBILITY_IDENTIFIED], true)) {
            return false;
        }

        if ($this->seoStopDate !== null) {
            $now ??= new \DateTimeImmutable();
            if ($now->format('Y-m-d') > $this->seoStopDate) {
                return false;
            }
        }

        return true;
    }
}
