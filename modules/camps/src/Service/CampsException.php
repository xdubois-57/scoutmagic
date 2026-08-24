<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Service;

use Core\Exception\UserFacingException;

/**
 * A refusal this module means, with a sentence a chief can act on — never
 * a generic failure. Same posture as Modules\Gallery\Service\
 * GalleryException.
 *
 * Marked UserFacingException: every message thrown by this module names
 * what the chief typed and what to do about it ("Indiquez les dates du
 * séjour, ou au moins son année si vous ne les connaissez plus."), in
 * French, as a whole sentence, with no path, no SQL and no class name. A
 * new throw that cannot say that much belongs in a different exception,
 * not in a shortened message here.
 */
class CampsException extends \RuntimeException implements UserFacingException
{
}
