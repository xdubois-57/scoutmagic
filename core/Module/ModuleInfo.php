<?php

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
