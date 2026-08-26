<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Module;

/**
 * The registration request a member came from — a pointer, not a copy.
 *
 * Deliberately carries no field of the request itself. The request has
 * its own page, built by the module that owns it, and duplicating even
 * three of its fields here would create a second place to keep in step
 * with Desk. What the admin member page needs is one line saying "this
 * person arrived through a request, here it is".
 *
 * `path` is the module's own route, supplied by the module: core links
 * to it without ever learning that `/config/inscriptions/demandes/{id}`
 * is where a request lives.
 */
final class MemberRegistrationOriginView
{
    /**
     * @param string $label       how to name it, e.g. "Demande du 14/03/2025"
     * @param string $path        site-absolute path of the request's page
     * @param string $statusLabel where the request ended up, in the module's
     *        own words — a member can exist while their request reads
     *        "encodée", and saying which is the point of showing it
     */
    public function __construct(
        public readonly string $label,
        public readonly string $path,
        public readonly string $statusLabel,
    ) {
    }
}
