<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Repository;

final class Album
{
    public const TYPE_LOCAL = 'local';
    public const TYPE_EXTERNAL = 'external';

    /** @var string[] */
    public const TYPES = [self::TYPE_LOCAL, self::TYPE_EXTERNAL];

    public const MIGRATION_NONE = 'none';
    public const MIGRATION_IN_PROGRESS = 'in_progress';
    public const MIGRATION_FAILED = 'failed';

    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly string $title,
        public readonly ?string $subtitle,
        public readonly string $albumDate,
        public readonly ?int $sectionId,
        public readonly int $scoutYearId,
        public readonly ?int $coverMediaId,
        public readonly ?string $externalUrl,
        public readonly ?string $ogTitle,
        public readonly ?string $ogDescription,
        public readonly ?string $ogImageUrl,
        public readonly ?int $ogImageFileId,
        public readonly ?int $storageLocationId,
        public readonly string $migrationStatus,
        public readonly ?int $migrationTargetLocationId,
        public readonly ?string $migrationError,
        public readonly int $createdBy,
        public readonly string $createdAt,
        // Last, with defaults — same "append-only" constructor discipline
        // as Core\Module\ModuleManifest's own `requires` addition
        // (docs/module-development.md): AlbumRepository::hydrate() uses
        // named arguments so it isn't order-sensitive, but
        // tests/Modules/Gallery/Repository/AlbumTest.php constructs an
        // Album positionally, and moving these earlier would silently
        // shift every argument after them.
        public readonly ?string $ownerType = null,
        public readonly ?int $ownerId = null
    ) {
    }

    public function isLocal(): bool
    {
        return $this->type === self::TYPE_LOCAL;
    }

    /**
     * A delegated album is hosted and stored by gallery but owned and
     * access-controlled by another module (schema.sql's owner_type/
     * owner_id header comment) — excluded from every gallery-side
     * listing/picker and reachable only through its owning module.
     */
    public function isDelegated(): bool
    {
        return $this->ownerType !== null;
    }

    /**
     * For an external album, `og_title` is re-scraped (and re-decoded)
     * every time the URL changes or a chief hits "Rafraîchir"
     * (AlbumService::update()/refreshOg()), while `title` is only ever
     * set once at creation and never rewritten after — so an album
     * created before OgScraperService's UTF-8 decoding fix keeps stale
     * mojibake in `title` forever even after a refresh corrects
     * `og_title`. Preferring `og_title` here is what
     * GalleryController::cardContext() and
     * GalleryMemberQueryService::getAlbumsForMember() already do for the
     * member-facing pages; this makes every other rendering path
     * (currently just the chief "Gérer la galerie" list) agree.
     */
    public function displayTitle(): string
    {
        return $this->isLocal() || $this->ogTitle === null ? $this->title : $this->ogTitle;
    }

    /**
     * Media reads/listings must be gated while a migration is in flight (a
     * partially copied media set could render inconsistently) — a 'failed'
     * migration does NOT gate availability, since the source is always left
     * fully intact until the very last step of a successful run.
     */
    public function isMigrating(): bool
    {
        return $this->migrationStatus === self::MIGRATION_IN_PROGRESS;
    }
}
