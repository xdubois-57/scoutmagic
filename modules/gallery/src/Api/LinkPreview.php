<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Api;

/**
 * @deprecated Use {@see \Core\Http\LinkPreview}. Kept for one release
 * alongside {@see LinkPreviewFetcher}; see that file for why both are
 * aliases rather than subtypes.
 */
class_alias(\Core\Http\LinkPreview::class, 'Modules\\Gallery\\Api\\LinkPreview');
