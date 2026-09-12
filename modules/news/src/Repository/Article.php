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
     * Visibilities whose title, summary and cover image may reach a
     * social-network crawler as og: metadata.
     *
     * Wider than "what an anonymous caller may read", and deliberately
     * so (issue #211). Sharing an article to a Facebook group of
     * animateurs is a thing the unit actually does, and the crawler that
     * renders that preview never signs in — so a members-only article
     * whose preview is suppressed is posted as a bare link with no
     * title and no picture, which is the same as not being shareable at
     * all. `identified` therefore gets a preview — and gets it at a URL
     * that answers 200, since a crawler does not read a 403's body
     * (Controller\NewsController::renderSocialPreview()). What that page
     * carries is the preview and nothing more: the body, the form and
     * the author stay behind Service\ArticleService::canView(), and so
     * does the listing, which an anonymous visitor still never sees.
     *
     * What that costs is stated rather than discovered: the title, the
     * one-sentence summary and the cover image of a members-only
     * article are public from the moment it is published — the cover's
     * own `files.role_min` is set to `public` for exactly this reason
     * (Service\ArticleService::coverImageRoleMin()), since a preview
     * whose image 403s is not a preview.
     *
     * `chief` and `admin` stay out. A staff-only article is not
     * something anybody pastes into a group of parents, and its cover
     * keeps the article's own floor.
     *
     * @var string[]
     */
    public const SOCIALLY_SHAREABLE_VISIBILITIES = [
        self::VISIBILITY_PUBLIC,
        self::VISIBILITY_DIRECT_LINK,
        self::VISIBILITY_IDENTIFIED,
    ];

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
        if (!$this->isIndexed || in_array(
            $this->visibility,
            [self::VISIBILITY_DIRECT_LINK, self::VISIBILITY_IDENTIFIED],
            true
        )) {
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
