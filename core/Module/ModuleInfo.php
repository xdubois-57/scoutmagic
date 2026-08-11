<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Module;

class ModuleInfo
{
    public function __construct(
        public readonly ModuleManifest $manifest,
        public readonly bool $enabled,
        public readonly ?string $installedVersion,
        public readonly bool $presentOnDisk,
        public readonly ?string $validationError
    ) {
    }
}
