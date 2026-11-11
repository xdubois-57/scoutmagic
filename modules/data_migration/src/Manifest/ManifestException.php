<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Manifest;

/**
 * A manifest that makes the whole archive unusable: rejected before
 * anything is written. Every message is a French sentence for the person
 * who deposited the file, naming only fields and members of THEIR archive.
 */
final class ManifestException extends \RuntimeException implements \Core\Exception\UserFacingException
{
}
