<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Service;

use Core\Config\SettingService;
use Core\File\StoredFileReader;
use Modules\Gallery\Api\AlbumShareSourceInterface;
use Modules\News\Api\ArticleShareSourceInterface;

/**
 * Turns an album or an article into a {@see ShareSource}, through the
 * gallery's and the news module's own `Api` (ARCHITECTURE.md §7.5): both
 * are optional, and a disabled one simply has nothing to share.
 *
 * Who may share is theirs to say — they answer only to someone who
 * manages the album or may edit the article — so this class never decides
 * it again.
 */
final class ShareSourceResolver
{
    public function __construct(
        private readonly SettingService $settings,
        private readonly StoredFileReader $files,
        private readonly ?AlbumShareSourceInterface $albums = null,
        private readonly ?ArticleShareSourceInterface $articles = null
    ) {
    }

    public function album(int $albumId, string $role, string $email): ?ShareSource
    {
        $album = $this->albums?->describe($albumId, $role, $email);
        if ($album === null) {
            return null;
        }

        $address = $this->address($album->path);

        return new ShareSource(
            ShareSource::KIND_ALBUM,
            $album->id,
            $album->title,
            $album->coverContents,
            true,
            null,
            $address,
            'Les photos de « ' . $album->title . ' » sont en ligne !'
                . ($address !== '' ? ' À voir sur ' . $address : ''),
            '/gallery/' . $album->id . '/edit'
        );
    }

    public function article(int $articleId, string $role, int $accountId): ?ShareSource
    {
        $article = $this->articles?->describe($articleId, $role, $accountId);
        if ($article === null) {
            return null;
        }

        $address = self::withoutScheme($article->url);

        return new ShareSource(
            ShareSource::KIND_ARTICLE,
            $article->id,
            $article->title,
            $article->imageFileId !== null ? $this->files->read($article->imageFileId) : null,
            false,
            $article->url !== '' ? $article->url : null,
            $address,
            $article->title . ($address !== '' ? ' — à lire sur ' . $address : ''),
            '/news/' . $article->id . '/gerer',
            $article->shareable
                ? null
                : 'Cette actualité est réservée aux animateurs : elle ne peut pas quitter le site.'
        );
    }

    private function address(string $path): string
    {
        $base = rtrim((string) ($this->settings->get('base_url') ?: ''), '/');

        return $base === '' ? '' : self::withoutScheme($base . $path);
    }

    private static function withoutScheme(string $url): string
    {
        return (string) preg_replace('#^https?://#i', '', $url);
    }
}
