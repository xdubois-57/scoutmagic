<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Storage\Location;

use Core\Storage\Location\Backend\StorageBackendInterface;

/**
 * Which targets the service looked at before attaching a location to
 * them (#832), and the backend each look is answered from.
 */
final class ProbeRecorder
{
    /** @var list<string> */
    public array $targets = [];

    public function __construct(public readonly StorageBackendInterface $backend)
    {
    }
}
