<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location;

/**
 * A location about to be attached to a target that already holds files
 * (#832): not a refusal of the target, but of attaching to it unseen.
 *
 * The screen answers it by asking the administrator to confirm, then
 * sends the same request again with the confirmation. Its own class so
 * that the screen can tell it from every other refusal, which no
 * confirmation would lift.
 */
final class StorageLocationTargetNotEmptyException extends StorageLocationException
{
}
