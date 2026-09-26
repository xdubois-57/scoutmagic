<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Api;

use Core\Exception\UserFacingException;

/**
 * Why a post another module asked for was not published — a sentence
 * written for the person who asked, never a technical detail.
 */
final class GroupPublishException extends \RuntimeException implements UserFacingException
{
}
