<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service;

use Modules\Gallery\Api\GalleryException;

/**
 * A photo refused BEFORE decoding because decoding it would exceed the
 * memory PHP still allows (PhotoMemoryBudget, issue #1449). Carries the
 * figures Task\ProcessPhotoHandler journals; the message is the one
 * constructor-built French sentence below, written for the animateur who
 * sees the failed media — it names neither the file nor the host's
 * configuration.
 */
final class PhotoMemoryRefusedException extends GalleryException
{
    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly int $requiredBytes,
        public readonly int $availableBytes
    ) {
        parent::__construct(sprintf(
            'Cette photo (%s mégapixels) est trop lourde pour être traitée par le serveur : '
            . 'réduisez sa définition, puis envoyez-la à nouveau.',
            number_format($this->megapixels(), 1, ',', ' ')
        ));
    }

    public function megapixels(): float
    {
        return round($this->width * $this->height / 1_000_000, 1);
    }
}
