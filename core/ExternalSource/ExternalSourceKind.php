<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ExternalSource;

/**
 * How deeply a registered page is checked.
 *
 * - `Content`: the page must answer 200 and still carry the expected
 *   content — the site reads it, or tells users to read it.
 * - `Link`: the page must merely be alive. Provider consoles sit behind a
 *   login, so a redirect to a sign-in page, a 401 or a 403 all prove the
 *   address still exists; only 404, 410, an unknown domain or no answer at
 *   all prove it does not.
 */
enum ExternalSourceKind: string
{
    case Content = 'content';
    case Link = 'link';
}
