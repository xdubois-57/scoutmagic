<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Module;

/**
 * One EFFECTIVE sub-processor a module's current configuration engages,
 * as the RGPD page's generation prompt states it.
 *
 * Presentation-ready, like every DTO in this folder, and in French: the
 * module hands over the processor's name with its location already
 * worded (« Anthropic (États-Unis, hors UE) »), never its own driver or
 * provider vocabulary — core never learns what an `s3_provider` is.
 */
final class SubProcessorView
{
    /**
     * The closed category vocabulary, declared by core because the RGPD
     * generation prompt has to BRANCH on it (which reference section a
     * processor belongs to) — the same "small set of constants core
     * declares, and the module maps onto" rule as
     * MemberSettledPaymentView's statuses (docs/module-development.md
     * § Filling a block).
     */
    public const CATEGORY_AI = 'ai';
    public const CATEGORY_MEDIA_STORAGE = 'media_storage';

    /**
     * @param string $category one of the CATEGORY_* constants
     * @param string $name     the processor and its data location, worded
     *        for the RGPD document — « Hetzner Object Storage
     *        (Allemagne/Finlande, UE) »
     * @param string $purpose  what this unit uses it for, in French —
     *        « Traitement par intelligence artificielle », « Hébergement
     *        des photos et vidéos »
     * @param string|null $details anything the document should state
     *        beyond name and purpose — the AI models in use, a bucket
     *        region. Null when name and purpose say it all.
     */
    public function __construct(
        public readonly string $category,
        public readonly string $name,
        public readonly string $purpose,
        public readonly ?string $details = null,
    ) {
    }
}
