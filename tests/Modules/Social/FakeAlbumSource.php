<?php

declare(strict_types=1);

namespace Tests\Modules\Social;

use Modules\Gallery\Api\AlbumShareSourceInterface;
use Modules\Gallery\Api\SharedAlbum;

/**
 * The gallery as the social module sees it: one album, described only to
 * the address that manages it.
 */
final class FakeAlbumSource implements AlbumShareSourceInterface
{
    public const MANAGER_EMAIL = 'chef@unite.be';

    public function __construct(public ?SharedAlbum $album)
    {
    }

    public function describe(int $albumId, string $role, string $email): ?SharedAlbum
    {
        return $this->album !== null && $this->album->id === $albumId && $email === self::MANAGER_EMAIL
            ? $this->album
            : null;
    }
}
