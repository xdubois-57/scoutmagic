<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Badge;

/**
 * A refused badge operation, stated in French for the chef who attempted
 * it (« Ce badge est déjà attribué à un membre — désactivez-le au lieu de
 * le supprimer. »).
 *
 * Marked {@see \Core\Exception\UserFacingException}.
 */
class BadgeException extends \RuntimeException implements \Core\Exception\UserFacingException
{
}
