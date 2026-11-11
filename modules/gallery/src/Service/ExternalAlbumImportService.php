<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service;

use Core\Config\SettingService;
use Core\Service\DateInput;
use Modules\Gallery\Api\ExternalAlbumUrl;
use Modules\Gallery\Api\ExternalAlbumWriter;
use Modules\Gallery\Api\GalleryException;
use Modules\Gallery\Api\ImportedExternalAlbum;
use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\AlbumRepository;

/**
 * The gallery's side of the data migration's « Galeries photo » import:
 * an external album created straight through the repository, without
 * {@see AlbumService::create()} and therefore without its
 * `dispatchAlbumPublished()` — see {@see ExternalAlbumWriter} for what
 * is left out and what is kept.
 */
class ExternalAlbumImportService implements ExternalAlbumWriter
{
    private const MAX_OG_TITLE_LENGTH = 255;

    public function __construct(
        private AlbumRepository $albums,
        private OgImageStore $ogImages,
        private SettingService $settings
    ) {
    }

    public function externalAlbumsAllowed(): bool
    {
        return (bool) $this->settings->get('gallery_allow_external', 'gallery', true);
    }

    public function findExternalAlbumByUrl(string $url): ?int
    {
        $normalized = ExternalAlbumUrl::normalize($url);
        if ($normalized === null) {
            return null;
        }

        foreach ($this->albums->findExternalByUrlPrefix($normalized) as $candidate) {
            if (ExternalAlbumUrl::normalize($candidate['external_url']) === $normalized) {
                return $candidate['id'];
            }
        }

        return null;
    }

    public function createImportedExternalAlbum(ImportedExternalAlbum $album): int
    {
        if (!$this->externalAlbumsAllowed()) {
            throw new GalleryException('Ce type d\'album est désactivé.');
        }
        $url = trim($album->url);
        if (ExternalAlbumUrl::normalize($url) === null) {
            throw new GalleryException('Le lien doit commencer par http:// ou https://.');
        }
        $title = trim($album->title);
        if ($title === '') {
            throw new GalleryException('Le titre est obligatoire.');
        }
        self::assertLength($url, ImportedExternalAlbum::MAX_URL_LENGTH, 'Le lien');
        self::assertLength($title, ImportedExternalAlbum::MAX_TITLE_LENGTH, 'Le titre');
        $subtitle = $album->subtitle !== null && trim($album->subtitle) !== '' ? trim($album->subtitle) : null;
        self::assertLength($subtitle, ImportedExternalAlbum::MAX_SUBTITLE_LENGTH, 'Le sous-titre');
        if (!DateInput::isIso($album->albumDate)) {
            throw new GalleryException('La date de l\'album est invalide.');
        }

        $id = $this->albums->create(
            Album::TYPE_EXTERNAL,
            $title,
            $subtitle,
            $album->albumDate,
            $album->sectionId,
            $album->scoutYearId,
            $url,
            null,
            $album->createdBy
        );

        $ogTitle = $album->ogTitle !== null && trim($album->ogTitle) !== ''
            ? mb_substr(trim($album->ogTitle), 0, self::MAX_OG_TITLE_LENGTH)
            : null;
        $ogDescription = $album->ogDescription !== null && trim($album->ogDescription) !== ''
            ? trim($album->ogDescription)
            : null;
        $imageId = $album->ogImageBytes !== null ? $this->ogImages->store($id, $album->ogImageBytes, $album->createdBy) : null;
        if ($ogTitle !== null || $ogDescription !== null || $imageId !== null) {
            // The image's own address is not known here — the fetcher hands
            // over its bytes, never a URL — and og_image_url is kept for
            // debugging only (schema.sql), so it stays empty.
            $this->albums->updateOgMetadata($id, $ogTitle, $ogDescription, null, $imageId);
        }

        return $id;
    }

    /**
     * @throws GalleryException
     */
    private static function assertLength(?string $value, int $max, string $label): void
    {
        if ($value !== null && mb_strlen($value) > $max) {
            throw new GalleryException("{$label} ne peut pas dépasser {$max} caractères.");
        }
    }
}
