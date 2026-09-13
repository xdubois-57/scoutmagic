<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location;

use Core\Exception\UserFacingException;

/**
 * A storage location refusing something an administrator asked for: a
 * deletion while a consumer still depends on it, a label already taken, a
 * configuration that cannot describe a reachable destination.
 *
 * Marked {@see UserFacingException}: every message this class carries is
 * written at its own `throw` site, in French, naming only what the
 * administrator typed — a label, a folder, a bucket — and never a class, a
 * path under the code, a driver's own words. Never construct it from
 * another exception's message; write the sentence here and let `$previous`
 * carry the technical detail to the journal.
 */
class StorageLocationException extends \RuntimeException implements UserFacingException
{
}
