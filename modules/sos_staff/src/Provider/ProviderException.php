<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SosStaff\Provider;

use Core\Exception\UserFacingException;

/**
 * Any failure talking to a telephony provider — message is always
 * human-readable (French) and safe to display directly to an admin — the
 * claim {@see UserFacingException} makes. The two sites that re-wrap an
 * Ovh\OvhApiException forward its sentence through
 * {@see \Core\Exception\UserFacingMessage::from()}, so the claim is checked
 * rather than assumed.
 */
class ProviderException extends \RuntimeException implements UserFacingException
{
}
