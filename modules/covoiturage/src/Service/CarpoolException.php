<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Covoiturage\Service;

use Core\Exception\UserFacingException;

/**
 * A refusal this module means, with a sentence the reader can act on.
 *
 * Marked UserFacingException: every message thrown with it says, in
 * French and as a whole sentence, what was refused and what to do — « Il
 * reste 1 place libre dans cette voiture. » — and names nothing internal.
 * A throw that cannot say that much belongs in another exception.
 */
class CarpoolException extends \RuntimeException implements UserFacingException
{
}
