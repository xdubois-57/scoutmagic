<?php

declare(strict_types=1);

namespace Core\Module;

/**
 * Optional hook a module can implement to inject a message onto the
 * homepage (Core\Http\Controller\PageController::home()) — without core
 * depending on the module directly. Core defines this interface, the
 * module implements it, and the composition root (public/index.php) wires
 * the concrete implementation into PageController only when that module
 * is enabled. Same precedent as Core\Module\FunctionFlagsProvider and
 * Core\Scheduler\TaskHandlerInterface (see ARCHITECTURE.md §7.4).
 */
interface HomeBannerProvider
{
    /**
     * Already-sanitized HTML content of one randomly chosen active banner,
     * freshly picked on every call (never cached) — or null when there is
     * nothing to show (no banners configured, or none active). The
     * homepage must render nothing at all in that case, not an empty box.
     */
    public function getRandomBannerHtml(): ?string;
}
