<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Api;

/**
 * @deprecated Use {@see \Core\Http\LinkPreviewFetcher}. Kept for one
 * release so an out-of-tree module type-hinting the old name still works.
 *
 * The contract moved to core because the rule it carries — one
 * implementation of "fetch a URL a member typed", beside the SSRF
 * validator that makes it safe (SECURITY.md §17) — is a rule about the
 * whole installation, not about the module that needed it first. Gallery
 * still holds the one implementation.
 *
 * An ALIAS rather than a sub-interface on purpose: an object implementing
 * only the core interface is not an instance of a sub-interface of it, so
 * extending would have broken exactly the call sites this file exists to
 * keep working.
 */
class_alias(\Core\Http\LinkPreviewFetcher::class, 'Modules\\Gallery\\Api\\LinkPreviewFetcher');
