<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

/**
 * Thrown inside the transaction of an automatic acceptance (issue #843,
 * IT-10) whose acceptance e-mail could not be planned, to roll that
 * acceptance back: Service\AutomaticAcceptanceService catches it, journals
 * it and ends the pass. Never shown to anybody — it carries no message
 * meant for a visitor and is not a Core\Exception\UserFacingException.
 */
final class AutomaticAcceptanceUnplanned extends \RuntimeException
{
}
