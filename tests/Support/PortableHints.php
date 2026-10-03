<?php

declare(strict_types=1);

namespace Tests\Support;

use Core\Maintenance\Portable\PortableArchiveHints;

/**
 * The clear hints a portable archive comment must carry (#719), for tests
 * that write a comment by hand.
 */
final class PortableHints
{
    public static function sample(string $version = '2.4.1'): PortableArchiveHints
    {
        return PortableArchiveHints::now(
            $version,
            'https://unite.example',
            PortableArchiveHints::KIND_MANUAL,
            null
        );
    }
}
