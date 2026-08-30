<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

/**
 * A refused change to the super-admin accounts, stated in French for the
 * person who attempted it (« Vous ne pouvez pas retirer votre propre
 * accès superadmin. »).
 *
 * Marked {@see \Core\Exception\UserFacingException}: every message this
 * class is constructed with is a full French sentence naming only what
 * the visitor can see — an account, their own access, the last remaining
 * administrator — and never a table, a column or a class. Same "typed
 * domain exception, caught by the controller, message shown as-is"
 * convention as Core\Member\SectionException.
 */
class SuperAdminException extends \RuntimeException implements \Core\Exception\UserFacingException
{
}
