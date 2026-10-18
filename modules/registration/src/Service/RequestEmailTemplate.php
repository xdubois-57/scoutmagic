<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Template\TokenCatalogue;

/**
 * One of the four e-mails of the request flow, as « Gabarits » lists it
 * (issue #843, D10): where its body is stored, the address segment of its
 * edit page, its name and its French description, the body it ships with
 * and the closed list of `{{ variables }}` its sender substitutes.
 *
 * Built only by {@see RequestEmailTemplateService::all()}: the catalogue
 * is one list, and nothing else decides which e-mails it holds.
 */
final class RequestEmailTemplate
{
    public function __construct(
        public readonly string $key,
        public readonly string $slug,
        public readonly string $name,
        public readonly string $description,
        public readonly string $defaultBody,
        public readonly TokenCatalogue $variables
    ) {
    }

    /**
     * Whether this e-mail goes to a family, which is to say whether it
     * declares `{{ lien_suivi }}` — and so must carry it (issue #932).
     */
    public function requiresTrackingLink(): bool
    {
        return $this->variables->has(RequestEmailTemplateService::TRACKING_LINK_VARIABLE);
    }
}
