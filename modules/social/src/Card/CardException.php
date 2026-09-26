<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Card;

use Core\Exception\UserFacingException;

/**
 * A card that could not be made. Every message is a French sentence meant
 * for the person who asked for it.
 */
final class CardException extends \RuntimeException implements UserFacingException
{
}
