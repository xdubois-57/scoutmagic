<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

/**
 * Where modules' Desk-import listeners plug in (ARCHITECTURE.md §7.4,
 * mutable-registry shape of §7.6): core builds this empty, hands it to
 * DeskImportService once, and each module block appends its listener from
 * inside its own `if ($isEnabled(...))` block in `public/index.php` —
 * however far down that block sits. The service reads the registry at
 * import time, so a listener registered after the service was constructed
 * still reconciles, which is exactly why this is an object with a
 * `register()` method and not an array passed by value (the same reason
 * Modules\Calendar\Service\VirtualEventRegistry gives).
 *
 * Before this registry existed the composition root had to build the
 * rental listener BEFORE DeskImportService — a second, early rental block
 * two thousand lines from the module's real one.
 */
class DeskImportListenerRegistry
{
    /** @var DeskImportListener[] */
    private array $listeners = [];

    public function register(DeskImportListener $listener): void
    {
        $this->listeners[] = $listener;
    }

    /** @return DeskImportListener[] */
    public function all(): array
    {
        return $this->listeners;
    }
}
