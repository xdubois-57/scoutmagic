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
     * A communication, to its author or an administrator.
     *
     * Two shapes, and the difference is where the card's image and title
     * come from (docs/chantiers/CHANTIER-medias-sociaux.md, IT-01):
     *
     * - written from nothing: its OWN image — a gallery photo read through
     *   the gallery's Api at every use, so its visibility is asked again
     *   then, and published blurred; or an uploaded file, which goes as it
     *   is — and its own title;
     * - opened from a source: the SOURCE's image, title and link, asked of
     *   the owning module's own rule at every use. Nothing of the source is
     *   copied into this row, which is why there is one answer to « what
     *   is on this card » rather than two that can disagree.
     *
     * A source that has since been deleted does not 404 the page: the
     * communication is returned BLOCKED, with the reason, because a chief
     * who opens it is owed an explanation rather than a missing page.
     */
    public function communication(
        int $communicationId,
        string $role,
        int $accountId,
        ?string $email = null
    ): ?ShareSource {
        $communication = $this->editableCommunication($communicationId, $role, $accountId);
        if ($communication === null) {
            return null;
        }

        return $communication->hasSource()
            ? $this->sourceBackedCommunication($communication, $role, $accountId, $email)
            : new ShareSource(
                ShareSource::KIND_COMMUNICATION,
                $communication->id,
                $communication->title,
                $this->communicationImage($communication, $role),
                $communication->galleryMediaId !== null,
                null,
                $this->address(''),
                $communication->body,
                self::path($communication->id),
                trim($communication->title) === '' ? 'Donnez d\'abord un titre à l\'image.' : null,
                null,
                null,
                // The slider's position, as this row kept it (issue #706,
                // IT-02). Null when nothing was ever chosen.
                $communication->blurRatio
            );
    }

    /**
     * The communication's own identity — so its publications are recorded
     * against it, and « the same communication twice to the same
     * destination » keeps meaning what it says — carrying the source's
     * image, title and link.
     */
    private function sourceBackedCommunication(
        Communication $communication,
        string $role,
        int $accountId,
        ?string $email
    ): ShareSource {
        $source = $this->describeSource(
            (string) $communication->sourceKind,
            (int) $communication->sourceId,
            $role,
            $accountId,
            $email
        );

        if ($source === null) {
            return new ShareSource(
                ShareSource::KIND_COMMUNICATION,
                $communication->id,
                '',
                null,
                false,
                null,
                $this->address(''),
                $communication->body,
                self::path($communication->id),
                self::vanishedSourceReason((string) $communication->sourceKind),
                null,
                null,
                $communication->blurRatio
            );
        }

        return new ShareSource(
            ShareSource::KIND_COMMUNICATION,
            $communication->id,
            $source->title,
            $source->image,
            $source->imageFromGallery,
            $source->link,
            $source->address,
            $communication->body,
            self::path($communication->id),
            $source->blockedReason,
            $source->pageUrl,
            // What it IS, beside the `communication` identity its
            // publications are recorded under — so a message about a
            // missing image can name the album instead of telling the
            // chief to choose one here, where there is no button to.
            $source->kind,
            // The slider's position belongs to THIS share, not to the
            // album: two shares of one album may be blurred differently,
            // and the album has no slider of its own (issue #706, IT-02).
            $communication->blurRatio
        );
    }

    /**
     * An album or an article, by kind — the one place that turns the two
     * stored strings back into the owning module's answer, so a new kind is
     * added here and nowhere else.
     */
    public function describeSource(
        string $kind,
        int $id,
        string $role,
        int $accountId,
        ?string $email
    ): ?ShareSource {
        return match ($kind) {
            ShareSource::KIND_ALBUM => $this->album($id, $role, $email ?? ''),
            ShareSource::KIND_ARTICLE => $this->article($id, $role, $accountId),
            default => null,
        };
    }

    /**
     * What a composer says it is sharing, for the one line above the card:
     * « Partage de l'album Week-end de rentrée ».
     */
    public static function sourceLabel(string $kind): ?string
    {
        return match ($kind) {
            ShareSource::KIND_ALBUM => 'l\'album',
            ShareSource::KIND_ARTICLE => 'l\'actualité',
            default => null,
        };
    }

    private static function vanishedSourceReason(string $kind): string
    {
        return match ($kind) {
            ShareSource::KIND_ALBUM => 'L\'album partagé n\'existe plus, ou n\'est plus à vous : cette'
                . ' communication ne peut plus être publiée.',
            ShareSource::KIND_ARTICLE => 'L\'actualité partagée n\'existe plus, ou n\'est plus à vous : cette'
                . ' communication ne peut plus être publiée.',
            default => 'Ce que cette communication partageait n\'existe plus : elle ne peut plus être publiée.',
        };
    }

    public static function path(int $communicationId): string
    {
        return '/medias-sociaux/' . $communicationId;
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
