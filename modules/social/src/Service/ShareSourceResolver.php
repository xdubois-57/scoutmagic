<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Service;

use Core\Config\SettingService;
use Core\File\StoredFileReader;
use Core\Security\Role;
use Modules\Gallery\Api\AlbumShareSourceInterface;
use Modules\Gallery\Api\PhotoPickerInterface;
use Modules\News\Api\ArticleShareSourceInterface;
use Modules\Social\Repository\Communication;
use Modules\Social\Repository\CommunicationRepository;

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
        private readonly ?ArticleShareSourceInterface $articles = null,
        private readonly ?CommunicationRepository $communications = null,
        private readonly ?PhotoPickerInterface $photos = null,
        /** @var array<int, int> the members this session is linked to, for the gallery's rule */
        private readonly array $linkedMemberIds = []
    ) {
    }

    /**
     * A free communication, to its author or an administrator. A gallery
     * photo is read through the gallery's Api at every use — its
     * visibility is asked again then — and published blurred; an uploaded
     * image goes as it is.
     */
    public function communication(int $communicationId, string $role, int $accountId): ?ShareSource
    {
        $communication = $this->editableCommunication($communicationId, $role, $accountId);
        if ($communication === null) {
            return null;
        }

        return new ShareSource(
            ShareSource::KIND_COMMUNICATION,
            $communication->id,
            $communication->title,
            $this->communicationImage($communication, $role),
            $communication->galleryMediaId !== null,
            null,
            $this->address(''),
            $communication->body,
            '/communications/' . $communication->id,
            trim($communication->title) === '' ? 'Donnez d\'abord un titre à l\'image.' : null
        );
    }

    /**
     * The communication itself, when this caller may change and publish
     * it: its author, or an administrator.
     */
    public function editableCommunication(int $communicationId, string $role, int $accountId): ?Communication
    {
        $communication = $this->communications?->find($communicationId);
        if ($communication === null) {
            return null;
        }

        return Role::fromString($role)->hasAccess(Role::ADMIN) || $communication->createdBy === $accountId
            ? $communication
            : null;
    }

    private function communicationImage(Communication $communication, string $role): ?string
    {
        if ($communication->galleryMediaId !== null) {
            return $this->photos?->photoContents($communication->galleryMediaId, $role, $this->linkedMemberIds);
        }

        return $communication->fileId !== null ? $this->files->read($communication->fileId) : null;
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
            '/gallery/' . $album->id . '/edit',
            null,
            $this->absolute($album->path)
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
                : 'Cette actualité est réservée aux animateurs : elle ne peut pas quitter le site.',
            $article->url !== '' ? $article->url : null
        );
    }

    private function absolute(string $path): ?string
    {
        $base = rtrim((string) ($this->settings->get('base_url') ?: ''), '/');

        return $base === '' ? null : $base . $path;
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
