<?php

declare(strict_types=1);

namespace Modules\News\Repository;

final class Article
{
    public const VISIBILITY_PUBLIC = 'public';
    public const VISIBILITY_CHIEF = 'chief';
    public const VISIBILITY_ADMIN = 'admin';
    public const VISIBILITY_DIRECT_LINK = 'direct_link';

    /** @var string[] */
    public const VISIBILITIES = [self::VISIBILITY_PUBLIC, self::VISIBILITY_CHIEF, self::VISIBILITY_ADMIN, self::VISIBILITY_DIRECT_LINK];

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
        public readonly string $updatedAt
    ) {
    }

    /**
     * Effective SEO indexing state — always computed live (module spec
     * §16: no cron for this). noindex when is_indexed is off, OR
     * seo_stop_date has passed, OR visibility is direct_link (enforced
     * again here as a rendering-time safety net on top of
     * Service\ArticleService already refusing to persist is_indexed=true
     * for a direct_link article in the first place).
     */
    public function isEffectivelyIndexed(?\DateTimeImmutable $now = null): bool
    {
        if (!$this->isIndexed || $this->visibility === self::VISIBILITY_DIRECT_LINK) {
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
