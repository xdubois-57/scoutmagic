<?php

declare(strict_types=1);

namespace Tests\Modules\Social;

use Modules\Gallery\Api\PhotoPickerInterface;
use Modules\Gallery\Api\PickablePhoto;

/**
 * The gallery's photo picker, played: a few photos a chief may see, and
 * their bytes — the gallery's own rule is PhotoPickerServiceTest's.
 */
final class FakePhotoPicker implements PhotoPickerInterface
{
    /** @param array<int, string> $photos media id => bytes */
    public function __construct(public array $photos)
    {
    }

    public function pickablePhotos(string $role, array $linkedMemberIds): array
    {
        $offered = [];
        foreach (array_keys($this->photos) as $id) {
            $offered[] = new PickablePhoto($id, 'Camp', '/gallery/media/' . $id . '/thumb', false);
        }

        return $role === 'intendant' ? [] : $offered;
    }

    public function photoContents(int $mediaId, string $role, array $linkedMemberIds): ?string
    {
        return $this->photos[$mediaId] ?? null;
    }
}
