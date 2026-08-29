<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Module;

/**
 * A module declares the sub-processors ("sous-traitants", RGPD art. 28)
 * its CURRENT configuration actually engages, so the RGPD page's
 * generation prompt can state them without core reading any module's
 * tables (ARCHITECTURE.md §7.4 — core defines the interface, the module
 * implements it, the composition root wires it only while the module is
 * enabled).
 *
 * The hook is DYNAMIC on purpose: an implementation inspects its real
 * configuration and answers only what is effectively active — the S3
 * provider actually configured, the AI provider actually enabled and its
 * assigned models. A potential sub-processor that nothing is configured
 * to reach is NOT a sub-processor, and declaring it would make the RGPD
 * document claim a data flow that does not exist. The empty list is the
 * ordinary answer for a module whose current configuration keeps every
 * byte on the unit's own server.
 */
interface SubProcessorProvider
{
    /**
     * @return list<SubProcessorView> the effectively engaged
     *         sub-processors, with location, in French — empty when the
     *         current configuration engages none
     */
    public function getSubProcessors(): array;
}
